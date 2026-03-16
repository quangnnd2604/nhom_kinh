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
                                    <option value="<?php echo esc_attr( $lang->code ); ?>"
                                        data-name="<?php echo esc_attr( $lang->name ); ?>"
                                        data-ai="<?php echo esc_attr( $lang->ai_name ?? $lang->name ); ?>">
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
        <!-- Pre-Sync Confirmation Modal                                     -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div id="mml-sync-confirm-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:999990; align-items:center; justify-content:center;">
            <div id="mml-sync-confirm-modal" style="background:#fff; border-radius:6px; padding:32px 36px; max-width:520px; width:90%; box-shadow:0 8px 32px rgba(0,0,0,0.25); position:relative;">
                <button type="button" id="mml-confirm-close" style="position:absolute;top:12px;right:14px;background:none;border:none;font-size:20px;cursor:pointer;color:#666;">&times;</button>
                <h2 style="margin-top:0; color:#1d2327;">🚀 Xác nhận Magic Sync</h2>
                <p id="mml-confirm-lang-line" style="font-size:15px; border-left:4px solid #2271b1; padding:10px 12px; background:#f0f6fc; border-radius:0 4px 4px 0; margin-bottom:16px;"></p>
                <p id="mml-confirm-example-line" style="color:#555; font-size:13px; margin-bottom:24px;"></p>
                <div style="background:#fff8e5; border:1px solid #f0b849; border-radius:4px; padding:10px 14px; margin-bottom:24px; font-size:12px; color:#50575e;">
                    ⚠️ Thao tác này sẽ <strong>clone và dịch hàng loạt</strong> toàn bộ bài viết, trang, sản phẩm và danh mục chưa có bản dịch.
                    Không thể hoàn tác tự động — hãy chắc chắn trước khi bắt đầu.
                </div>
                <div style="display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" id="mml-confirm-cancel" class="button">Hủy bỏ</button>
                    <button type="button" id="mml-confirm-go" class="button button-primary" style="background:#2271b1; border-color:#2271b1;">✔ Xác nhận &amp; Bắt đầu</button>
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

            <hr style="margin:24px 0; border-color:#f5c6c6;">

            <h3 style="color:#a00; margin-top:0;">🔧 Reset Translation Links</h3>
            <p>Use this <strong>only</strong> if your translation data is corrupted — for example, if Magic Sync reports "Everything is already translated" for languages you have never cloned.<br>
            This empties the <code>wp_my_translations</code> table so you can re-run Magic Sync from scratch. <em>Your original Vietnamese posts/products are NOT deleted.</em>
            Previously created clone posts/pages/products will become orphaned and should be deleted manually, or re-purged per language first.</p>
            <p>
                <button type="button" id="mml-reset-links-btn" class="button" style="background:#7f0000; border-color:#7f0000; color:#fff; font-weight:bold;">
                    ☠️ Reset All Translation Links
                </button>
                <span class="spinner" id="mml-reset-links-spinner" style="float:none; margin-top:10px;"></span>
            </p>
            <div id="mml-reset-links-result" style="display:none; margin-top:12px; padding:12px; border-radius:4px;"></div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- Nút Xóa Toàn Bộ Clone – Tất Cả Ngôn Ngữ                              -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="mml-card" style="background:#1e0f0f; border:2px solid #8b0000; padding:24px 28px; max-width:800px; margin-top:16px;">
            <h2 style="color:#ff6b6b; margin-top:0; font-size:18px;">🚨 Xóa Toàn Bộ Clone – Tất Cả Ngôn Ngữ</h2>
            <p style="color:#ffcccc; margin-bottom:8px;">
                Nút này sẽ <strong style="color:#fff;">xóa vĩnh viễn</strong> toàn bộ bài viết, trang, sản phẩm, danh mục và menu
                đã được tạo bởi Magic Sync cho <strong style="color:#fff;">tất cả ngôn ngữ không mặc định</strong>.
                Không cần chọn ngôn ngữ — mọi clone sẽ bị xóa cùng lúc.
            </p>
            <p style="color:#ff9999; font-size:12px; margin-bottom:20px;"><em>⚠️ Hành động này không thể hoàn tác.</em></p>
            <p style="margin:0;">
                <button type="button" id="mml-purge-all-btn" class="button"
                    style="background:#a00000; border-color:#6a0000; color:#fff; font-weight:bold; font-size:14px; padding:10px 26px; height:auto; line-height:1.4;">
                    🚨 Xóa Toàn Bộ Clone (Tất Cả Ngôn Ngữ)
                </button>
                <span class="spinner" id="mml-purge-all-spinner" style="float:none; margin-top:10px;"></span>
            </p>
            <div id="mml-purge-all-result" style="display:none; margin-top:16px; padding:12px; border-radius:4px;"></div>
        </div>

        <!-- Purge-All Confirmation Modal -->
        <div id="mml-purge-all-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:999992; align-items:center; justify-content:center;">
            <div style="background:#1e0f0f; border:2px solid #8b0000; border-radius:6px; padding:32px 36px; max-width:540px; width:90%; box-shadow:0 8px 40px rgba(0,0,0,0.6); position:relative;">
                <button type="button" id="mml-purge-all-close" style="position:absolute;top:12px;right:14px;background:none;border:none;font-size:22px;cursor:pointer;color:#ff9999;">&times;</button>
                <h2 style="margin-top:0; color:#ff6b6b;">🚨 Xác nhận Xóa Toàn Bộ Clone</h2>
                <p style="color:#ffdddd; font-size:14px; border-left:4px solid #a00000; padding:10px 12px; background:rgba(160,0,0,0.25); border-radius:0 4px 4px 0; margin-bottom:16px;">
                    Các ngôn ngữ sẽ bị purge: <strong id="mml-purge-all-lang-list" style="color:#fff;"></strong>
                </p>
                <div style="background:rgba(160,0,0,0.2); border:1px solid #8b0000; border-radius:4px; padding:10px 14px; margin-bottom:24px; font-size:13px; color:#ffcccc;">
                    ⚠️ Hành động này sẽ xóa <strong>’toàn bộ</strong> bài viết, trang, sản phẩm, danh mục và menu đã clone sang tất cả ngôn ngữ không mặc định. Không thể hoàn tác.
                </div>
                <div style="display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" id="mml-purge-all-cancel" class="button" style="color:#ccc;">Hủy bỏ</button>
                    <button type="button" id="mml-purge-all-go" class="button"
                        style="background:#a00000; border-color:#6a0000; color:#fff; font-weight:bold;">
                        🚨 Xác nhận Xóa Toàn Bộ
                    </button>
                </div>
            </div>
        </div>

        <script>
        var mmlSyncI18n = <?php echo wp_json_encode( [
            'discoveryFailed'      => __( 'Discovery failed.', 'my-multilang' ),
            'pleaseSelectLang'     => __( 'Please select a target language first.', 'my-multilang' ),
            'pleaseSelectPurgeLang'=> __( 'Please select a language to purge first.', 'my-multilang' ),
        ], JSON_UNESCAPED_UNICODE ); ?>;
        var mmlAllLangNames = <?php
            $non_default_langs = array_filter( $languages, fn( $l ) => $l->code !== $default_code );
            echo wp_json_encode(
                array_map( fn( $l ) => $l->name . ' (' . strtoupper( $l->code ) . ')', array_values( $non_default_langs ) ),
                JSON_UNESCAPED_UNICODE
            );
        ?>;
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

            // ── Build a registry example map from language data attributes ─
            var exampleMap = {};
            <?php
            if ( function_exists( 'mml_language_registry_by_code' ) ) {
                foreach ( mml_language_registry_by_code() as $code => $entry ) {
                    printf(
                        "exampleMap[%s] = %s;\n            ",
                        wp_json_encode( $code ),
                        wp_json_encode( $entry['example'] )
                    );
                }
            }
            ?>

            // ── Confirmation modal helpers ─────────────────────────────────
            var $overlay = $('#mml-sync-confirm-overlay');

            function showSyncModal( code, name, aiName ) {
                var example = exampleMap[code] || '';
                var aiLabel = aiName || name;
                $('#mml-confirm-lang-line').html(
                    'Ngôn ngữ đích được chọn: <strong>' + name + ' (' + code.toUpperCase() + ')</strong>'
                );
                $('#mml-confirm-example-line').html(
                    example
                        ? 'Ví dụ bản dịch: <em>"Xin chào"</em> &nbsp;⟶&nbsp; <strong>"' + example + '"</strong> &nbsp;<span style="color:#888;font-size:11px;">(' + aiLabel + ')</span>'
                        : ''
                );
                $overlay.css('display', 'flex');
            }

            function hideSyncModal() {
                $overlay.hide();
            }

            $('#mml-confirm-close, #mml-confirm-cancel').on('click', hideSyncModal);
            $overlay.on('click', function(e) {
                if (e.target === this) hideSyncModal();
            });

            // Start Sync Button — opens the modal instead of browser confirm
            $('#mml-start-sync-btn').on('click', function() {
                targetLang = $('#mml_target_lang').val();
                if (!targetLang) {
                    alert(mmlSyncI18n.pleaseSelectLang);
                    return;
                }
                var $opt    = $('#mml_target_lang option:selected');
                var name    = $opt.data('name')   || targetLang;
                var aiName  = $opt.data('ai')     || name;
                showSyncModal(targetLang, name, aiName);
            });

            // Confirmed — actually start the sync
            $('#mml-confirm-go').on('click', function() {
                hideSyncModal();

                $('#mml-start-sync-btn').prop('disabled', true);
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
                            $('#mml-sync-status').text(mmlSyncI18n.discoveryFailed).css('color', 'red');
                        }
                    },
                    error: function() {
                        logMsg('Network error during discovery phase.', true);
                        $('#mml-sync-spinner').removeClass('is-active');
                        $('#mml-start-sync-btn').prop('disabled', false);
                        $('#mml-sync-status').text(mmlSyncI18n.discoveryFailed).css('color', 'red');
                    }
                });
            });
            // ── Purge All Button ─────────────────────────────────────────────
            var $purgeAllOverlay = $('#mml-purge-all-overlay');

            $('#mml-purge-all-btn').on('click', function() {
                var listText = mmlAllLangNames.length > 0
                    ? mmlAllLangNames.join(', ')
                    : '(không có ngôn ngữ nào)';
                $('#mml-purge-all-lang-list').text(listText);
                $purgeAllOverlay.css('display', 'flex');
            });

            $('#mml-purge-all-close, #mml-purge-all-cancel').on('click', function() {
                $purgeAllOverlay.hide();
            });
            $purgeAllOverlay.on('click', function(e) {
                if (e.target === this) $purgeAllOverlay.hide();
            });

            $('#mml-purge-all-go').on('click', function() {
                $purgeAllOverlay.hide();

                const $btn    = $('#mml-purge-all-btn');
                const $result = $('#mml-purge-all-result');

                $btn.prop('disabled', true);
                $('#mml-purge-all-spinner').addClass('is-active');
                $result.hide();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'mml_purge_all_clones',
                        nonce: '<?php echo esc_js( wp_create_nonce( 'mml_admin_nonce' ) ); ?>'
                    },
                    success: function(res) {
                        if (res.success) {
                            $result
                                .css({ background: '#edfaef', border: '1px solid #00a32a', color: '#1d2327' })
                                .html('<strong>✓ ' + res.data.message + '</strong>')
                                .show();
                        } else {
                            $result
                                .css({ background: '#fef1f1', border: '1px solid #d63638', color: '#d63638' })
                                .html('<strong>✗ Lỗi:</strong> ' + res.data)
                                .show();
                        }
                    },
                    error: function() {
                        $result
                            .css({ background: '#fef1f1', border: '1px solid #d63638', color: '#d63638' })
                            .html('<strong>✗ Lỗi mạng.</strong> Vui lòng thử lại.')
                            .show();
                    },
                    complete: function() {
                        $btn.prop('disabled', false);
                        $('#mml-purge-all-spinner').removeClass('is-active');
                    }
                });
            });

            // ── Purge Button (per language) ───────────────────────────────
            $('#mml-purge-btn').on('click', function() {
                const purgeLang = $('#mml_purge_lang').val();
                if (!purgeLang) {
                    alert(mmlSyncI18n.pleaseSelectPurgeLang);
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

            // ── Reset Translation Links Button ────────────────────────────
            $('#mml-reset-links-btn').on('click', function() {
                if (!confirm('⚠️ WARNING: This will ERASE all translation link records.\n\nYour original Vietnamese content is NOT deleted, but all clone relationships will be lost.\n\nAre you sure?')) return;
                if (!confirm('Final confirmation: RESET all translation links?')) return;

                const $btn    = $(this);
                const $result = $('#mml-reset-links-result');

                $btn.prop('disabled', true);
                $('#mml-reset-links-spinner').addClass('is-active');
                $result.hide();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'mml_reset_translation_links',
                        nonce: '<?php echo esc_js( wp_create_nonce( 'mml_admin_nonce' ) ); ?>'
                    },
                    success: function(res) {
                        if (res.success) {
                            $result
                                .css({ background: '#edfaef', border: '1px solid #00a32a', color: '#00a32a' })
                                .html('<strong>✓ ' + res.data + '</strong>')
                                .show();
                        } else {
                            $result
                                .css({ background: '#fef1f1', border: '1px solid #d63638', color: '#d63638' })
                                .html('<strong>✗ Error:</strong> ' + res.data)
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
                        $('#mml-reset-links-spinner').removeClass('is-active');
                    }
                });
            });
        });
        </script>
        <?php
    }
}
