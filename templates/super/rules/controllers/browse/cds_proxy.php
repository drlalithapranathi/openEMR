<?php
/**
 * cds_proxy.php — CDS Import Backend Handler
 *
 * Place at: templates/super/rules/controllers/browse/cds_proxy.php
 *
 * Actions:
 *   GET  ?action=validate      → fetch library list from CQL service + flag already-imported
 *   GET  ?action=key_status    → returns whether Groq API key is saved in OpenEMR globals table
 *   POST ?action=save_key      → saves Groq API key into OpenEMR globals table
 *   POST ?action=import&name=X → fetch ELM → simplify → Groq validates → insert into DB
 */

// ── Load OpenEMR globals.php ──────────────────────────────────────────────────
// From templates/super/rules/controllers/browse/ → up 5 levels → interface/
require_once(__DIR__ . '/../../../../../interface/globals.php');

use OpenEMR\Common\Acl\AclMain;

header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────────────────────────
if (!AclMain::aclCheckCore('admin', 'super')) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── Config ────────────────────────────────────────────────────────────────────
$CQL_SERVICE_URL = 'https://cdsconnect.org';
$GROQ_MODEL      = 'openai/gpt-oss-120b';
$GROQ_ENDPOINT   = 'https://api.groq.com/openai/v1/chat/completions';

// ── Route ─────────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? 'validate';

try {
    if ($action === 'validate') {
        handleValidate($CQL_SERVICE_URL);

    } elseif ($action === 'key_status') {
        handleKeyStatus();

    } elseif ($action === 'save_key') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Use POST for save_key']);
            exit;
        }
        handleSaveKey();

    } elseif ($action === 'import' && isset($_GET['name'])) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Use POST for import']);
            exit;
        }
        handleImport(trim($_GET['name']), $CQL_SERVICE_URL, $GROQ_MODEL, $GROQ_ENDPOINT);

    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
    }

} catch (Exception $e) {
    error_log('CDS Proxy Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'details' => $e->getMessage()]);
}


// ═══════════════════════════════════════════════════════════════════════════════
// GROQ API KEY — stored in OpenEMR globals table as 'cds_groq_api_key'
// Reads/writes directly from DB — no need to modify interface/globals.php
// ═══════════════════════════════════════════════════════════════════════════════

function getGroqApiKey(): string
{
    $row = sqlQuery("SELECT gl_value FROM globals WHERE gl_name = 'cds_groq_api_key'");
    return $row ? (string)($row['gl_value'] ?? '') : '';
}

function handleKeyStatus(): void
{
    $key = getGroqApiKey();
    echo json_encode(['configured' => !empty($key)]);
}

function handleSaveKey(): void
{
    $input = json_decode(file_get_contents('php://input'), true);
    $key   = trim($input['key'] ?? '');

    if (empty($key)) {
        http_response_code(400);
        echo json_encode(['error' => 'Key cannot be empty']);
        return;
    }

    $existing = sqlQuery("SELECT gl_name FROM globals WHERE gl_name = 'cds_groq_api_key'");
    if ($existing) {
        sqlStatement("UPDATE globals SET gl_value = ? WHERE gl_name = 'cds_groq_api_key'", [$key]);
    } else {
        sqlStatement("INSERT INTO globals (gl_name, gl_value) VALUES ('cds_groq_api_key', ?)", [$key]);
    }

    echo json_encode(['success' => true]);
}


// ═══════════════════════════════════════════════════════════════════════════════
// ACTION: validate
// Calls CDS Connect /cds-services/ and normalises response into:
//   { valid_libraries: [{name, version, imported}], invalid_libraries: [] }
// ═══════════════════════════════════════════════════════════════════════════════
function handleValidate(string $cqlUrl): void
{
    $ch = curl_init($cqlUrl . '/cds-services/');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        throw new Exception('CQL service unreachable: ' . $curlErr);
    }
    if ($httpCode !== 200) {
        throw new Exception('CQL service returned HTTP ' . $httpCode);
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new Exception('Invalid JSON from CQL service');
    }

    // CDS Connect returns { services: [{id, hook, title, description, prefetch}] }
    $validLibs = [];
    foreach ($data['services'] ?? [] as $svc) {
        $name  = $svc['id']    ?? ($svc['name'] ?? '');
        $title = $svc['title'] ?? $name;
        if (empty($name)) continue;

        $ruleId   = slugifyRuleId($name);
        $existing = sqlQuery("SELECT id FROM clinical_rules WHERE id = ?", [$ruleId]);

        $validLibs[] = [
            'name'     => $name,
            'title'    => $title,
            'version'  => $svc['version'] ?? null,
            'imported' => !empty($existing),
        ];
    }

    echo json_encode([
        'valid_libraries'   => $validLibs,
        'invalid_libraries' => [],
    ]);
}


// ═══════════════════════════════════════════════════════════════════════════════
// ACTION: import
// Fetches ELM → checks embedded errors → Groq validates → inserts into DB
// Fails explicitly if Groq API key is not configured
// ═══════════════════════════════════════════════════════════════════════════════
function handleImport(
    string $libraryName,
    string $cqlUrl,
    string $groqModel,
    string $groqEndpoint
): void {
    $ruleId = slugifyRuleId($libraryName);

    // Already imported?
    $existing = sqlQuery("SELECT id FROM clinical_rules WHERE id = ?", [$ruleId]);
    if ($existing) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => "Already imported as rule '$ruleId'"]);
        return;
    }

    // Require Groq key — do not fall back to structural-only validation
    $groqKey = getGroqApiKey();
    if (empty($groqKey)) {
        http_response_code(422);
        echo json_encode([
            'success'          => false,
            'validation_error' => 'Groq API key not configured. Please enter your key in the settings above.',
        ]);
        return;
    }

    // Fetch ELM JSON from CQL service
    $elmJson = fetchElmJson($cqlUrl, $libraryName);

    // Check embedded CQL-to-ELM compilation errors
    $embeddedErrors = extractEmbeddedErrors($elmJson);
    if (!empty($embeddedErrors)) {
        http_response_code(422);
        echo json_encode([
            'success'          => false,
            'validation_error' => 'ELM compilation errors: ' . implode('; ', $embeddedErrors),
        ]);
        return;
    }

    // Groq LLM validation
    $validation = validateWithGroq($elmJson, $groqKey, $groqModel, $groqEndpoint);
    if (!$validation['valid']) {
        http_response_code(422);
        echo json_encode([
            'success'          => false,
            'validation_error' => implode("\n", $validation['errors']) ?: 'Validation failed',
        ]);
        return;
    }

    // Import into OpenEMR DB
    importElmToOpenEmr($elmJson, $ruleId, $libraryName);

    echo json_encode([
        'success' => true,
        'rule_id' => $ruleId,
        'message' => "Imported '$libraryName' as OpenEMR rule '$ruleId'",
    ]);
}


// ── Fetch ELM JSON from CDS Connect ──────────────────────────────────────────
function fetchElmJson(string $cqlUrl, string $libraryName): array
{
    $urlsToTry = [
        $cqlUrl . '/api/library/' . urlencode($libraryName) . '/version/1.0.0',
        $cqlUrl . '/api/library/' . urlencode($libraryName),
        $cqlUrl . '/library/' . urlencode($libraryName),
    ];

    foreach ($urlsToTry as $url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr || $httpCode !== 200) continue;

        $elmJson = json_decode($response, true);
        if (is_array($elmJson) && isset($elmJson['library'])) {
            return $elmJson;
        }
    }

    throw new Exception("Could not fetch ELM for '$libraryName' from CQL service");
}


// ═══════════════════════════════════════════════════════════════════════════════
// ELM SIMPLIFIER — PHP port of elm_simplifier.py
//
// Two functions mirror the reference Python implementation:
//   parseExpression()     → parse_expression()   — recursive ELM→text
//   simplifyElm()         → simplify_elm()       — full logic summary
//   keyValuesSection()    → simplify_elm_for_gemini() — age/time/vs only
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Recursively convert one ELM expression node into human-readable text.
 * PHP port of parse_expression() from elm_simplifier.py
 */
function parseExpression($expr, int $depth = 0): string
{
    if (!is_array($expr)) return (string)$expr;

    $type = $expr['type'] ?? '';

    // Literal values
    if ($type === 'Literal') {
        $value     = $expr['value'] ?? '?';
        $valueType = preg_replace('/^.*\}/', '', $expr['valueType'] ?? '');
        return "{$value} ({$valueType})";
    }

    // Quantity (time intervals, dosages)
    if ($type === 'Quantity') {
        return ($expr['value'] ?? '?') . ' ' . ($expr['unit'] ?? '');
    }

    // Age calculation
    if ($type === 'CalculateAge') {
        $precision = $expr['precision'] ?? 'Year';
        return "Patient's age in " . strtolower($precision) . "s";
    }

    // Comparison operators
    $opMap = ['GreaterOrEqual' => '>=', 'Greater' => '>', 'LessOrEqual' => '<=', 'Less' => '<', 'Equal' => '='];
    if (isset($opMap[$type])) {
        $operands = $expr['operand'] ?? [];
        if (count($operands) >= 2) {
            return parseExpression($operands[0], $depth) . ' ' . $opMap[$type] . ' ' . parseExpression($operands[1], $depth);
        }
    }

    // Boolean operators
    if ($type === 'And') {
        $parts = array_map(fn($op) => parseExpression($op, $depth), $expr['operand'] ?? []);
        return implode(' AND ', $parts);
    }
    if ($type === 'Or') {
        $parts = array_map(fn($op) => parseExpression($op, $depth), $expr['operand'] ?? []);
        return implode(' OR ', $parts);
    }
    if ($type === 'Not') {
        return 'NOT (' . parseExpression($expr['operand'] ?? [], $depth) . ')';
    }

    // Existence check
    if ($type === 'Exists') {
        return 'EXISTS (' . parseExpression($expr['operand'] ?? [], $depth) . ')';
    }

    // Named definition reference
    if ($type === 'ExpressionRef') {
        return '[' . ($expr['name'] ?? '?') . ']';
    }

    // Function call
    if ($type === 'FunctionRef') {
        $args = array_map(fn($op) => parseExpression($op, $depth), $expr['operand'] ?? []);
        return ($expr['name'] ?? '?') . '(' . implode(', ', $args) . ')';
    }

    // Value set reference
    if ($type === 'ValueSetRef') {
        return 'ValueSet "' . ($expr['name'] ?? '?') . '"';
    }

    // FHIR data retrieval
    if ($type === 'Retrieve') {
        $dataType = preg_replace('/^.*\}/', '', $expr['dataType'] ?? '');
        $codes    = $expr['codes'] ?? [];
        if (!empty($codes) && isset($codes['name'])) {
            return "Retrieve {$dataType} where type in ValueSet \"{$codes['name']}\"";
        }
        return "Retrieve {$dataType}";
    }

    // Conditional
    if ($type === 'If') {
        $cond = parseExpression($expr['condition'] ?? [], $depth);
        $then = parseExpression($expr['then']      ?? [], $depth);
        $else = parseExpression($expr['else']      ?? [], $depth);
        return "IF {$cond} THEN {$then} ELSE {$else}";
    }

    if ($type === 'Null') return 'null';

    // Type cast — just unwrap
    if ($type === 'As') {
        return parseExpression($expr['operand'] ?? [], $depth);
    }

    // Interval
    if ($type === 'Interval') {
        $low  = parseExpression($expr['low']  ?? [], $depth);
        $high = parseExpression($expr['high'] ?? [], $depth);
        $lc   = ($expr['lowClosed']  ?? true) ? '[' : '(';
        $hc   = ($expr['highClosed'] ?? true) ? ']' : ')';
        return "{$lc}{$low} to {$high}{$hc}";
    }

    // Arithmetic
    if ($type === 'Subtract') {
        $operands = $expr['operand'] ?? [];
        if (count($operands) >= 2) {
            return parseExpression($operands[0], $depth) . ' - ' . parseExpression($operands[1], $depth);
        }
    }
    if ($type === 'Now') return 'Now';

    // Function parameter reference
    if ($type === 'OperandRef') {
        return '$' . ($expr['name'] ?? '?');
    }

    // Query (FROM ... WHERE ...)
    if ($type === 'Query') {
        $parts = [];
        foreach ($expr['source'] ?? [] as $src) {
            $alias   = $src['alias'] ?? '';
            $srcExpr = parseExpression($src['expression'] ?? [], $depth);
            $parts[] = "FROM {$srcExpr} AS {$alias}";
        }
        foreach ($expr['let'] ?? [] as $let) {
            $parts[] = 'LET ' . ($let['identifier'] ?? '?') . ' = ' . parseExpression($let['expression'] ?? [], $depth);
        }
        if (!empty($expr['where'])) {
            $parts[] = 'WHERE ' . parseExpression($expr['where'], $depth);
        }
        return implode(' ', $parts);
    }

    if ($type === 'QueryLetRef') {
        return '$' . ($expr['name'] ?? '?');
    }

    // Interval overlap
    if ($type === 'Overlaps') {
        $operands = $expr['operand'] ?? [];
        if (count($operands) >= 2) {
            return '(' . parseExpression($operands[0], $depth) . ') OVERLAPS (' . parseExpression($operands[1], $depth) . ')';
        }
    }

    // Property access
    if ($type === 'Property') {
        $path  = $expr['path']  ?? '?';
        $scope = $expr['scope'] ?? '';
        if ($scope) return "{$scope}.{$path}";
        return parseExpression($expr['source'] ?? [], $depth) . ".{$path}";
    }

    if ($type === 'Count') {
        return 'COUNT(' . parseExpression($expr['source'] ?? [], $depth) . ')';
    }

    if ($type === 'SingletonFrom') {
        return parseExpression($expr['operand'] ?? [], $depth);
    }

    // Fallback — show type name so LLM knows something is there
    return "[{$type}]";
}

/**
 * Convert full ELM JSON into a human-readable clinical logic summary.
 * PHP port of simplify_elm() from elm_simplifier.py
 */
function simplifyElm(array $elmJson): string
{
    $library    = $elmJson['library'] ?? [];
    $identifier = $library['identifier'] ?? [];
    $lines      = [];

    $libName    = $identifier['id']      ?? 'Unknown';
    $libVersion = $identifier['version'] ?? '';
    $lines[]    = "# ELM Logic Summary: {$libName} {$libVersion}";
    $lines[]    = '';

    // Value Sets
    $valueSets = $library['valueSets']['def'] ?? [];
    if (!empty($valueSets)) {
        $lines[] = '## Value Sets Referenced';
        foreach ($valueSets as $vs) {
            $name  = $vs['name'] ?? 'Unknown';
            $raw   = $vs['id']   ?? '';
            $oid   = substr($raw, (int)strrpos($raw, '/') + 1);
            $lines[] = "- {$name}: {$oid}";
        }
        $lines[] = '';
    }

    // Statements — the actual clinical logic
    $statements = $library['statements']['def'] ?? [];
    if (!empty($statements)) {
        $lines[] = '## Clinical Logic Definitions';
        $lines[] = '';
        foreach ($statements as $stmt) {
            $name = $stmt['name'] ?? 'Unknown';
            $expr = $stmt['expression'] ?? [];

            // Skip the internal Patient singleton
            if ($name === 'Patient' && ($expr['type'] ?? '') === 'SingletonFrom') continue;

            $summary = parseExpression($expr, 0);

            if (($stmt['type'] ?? '') === 'FunctionDef') {
                $params  = implode(', ', array_map(fn($op) => $op['name'] ?? '?', $stmt['operand'] ?? []));
                $lines[] = "### {$name}({$params})";
            } else {
                $lines[] = "### {$name}";
            }

            $lines[] = $summary;
            $lines[] = '';
        }
    }

    return implode("\n", $lines);
}

/**
 * Extract key values section (age thresholds, time intervals, value sets).
 * PHP port of simplify_elm_for_gemini() from elm_simplifier.py
 */
function keyValuesSection(array $elmJson): string
{
    $library    = $elmJson['library'] ?? [];
    $identifier = $library['identifier'] ?? [];

    $ageThresholds = [];
    $timeIntervals = [];
    $valueSets     = [];

    foreach ($library['valueSets']['def'] ?? [] as $vs) {
        $name      = $vs['name'] ?? 'Unknown';
        $raw       = $vs['id']   ?? '';
        $oid       = substr($raw, (int)strrpos($raw, '/') + 1);
        $valueSets[] = "- {$name}: {$oid}";
    }

    $extract = null;
    $extract = function ($expr, string $ctx) use (&$extract, &$ageThresholds, &$timeIntervals): void {
        if (!is_array($expr)) return;
        $type  = $expr['type'] ?? '';
        $opMap = ['GreaterOrEqual' => '>=', 'Greater' => '>', 'LessOrEqual' => '<=', 'Less' => '<', 'Equal' => '='];

        if (isset($opMap[$type])) {
            $operands = $expr['operand'] ?? [];
            if (count($operands) >= 2 && ($operands[0]['type'] ?? '') === 'CalculateAge') {
                $precision       = $operands[0]['precision'] ?? 'Year';
                $value           = $operands[1]['value']     ?? '?';
                $ageThresholds[] = "- Age {$opMap[$type]} {$value} " . strtolower($precision) . "s (in: {$ctx})";
            }
        }

        if ($type === 'Quantity') {
            $timeIntervals[] = "- " . ($expr['value'] ?? '?') . " " . ($expr['unit'] ?? '') . " (in: {$ctx})";
        }

        foreach ($expr as $val) {
            if (is_array($val)) {
                if (isset($val['type'])) {
                    $extract($val, $ctx);
                } else {
                    foreach ($val as $item) {
                        if (is_array($item)) $extract($item, $ctx);
                    }
                }
            }
        }
    };

    foreach ($library['statements']['def'] ?? [] as $stmt) {
        $name = $stmt['name'] ?? 'Unknown';
        if (!empty($stmt['expression'])) $extract($stmt['expression'], $name);
        foreach ($stmt['operand'] ?? [] as $op) {
            if (is_array($op)) $extract($op, $name);
        }
    }

    $lines   = [];
    $libName = $identifier['id'] ?? 'Unknown';
    $lines[] = "Library: {$libName}";
    $lines[] = '';
    $lines[] = '**Age Thresholds:**';
    foreach ($ageThresholds ?: ['- None specified'] as $l) $lines[] = $l;
    $lines[] = '';
    $lines[] = '**Time Intervals (Lookback Periods):**';
    foreach ($timeIntervals ?: ['- None specified'] as $l) $lines[] = $l;
    $lines[] = '';
    $lines[] = '**Value Sets:**';
    foreach ($valueSets ?: ['- None specified'] as $l) $lines[] = $l;

    return implode("\n", $lines);
}


// ═══════════════════════════════════════════════════════════════════════════════
// GROQ VALIDATION — follows build_gemini_prompt (without CPG) pattern
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Build prompt matching build_gemini_prompt() without CPG.
 * Without a CPG file, uses a lenient "clinically reasonable?" check.
 * Sends BOTH the key-values summary AND the full parsed logic summary.
 */
function buildGroqPrompt(array $elmJson): string
{
    $keyValues = keyValuesSection($elmJson);
    $fullLogic = simplifyElm($elmJson);

    return "Analyze this clinical logic implementation.\n\n"
         . $keyValues . "\n\n"
         . "## Full Logic Summary\n\n"
         . $fullLogic . "\n\n"
         . "Are the values clinically reasonable?\n\n"
         . "VALID: YES or NO\n"
         . "ERRORS: None, or list issues";
}

function parseGroqResponse(string $text): array
{
    $lines   = array_filter(array_map('trim', explode("\n", $text)));
    $valid   = true;
    $errors  = [];
    $section = null;

    foreach ($lines as $line) {
        $upper = strtoupper($line);
        if (strncmp($upper, 'VALID:', 6) === 0) {
            $valid = strpos($upper, 'YES') !== false;
        } elseif (strncmp($upper, 'ERRORS:', 7) === 0) {
            $section = 'errors';
            $content = trim(substr($line, 7));
            if ($content !== '' && strtolower($content) !== 'none') $errors[] = $content;
        } elseif ($section === 'errors' && strlen($line) > 1 && ($line[0] === '-' || $line[0] === '*')) {
            $item = trim(substr($line, 1));
            if ($item !== '' && strtolower($item) !== 'none') $errors[] = $item;
        }
    }

    return ['valid' => $valid && empty($errors), 'errors' => $errors];
}

function validateWithGroq(array $elmJson, string $groqKey, string $groqModel, string $groqEndpoint): array
{
    // Structural checks always run first
    if (empty($elmJson['library'])) {
        return ['valid' => false, 'errors' => ['Missing top-level "library" key']];
    }
    if (empty($elmJson['library']['identifier']['id'])) {
        return ['valid' => false, 'errors' => ['Missing library.identifier.id']];
    }

    $ch = curl_init($groqEndpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $groqKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model'       => $groqModel,
            'messages'    => [
                ['role' => 'system', 'content' => 'You are a clinical decision support validator. Always use the exact response format requested.'],
                ['role' => 'user',   'content' => buildGroqPrompt($elmJson)],
            ],
            'temperature' => 0.1,
            'max_tokens'  => 500,
        ]),
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['valid' => false, 'errors' => ['Groq API connection failed: ' . $curlErr]];
    }
    if ($httpCode === 401) {
        return ['valid' => false, 'errors' => ['Groq API key is invalid or expired. Please update it in the settings above.']];
    }
    if ($httpCode !== 200) {
        return ['valid' => false, 'errors' => ['Groq API returned HTTP ' . $httpCode]];
    }

    $content = json_decode($response, true)['choices'][0]['message']['content'] ?? '';
    if (empty($content)) {
        return ['valid' => false, 'errors' => ['Empty response from Groq API']];
    }

    return parseGroqResponse($content);
}


// ═══════════════════════════════════════════════════════════════════════════════
// DB IMPORT — field_mapping.json rules
// ═══════════════════════════════════════════════════════════════════════════════
function importElmToOpenEmr(array $elmJson, string $ruleId, string $libraryName): void
{
    $library = $elmJson['library'] ?? [];
    $version = $library['identifier']['version'] ?? null;

    $recPatterns = ['Recommendation', 'RecommendationText', 'ClinicalRecommendation'];
    $ratPatterns = ['Rationale', 'RationaleText'];
    $recText = null;
    $ratText = null;

    foreach ($library['statements']['def'] ?? [] as $stmt) {
        $name = $stmt['name'] ?? '';
        $expr = $stmt['expression'] ?? [];
        if ($recText === null && in_array($name, $recPatterns, true)) $recText = extractLiteralText($expr);
        if ($ratText  === null && in_array($name, $ratPatterns,  true)) $ratText  = extractLiteralText($expr);
    }

    $reminderMessage = $recText ?? "Imported from CQL library: {$libraryName}";
    if ($ratText) $reminderMessage .= "\n\nRationale: " . $ratText;

    // Human-readable title: replace dashes/underscores with spaces, title-case
    $ruleTitle = ucwords(str_replace(['-', '_'], ' ', $libraryName));

    // Unique action item name for this rule (max 31 chars, prefixed 'cds_')
    $actionItem = 'cds_' . substr($ruleId, 0, 27);

    // 1. Main rule record
    sqlStatement(
        "INSERT INTO clinical_rules
            (id, pid, active_alert_flag, passive_alert_flag, patient_reminder_flag,
             cqm_flag, amc_flag, access_control, release_version)
         VALUES (?, 0, 0, 1, 0, 0, 0, 'patients:med', ?)",
        [$ruleId, $version]
    );

    // 2. Display title — required for the rule to appear with a name in OpenEMR UI
    //    activity=1 makes it visible; edit_options=1 allows editing in admin Lists UI
    sqlStatement(
        "INSERT INTO list_options (list_id, option_id, title, seq, is_default, activity, edit_options)
         VALUES ('clinical_rules', ?, ?, 10, 0, 1, 1)",
        [$ruleId, $ruleTitle]
    );

    // 3. Action item — stores the reminder text shown in the patient chart
    //    Uses act_cat_assess (a valid existing category) with a unique item per rule
    sqlStatement(
        "INSERT INTO rule_action_item (category, item, reminder_message, custom_flag)
         VALUES ('act_cat_assess', ?, ?, 1)",
        [$actionItem, $reminderMessage]
    );

    // 4. Action binding — links rule → (category, item)
    sqlStatement(
        "INSERT INTO rule_action (id, category, item) VALUES (?, 'act_cat_assess', ?)",
        [$ruleId, $actionItem]
    );

    // 5. Reminder timing (best-effort; safe to skip if column structure differs)
    try {
        sqlStatement(
            "INSERT INTO rule_reminder (id, method, method_detail, value)
             VALUES (?, 'clinical_reminder_pre', 'month', '1')",
            [$ruleId]
        );
    } catch (Exception $e) {
        error_log("CDS Import: rule_reminder insert skipped — " . $e->getMessage());
    }
}


// ═══════════════════════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════════════════════
function extractEmbeddedErrors(array $elmJson): array
{
    $errors = [];
    foreach ($elmJson['library']['annotation'] ?? [] as $ann) {
        if (($ann['type'] ?? '') === 'CqlToElmError' && ($ann['errorSeverity'] ?? '') === 'error') {
            $errors[] = ($ann['message'] ?? 'Unknown') . ' (line ' . ($ann['startLine'] ?? '?') . ')';
        }
    }
    return $errors;
}

function extractLiteralText(array $expr): ?string
{
    if (($expr['type'] ?? '') === 'Literal' && isset($expr['value'])) {
        return (string) $expr['value'];
    }
    foreach ($expr as $val) {
        if (!is_array($val)) continue;
        $result = isset($val['type']) ? extractLiteralText($val) : null;
        if ($result !== null) return $result;
        if (!isset($val['type'])) {
            foreach ($val as $item) {
                if (is_array($item)) {
                    $r = extractLiteralText($item);
                    if ($r !== null) return $r;
                }
            }
        }
    }
    return null;
}

function slugifyRuleId(string $name): string
{
    $slug = strtolower($name);
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $slug = trim($slug, '_');
    return substr($slug, 0, 31);
}
