<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap mml-wrapper mml-scanner-wrap">
    <h1 class="wp-heading-inline">🔍 Smart Scan &amp; Refactor</h1>
    <hr class="wp-header-end">

    <!-- ── Phase A: Scan Controls ────────────────────────────────────────── -->
    <div class="mml-scan-panel mml-card">
        <h2>Phase A — Scan Plugin &amp; System Strings</h2>
        <p class="description">
            Scans <code>wp_options</code> for hardcoded Vietnamese text in widget settings,
            WooCommerce labels (<code>woocommerce_*</code>), theme customizer values (<code>theme_mods_*</code>)
            and sidebar/widget configurations (<code>sidebars_widgets</code>).
            <strong>No database content is modified during the scan.</strong>
        </p>

        <div class="mml-scan-target-options">
            <label class="mml-scan-target-label">
                <strong>Scan Target:</strong>
            </label>
            <label class="mml-target-check">
                <input type="checkbox" id="mml-target-options" checked disabled>
                wp_options (widgets, WooCommerce, theme settings)
            </label>
            <label class="mml-target-check">
                <input type="checkbox" id="mml-target-ux-blocks">
                Include Flatsome <strong>UX Blocks</strong>
                <span class="description" style="font-weight:normal;">
                    — System-wide Header / Footer / Global layout blocks
                </span>
            </label>
            <label class="mml-target-check">
                <input type="checkbox" id="mml-target-gettext">
                Include <strong>WooCommerce Gettext Strings</strong>
                <span class="description" style="font-weight:normal;">
                    — “Sắp xếp theo”, “Tìm kiếm sản phẩm”, sorting labels, pagination
                </span>
            </label>            <label class="mml-target-check">
                <input type="checkbox" id="mml-target-orphaned" checked>
                Scan <strong>Orphaned Shortcodes</strong> trong bài viết
                <span class="description" style="font-weight:normal;">
                    — Tìm <code>[my_trans]</code> có key đã bị xóa khỏi String Translation (chuỗi mồ côi)
                </span>
            </label>        </div>

        <div class="mml-scan-controls" style="margin-top:14px;">
            <button id="mml-start-scan" class="button button-primary button-large">▶ Scan Plugin &amp; System Strings</button>
            <button id="mml-stop-scan" class="button button-large" style="display:none;">⏹ Stop</button>
        </div>

        <div id="mml-scan-progress-wrap" style="display:none; margin-top:16px;">
            <div class="mml-progress-bar-track">
                <div id="mml-scan-bar" class="mml-progress-bar" style="width:0%"></div>
            </div>
            <p id="mml-scan-status" class="description" style="margin-top:6px;">Preparing…</p>
        </div>
    </div>

    <!-- ── Phase B: Review Table ─────────────────────────────────────────── -->
    <div id="mml-results-panel" class="mml-card" style="display:none; margin-top:20px;">
        <h2>Phase B — Review &amp; Approve</h2>
        <p class="description">
            Each row shows a Vietnamese string found in a <code>wp_options</code> key or
            <strong>UX Block</strong> post_content.
            Edit the <strong>Proposed Key</strong> if needed (lowercase, underscores only).
            Approved items are registered in <strong>My Strings</strong> and automatically translated.
            The <code>gettext</code> filter (and <code>the_content</code> filter for UX Blocks)
            swap them on the frontend —
            <strong>no database content is ever modified</strong>.
        </p>

        <div class="mml-results-toolbar">
            <label>
                <input type="checkbox" id="mml-select-all"> Select All
            </label>
            <span id="mml-selected-count" style="margin-left:12px; color:#646970;"><span id="mml-selected-num">0</span> selected</span>
            <button id="mml-process-btn" class="button button-primary" style="margin-left:auto;" disabled>
                ⚙ Process Approved Items
            </button>
        </div>

        <div class="mml-table-scroll" style="margin-top:12px;">
            <table class="wp-list-table widefat fixed striped mml-results-table">
                <thead>
                    <tr>
                        <th style="width:32px;"></th>
                        <th style="width:35%;">Original Vietnamese Text</th>
                        <th style="width:26%;">Proposed Shortcode Key</th>
                        <th>Source</th>
                    </tr>
                </thead>
                <tbody id="mml-results-tbody">
                    <!-- Rows injected by JS -->
                </tbody>
            </table>
        </div>

        <div class="mml-results-footer" style="margin-top:12px;">
            <button id="mml-process-btn-bottom" class="button button-primary" disabled>
                ⚙ Process Approved Items
            </button>
        </div>

        <!-- Processing progress -->
        <div id="mml-process-progress" style="display:none; margin-top:16px;">
            <div class="mml-progress-bar-track">
                <div id="mml-process-bar" class="mml-progress-bar" style="width:0%"></div>
            </div>
            <p id="mml-process-status" class="description" style="margin-top:6px;">Processing…</p>
        </div>

        <div id="mml-process-result" class="notice" style="display:none; margin-top:16px; padding:12px 16px;"></div>
    </div>

    <!-- ── Orphaned Shortcodes Panel ─────────────────────────────────────── -->
    <div id="mml-orphaned-panel" class="mml-card" style="display:none; margin-top:20px;">
        <h2>🔍 Chuỗi mồ côi (Cần khôi phục)</h2>
        <p class="description">
            Các shortcode <code>[my_trans]</code> dưới đây vẫn còn trong nội dung bài viết nhưng
            <strong>key đã bị xóa</strong> khỏi <em>String Translation</em>.
            Frontend hiện đang hiển thị văn bản gốc từ thuộc tính <code>original=</code> (nếu có).
            Nhấn <strong>Khôi phục</strong> để đăng ký lại key vào wp_my_strings và dịch tự động.
        </p>

        <div class="mml-results-toolbar">
            <label>
                <input type="checkbox" id="mml-orphaned-select-all"> Select All
            </label>
            <span id="mml-orphaned-count-wrap" style="margin-left:12px; color:#646970;">
                <span id="mml-orphaned-num">0</span> chuỗi mồ côi
            </span>
            <button id="mml-orphaned-recover-btn" class="button button-primary" style="margin-left:auto;" disabled>
                ↩ Khôi phục Đã Chọn
            </button>
        </div>

        <div class="mml-table-scroll" style="margin-top:12px;">
            <table class="wp-list-table widefat fixed striped mml-results-table">
                <thead>
                    <tr>
                        <th style="width:32px;"></th>
                        <th style="width:26%;">Key (đã mất)</th>
                        <th style="width:38%;">Văn bản gốc (original attribute)</th>
                        <th>Nguồn</th>
                    </tr>
                </thead>
                <tbody id="mml-orphaned-tbody">
                    <!-- Rows injected by JS -->
                </tbody>
            </table>
        </div>

        <div id="mml-orphaned-progress" style="display:none; margin-top:16px;">
            <div class="mml-progress-bar-track">
                <div id="mml-orphaned-bar" class="mml-progress-bar" style="width:0%"></div>
            </div>
            <p id="mml-orphaned-status" class="description" style="margin-top:6px;">Đang khôi phục…</p>
        </div>

        <div id="mml-orphaned-result" class="notice" style="display:none; margin-top:16px; padding:12px 16px;"></div>
    </div>

    <!-- ── Manual Add String (Feature B) ─────────────────────────────────── -->
    <div class="mml-card" style="margin-top:20px;">
        <h2>➕ Manually Add String</h2>
        <p class="description">
            For WooCommerce search labels, dynamic placeholders, or any Vietnamese string
            the scanner might miss. Enter the exact text and it will be registered +
            auto-translated immediately. The <code>gettext</code> filter will intercept it
            on the frontend.
        </p>
        <div class="mml-manual-add-form">
            <div class="mml-manual-add-row">
                <label for="mml-manual-text"><strong>Vietnamese Text</strong></label>
                <input type="text" id="mml-manual-text" class="regular-text"
                       placeholder="e.g. Tìm kiếm sản phẩm…">
            </div>
            <div class="mml-manual-add-row">
                <label for="mml-manual-key"><strong>Proposed Key</strong> <span class="description">(optional — auto-generated if blank)</span></label>
                <input type="text" id="mml-manual-key" class="regular-text"
                       placeholder="e.g. tim_kiem_san_pham">
            </div>
            <button id="mml-manual-add-btn" class="button button-secondary">Add &amp; Translate</button>
            <span id="mml-manual-add-status" style="margin-left:10px; color:#646970;"></span>
        </div>
    </div>

    <!-- ── Phase C: Restore Sessions ─────────────────────────────────────── -->
    <div class="mml-card mml-danger-zone" style="margin-top:24px;">
        <h2>🗑️ Restore Sessions</h2>
        <p class="description">
            Each time you run &#8220;Process Approved Items&#8221;, a restore session is logged here.
            <strong>Preview</strong> shows the original texts that were registered.
            <strong>Restore</strong> reverts post content to the original <em>and</em> removes all auto-scanned strings.
            <strong>Discard</strong> deletes the log without changing anything.
        </p>

        <div class="mml-global-restore-bar">
            <button id="mml-global-restore-btn" class="button button-large mml-global-restore-btn">
                <span class="dashicons dashicons-undo"></span>
                Restore All to Original Language
            </button>
            <span class="description">Reverts all backed-up post content to original — registered translations in wp_my_strings are preserved.</span>
        </div>

        <table id="mml-sessions-table" class="wp-list-table widefat fixed striped"<?php echo empty( $sessions ) ? ' style="display:none;"' : ''; ?>>
            <thead>
                <tr>
                    <th class="mml-col-date">Date</th>
                    <th>Content</th>
                    <th class="mml-col-actions">Actions</th>
                </tr>
            </thead>
            <tbody id="mml-sessions-list">
                <?php foreach ( $sessions as $session ) : ?>
                    <?php
                    $dt    = new DateTime( $session->created_at );
                    $count = $session->key_count > 0
                             ? (int) $session->key_count . ' keys'
                             : (int) $session->post_count . ' entries';
                    ?>
                    <tr class="mml-session-row" data-sid="<?php echo esc_attr( $session->session_id ); ?>">
                        <td>
                            <strong><?php echo esc_html( $dt->format( 'Y-m-d H:i' ) ); ?></strong><br>
                            <span class="mml-session-meta"><?php echo esc_html( $count ); ?></span>
                        </td>
                        <td>
                            <div class="mml-session-content-summary">
                                <?php if ( ! empty( $session->posts ) ) : ?>
                                    <?php foreach ( $session->posts as $p ) : ?>
                                        <div class="mml-session-post-line">
                                            <code class="mml-type-badge"><?php echo esc_html( $p['post_type'] ); ?></code>
                                            <?php echo esc_html( $p['post_title'] ); ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <em class="mml-no-posts-label">Options / gettext strings only</em>
                                <?php endif; ?>
                            </div>
                            <div class="mml-preview-panel" data-sid="<?php echo esc_attr( $session->session_id ); ?>" style="display:none;"></div>
                        </td>
                        <td class="mml-session-actions">
                            <button class="button mml-preview-btn" data-sid="<?php echo esc_attr( $session->session_id ); ?>">
                                <span class="dashicons dashicons-visibility"></span> Preview
                            </button>
                            <button class="button mml-restore-btn" data-sid="<?php echo esc_attr( $session->session_id ); ?>">
                                <span class="dashicons dashicons-backup"></span> Restore
                            </button>
                            <button class="button mml-discard-btn" data-sid="<?php echo esc_attr( $session->session_id ); ?>">
                                <span class="dashicons dashicons-trash"></span> Discard
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p id="mml-no-sessions" style="color:#646970;<?php echo ! empty( $sessions ) ? 'display:none;' : ''; ?>">No backup sessions yet.</p>

        <div id="mml-restore-result" class="notice" style="display:none; margin-top:12px; padding:10px 16px;"></div>
    </div>

</div>
