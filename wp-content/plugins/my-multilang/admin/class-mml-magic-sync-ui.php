<?php
/**
 * Admin UI for Magic Sync Tool.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MML_Magic_Sync_UI {

    public static function render_page(): void {
        $languages = MML_Languages::get_all();
        $default_code = MML_Languages::get_default_code();
        ?>
        <div class="wrap mml-magic-sync-wrap">
            <h1><?php esc_html_e( 'Magic Sync (Auto Translate & Clone)', 'my-multilang' ); ?></h1>
            
            <div class="notice notice-warning" style="margin-top:20px;">
                <p><strong>⚠️ User Review Required:</strong> This tool will scan all Posts, Pages, Products, and Terms in the default language that DO NOT have a translation yet.<br>
                It will automatically clone them, hit the <strong>Google Translate Free API</strong> to translate their titles/descriptions, regenerate URL slugs, and finally clone all your Menus.</p>
                <p>Because this is a heavy operation, please do not close this tab until the progress bar reaches 100%.</p>
            </div>

            <div class="mml-card" style="background:#fff; border:1px solid #ccd0d4; padding:20px; max-width: 800px; margin-top:20px;">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="mml_target_lang">Target Language to Sync:</label></th>
                        <td>
                            <select id="mml_target_lang">
                                <option value="">-- Select Language --</option>
                                <?php foreach ( $languages as $lang ) : 
                                    if ( $lang->code === $default_code ) continue;
                                ?>
                                    <option value="<?php echo esc_attr( $lang->code ); ?>">
                                        <?php echo esc_html( $lang->name . ' (' . strtoupper( $lang->code ) . ')' ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Select the language you want to build auto-translations for.</p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="button" id="mml-start-sync-btn" class="button button-primary button-hero">Start Magic Sync</button>
                    <span class="spinner" id="mml-sync-spinner" style="float:none; margin-top:10px;"></span>
                </p>

                <!-- Progress Dashboard -->
                <div id="mml-sync-dashboard" style="display:none; margin-top: 30px; border-top:1px solid #eee; padding-top:20px;">
                    <h3>Sync Progress</h3>
                    
                    <div style="background:#e5e5e5; height:24px; border-radius:12px; overflow:hidden; margin-bottom:15px;">
                        <div id="mml-progress-bar" style="background:#2271b1; width:0%; height:100%; transition:width 0.3s;"></div>
                    </div>
                    
                    <p style="font-weight:bold;">
                        Status: <span id="mml-sync-status">Discovering items...</span> 
                        <span id="mml-sync-counter" style="float:right;">0 / 0</span>
                    </p>

                    <div id="mml-sync-log" style="background:#f1f1f1; padding:10px; height:200px; overflow-y:auto; font-family:monospace; font-size:12px; border:1px solid #ccc;">
                    </div>
                </div>

            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- Danger Zone: Delete All Clones                                  -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="mml-card" style="background:#fff7f7; border:1px solid #d63638; padding:20px; max-width:800px; margin-top:24px;">
            <h2 style="color:#d63638; margin-top:0;">⚠️ Danger Zone – Delete All Clones</h2>
            <p>This will <strong>permanently delete</strong> all posts, pages, products, terms (categories/tags) and menus that were created by Magic Sync for the selected language. Translation links will also be removed.<br>
            <em>This action cannot be undone.</em></p>

            <table class="form-table" style="margin-bottom:0;">
                <tr>
                    <th scope="row"><label for="mml_purge_lang">Language to purge:</label></th>
                    <td>
                        <select id="mml_purge_lang">
                            <option value="">-- Select Language --</option>
                            <?php foreach ( $languages as $lang ) :
                                if ( $lang->code === $default_code ) continue;
                            ?>
                                <option value="<?php echo esc_attr( $lang->code ); ?>">
                                    <?php echo esc_html( $lang->name . ' (' . strtoupper( $lang->code ) . ')' ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>

            <p style="margin-top:16px;">
                <button type="button" id="mml-purge-btn" class="button" style="background:#d63638; border-color:#d63638; color:#fff; font-weight:bold;">🗑️ Delete All Clones for Selected Language</button>
                <span class="spinner" id="mml-purge-spinner" style="float:none; margin-top:10px;"></span>
            </p>

            <div id="mml-purge-result" style="display:none; margin-top:12px; padding:12px; border-radius:4px;"></div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            let syncItems = [];
            let currentIndex = 0;
            let targetLang = '';
            
            function logMsg(msg, isError = false) {
                const color = isError ? 'red' : '#333';
                $('#mml-sync-log').prepend(`<div style="color:${color}; border-bottom:1px solid #ddd; padding:4px 0;">> ${msg}</div>`);
            }

            function updateProgress() {
                const total = syncItems.length;
                if (total === 0) return;
                
                const percent = Math.round((currentIndex / total) * 100);
                $('#mml-progress-bar').css('width', percent + '%');
                $('#mml-sync-counter').text(`${currentIndex} / ${total}`);
            }

            function processNextItem() {
                if (currentIndex >= syncItems.length) {
                    $('#mml-sync-status').text('Items finished. Syncing Menus now...');
                    syncMenus();
                    return;
                }

                const item = syncItems[currentIndex];
                $('#mml-sync-status').text(`Translating ${item.type} ID: ${item.id}...`);

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'mml_magic_sync_execute_item',
                        nonce: '<?php echo esc_js( wp_create_nonce( 'mml_admin_nonce' ) ); ?>',
                        item_type: item.type,
                        item_id: item.id,
                        item_tax: item.tax || '',
                        target_lang: targetLang
                    },
                    success: function(res) {
                        if (res.success) {
                            logMsg(res.data);
                        } else {
                            logMsg(`Error on ${item.type} #${item.id}: ${res.data}`, true);
                        }
                    },
                    error: function() {
                        logMsg(`Network error on ${item.type} #${item.id}. Skipped.`, true);
                    },
                    complete: function() {
                        currentIndex++;
                        updateProgress();
                        // Recursive call after brief pause to avoid 429 Too Many Requests
                        setTimeout(processNextItem, 500);
                    }
                });
            }

            function syncMenus() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'mml_magic_sync_menus',
                        nonce: '<?php echo esc_js( wp_create_nonce( 'mml_admin_nonce' ) ); ?>',
                        target_lang: targetLang
                    },
                    success: function(res) {
                        if (res.success) {
                            logMsg(res.data);
                            $('#mml-sync-status').text('✨ MAGIC SYNC COMPLETE ✨').css('color', 'green');
                        } else {
                            logMsg(`Menu Sync Error: ${res.data}`, true);
                            $('#mml-sync-status').text('Completed with menu errors.').css('color', 'red');
                        }
                        $('#mml-sync-spinner').removeClass('is-active');
                        $('#mml-start-sync-btn').prop('disabled', false);
                    },
                    error: function() {
                        logMsg('Menu sync failed due to network error.', true);
                        $('#mml-sync-spinner').removeClass('is-active');
                        $('#mml-start-sync-btn').prop('disabled', false);
                    }
                });
            }

            // Start Sync Button
            $('#mml-start-sync-btn').on('click', function() {
                targetLang = $('#mml_target_lang').val();
                if (!targetLang) {
                    alert('Please select a target language first.');
                    return;
                }

                const confirmSync = confirm(`Are you sure you want to run Magic Sync for [ ${targetLang} ]? This will start bulk cloning and auto-translating via Google.`);
                if (!confirmSync) return;

                $(this).prop('disabled', true);
                $('#mml-sync-spinner').addClass('is-active');
                $('#mml-sync-dashboard').slideDown();
                
                $('#mml-sync-log').empty();
                $('#mml-progress-bar').css('width', '0%');
                $('#mml-sync-counter').text('0 / 0');
                currentIndex = 0;
                syncItems = [];

                logMsg(`Discovering missing translations for [${targetLang}]...`);
                $('#mml-sync-status').text('Querying database...').css('color', 'inherit');

                // Step 1: Discover
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'mml_magic_sync_discover',
                        nonce: '<?php echo esc_js( wp_create_nonce( 'mml_admin_nonce' ) ); ?>',
                        target_lang: targetLang
                    },
                    success: function(res) {
                        if (res.success) {
                            syncItems = res.data.items;
                            logMsg(`Found ${syncItems.length} items to translate.`);
                            
                            if (syncItems.length > 0) {
                                processNextItem(); // Start Step 2 LOOP
                            } else {
                                logMsg('Everything is already translated! Moving to sync menus...');
                                syncMenus(); // Skip directly to menu sync
                            }
                        } else {
                            logMsg(`Discovery Error: ${res.data}`, true);
                            $('#mml-sync-spinner').removeClass('is-active');
                            $('#mml-start-sync-btn').prop('disabled', false);
                            $('#mml-sync-status').text('Discovery Failed.').css('color', 'red');
                        }
                    },
                    error: function() {
                        logMsg('Network error during discovery phase.', true);
                        $('#mml-sync-spinner').removeClass('is-active');
                        $('#mml-start-sync-btn').prop('disabled', false);
                        $('#mml-sync-status').text('Discovery Failed.').css('color', 'red');
                    }
                });
            });
            // ── Purge Button ─────────────────────────────────────────────
            $('#mml-purge-btn').on('click', function() {
                const purgeLang = $('#mml_purge_lang').val();
                if (!purgeLang) {
                    alert('Please select a language to purge first.');
                    return;
                }

                const confirmMsg = `⚠️ WARNING: This will PERMANENTLY DELETE all cloned content for language [${purgeLang.toUpperCase()}].\n\nThis includes:\n• All translated posts, pages, products\n• All translated categories and tags\n• All cloned navigation menus\n\nThis CANNOT be undone. Are you sure?`;
                if (!confirm(confirmMsg)) return;

                // Second confirmation for extra safety
                if (!confirm(`Final confirmation: permanently delete ALL [${purgeLang.toUpperCase()}] clones?`)) return;

                const $btn    = $(this);
                const $result = $('#mml-purge-result');

                $btn.prop('disabled', true);
                $('#mml-purge-spinner').addClass('is-active');
                $result.hide();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'mml_magic_sync_purge',
                        nonce: '<?php echo esc_js( wp_create_nonce( 'mml_admin_nonce' ) ); ?>',
                        target_lang: purgeLang
                    },
                    success: function(res) {
                        if (res.success) {
                            $result
                                .css({ background: '#edfaef', border: '1px solid #00a32a', color: '#00a32a' })
                                .html(`<strong>✓ ${res.data.message}</strong>`)
                                .show();
                        } else {
                            $result
                                .css({ background: '#fef1f1', border: '1px solid #d63638', color: '#d63638' })
                                .html(`<strong>✗ Error:</strong> ${res.data}`)
                                .show();
                        }
                    },
                    error: function() {
                        $result
                            .css({ background: '#fef1f1', border: '1px solid #d63638', color: '#d63638' })
                            .html('<strong>✗ Network error.</strong> Please try again.')
                            .show();
                    },
                    complete: function() {
                        $btn.prop('disabled', false);
                        $('#mml-purge-spinner').removeClass('is-active');
                    }
                });
            });
        });
        </script>
        <?php
    }
}
