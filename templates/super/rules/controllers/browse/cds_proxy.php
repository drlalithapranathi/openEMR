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
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 */

require_once(__DIR__ . '/../../../../../interface/globals.php');

use OpenEMR\Common\Acl\AclMain;

header('Content-Type: application/json');

if (!AclMain::aclCheckCore('admin', 'super')) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$CQL_SERVICE_URL = 'https://cdsconnect.org';
$GROQ_MODEL      = 'openai/gpt-oss-120b';
$GROQ_ENDPOINT   = 'https://api.groq.com/openai/v1/chat/completions';

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


// ── Groq API Key — stored in OpenEMR globals table ────────────────────────────

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

    if ($curlErr) throw new Exception('CQL service unreachable: ' . $curlErr);
    if ($httpCode !== 200) throw new Exception('CQL service returned HTTP ' . $httpCode);

    $data = json_decode($response, true);
    if (!is_array($data)) throw new Exception('Invalid JSON from CQL service');

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


function handleImport(
    string $libraryName,
    string $cqlUrl,
    string $groqModel,
    string $groqEndpoint
): void {
    $ruleId = slugifyRuleId($libraryName);

    $existing = sqlQuery("SELECT id FROM clinical_rules WHERE id = ?", [$ruleId]);
    if ($existing) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => "Already imported as rule '$ruleId'"]);
        return;
    }

    $groqKey = getGroqApiKey();
    if (empty($groqKey)) {
        http_response_code(422);
        echo json_encode([
            'success'          => false,
            'validation_error' => 'Groq API key not configured. Please enter your key in the settings above.',
        ]);
        return;
    }

    $elmJson = fetchElmJson($cqlUrl, $libraryName);

    $embeddedErrors = extractEmbeddedErrors($elmJson);
    if (!empty($embeddedErrors)) {
        http_response_code(422);
        echo json_encode([
            'success'          => false,
            'validation_error' => 'ELM compilation errors: ' . implode('; ', $embeddedErrors),
        ]);
        return;
    }

    $validation = validateWithGroq($elmJson, $groqKey, $groqModel, $groqEndpoint);
    if (!$validation['valid']) {
        http_response_code(422);
        echo json_encode([
            'success'          => false,
            'validation_error' => implode("\n", $validation['errors']) ?: 'Validation failed',
        ]);
        return;
    }

    importElmToOpenEmr($elmJson, $ruleId, $libraryName);

    echo json_encode([
        'success' => true,
        'rule_id' => $ruleId,
        'message' => "Imported '$libraryName' as OpenEMR rule '$ruleId'",
    ]);
}


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


function simplifyElmForPrompt(array $elmJson): string
{
    $library = $elmJson['library'] ?? [];
    $libName = $library['identifier']['id'] ?? 'Unknown';
    $lines   = ["Library: {$libName}", ''];

    $ageThresholds = [];
    $timeIntervals = [];
    $valueSets     = [];

    foreach ($library['valueSets']['def'] ?? [] as $vs) {
        $name      = $vs['name'] ?? 'Unknown';
        $raw       = $vs['id']   ?? '';
        $oid       = substr($raw, (int)strrpos($raw, '/') + 1);
        $valueSets[] = "- {$name}: {$oid}";
    }

    $walk = null;
    $walk = function ($expr, string $ctx) use (&$walk, &$ageThresholds, &$timeIntervals): void {
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
                if (isset($val['type'])) $walk($val, $ctx);
                else foreach ($val as $item) { if (is_array($item)) $walk($item, $ctx); }
            }
        }
    };

    foreach ($library['statements']['def'] ?? [] as $stmt) {
        $name = $stmt['name'] ?? 'Unknown';
        if (!empty($stmt['expression'])) $walk($stmt['expression'], $name);
    }

    $lines[] = '**Age Thresholds:**';
    foreach ($ageThresholds ?: ['- None specified'] as $l) $lines[] = $l;
    $lines[] = '';
    $lines[] = '**Time Intervals:**';
    foreach ($timeIntervals ?: ['- None specified'] as $l) $lines[] = $l;
    $lines[] = '';
    $lines[] = '**Value Sets:**';
    foreach ($valueSets ?: ['- None specified'] as $l) $lines[] = $l;

    return implode("\n", $lines);
}

function buildGroqPrompt(array $elmJson): string
{
    $summary = simplifyElmForPrompt($elmJson);
    return "You are a clinical decision support validator.\n\n"
         . "Review this CQL library and determine if it is valid for import into OpenEMR.\n\n"
         . $summary . "\n\n"
         . "Respond in exactly this format:\n"
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

    if ($curlErr) return ['valid' => false, 'errors' => ['Groq API connection failed: ' . $curlErr]];
    if ($httpCode === 401) return ['valid' => false, 'errors' => ['Groq API key is invalid or expired. Please update it in the settings above.']];
    if ($httpCode !== 200) return ['valid' => false, 'errors' => ['Groq API returned HTTP ' . $httpCode]];

    $content = json_decode($response, true)['choices'][0]['message']['content'] ?? '';
    if (empty($content)) return ['valid' => false, 'errors' => ['Empty response from Groq API']];

    return parseGroqResponse($content);
}


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

    sqlStatement(
        "INSERT INTO clinical_rules
            (id, pid, active_alert_flag, passive_alert_flag, patient_reminder_flag,
             cqm_flag, amc_flag, access_control, release_version)
         VALUES (?, 0, 0, 1, 0, 0, 0, 'patients:med', ?)",
        [$ruleId, $version]
    );

    sqlStatement(
        "INSERT INTO rule_action (id, category, item) VALUES (?, 'reminder_clin', 'clin_reminder_a')",
        [$ruleId]
    );

    try {
        sqlStatement(
            "INSERT INTO rule_reminder (id, method, method_detail, value)
             VALUES (?, 'clinical_reminder_pre', 'month', ?)",
            [$ruleId, $reminderMessage]
        );
    } catch (Exception $e) {
        error_log("CDS Import: rule_reminder insert skipped — " . $e->getMessage());
    }
}


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
