<?php

/**
 * templates/super/rules/controllers/browse/list.php
 *
 * Enhanced with CDS Library import from CQL Service with Groq LLM validation.
 * Workflow:
 *   1. Page loads → auto-fetches available CQL libraries alongside existing OpenEMR rules
 *   2. User clicks "Validate & Import" → proxy fetches ELM JSON from CQL service
 *   3. ELM is simplified (elm_simplifier logic) and sent to Groq openai/gpt-oss-120b
 *   4. If VALID → imported into clinical_rules/rule_action tables using field_mapping
 *   5. If INVALID → Groq's error is shown to the user
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 */

use OpenEMR\ClinicalDecisionRules\Interface\Common;

?>

<script src="<?php Common::js_src('list.js') ?>"></script>
<script src="<?php Common::js_src('jQuery.fn.sortElements.js') ?>"></script>

<script>
    var list = new list_rules();
    list.init();
</script>

<script>
$(document).ready(function () {

    // Absolute URL to the proxy — uses OpenEMR webroot so it works at any install path
    var CDS_PROXY = <?php echo json_encode($GLOBALS['webroot'] . '/templates/super/rules/controllers/browse/cds_proxy.php'); ?>;

    // Safe HTML-escape helper (XSS prevention when building table rows)
    function esc(str) {
        return $('<div/>').text(String(str || '')).html();
    }

    // ----------------------------------------------------------------
    // Groq API Key — check status on load, allow saving from the UI
    // Key is stored in OpenEMR globals table (gl_name = cds_groq_api_key)
    // ----------------------------------------------------------------
    checkKeyStatus();

    $('#cds-save-key-btn').on('click', function () {
        saveGroqKey();
    });

    // Single delegated binding — works even after bar HTML is re-rendered
    $(document).on('click', '#cds-toggle-key-link', function (e) {
        e.preventDefault();
        $('#cds-key-form').toggle();
        if ($('#cds-key-form').is(':visible')) {
            $('#cds-groq-key-input').focus();
        }
    });

    function checkKeyStatus() {
        $.ajax({
            url:      CDS_PROXY + '?action=key_status',
            method:   'GET',
            dataType: 'json',
            success: function (data) {
                var $bar = $('#cds-key-status-bar');
                if (data.configured) {
                    $bar.removeClass('alert-warning alert-info').addClass('alert-success')
                        .html('<strong><?php echo xlt('Groq API Key'); ?>: <?php echo xlt('Configured'); ?> \u2713</strong>' +
                              ' &nbsp;<a href="#" id="cds-toggle-key-link" class="small"><?php echo xlt('Update Key'); ?></a>');
                    $('#cds-key-form').hide();
                } else {
                    $bar.removeClass('alert-success alert-info').addClass('alert-warning')
                        .html('<strong>\u26a0 <?php echo xlt('Groq API Key not set'); ?></strong>' +
                              ' &mdash; <?php echo xlt('Enter your Groq API key below to enable AI validation.'); ?>');
                    $('#cds-key-form').show();
                    $('#cds-groq-key-input').focus();
                }
            }
        });
    }

    function saveGroqKey() {
        var key  = $('#cds-groq-key-input').val().trim();
        var $btn = $('#cds-save-key-btn');

        if (!key) {
            alert('<?php echo xlt('Please enter your Groq API key'); ?>');
            return;
        }

        $btn.prop('disabled', true).text('<?php echo xlt('Saving...'); ?>');

        $.ajax({
            url:         CDS_PROXY + '?action=save_key',
            method:      'POST',
            contentType: 'application/json',
            data:        JSON.stringify({ key: key }),
            dataType:    'json',
            success: function (data) {
                $btn.prop('disabled', false).text('<?php echo xlt('Save Key'); ?>');
                if (data.success) {
                    $('#cds-groq-key-input').val('');
                    $('#cds-key-form').hide();
                    checkKeyStatus();
                } else {
                    alert('<?php echo xlt('Error saving key'); ?>: ' + (data.error || ''));
                }
            },
            error: function (xhr) {
                $btn.prop('disabled', false).text('<?php echo xlt('Save Key'); ?>');
                var msg = '';
                try { msg = JSON.parse(xhr.responseText).error || ''; } catch (e) {}
                alert('<?php echo xlt('Error saving key'); ?>: ' + msg);
            }
        });
    }

    // ----------------------------------------------------------------
    // Auto-load CDS libraries when the CDR rules page opens
    // ----------------------------------------------------------------
    loadCdsLibraries();

    $('#cds-refresh-btn').on('click', function () {
        loadCdsLibraries();
    });

    function loadCdsLibraries() {
        var $container = $('#cds-table-container');
        var $btn       = $('#cds-refresh-btn');

        $btn.prop('disabled', true).text('<?php echo xlt('Loading...'); ?>');
        $container.html(
            '<div class="text-center py-3">' +
            '<span class="spinner-border spinner-border-sm text-info mr-2" role="status"></span>' +
            '<span class="text-muted"><?php echo xlt('Fetching available CDS libraries from CQL service...'); ?></span>' +
            '</div>'
        );

        $.ajax({
            url:      CDS_PROXY + '?action=validate',
            method:   'GET',
            dataType: 'json',
            timeout:  190000,   // CQL /validate can take up to 3 min
            success: function (data) {
                $btn.prop('disabled', false).text('<?php echo xlt('Refresh'); ?>');
                renderTable(data);
            },
            error: function (xhr, status, error) {
                $btn.prop('disabled', false).text('<?php echo xlt('Refresh'); ?>');
                var detail = '';
                try { detail = JSON.parse(xhr.responseText).error || ''; } catch (e) {}
                $container.html(
                    '<div class="alert alert-warning mb-0">' +
                    '<strong><?php echo xlt('CQL Service Unavailable'); ?></strong> &mdash; ' +
                    esc(detail || error) +
                    '</div>'
                );
            }
        });
    }

    // ----------------------------------------------------------------
    // Build the available-libraries table
    // valid_libraries   → CQL-valid, may or may not already be imported
    // invalid_libraries → CQL-invalid, cannot be imported
    // The proxy augments each entry with imported: true/false
    // ----------------------------------------------------------------
    function renderTable(data) {
        var $container = $('#cds-table-container');
        var allLibs    = [];

        (data.valid_libraries   || []).forEach(function (lib) {
            allLibs.push($.extend({}, lib, { cql_valid: true  }));
        });
        (data.invalid_libraries || []).forEach(function (lib) {
            allLibs.push($.extend({}, lib, { cql_valid: false }));
        });

        if (allLibs.length === 0) {
            $container.html(
                '<div class="alert alert-info mb-0">' +
                '<?php echo xlt('No CDS libraries found in CQL service.'); ?>' +
                '</div>'
            );
            return;
        }

        var html  = '<table class="table table-sm table-bordered table-hover mb-0">';
        html += '<thead class="thead-light"><tr>';
        html += '<th><?php echo xlt('Library Name'); ?></th>';
        html += '<th><?php echo xlt('Version'); ?></th>';
        html += '<th><?php echo xlt('CQL Status'); ?></th>';
        html += '<th><?php echo xlt('Import Status'); ?></th>';
        html += '<th><?php echo xlt('Action'); ?></th>';
        html += '</tr></thead><tbody>';

        allLibs.forEach(function (lib) {
            var safeName = esc(lib.name);
            var rowId    = 'cds-row-' + String(lib.name || '').replace(/[^a-zA-Z0-9]/g, '_');

            var cqlBadge = lib.cql_valid
                ? '<span class="badge badge-success"><?php echo xlt('Valid'); ?></span>'
                : '<span class="badge badge-danger"><?php echo xlt('Invalid'); ?></span>';

            var importBadge = lib.imported
                ? '<span class="badge badge-info"     id="status-' + rowId + '"><?php echo xlt('Imported'); ?></span>'
                : '<span class="badge badge-secondary" id="status-' + rowId + '"><?php echo xlt('Not Imported'); ?></span>';

            var actionBtn;
            if (!lib.cql_valid) {
                actionBtn = '<button class="btn btn-sm btn-secondary" disabled>' +
                            '<?php echo xlt('Cannot Import'); ?></button>';
            } else if (lib.imported) {
                actionBtn = '<button class="btn btn-sm btn-success" disabled id="btn-' + rowId + '">' +
                            '<?php echo xlt('Already Imported'); ?></button>';
            } else {
                actionBtn = '<button class="btn btn-sm btn-primary import-btn"' +
                            ' data-lib="' + esc(lib.name) + '"' +
                            ' id="btn-' + rowId + '">' +
                            '<?php echo xlt('Validate & Import'); ?></button>';
            }

            html += '<tr id="' + rowId + '">';
            html += '<td><strong>' + safeName + '</strong></td>';
            html += '<td>' + esc(lib.version || 'N/A') + '</td>';
            html += '<td>' + cqlBadge + '</td>';
            html += '<td>' + importBadge + '</td>';
            html += '<td>' + actionBtn + '</td>';
            html += '</tr>';
        });

        html += '</tbody></table>';
        $container.html(html);

        // Bind import buttons after rendering
        $container.on('click', '.import-btn', function () {
            importLibrary($(this).data('lib'), $(this));
        });
    }

    // ----------------------------------------------------------------
    // Import a library:
    //   proxy fetches ELM → simplifies → Groq validates → DB import
    // ----------------------------------------------------------------
    function importLibrary(libName, $btn) {
        var rowId = 'cds-row-' + String(libName).replace(/[^a-zA-Z0-9]/g, '_');

        $btn.prop('disabled', true).html(
            '<span class="spinner-border spinner-border-sm mr-1" role="status"></span>' +
            '<?php echo xlt('Validating with AI...'); ?>'
        );

        $.ajax({
            url:      CDS_PROXY + '?action=import&name=' + encodeURIComponent(libName),
            method:   'POST',
            dataType: 'json',
            timeout:  120000,
            success: function (data) {
                if (data.success) {
                    $btn.removeClass('btn-primary').addClass('btn-success')
                        .prop('disabled', true)
                        .text('<?php echo xlt('Imported'); ?> \u2713');
                    $('#status-' + rowId)
                        .removeClass('badge-secondary').addClass('badge-info')
                        .text('<?php echo xlt('Imported'); ?>');
                } else {
                    $btn.prop('disabled', false).text('<?php echo xlt('Validate & Import'); ?>');
                    showError(libName, data.validation_error || data.error);
                }
            },
            error: function (xhr, status, error) {
                $btn.prop('disabled', false).text('<?php echo xlt('Validate & Import'); ?>');
                var msg = error;
                try {
                    var r = JSON.parse(xhr.responseText);
                    if (r.validation_error) msg = '<?php echo xlt('Validation failed'); ?>:\n' + r.validation_error;
                    else if (r.error)       msg = r.error;
                } catch (e) {}
                showError(libName, msg);
            }
        });
    }

    function showError(libName, reason) {
        alert('<?php echo xlt('Import Error'); ?> \u2014 "' + libName + '":\n\n' +
              (reason || '<?php echo xlt('Unknown error'); ?>'));
    }

});
</script>

<hr />
<div class="header">
    <div>
        <header class="title"><?php echo xlt('Plans Configuration'); ?>
            <span>
            <a href="index.php?action=browse!plans_config" class="iframe_medium btn btn-primary">
                <span><?php echo xlt('Go'); ?></span>
            </a>
            </span>
        </header>
    </div>
    <hr/>
    <div class="">
        <header class="title"><?php echo xlt('Rules Configuration'); ?>
            <span>
            <a href="index.php?action=edit!summary" class="iframe_medium btn btn-primary" onclick="top.restoreSession()">
                <span><?php echo xlt('Add new{{Rule}}'); ?></span>
            </a>
            </span>
        </header>
    </div>
</div>

<!-- Existing OpenEMR rules list (populated by list.js) -->
<div class="rule_container">
    <div class="rule_row header">
        <div class="rule_type header_type w-25"><?php echo xlt('Type'); ?></div>
        <div class="rule_title header_title"><?php echo xlt('Name'); ?></div>
    </div>
</div>

<!-- JS template row used by list.js to render each rule -->
<div class="rule_row data template">
    <div class="rule_type w-25"><a href="index.php?action=detail!view" onclick="top.restoreSession()"></a></div>
    <div class="rule_title"><a href="index.php?action=detail!view" onclick="top.restoreSession()"></a></div>
</div>

<hr class="mt-4"/>

<!-- ================================================================
     CDS Libraries Section
     Shows what is available in the CQL service and can be imported
     ================================================================ -->
<div class="mt-3 mb-4">
    <div class="header">
        <header class="title">
            <?php echo xlt('Available CDS Libraries (from CQL Service)'); ?>
            <span>
                <button id="cds-refresh-btn" class="btn btn-info btn-sm ml-2" type="button">
                    <?php echo xlt('Refresh'); ?>
                </button>
            </span>
        </header>
    </div>
    <!-- Groq API Key Settings -->
    <div id="cds-key-status-bar" class="alert alert-info py-2 mb-2 small">
        <span class="spinner-border spinner-border-sm mr-1" role="status"></span>
        <?php echo xlt('Checking Groq API key status...'); ?>
    </div>
    <div id="cds-key-form" class="form-inline mb-2" style="display:none">
        <input type="password" id="cds-groq-key-input"
               class="form-control form-control-sm mr-2"
               placeholder="gsk_..."
               style="width:340px"
               autocomplete="new-password">
        <button id="cds-save-key-btn" class="btn btn-sm btn-primary" type="button">
            <?php echo xlt('Save Key'); ?>
        </button>
    </div>

    <div id="cds-table-container" class="mt-2 border rounded p-2 bg-white">
        <div class="text-center py-3 text-muted">
            <span class="spinner-border spinner-border-sm mr-1" role="status"></span>
            <?php echo xlt('Loading CDS libraries...'); ?>
        </div>
    </div>
</div>
