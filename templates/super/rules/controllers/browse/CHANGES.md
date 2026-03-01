# CDS Import Feature — Detailed Change Log
# (for commit history and code review)

===========================================================================
## OVERVIEW — What this feature does
===========================================================================

Adds a "CDS Library Import" section to OpenEMR's Clinical Decision Rules (CDR)
page. Admins can browse CDS libraries available from the CDS Connect CQL service
and import them directly into OpenEMR's clinical rules database with one click.
An LLM (Groq openai/gpt-oss-120b) validates the ELM JSON before any import
is written to the database.

**Files deployed to container:**
```
/var/www/localhost/htdocs/openemr/templates/super/rules/controllers/browse/
  ├── list.php       ← MODIFIED  (original OpenEMR file, we added to it)
  └── cds_proxy.php  ← NEW FILE  (did not exist in original OpenEMR)
```

**Deploy command:**
```bash
bash /home/lapula/openemr-ai/cds-import/deploy.sh
```

**Container:** `oemr-openemr-1`
**Database:** `oemr-mysql-1` → openemr database


===========================================================================
## COMMIT 1 — New file: cds_proxy.php (initial version)
===========================================================================

**File:** `templates/super/rules/controllers/browse/cds_proxy.php`
**Status:** NEW FILE — did not exist in original OpenEMR
**Purpose:** PHP backend that handles all CDS import operations via AJAX.
            list.php calls this file for all data and actions.

### How globals.php is loaded:

```php
require_once(__DIR__ . '/../../../../../interface/globals.php');
```

Path breakdown from `browse/`:
- `..` → `controllers/`
- `../..` → `rules/`
- `../../..` → `super/`
- `../../../..` → `templates/`
- `../../../../..` → openemr root
- `../../../../../interface/` → `interface/`

Confirmed by running inside container:
```bash
find /var/www -name "globals.php" -not -path "*/vendor/*"
# Result: /var/www/localhost/htdocs/openemr/interface/globals.php
```

### Security:

```php
use OpenEMR\Common\Acl\AclMain;
if (!AclMain::aclCheckCore('admin', 'super')) {
    http_response_code(403); exit;
}
```
Only OpenEMR admins with `admin:super` ACL can call this file.

### Router (initial version — 2 actions):

| Action                     | Method | Handler function  |
|----------------------------|--------|-------------------|
| ?action=validate           | GET    | handleValidate()  |
| ?action=import&name={name} | POST   | handleImport()    |

### Functions in initial cds_proxy.php:

**handleValidate($cqlUrl)**
- Calls `GET https://cdsconnect.org/cds-services/`
- CDS Connect returns: `{ services: [{id, hook, title, description}] }`
- For each service: slugifies the `id`, checks `clinical_rules` table for
  existing entry to set `imported: true/false`
- Returns: `{ valid_libraries: [{name, title, version, imported}], invalid_libraries: [] }`

**handleImport($libraryName, $cqlUrl, $groqKey, $groqModel, $groqEndpoint)**
- Checks if already imported → 409 if yes
- Calls `fetchElmJson()` to get ELM JSON from CQL service
- Calls `extractEmbeddedErrors()` → 422 if ELM has compile errors
- Calls `validateWithGroq()` → 422 if LLM says invalid
- On pass: calls `importElmToOpenEmr()` → inserts to DB
- NOTE: At this stage, groqKey was passed as a parameter from the config block

**fetchElmJson($cqlUrl, $libraryName)**
- Tries 3 URL patterns in order until one returns HTTP 200 + valid ELM JSON:
  1. `/api/library/{name}/version/1.0.0`
  2. `/api/library/{name}`
  3. `/library/{name}`
- Throws exception if all 3 fail

**simplifyElmForPrompt($elmJson)**
- PHP port of `simplify_elm_for_prompt()` from modal_app.py
- Walks the ELM expression tree and extracts:
  - Age thresholds: `CalculateAge` comparison expressions
    e.g. "Age >= 18 years (in: MeetsInclusionCriteria)"
  - Time intervals: `Quantity` expressions
    e.g. "6 months (in: LookbackPeriod)"
  - Value sets: from `library.valueSets.def[]`
    e.g. "Hypertension: 2.16.840.1.113883.3.526.3.1174"
- Returns a compact multi-line string (NOT raw JSON) to reduce token usage

**buildGroqPrompt($elmJson)**
- PHP port of `build_prompt()` from modal_app.py
- Calls `simplifyElmForPrompt()` to get the summary
- Returns prompt text that instructs Groq to respond in EXACTLY this format:
  ```
  VALID: YES
  ERRORS: None
  ```
  or:
  ```
  VALID: NO
  ERRORS: [specific issues]
  ```

**parseGroqResponse($text)**
- PHP port of `parse_response()` from modal_app.py
- Parses `VALID: YES/NO` line → sets $valid bool
- Parses `ERRORS:` line and any `-` / `*` bullet lines after it
- Returns `['valid' => bool, 'errors' => string[]]`

**validateWithGroq($elmJson, $groqKey, $groqModel, $groqEndpoint)**
- First runs structural checks (missing `library` key, missing `identifier.id`)
- If no groqKey: ~~fell back to structural-only pass~~ (this was a bug — see COMMIT 3)
- Calls `POST https://api.groq.com/openai/v1/chat/completions`
  - model: `openai/gpt-oss-120b`
  - temperature: 0.1 (low randomness for consistent validation output)
  - max_tokens: 500
- Returns `parseGroqResponse()` result

**importElmToOpenEmr($elmJson, $ruleId, $libraryName)**
- Follows field_mapping.json rules for column names and defaults
- Scans ELM `statements.def[]` for known statement names:
  - Recommendation text: looks for names `Recommendation`, `RecommendationText`,
    `ClinicalRecommendation` → calls `extractLiteralText()` on the expression
  - Rationale text: looks for `Rationale`, `RationaleText`
- Builds `$reminderMessage` = recommendation + "\n\nRationale: " + rationale
- Runs 3 INSERTs:
  ```sql
  INSERT INTO clinical_rules
    (id, pid, active_alert_flag, passive_alert_flag, patient_reminder_flag,
     cqm_flag, amc_flag, access_control, release_version)
  VALUES (?, 0, 0, 1, 0, 0, 0, 'patients:med', ?)
  ```
  ```sql
  INSERT INTO rule_action (id, category, item)
  VALUES (?, 'reminder_clin', 'clin_reminder_a')
  ```
  ```sql
  INSERT INTO rule_reminder (id, method, method_detail, value)
  VALUES (?, 'clinical_reminder_pre', 'month', ?)
  ```
  rule_reminder INSERT is wrapped in try/catch — skipped silently if it
  fails (table schema can differ across OpenEMR versions).

**Helper functions:**

- `extractEmbeddedErrors($elmJson)` — scans `library.annotation[]` for entries
  where `type = "CqlToElmError"` AND `errorSeverity = "error"`. Returns array
  of error strings with line numbers.

- `extractLiteralText($expr)` — recursively walks an ELM expression tree,
  returns the first `Literal` node's `value` string it finds. Used to pull
  recommendation text out of ELM statement expressions.

- `slugifyRuleId($name)` — converts a library name to a valid OpenEMR rule ID:
  lowercase → non-alphanumeric chars → underscore → trim underscores → max 31 chars
  Example: `"Acute-Cholecystitis-Early-Surgery"` → `"acute_cholecystitis_early_su"`
  Max 31 chars because `clinical_rules.id` column is `varchar(31)`.

### Initial config block (later replaced — see COMMIT 3):

```php
$GROQ_API_KEY = $GLOBALS['groq_api_token']
             ?? $GLOBALS['groq_api_key']
             ?? getenv('GROQ_API_KEY')
             ?? '';
```
This tried OpenEMR globals then env var — but OpenEMR has no groq globals
defined by default, so the key was always empty.


===========================================================================
## COMMIT 2 — Modified file: list.php (initial CDS section)
===========================================================================

**File:** `templates/super/rules/controllers/browse/list.php`
**Status:** MODIFIED — original OpenEMR file, we added to it without touching
            any existing lines

### What the original file had (unchanged):

```
Lines 1–20:   PHP docblock + use OpenEMR\ClinicalDecisionRules\Interface\Common
Lines 22–23:  js_src() calls for list.js and jQuery.fn.sortElements.js
Lines 25–28:  <script> var list = new list_rules(); list.init(); </script>
Lines 216–250: HTML — Plans Config header, Rules Config header,
               rule_container div, template div
```

### What we added:

**New `<script>` block — CDS logic (jQuery/AJAX)**

```
CDS_PROXY = json_encode($GLOBALS['webroot'] . '/templates/.../cds_proxy.php')
```
Uses OpenEMR's `$GLOBALS['webroot']` so the URL is correct regardless of
install path. `json_encode()` makes it safe to embed in JavaScript.

`esc(str)` helper — XSS-safe HTML encoding using jQuery:
```javascript
function esc(str) { return $('<div/>').text(String(str || '')).html(); }
```
Used whenever user-supplied data (library names) is inserted into HTML.

`loadCdsLibraries()`:
- GETs `cds_proxy.php?action=validate`
- Shows Bootstrap spinner while loading
- On success → calls `renderTable(data)`
- On error → shows Bootstrap `alert-warning` with error detail

`renderTable(data)`:
- Merges `valid_libraries` and `invalid_libraries` arrays from proxy response
- Builds a Bootstrap `table-sm table-bordered table-hover` HTML table
- Columns: Library Name | Version | CQL Status | Import Status | Action
- CQL Status badge: green "Valid" or red "Invalid"
- Import Status badge: blue "Imported" or grey "Not Imported"
- Action button states:
  - `invalid`: grey "Cannot Import" (disabled)
  - `imported`: green "Already Imported" (disabled)
  - `valid + not imported`: blue "Validate & Import" (clickable)
- After rendering: binds `.import-btn` click via event delegation on `$container`

`importLibrary(libName, $btn)`:
- Disables button, shows Bootstrap spinner + "Validating with AI..."
- POSTs to `cds_proxy.php?action=import&name={encodeURIComponent(libName)}`
- On success (`data.success === true`):
  - Button → green "Imported ✓" (stays disabled)
  - Import Status badge → blue "Imported"
- On failure: calls `showError(libName, msg)`

`showError(libName, reason)`:
- `alert()` with library name and error reason
- Checks `data.validation_error` first, then `data.error`

**New HTML section at bottom of page:**

```html
<hr class="mt-4"/>
<div class="mt-3 mb-4">
  <div class="header">
    <header class="title">
      Available CDS Libraries (from CQL Service)
      <span>
        <button id="cds-refresh-btn" class="btn btn-info btn-sm ml-2">Refresh</button>
      </span>
    </header>
  </div>
  <div id="cds-table-container" class="mt-2 border rounded p-2 bg-white">
    [spinner shown on page load]
  </div>
</div>
```


===========================================================================
## COMMIT 3 — Bug fix + Groq key UI: cds_proxy.php + list.php
===========================================================================

### Bug 1 fixed: Libraries imported without real Groq validation

**Root cause in cds_proxy.php `validateWithGroq()`:**
```php
// No Groq key — structural check is enough for now   ← WRONG
if (empty($groqKey)) {
    return ['valid' => true, 'errors' => []];          // always passed!
}
```
With no key set (which was always the case since the config block couldn't
find the key), every library silently passed validation and was inserted
into the database. The "Validate & Import" button always showed "Imported ✓"
whether the library was valid or not.

**Fix:**
- `handleImport()` now calls `getGroqApiKey()` at the very start
- If key is empty → immediately returns HTTP 422:
  `"Groq API key not configured. Please enter your key in the settings above."`
- The fallback `return ['valid' => true]` in `validateWithGroq()` is removed
- All Groq API errors are now hard failures with descriptive messages:
  - HTTP 401 → "Groq API key is invalid or expired. Please update it."
  - curl error → "Groq API connection failed: {curl error}"
  - empty response → "Empty response from Groq API"
  - HTTP other → "Groq API returned HTTP {code}"

---

### Bug 2 fixed: API key was never actually being read

**Root cause:** The original config block tried:
```php
$GLOBALS['groq_api_token'] ?? $GLOBALS['groq_api_key'] ?? getenv('GROQ_API_KEY') ?? ''
```
OpenEMR's `$GLOBALS` are only populated for settings defined in
`interface/globals.php`. We never added a groq entry there, so both
`$GLOBALS` lookups returned null. There was no `GROQ_API_KEY` env var either.
The key was always an empty string.

**Fix:** Removed the broken config block entirely. Replaced with direct DB reads.

---

### New feature: Groq API key stored in OpenEMR globals table, set via UI

**Design decision:** Instead of modifying OpenEMR's core `interface/globals.php`
to add a new settings field (which would require understanding its large metadata
array format), we write directly to the `globals` table using OpenEMR's own
`sqlQuery()` / `sqlStatement()` functions. The key is stored as:
```
gl_name  = 'cds_groq_api_key'
gl_value = 'gsk_...'
```

**New functions in cds_proxy.php:**

`getGroqApiKey(): string`
```php
$row = sqlQuery("SELECT gl_value FROM globals WHERE gl_name = 'cds_groq_api_key'");
return $row ? (string)($row['gl_value'] ?? '') : '';
```
Called from `handleImport()` and `handleKeyStatus()`.

`handleKeyStatus(): void`
- Returns `{"configured": true}` or `{"configured": false}`
- Used by list.php on page load to show green/yellow status bar

`handleSaveKey(): void`
- Reads JSON body from `php://input`: `{"key": "gsk_..."}`
- Validates key is non-empty → HTTP 400 if empty
- INSERT if `cds_groq_api_key` row doesn't exist, UPDATE if it does:
  ```php
  $existing = sqlQuery("SELECT gl_name FROM globals WHERE gl_name = 'cds_groq_api_key'");
  if ($existing) {
      sqlStatement("UPDATE globals SET gl_value = ? WHERE gl_name = 'cds_groq_api_key'", [$key]);
  } else {
      sqlStatement("INSERT INTO globals (gl_name, gl_value) VALUES ('cds_groq_api_key', ?)", [$key]);
  }
  ```
- Returns `{"success": true}`

**Updated router — now handles 4 actions:**

| Action                     | Method | Handler            |
|----------------------------|--------|--------------------|
| ?action=validate           | GET    | handleValidate()   |
| ?action=key_status         | GET    | handleKeyStatus()  |
| ?action=save_key           | POST   | handleSaveKey()    |
| ?action=import&name={name} | POST   | handleImport()     |

**handleImport() signature changed:**
- Before: `handleImport($libraryName, $cqlUrl, $groqKey, $groqModel, $groqEndpoint)`
- After:  `handleImport($libraryName, $cqlUrl, $groqModel, $groqEndpoint)`
- Key is no longer passed as parameter — fetched from DB via `getGroqApiKey()`
- Key check is the first thing in the function (before any curl calls)

---

### New UI in list.php — Groq key settings bar

**New JS functions:**

`checkKeyStatus()`:
- GETs `?action=key_status` on page load
- If `configured: true`:
  - `#cds-key-status-bar` → green (`alert-success`):
    "Groq API Key: Configured ✓  [Update Key]"
  - `#cds-key-form` → hidden
- If `configured: false`:
  - `#cds-key-status-bar` → yellow (`alert-warning`):
    "⚠ Groq API Key not set — Enter your key below..."
  - `#cds-key-form` → shown, input focused automatically

`saveGroqKey()`:
- Reads value from `#cds-groq-key-input`
- Validates non-empty → alert if empty
- POSTs `{"key": "..."}` JSON to `?action=save_key`
- On success: clears input, hides form, calls `checkKeyStatus()` to refresh bar
- On error: shows alert with error message
- Button shows "Saving..." while in-flight, re-enabled on completion

**New HTML elements added above `#cds-table-container`:**

```html
<!-- Status bar — re-rendered by checkKeyStatus() -->
<div id="cds-key-status-bar" class="alert alert-info py-2 mb-2 small">
    [spinner] Checking Groq API key status...
</div>

<!-- Key entry form — shown/hidden by JS -->
<div id="cds-key-form" class="form-inline mb-2" style="display:none">
    <input type="password" id="cds-groq-key-input"
           placeholder="gsk_..." style="width:340px" autocomplete="new-password">
    <button id="cds-save-key-btn" class="btn btn-sm btn-primary">Save Key</button>
</div>
```

**User workflow after this commit:**
1. Open CDR Rules page → yellow bar "⚠ Groq API Key not set" appears
2. Paste Groq API key into password field → click "Save Key"
3. Bar turns green "Groq API Key: Configured ✓  [Update Key]"
4. Click "Validate & Import" on any library → Groq runs → result shown
5. Next page load: bar shows green immediately (key persists in globals table)

**Files removed:**
- `cds_config.php` — this was a short-lived wrong approach (key in a PHP file).
  Deleted. Key now lives in the database.

**deploy.sh fixed:** Added `sudo` to all docker commands. Without sudo, `docker cp`
silently copies 0 bytes (permission denied on the socket). All three commands
now use `sudo docker ...`.


===========================================================================
## COMMIT 4 — Bug fix: "Update Key" link not working (list.php)
===========================================================================

**File:** `templates/super/rules/controllers/browse/list.php`
**Problem:** After saving a key, the green bar showed "Configured ✓ [Update Key]"
but clicking "Update Key" did nothing — the password form would not appear.

**Root cause — two bugs in the JS event binding:**

**Bug A:** Initial binding ran before element existed:
```javascript
// This ran at $(document).ready() time — before checkKeyStatus() rendered
// the #cds-toggle-key-link element. So it bound to nothing.
$('#cds-toggle-key-link').on('click', function (e) {
    e.preventDefault();
    $('#cds-key-form').toggle();
});
```

**Bug B:** Re-binding inside `checkKeyStatus()` stacked listeners:
```javascript
// Called every time checkKeyStatus() ran (page load + after each save).
// Each call added ANOTHER click listener. On the second call, two listeners
// fired: toggle open, then immediately toggle closed. Form never stayed open.
$(document).on('click', '#cds-toggle-key-link', function (e) {
    e.preventDefault();
    $('#cds-key-form').toggle();
});
```

**Fix:** One delegated binding at the top level, outside `checkKeyStatus()`,
never duplicated:
```javascript
$(document).on('click', '#cds-toggle-key-link', function (e) {
    e.preventDefault();
    $('#cds-key-form').toggle();
    if ($('#cds-key-form').is(':visible')) {
        $('#cds-groq-key-input').focus();   // auto-focus input when form opens
    }
});
```
Event delegation (`$(document).on(...)`) means it works even after the bar
HTML is re-rendered by `checkKeyStatus()`, because the listener is on `document`
not on the element itself.

Also added `$('#cds-key-form').hide()` explicitly in the `configured: true`
branch of `checkKeyStatus()` so the form is always hidden after a successful save.

**Updated key status bar label:** "Update" → "Update Key" (clearer label).


===========================================================================
## COMMIT 6 — Use CQL service description as LLM ground truth
===========================================================================

### Why this change

The `/cds-services/` endpoint returns a `description` field for every library.
That description is the human-written clinical logic — referred to as
`cpg_content` (Clinical Practice Guideline content) in the reference implementation.

Instead of asking Groq "are these values clinically reasonable?" (vague),
we now pass the description as ground truth and ask Groq to compare the
ELM implementation against it. This is the WITH-CPG path from the reference
`build_gemini_prompt()`.

When description is empty (fallback), the without-CPG prompt still applies.

---

### CHANGE 1 of 6
**File:** `templates/super/rules/controllers/browse/cds_proxy.php`
**What:** Add `description` field to each entry returned by `handleValidate()`

Find this exact block (around line 149):
```php
        $validLibs[] = [
            'name'     => $name,
            'title'    => $title,
            'version'  => $svc['version'] ?? null,
            'imported' => !empty($existing),
        ];
```

Replace with:
```php
        $validLibs[] = [
            'name'        => $name,
            'title'       => $title,
            'version'     => $svc['version']     ?? null,
            'imported'    => !empty($existing),
            'description' => $svc['description'] ?? '',
        ];
```

---

### CHANGE 2 of 6
**File:** `templates/super/rules/controllers/browse/cds_proxy.php`
**What:** Read `description` from the POST body at the top of `handleImport()`

Find this exact block (around line 175):
```php
    $ruleId = slugifyRuleId($libraryName);

    // Already imported?
    $existing = sqlQuery("SELECT id FROM clinical_rules WHERE id = ?", [$ruleId]);
```

Replace with:
```php
    $ruleId = slugifyRuleId($libraryName);

    // Read description (ground truth) sent from the browser
    $postBody    = json_decode(file_get_contents('php://input'), true) ?? [];
    $description = trim($postBody['description'] ?? '');

    // Already imported?
    $existing = sqlQuery("SELECT id FROM clinical_rules WHERE id = ?", [$ruleId]);
```

---

### CHANGE 3 of 6
**File:** `templates/super/rules/controllers/browse/cds_proxy.php`
**What:** Pass `$description` into `validateWithGroq()`

Find this exact line (around line 211):
```php
    $validation = validateWithGroq($elmJson, $groqKey, $groqModel, $groqEndpoint);
```

Replace with:
```php
    $validation = validateWithGroq($elmJson, $groqKey, $groqModel, $groqEndpoint, $description);
```

---

### CHANGE 4 of 6
**File:** `templates/super/rules/controllers/browse/cds_proxy.php`
**What:** Add `$description` parameter to `validateWithGroq()` and pass it to
`buildGroqPrompt()`

Find this exact function signature + call:
```php
function validateWithGroq(array $elmJson, string $groqKey, string $groqModel, string $groqEndpoint): array
```

Replace with:
```php
function validateWithGroq(array $elmJson, string $groqKey, string $groqModel, string $groqEndpoint, string $description = ''): array
```

Then inside the same function find:
```php
                ['role' => 'user',   'content' => buildGroqPrompt($elmJson)],
```

Replace with:
```php
                ['role' => 'user',   'content' => buildGroqPrompt($elmJson, $description)],
```

---

### CHANGE 5 of 6
**File:** `templates/super/rules/controllers/browse/cds_proxy.php`
**What:** Update `buildGroqPrompt()` to use the WITH-description prompt when
description is available, falling back to the without-CPG prompt if not.

Find the entire `buildGroqPrompt()` function:
```php
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
```

Replace with:
```php
function buildGroqPrompt(array $elmJson, string $description = ''): string
{
    $keyValues = keyValuesSection($elmJson);
    $fullLogic = simplifyElm($elmJson);

    if (!empty($description)) {
        // WITH ground truth: compare ELM implementation against the description
        // Mirrors reference build_gemini_prompt() with cpg_content
        return "You are validating a clinical decision support (CDS) implementation.\n\n"
             . "## Clinical Description (Ground Truth):\n{$description}\n\n"
             . "## ELM Implementation Summary:\n{$keyValues}\n\n"
             . "## Full Logic:\n{$fullLogic}\n\n"
             . "## Task:\nCompare the ELM implementation against the clinical description.\n"
             . "Check that the logic matches what is described.\n\n"
             . "VALID: YES\n"
             . "ERRORS: None\n\n"
             . "OR if there are mismatches:\n"
             . "VALID: NO\n"
             . "ERRORS: [describe specific mismatches between ELM and description]";
    }

    // WITHOUT description: basic clinical reasonableness check
    // Mirrors reference build_gemini_prompt() without cpg_content
    return "Analyze this clinical logic implementation.\n\n"
         . $keyValues . "\n\n"
         . "## Full Logic Summary\n\n"
         . $fullLogic . "\n\n"
         . "Are the values clinically reasonable?\n\n"
         . "VALID: YES or NO\n"
         . "ERRORS: None, or list issues";
}
```

---

### CHANGE 6 of 6
**File:** `templates/super/rules/controllers/browse/list.php`
**What:** Two edits in this file — A and B.

**6A — Add `data-description` to the import button in `renderTable()`**

Find:
```javascript
                actionBtn = '<button class="btn btn-sm btn-primary import-btn"' +
                            ' data-lib="' + esc(lib.name) + '"' +
                            ' id="btn-' + rowId + '">' +
                            '<?php echo xlt('Validate & Import'); ?></button>';
```

Replace with:
```javascript
                actionBtn = '<button class="btn btn-sm btn-primary import-btn"' +
                            ' data-lib="' + esc(lib.name) + '"' +
                            ' data-description="' + esc(lib.description || '') + '"' +
                            ' id="btn-' + rowId + '">' +
                            '<?php echo xlt('Validate & Import'); ?></button>';
```

**6B — Read description and send in POST body in `importLibrary()`**

Find:
```javascript
    function importLibrary(libName, $btn) {
        var rowId = 'cds-row-' + String(libName).replace(/[^a-zA-Z0-9]/g, '_');
```

Replace with:
```javascript
    function importLibrary(libName, $btn) {
        var rowId       = 'cds-row-' + String(libName).replace(/[^a-zA-Z0-9]/g, '_');
        var description = $btn.data('description') || '';
```

Then find the `$.ajax({` call inside `importLibrary()`:
```javascript
        $.ajax({
            url:      CDS_PROXY + '?action=import&name=' + encodeURIComponent(libName),
            method:   'POST',
            dataType: 'json',
            timeout:  120000,
```

Replace with:
```javascript
        $.ajax({
            url:         CDS_PROXY + '?action=import&name=' + encodeURIComponent(libName),
            method:      'POST',
            contentType: 'application/json',
            data:        JSON.stringify({ description: description }),
            dataType:    'json',
            timeout:     120000,
```

---

### What the full flow looks like after this commit

```
Page load
  → GET /cds-services/
  → Each service: { id, title, description, ... }
  → description stored in data-description on the Import button

User clicks "Validate & Import"
  → POST ?action=import&name=X
      body: { description: "...clinical text from /cds-services/..." }

cds_proxy.php
  → reads description from POST body
  → fetches ELM JSON from CQL service
  → builds prompt WITH description as ground truth:
       "Clinical Description: ...
        ELM Implementation: ...
        Compare the two. VALID: YES/NO"
  → Groq compares text description vs ELM logic
  → if VALID: YES → insert into DB
  → if VALID: NO  → show error to user
```

---

### Files changed in this commit

| File          | Changes |
|---------------|---------|
| cds_proxy.php | 5 edits — handleValidate, handleImport, validateWithGroq signature, validateWithGroq body, buildGroqPrompt |
| list.php      | 2 edits — data-description on button, description in AJAX POST body |


===========================================================================
## COMMIT 5 — New file: field_mapping.json (reference document)
===========================================================================

**File:** `openemr-ai/field_mapping.json`
**Status:** Reference document — NOT deployed to container
**Purpose:** Documents how ELM JSON fields map to OpenEMR DB table columns.
            Used as the design spec when writing `importElmToOpenEmr()`.

Key mappings used in the code (see COMMIT 7 for corrected DB columns):

| ELM field                  | OpenEMR table    | Column               | Transform         |
|----------------------------|------------------|----------------------|-------------------|
| library.identifier.id      | clinical_rules   | id                   | slugify, max 31   |
| library.identifier.version | clinical_rules   | release_version      | passthrough       |
| (default)                  | clinical_rules   | passive_alert_flag   | hardcoded 1       |
| (default)                  | clinical_rules   | access_control       | 'patients:med'    |
| (display title)            | list_options     | title                | ucwords + replace |
| Recommendation statement   | rule_action_item | reminder_message     | extractLiteralText|
| Rationale statement        | rule_action_item | reminder_message     | appended          |


===========================================================================
## COMMIT 7 — Full ELM simplifier rewrite + prompt fix (cds_proxy.php)
===========================================================================

**File:** `templates/super/rules/controllers/browse/cds_proxy.php`
**Problem:** Groq was rejecting almost every library because the original
`simplifyElmForPrompt()` only extracted age thresholds and value sets.
The full clinical logic (statements, conditions, expressions) was never
sent to Groq. The prompt also did not match the reference format.

### ELM Simplifier — complete rewrite

Three functions replaced the original `simplifyElmForPrompt()`:

**`parseExpression($expr, $depth)`** — PHP port of `parse_expression()`
from `elm_simplifier.py`. Recursively converts any ELM expression node into
human-readable text. Handles 20+ node types:

| ELM type         | Output example                              |
|------------------|---------------------------------------------|
| Literal          | `18 (Integer)`                              |
| Quantity         | `6 months`                                  |
| CalculateAge     | `Patient's age in years`                    |
| GreaterOrEqual   | `Patient's age in years >= 18 (Integer)`    |
| And              | `[expr1] AND [expr2]`                       |
| Or               | `[expr1] OR [expr2]`                        |
| Not              | `NOT ([expr])`                              |
| Exists           | `EXISTS ([expr])`                           |
| ExpressionRef    | `[MeetsInclusionCriteria]`                  |
| FunctionRef      | `AgeInYears()`                              |
| ValueSetRef      | `ValueSet "Hypertension"`                   |
| Retrieve         | `Retrieve Condition where type in VS "..."`  |
| If               | `IF [cond] THEN [x] ELSE [y]`               |
| Interval         | `[0 to 18 (Integer)]`                       |
| Subtract         | `Now - 6 months`                            |
| Query            | `FROM [expr] AS alias WHERE [cond]`         |
| Overlaps         | `([expr1]) OVERLAPS ([expr2])`              |
| Property         | `alias.onsetDateTime`                       |
| Count            | `COUNT([expr])`                             |
| SingletonFrom    | unwraps and delegates                       |

**`simplifyElm($elmJson)`** — PHP port of `simplify_elm()`.
Produces full human-readable clinical logic summary:
```
# ELM Logic Summary: LibraryName 1.0.0

## Value Sets Referenced
- Hypertension: 2.16.840.1.113883.3.526.3.1174

## Clinical Logic Definitions

### MeetsInclusionCriteria
Patient's age in years >= 18 (Integer) AND EXISTS (...)
```

**`keyValuesSection($elmJson)`** — PHP port of `simplify_elm_for_gemini()`.
Extracts the numeric key values (age thresholds, time intervals, value sets)
for a compact summary at the top of the prompt.

### Prompt fix

Old prompt (too strict — generic OpenEMR import check):
```
"Check if this CQL library is valid for import into OpenEMR..."
```

New prompt — matches reference `build_gemini_prompt()` WITHOUT CPG exactly:
```php
return "Analyze this clinical logic implementation.\n\n"
     . $keyValues . "\n\n"
     . "## Full Logic Summary\n\n"
     . $fullLogic . "\n\n"
     . "Are the values clinically reasonable?\n\n"
     . "VALID: YES or NO\n"
     . "ERRORS: None, or list issues";
```

Both the key-values summary AND the full parsed logic are now sent to Groq.


===========================================================================
## COMMIT 8 — DB import fix: correct table/column mapping (cds_proxy.php)
===========================================================================

**File:** `templates/super/rules/controllers/browse/cds_proxy.php`

### Problem discovered

After importing a rule, clicking it in the OpenEMR CDR admin list opened a
blank detail view — no title, no action, no reminder message. Separately,
the table `rule_label` was confirmed to not exist in this OpenEMR install.

### Root causes (4 bugs in `importElmToOpenEmr()`)

**Bug 1 — Missing `list_options` INSERT (rule has no display title)**

OpenEMR reads CDR rule display titles from:
```sql
SELECT title FROM list_options
WHERE list_id = 'clinical_rules' AND option_id = '<rule_id>'
```
This is managed through Admin → Forms → Lists → clinical_rules in the UI.
Without this row the rule appears with a blank title. The detail view never
opens properly because OpenEMR cannot resolve the rule's label.

Confirmed by querying:
```sql
SELECT list_id, option_id, title FROM list_options
WHERE list_id = 'clinical_rules' LIMIT 3;
-- Returns rows like: cpoe_med_amc | Use CPOE for medication orders...
```

Also confirmed `list_options` has `activity` column (default was 0):
```sql
SELECT * FROM list_options WHERE list_id='clinical_rules' LIMIT 1\G
-- activity: 1  ← must be 1 for the rule to be visible
-- edit_options: 1  ← must be 1 for the admin list UI to allow editing
```

**Bug 2 — `rule_action` used non-existent category `reminder_clin`**

The original INSERT:
```sql
INSERT INTO rule_action (id, category, item)
VALUES (?, 'reminder_clin', 'clin_reminder_a')
```
`reminder_clin` does not exist in `rule_action_item`. Confirmed:
```sql
SELECT * FROM rule_action_item WHERE category = 'reminder_clin';
-- 0 rows
```

Valid categories in this install:
```
act_cat_assess, act_cat_edu, act_cat_exam, act_cat_inter,
act_cat_measure, act_cat_treat
```

**Bug 3 — `rule_action_item` INSERT was missing entirely**

`rule_action_item` is where OpenEMR reads the reminder message shown to
clinicians. The `reminder_message` column in this table is the actual text.
Our code was never inserting here.

**Bug 4 — Reminder message stored in wrong table**

`rule_reminder.value` stores timing (e.g. "1 month before") — NOT the
reminder message text. The message belongs in `rule_action_item.reminder_message`.

### Fix — corrected `importElmToOpenEmr()`

The function now does 5 INSERTs in order:

**INSERT 1 — clinical_rules (unchanged):**
```sql
INSERT INTO clinical_rules
  (id, pid, active_alert_flag, passive_alert_flag, patient_reminder_flag,
   cqm_flag, amc_flag, access_control, release_version)
VALUES (?, 0, 0, 1, 0, 0, 0, 'patients:med', ?)
```

**INSERT 2 — list_options (NEW — was completely missing):**
```sql
INSERT INTO list_options
  (list_id, option_id, title, seq, is_default, activity, edit_options)
VALUES ('clinical_rules', ?, ?, 10, 0, 1, 1)
```
- `option_id` = slugified rule ID
- `title` = `ucwords(str_replace(['-','_'], ' ', $libraryName))`
  e.g. "Prenatal-Trisomy-18-Screening" → "Prenatal Trisomy 18 Screening"
- `activity = 1` — REQUIRED for rule to be visible in OpenEMR UI
- `edit_options = 1` — allows editing via Admin → Forms → Lists

**INSERT 3 — rule_action_item (NEW — was completely missing):**
```sql
INSERT INTO rule_action_item
  (category, item, reminder_message, custom_flag)
VALUES ('act_cat_assess', ?, ?, 1)
```
- `item` = `'cds_' . substr($ruleId, 0, 27)` — unique per rule, max 31 chars
- `reminder_message` = recommendation text extracted from ELM statements
- `custom_flag = 1` — marks it as user-created (not built-in OpenEMR)

**INSERT 4 — rule_action (category + item corrected):**
```sql
INSERT INTO rule_action (id, category, item)
VALUES (?, 'act_cat_assess', ?)   -- was 'reminder_clin'/'clin_reminder_a' (invalid)
```
- Uses same `act_cat_assess` + `cds_<ruleid>` pair as rule_action_item

**INSERT 5 — rule_reminder (timing, best-effort):**
```sql
INSERT INTO rule_reminder (id, method, method_detail, value)
VALUES (?, 'clinical_reminder_pre', 'month', '1')
```
- `value = '1'` (the number, for timing) — NOT the reminder message text
- Wrapped in try/catch — skipped silently if schema differs

### Cleanup SQL for previously broken imports

To remove rules imported with the old broken code (run once):
```sql
DELETE FROM clinical_rules WHERE id IN ('<id1>', '<id2>', ...);
DELETE FROM list_options WHERE list_id='clinical_rules' AND option_id IN (...);
DELETE FROM rule_action WHERE id IN (...);
DELETE FROM rule_action_item WHERE custom_flag=1 AND item LIKE 'cds_%';
DELETE FROM rule_reminder WHERE id IN (...);
```


===========================================================================
## Reference Python files (elm_simplifier.py / modal_app.py)
===========================================================================

These Python files were NOT deployed — their logic was ported to PHP:

| Prof's Python function      | PHP equivalent in cds_proxy.php  |
|-----------------------------|----------------------------------|
| simplify_elm_for_prompt()   | simplifyElmForPrompt()           |
| build_prompt()              | buildGroqPrompt()                |
| parse_response()            | parseGroqResponse()              |
| extract_embedded_errors()   | extractEmbeddedErrors()          |
| run_batch_groq_validation() | validateWithGroq()               |
| validate_from_service()     | fetchElmJson() + handleImport()  |


===========================================================================
## OpenEMR DB changes (runtime — no new tables, no schema changes)
===========================================================================

| Table            | Operation        | When                                                          |
|------------------|------------------|---------------------------------------------------------------|
| globals          | INSERT or UPDATE | Admin enters Groq key in UI → save_key action                 |
| clinical_rules   | INSERT           | On successful library import                                  |
| list_options     | INSERT           | On successful library import (title + activity=1)             |
| rule_action_item | INSERT           | On successful library import (reminder_message stored here)   |
| rule_action      | INSERT           | On successful library import                                  |
| rule_reminder    | INSERT           | On successful library import (try/catch — skipped if fails)   |

The `globals` table write uses INSERT-or-UPDATE pattern:
- First save: `INSERT INTO globals (gl_name, gl_value) VALUES ('cds_groq_api_key', ?)`
- Key update:  `UPDATE globals SET gl_value = ? WHERE gl_name = 'cds_groq_api_key'`

Tables confirmed to exist in this OpenEMR install (from `SHOW TABLES LIKE '%rule%'`):
```
clinical_plans_rules, clinical_rules, clinical_rules_log,
rule_action, rule_action_item, rule_filter,
rule_patient_data, rule_reminder, rule_target
```
Table `rule_label` does NOT exist in this install.


===========================================================================
## External APIs used
===========================================================================

| API         | Endpoint                              | Called from          | When              |
|-------------|---------------------------------------|----------------------|-------------------|
| CDS Connect | GET /cds-services/                    | handleValidate()     | Page load / Refresh |
| CDS Connect | GET /api/library/{name}/version/1.0.0 | fetchElmJson()       | On import click   |
| CDS Connect | GET /api/library/{name}               | fetchElmJson()       | Fallback if above fails |
| CDS Connect | GET /library/{name}                   | fetchElmJson()       | Fallback #2       |
| Groq        | POST /openai/v1/chat/completions      | validateWithGroq()   | On import click   |

Groq request config:
- Model: `openai/gpt-oss-120b`
- Temperature: `0.1`
- Max tokens: `500`
- Timeout: `60` seconds


===========================================================================
## Errors encountered and fixed during development
===========================================================================

| Error | Cause | Fix |
|-------|-------|-----|
| "Cannot find globals.php" | 5-level relative path miscounted, globals.php is in `interface/` not root | Ran `find /var/www -name "globals.php"` in container, corrected path |
| "CQL Service Unavailable — Internal Server Error" | globals.php not loading → PHP fatal error | Fixed path (above) |
| Wrong CQL API format | Coded for web23 service format; CDS Connect returns `{services:[]}` | Updated `handleValidate()` to parse CDS Connect format |
| `docker cp` copying 0 bytes | Script ran without `sudo`, permission denied on docker socket | Added `sudo` to all docker commands in deploy.sh |
| "Imported" without Groq validation | `validateWithGroq()` returned `valid=true` when no key was set | Removed fallback; `handleImport()` now checks key first and hard-fails |
| "Update Key" link did nothing | Event bound before element existed; duplicate bindings stacked | Single delegated `$(document).on(...)` outside `checkKeyStatus()` |
| MySQL SSL error from openemr container | `mysql -u root` fails with SSL error in openemr container | Connect from MySQL container: `docker exec oemr-mysql-1 mysql -u openemr -popenemr openemr` |
| BusyBox grep no `--include` flag | Container uses BusyBox grep, not GNU grep | Use `find ... \| xargs grep` instead |
| Groq rejected almost all libraries | ELM simplifier only extracted age/value sets; full clinical logic never sent | Complete rewrite: `parseExpression()` + `simplifyElm()` + `keyValuesSection()` (see COMMIT 7) |
| Imported rule shows blank detail view | `list_options` INSERT was missing entirely — OpenEMR reads titles from there | Added INSERT into `list_options` with `activity=1`, `edit_options=1` (see COMMIT 8) |
| Rule action category not found | `rule_action` used `reminder_clin`/`clin_reminder_a` — neither exists in `rule_action_item` | Changed to `act_cat_assess` + unique `cds_<ruleid>` item (see COMMIT 8) |
| Reminder message not showing | Message was stored in `rule_reminder.value` (timing column) not `rule_action_item.reminder_message` | Moved message to `rule_action_item.reminder_message`; `rule_reminder.value` now stores timing `'1'` |
| `rule_label` table does not exist | `SELECT * FROM rule_label` → ERROR 1146 — table absent in this OpenEMR version | Dropped; label is in `list_options` instead |
