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
            </label>
        </div>

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
            Each time you run “Process Approved Items”, the registered string keys are logged here.
            <strong>Restore</strong> removes the registered string keys from the system,
            effectively reverting the interception — original plugin strings reappear on the frontend.
            <strong>Discard</strong> deletes this log without removing any registered strings.
        </p>

        <div id="mml-sessions-list">
            <?php if ( empty( $sessions ) ) : ?>
                <p style="color:#646970;" id="mml-no-sessions">No backup sessions yet.</p>
            <?php else : ?>
                <?php foreach ( $sessions as $session ) : ?>
                    <?php
                    $dt = new DateTime( $session->created_at );
                    $label = $dt->format( 'Y-m-d H:i' ) . ' — ' . (int) $session->post_count . ' post(s)';
                    ?>
                    <div class="mml-session-row" data-sid="<?php echo esc_attr( $session->session_id ); ?>">
                        <span class="mml-session-label">
                            <?php
                            $dt    = new DateTime( $session->created_at );
                            $count = isset( $session->key_count ) && $session->key_count > 0
                                     ? (int) $session->key_count . ' chuỗi'
                                     : (int) $session->post_count . ' bài';
                            echo esc_html( $dt->format( 'Y-m-d H:i' ) . ' — ' . $count );
                            ?>
                        </span>
                        <button class="button mml-restore-btn"
                                data-sid="<?php echo esc_attr( $session->session_id ); ?>">
                            ↩ Restore
                        </button>
                        <button class="button mml-discard-btn"
                                data-sid="<?php echo esc_attr( $session->session_id ); ?>">
                            🗑 Discard
                        </button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div id="mml-restore-result" class="notice" style="display:none; margin-top:12px; padding:10px 16px;"></div>
    </div>

    <!-- ── Phase D: Rescue Scanner (one-time shortcode upgrade) ─────────── -->
    <div class="mml-card" style="margin-top:24px;">
        <h2>🚑 Rescue Scanner — Upgrade Shortcodes</h2>
        <p class="description">
            Old <code>[my_trans key="X"]</code> shortcodes were created without a fallback
            <code>original</code> attribute. If the key is ever deleted from
            <strong>String Translation</strong>, the frontend shows a blank.
            <br><br>
            <strong>Step 1 — Scan</strong> detects which shortcodes need upgrading and which
            are already broken (key deleted, no original text available).<br>
            <strong>Step 2 — Upgrade All</strong> rewrites every upgradeable shortcode in-place
            to <code>[my_trans key="X" original="Văn bản gốc"]</code> so future key deletions
            always fall back to the original Vietnamese text.
        </p>

        <div style="margin-top:12px; display:flex; gap:10px; align-items:center;">
            <button id="mml-rescue-scan-btn" class="button button-secondary">
                🔍 Step 1 — Scan for Old Shortcodes
            </button>
            <button id="mml-rescue-upgrade-btn" class="button button-primary" style="display:none;">
                ⬆ Step 2 — Upgrade All Upgradeable
            </button>
            <span id="mml-rescue-status" style="color:#646970;"></span>
        </div>

        <div id="mml-rescue-progress-wrap" style="display:none; margin-top:12px;">
            <div class="mml-progress-bar-track">
                <div id="mml-rescue-bar" class="mml-progress-bar" style="width:0%"></div>
            </div>
        </div>

        <div id="mml-rescue-results" style="display:none; margin-top:16px;">
            <div id="mml-rescue-upgradeable-panel" style="display:none;">
                <h4 style="margin-bottom:6px; color:#00a32a;">✅ Upgradeable
                    (<span id="mml-rescue-upgradeable-count">0</span>)</h4>
                <p class="description">These shortcodes have their key in the DB.
                    "Upgrade All" will add the <code>original=</code> attribute automatically.</p>
                <table class="wp-list-table widefat fixed striped" style="margin-top:8px;">
                    <thead><tr>
                        <th style="width:30%;">Key</th>
                        <th style="width:40%;">Vietnamese Text (will become <code>original</code>)</th>
                        <th>Post</th>
                    </tr></thead>
                    <tbody id="mml-rescue-upgradeable-tbody"></tbody>
                </table>
            </div>
            <div id="mml-rescue-unresolvable-panel" style="display:none; margin-top:16px;">
                <h4 style="margin-bottom:6px; color:#d63638;">⚠ Unresolvable
                    (<span id="mml-rescue-unresolvable-count">0</span>)</h4>
                <p class="description">These shortcodes have no key in the DB and no
                    <code>original=</code> attribute. The text cannot be recovered automatically.
                    Use <strong>Manually Add String</strong> above to re-register the key.</p>
                <table class="wp-list-table widefat fixed striped" style="margin-top:8px;">
                    <thead><tr>
                        <th style="width:40%;">Key (missing from DB)</th>
                        <th>Post</th>
                    </tr></thead>
                    <tbody id="mml-rescue-unresolvable-tbody"></tbody>
                </table>
            </div>
        </div>

        <div id="mml-rescue-upgrade-result" class="notice" style="display:none; margin-top:12px; padding:10px 16px;"></div>
    </div>
</div>
