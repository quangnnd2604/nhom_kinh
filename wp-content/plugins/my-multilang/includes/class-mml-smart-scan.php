<?php
/**
 * Smart Scan AJAX endpoints — scan wp_options, register strings, intercept gettext.
 *
 * Scanning does NOT modify wp_options or any post_content.
 * Approved strings are stored in wp_my_strings and swapped on the frontend via
 * the gettext / gettext_with_context WordPress filters.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MML_Smart_Scan {

    public static function init(): void {
        add_action( 'wp_ajax_mml_scan_batch',               [ self::class, 'ajax_scan_batch' ] );
        add_action( 'wp_ajax_mml_scan_process',             [ self::class, 'ajax_process' ] );
        add_action( 'wp_ajax_mml_scan_get_sessions',        [ self::class, 'ajax_get_sessions' ] );
        add_action( 'wp_ajax_mml_scan_restore',             [ self::class, 'ajax_restore' ] );
        add_action( 'wp_ajax_mml_scan_delete_session',      [ self::class, 'ajax_delete_session' ] );
        add_action( 'wp_ajax_mml_scan_count',               [ self::class, 'ajax_count' ] );
        add_action( 'wp_ajax_mml_scan_add_manual_string',   [ self::class, 'ajax_add_manual_string' ] );
        add_action( 'wp_ajax_mml_scan_orphaned',            [ self::class, 'ajax_scan_orphaned' ] );
        add_action( 'wp_ajax_mml_scan_rescue_scan',         [ self::class, 'ajax_rescue_scan' ] );
        add_action( 'wp_ajax_mml_scan_rescue_upgrade',      [ self::class, 'ajax_rescue_upgrade' ] );

        // Frontend string interception via filters (only outside admin)
        if ( ! is_admin() ) {
            // Priority 999 ensures we run AFTER all plugin .mo translations,
            // giving us the final say for any string registered in wp_my_strings.
            add_filter( 'gettext',              [ self::class, 'gettext_intercept' ],     999, 3 );
            add_filter( 'gettext_with_context', [ self::class, 'gettext_intercept_ctx' ], 999, 4 );
            // ngettext (plural forms) — catches _n() calls e.g. WooCommerce result-count
            add_filter( 'ngettext',              [ self::class, 'ngettext_intercept' ],     999, 5 );
            add_filter( 'ngettext_with_context', [ self::class, 'ngettext_intercept_ctx' ], 999, 6 );
            // Intercept rendered post_content for UX Block strings
            add_filter( 'the_content',          [ self::class, 'the_content_intercept' ], 20 );
            // Widget / sidebar content (Text widgets, block widgets, widget titles)
            add_filter( 'widget_text',          [ self::class, 'widget_content_intercept' ], 20, 3 );
            add_filter( 'widget_block_content', [ self::class, 'widget_content_intercept' ], 20, 3 );
            add_filter( 'widget_title',         [ self::class, 'widget_title_intercept' ],   20, 3 );
            // WooCommerce product search form placeholder
            add_filter( 'get_product_search_form', [ self::class, 'widget_content_intercept' ], 20 );
            // WooCommerce result count — direct HTML replacement as belt-and-braces on
            // top of the gettext/ngettext interceptors. Handles edge cases where WC
            // assembles the string without calling __() a second time.
            add_filter( 'woocommerce_result_count', [ self::class, 'wc_result_count_intercept' ], 999, 3 );
        }

        // WooCommerce notice translation — runs in ALL contexts (frontend, admin-ajax,
        // WC AJAX /?wc-ajax=...). Catches notices that were stored in the session while
        // the gettext filter wasn't active, and auto-registers unseen VI notices so the
        // user can fill in translations in the String Translation UI.
        add_filter( 'woocommerce_add_error',   [ self::class, 'wc_notice_intercept' ], 999 );
        add_filter( 'woocommerce_add_success', [ self::class, 'wc_notice_intercept' ], 999 );
        add_filter( 'woocommerce_add_notice',  [ self::class, 'wc_notice_intercept' ], 999 );
    }

    // ── AJAX: count total scannable options ─────────────────────────────────

    public static function ajax_count(): void {
        check_ajax_referer( 'mml_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $scan_target = sanitize_text_field( wp_unslash( $_POST['scan_target'] ?? 'options' ) );
        $targets     = array_map( 'trim', explode( ',', $scan_target ) );

        $options_total   = 0;
        $gettext_total   = 0;
        $ux_blocks_total = 0;

        if ( array_intersect( $targets, [ 'options', 'both' ] ) ) {
            $options_total = MML_Scanner::count_options();
        }
        if ( in_array( 'gettext', $targets, true ) ) {
            $gettext_total = MML_Scanner::count_woocommerce_strings();
        }
        if ( array_intersect( $targets, [ 'ux_blocks', 'both' ] ) ) {
            $ux_blocks_total = MML_Scanner::count_ux_blocks();
        }

        wp_send_json_success( [
            'total'           => $options_total + $gettext_total + $ux_blocks_total,
            'options_total'   => $options_total,
            'gettext_total'   => $gettext_total,
            'ux_blocks_total' => $ux_blocks_total,
        ] );
    }

    // ── AJAX: Endpoint 1 — scan one batch of wp_options ───────────────────

    public static function ajax_scan_batch(): void {
        check_ajax_referer( 'mml_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        set_time_limit( 120 );

        $offset      = absint( $_POST['offset'] ?? 0 );
        $limit       = min( absint( $_POST['limit'] ?? 20 ), 50 );
        $scan_target = sanitize_text_field( wp_unslash( $_POST['scan_target'] ?? 'options' ) );

        if ( $scan_target === 'ux_blocks' ) {
            $ux_limit = min( $limit, 10 );
            $result   = MML_Scanner::scan_ux_blocks_batch( $offset, $ux_limit );
            wp_send_json_success( [
                'items'       => $result['items'],
                'batch_count' => $result['block_count'],
                'next_offset' => $offset + $result['block_count'],
                'done'        => $result['block_count'] < $ux_limit,
            ] );
            return;
        }

        if ( $scan_target === 'gettext' ) {
            $result = MML_Scanner::scan_woocommerce_batch( $offset, $limit );
            wp_send_json_success( [
                'items'        => $result['items'],
                'batch_count'  => $result['string_count'],
                'next_offset'  => $offset + $result['string_count'],
                'done'         => $result['string_count'] < $limit,
            ] );
            return;
        }

        // Default: scan wp_options
        $result = MML_Scanner::scan_batch( $offset, $limit );
        wp_send_json_success( [
            'items'        => $result['items'],
            'option_count' => $result['option_count'],
            'batch_count'  => $result['option_count'],
            'next_offset'  => $offset + $result['option_count'],
            'done'         => $result['option_count'] < $limit,
        ] );
    }

    // ── AJAX: Endpoint 2 — register + optionally replace UX Block content ──
    //
    // Expected POST body:
    //   items:      JSON array of { text, key, post_id, source_type }
    //   session_id: (optional) existing session to append to
    //
    // source_type = 'ux_block'  → text is replaced in post_content with [my_trans key="..."]
    // source_type = 'options'   → strings registered only; gettext filter handles frontend swap

    public static function ajax_process(): void {
        check_ajax_referer( 'mml_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        set_time_limit( 300 );

        $raw_items  = wp_unslash( $_POST['items'] ?? '[]' );
        $items      = json_decode( $raw_items, true );
        $session_id = sanitize_text_field( wp_unslash( $_POST['session_id'] ?? '' ) );

        if ( ! is_array( $items ) || empty( $items ) ) {
            wp_send_json_error( 'No items to process.' );
        }

        if ( empty( $session_id ) ) {
            $session_id = MML_Backup::new_session_id();
        }

        $default_lang = MML_Languages::get_default_code();
        $active_langs = MML_Languages::get_all();
        $non_default  = array_filter( $active_langs, fn( $l ) => ! $l->is_default );

        // ── Phase 1: sanitise & categorise items ──────────────────────────
        $cleaned = [];
        foreach ( $items as $item ) {
            $text        = sanitize_textarea_field( wp_unslash( $item['text'] ?? '' ) );
            $key         = preg_replace( '/[^a-z0-9_]/', '', strtolower( sanitize_key( $item['key'] ?? '' ) ) );
            $post_id     = absint( $item['post_id'] ?? 0 );
            $source_type = sanitize_key( $item['source_type'] ?? 'options' );

            if ( empty( $text ) || empty( $key ) ) {
                continue;
            }
            $cleaned[] = [ 'text' => $text, 'key' => $key, 'post_id' => $post_id, 'source_type' => $source_type ];
        }

        if ( empty( $cleaned ) ) {
            wp_send_json_error( 'All items were invalid after sanitisation.' );
        }

        // ── Phase 2: register strings + auto-translate ────────────────────
        $registered_keys   = [];
        $translated_fields = 0;
        $ux_block_map      = []; // post_id => [ original_text => shortcode_key ]

        foreach ( $cleaned as $item ) {
            $text        = $item['text'];
            $key         = $item['key'];
            $post_id     = $item['post_id'];
            $source_type = $item['source_type'];

            $row_id = MML_Strings::upsert_autoscanned( $key, $text );
            if ( ! $row_id ) {
                continue;
            }

            $registered_keys[] = $key;

            $translations = [ $default_lang => $text ];
            foreach ( $non_default as $lang ) {
                usleep( 150000 ); // 0.15s rate-limit guard
                $translations[ $lang->code ] = MML_Auto_Translate::translate( $text, $default_lang, $lang->code );
            }

            MML_Strings::update( $row_id, wp_json_encode( $translations, JSON_UNESCAPED_UNICODE ) );
            $translated_fields++;

            // Queue UX Block post_content replacement
            if ( $source_type === 'ux_block' && $post_id > 0 ) {
                $ux_block_map[ $post_id ][ $text ] = $key;
            }
        }

        // ── Phase 3: replace text inside UX Block post_content ────────────
        $replaced_blocks = 0;
        foreach ( $ux_block_map as $post_id => $replacements ) {
            $post = get_post( $post_id );
            if ( ! $post || $post->post_type !== 'blocks' ) {
                continue;
            }

            // Snapshot original content so Restore can revert it
            MML_Backup::snapshot( $session_id, $post_id, $post->post_content );

            $new_content = $post->post_content;
            $block_keys  = [];

            foreach ( $replacements as $original_text => $shortcode_key ) {
                // [my_trans key="..." original="..."] — the `original` attribute stores the
                // raw Vietnamese text so the shortcode can self-heal if the DB record is ever
                // deleted (fallback rendering without a blank or raw "[key]" placeholder).
                $replacement  = '[my_trans key="' . esc_attr( $shortcode_key ) . '" original="' . esc_attr( $original_text ) . '"]';
                $new_content  = str_replace( $original_text, $replacement, $new_content );
                $block_keys[] = $shortcode_key;
            }

            if ( $new_content !== $post->post_content ) {
                wp_update_post( [
                    'ID'           => $post_id,
                    'post_content' => $new_content,
                ] );
                MML_Backup::attach_keys( $session_id, $post_id, $block_keys );
                $replaced_blocks++;
            }
        }

        // ── Phase 4: log options-based keys for the session ───────────────
        $options_keys = [];
        foreach ( $cleaned as $item ) {
            if ( $item['source_type'] !== 'ux_block' && in_array( $item['key'], $registered_keys, true ) ) {
                $options_keys[] = $item['key'];
            }
        }
        if ( ! empty( $options_keys ) ) {
            MML_Backup::log_keys( $session_id, array_unique( $options_keys ) );
        }

        // Clear caches so newly registered strings are immediately visible on the frontend.
        MML_Strings::clear_cache();
        wp_cache_flush();

        wp_send_json_success( [
            'session_id'        => $session_id,
            'registered_keys'   => count( $registered_keys ),
            'replaced_blocks'   => $replaced_blocks,
            'translated_fields' => $translated_fields,
            'message'           => sprintf(
                'Đã đăng ký %d chuỗi, dịch sang %d ngôn ngữ, cập nhật %d UX Block.',
                count( $registered_keys ),
                count( $non_default ),
                $replaced_blocks
            ),
        ] );
    }

    // ── AJAX: Endpoint 3 — list backup sessions ────────────────────────────

    public static function ajax_get_sessions(): void {
        check_ajax_referer( 'mml_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $sessions = MML_Backup::get_sessions();
        wp_send_json_success( $sessions );
    }

    // ── AJAX: Endpoint 4 — restore a session ──────────────────────────────

    public static function ajax_restore(): void {
        check_ajax_referer( 'mml_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $session_id = sanitize_text_field( wp_unslash( $_POST['session_id'] ?? '' ) );
        if ( empty( $session_id ) ) {
            wp_send_json_error( 'Missing session_id.' );
        }

        $result = MML_Backup::restore_session( $session_id );

        // Clear translation caches so the restored state takes effect immediately.
        MML_Strings::clear_cache();
        wp_cache_flush();

        $removed = $result['removed_keys'] ?? 0;

        if ( $result['restored_posts'] > 0 ) {
            $msg = sprintf(
                'Đã khôi phục %d bài viết về nội dung gốc. Đã xóa %d chuỗi tự động.',
                $result['restored_posts'],
                $removed
            );
        } else {
            $msg = sprintf(
                'Không có bài viết nào cần khôi phục. Đã xóa %d chuỗi tự động.',
                $removed
            );
        }

        wp_send_json_success( array_merge( $result, [ 'message' => $msg ] ) );
    }

    // ── AJAX: Endpoint 5 — delete session without restoring ───────────────

    public static function ajax_delete_session(): void {
        check_ajax_referer( 'mml_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $session_id = sanitize_text_field( wp_unslash( $_POST['session_id'] ?? '' ) );
        if ( empty( $session_id ) ) {
            wp_send_json_error( 'Missing session_id.' );
        }

        MML_Backup::delete_session( $session_id );
        wp_send_json_success( [ 'message' => 'Backup session deleted.' ] );
    }

    // ── AJAX: Endpoint 6 — manually register a single string ──────────────
    //
    // For WooCommerce search placeholders, dynamic labels, or any string
    // the scanner might miss. Registers it in wp_my_strings + auto-translates.

    public static function ajax_add_manual_string(): void {
        check_ajax_referer( 'mml_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $text = sanitize_textarea_field( wp_unslash( $_POST['text'] ?? '' ) );
        $key  = preg_replace( '/[^a-z0-9_]/', '', strtolower( sanitize_key( wp_unslash( $_POST['key'] ?? '' ) ) ) );

        if ( empty( $text ) ) {
            wp_send_json_error( 'Text cannot be empty.' );
        }

        if ( empty( $key ) ) {
            $key = MML_Scanner::generate_key( $text );
        }

        $row_id = MML_Strings::upsert( $key, $text );
        if ( ! $row_id ) {
            wp_send_json_error( 'Failed to register string.' );
        }

        $default_lang = MML_Languages::get_default_code();
        $active_langs = MML_Languages::get_all();
        $non_default  = array_filter( $active_langs, fn( $l ) => ! $l->is_default );

        $translations = [ $default_lang => $text ];
        foreach ( $non_default as $lang ) {
            usleep( 150000 );
            $translations[ $lang->code ] = MML_Auto_Translate::translate( $text, $default_lang, $lang->code );
        }

        MML_Strings::update( $row_id, wp_json_encode( $translations, JSON_UNESCAPED_UNICODE ) );

        wp_send_json_success( [
            'key'     => $key,
            'message' => sprintf( 'Đã thêm chuỗi "%s" với key "%s".', $text, $key ),
        ] );
    }

    // ── AJAX: Endpoint — scan orphaned [my_trans] shortcodes ──────────────
    //
    // Finds [my_trans key="..." original="..."] shortcodes in published post_content
    // whose `key` is no longer registered in wp_my_strings. Returns items with the
    // key pre-set (not re-generated) and text from the `original` attribute so the
    // existing "Process Approved Items" workflow can re-register them.

    public static function ajax_scan_orphaned(): void {
        check_ajax_referer( 'mml_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $offset = absint( $_POST['offset'] ?? 0 );
        $limit  = min( absint( $_POST['limit'] ?? 10 ), 50 );

        $result = MML_Scanner::scan_orphaned_batch( $offset, $limit );

        wp_send_json_success( [
            'items'      => $result['items'],
            'post_count' => $result['post_count'],
            'next_offset' => $offset + $result['post_count'],
            'done'        => $result['post_count'] < $limit,
            'total_found' => count( $result['items'] ),
        ] );
    }

    // ── AJAX: Rescue Scan — list old-format shortcodes ────────────────────
    //
    // Phase 1 of the rescue workflow: scans $limit posts per batch and returns
    // two lists:
    //   upgradeable  — shortcode has key in DB, can add `original=` automatically
    //   unresolvable — key deleted from DB and no `original` attribute; data is lost

    public static function ajax_rescue_scan(): void {
        check_ajax_referer( 'mml_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $offset = absint( $_POST['offset'] ?? 0 );
        $limit  = min( absint( $_POST['limit'] ?? 10 ), 50 );

        $result = MML_Scanner::scan_rescue_batch( $offset, $limit );

        wp_send_json_success( [
            'upgradeable'  => $result['upgradeable'],
            'unresolvable' => $result['unresolvable'],
            'post_count'   => $result['post_count'],
            'next_offset'  => $offset + $result['post_count'],
            'done'         => $result['post_count'] < $limit,
        ] );
    }

    // ── AJAX: Rescue Upgrade — rewrite old-format shortcodes ─────────────
    //
    // Phase 2 of the rescue workflow: runs MML_Scanner::run_rescue_upgrade()
    // which rewrites every upgradeable shortcode in-place to include `original=`.
    // One-shot action — no pagination needed because run_rescue_upgrade() walks
    // all affected posts internally.

    public static function ajax_rescue_upgrade(): void {
        check_ajax_referer( 'mml_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        set_time_limit( 120 );

        $result = MML_Scanner::run_rescue_upgrade();

        // Flush caches so newly-revised post_content is served fresh.
        MML_Strings::clear_cache();
        wp_cache_flush();

        $msg = sprintf(
            'Đã nâng cấp %d shortcode trong %d bài viết. Không thể khôi phục: %d (key đã bị xóa và không có `original`).',
            $result['upgraded'],
            $result['posts_changed'],
            $result['unresolvable']
        );

        wp_send_json_success( array_merge( $result, [ 'message' => $msg ] ) );
    }

    // ── Frontend: gettext interception ──────────────────────────────────────────
    //
    // When a plugin (e.g. WooCommerce) outputs a string via __() / _e() that
    // matches a registered VI value in wp_my_strings, this filter swaps it for
    // the translation that matches the current MML_LANG.
    //
    // A static cache is built once per request — O(1) lookups afterwards.

    public static function gettext_intercept( string $translated, string $text, string $domain ): string {
        static $vi_map   = null;
        static $def_lang = null;

        if ( null === $vi_map ) {
            $def_lang = MML_Languages::get_default_code();
            $vi_map   = [];
            foreach ( MML_Strings::get_all() as $row ) {
                $t = json_decode( $row->translations, true );
                if ( is_array( $t ) && isset( $t[ $def_lang ] ) ) {
                    $vi_map[ $t[ $def_lang ] ] = $t;
                }
            }
        }

        if ( empty( $vi_map ) ) {
            return $translated;
        }

        $current = defined( 'MML_LANG' ) ? MML_LANG : $def_lang;
        if ( $current === $def_lang ) {
            return $translated;
        }

        // ── 1a. Exact match on the original source string ──────────────────
        //       Works when wp_my_strings stores VI text and MML_LANG is non-VI,
        //       AND when VI is not the site locale (string stored as English).
        if ( isset( $vi_map[ $text ], $vi_map[ $text ][ $current ] ) ) {
            return $vi_map[ $text ][ $current ];
        }

        // ── 1b. Exact match on the .mo-translated string ───────────────────
        //       When site locale = vi_VN the .mo file already translated $text
        //       to Vietnamese before our filter runs; $translated IS the VI text.
        //       Handles result-count, ordering, search placeholder, etc.
        if ( $translated !== $text
             && isset( $vi_map[ $translated ], $vi_map[ $translated ][ $current ] )
        ) {
            return $vi_map[ $translated ][ $current ];
        }

        // ── 2. Digit-run pattern match — rendered strings with literal numbers ───
        // Normalises actual digit runs → '#' so "Hiển thị 12 trong 50" matches
        // a stored string that was originally registered with those numbers.
        static $pattern_map = null;
        if ( null === $pattern_map ) {
            $pattern_map = [];
            foreach ( $vi_map as $vi_text => $translations ) {
                if ( preg_match( '/\d/', $vi_text ) ) {
                    $pk = trim( preg_replace( [ '/\d+/', '/\s+/' ], [ '#', ' ' ], $vi_text ) );
                    $pattern_map[ $pk ] = $translations;
                }
            }
        }

        if ( ! empty( $pattern_map ) ) {
            // Try $text and $translated both
            foreach ( [ $text, $translated ] as $_candidate ) {
                if ( ! preg_match( '/\d/', $_candidate ) ) { continue; }
                $norm = trim( preg_replace( [ '/\d+/', '/\s+/' ], [ '#', ' ' ], $_candidate ) );
                if ( isset( $pattern_map[ $norm ], $pattern_map[ $norm ][ $current ] ) ) {
                    $template = $pattern_map[ $norm ][ $current ];
                    preg_match_all( '/\d+/', $_candidate, $num_m );
                    $nums = $num_m[0];
                    return preg_replace_callback( '/#/', static function () use ( &$nums ): string {
                        return (string) ( array_shift( $nums ) ?? '0' );
                    }, $template );
                }
            }
        }

        // ── 3. Printf-placeholder pattern match — WooCommerce result-count ───────
        // WooCommerce passes printf-format templates to __():
        //   'Showing all %d results'
        //   'Showing %1$d\u2013%2$d of %3$d results'
        // The .mo file translates the template (placeholders preserved), so
        // $translated = 'Hiển thị tất cả %d kết quả' (still has %d).
        // Strategy: normalise ALL printf specifiers to '#' and match against the
        // stored VI key.  On a hit, return the TRANSLATED template unchanged so
        // that WordPress's own printf() fills in the actual numbers correctly.
        static $printf_map = null;
        if ( null === $printf_map ) {
            // Regex matches: %d  %s  %1$d  %2$s  %-10s  %05.2f  …
            $ph_re      = '/%(?:\d+\$)?[-+]?\d*(?:\.\d+)?[dDsSeEfFgGuxXobcq]/u';
            $printf_map = [];
            foreach ( $vi_map as $vi_text => $translations ) {
                if ( strpos( $vi_text, '%' ) === false ) { continue; }
                $pk = trim( preg_replace( [ $ph_re, '/\s+/' ], [ '#', ' ' ], $vi_text ) );
                if ( strpos( $pk, '#' ) !== false ) {
                    $printf_map[ $pk ] = $translations;
                }
            }
        }

        if ( ! empty( $printf_map ) ) {
            $ph_re = '/%(?:\d+\$)?[-+]?\d*(?:\.\d+)?[dDsSeEfFgGuxXobcq]/u';
            // Try $translated first (locale = vi_VN already replaced $text)
            foreach ( [ $translated, $text ] as $_cand ) {
                if ( strpos( $_cand, '%' ) === false ) { continue; }
                $norm = trim( preg_replace( [ $ph_re, '/\s+/' ], [ '#', ' ' ], $_cand ) );
                if ( isset( $printf_map[ $norm ], $printf_map[ $norm ][ $current ] ) ) {
                    // Return the translated printf-format template. WordPress will
                    // call printf/sprintf on it and inject the actual numbers.
                    return $printf_map[ $norm ][ $current ];
                }
            }
        }

        return $translated;
    }

    /**
     * Handles gettext_with_context filter (4th param = context, ignored here).
     */
    public static function gettext_intercept_ctx( string $translated, string $text, string $context, string $domain ): string {
        return self::gettext_intercept( $translated, $text, $domain );
    }

    /**
     * Intercept ngettext (plural forms) — e.g. WooCommerce result-count uses _n().
     * Filter signature: ( $translation, $single, $plural, $number, $domain )
     *
     * Strategy: delegate to gettext_intercept using the already-selected $translation
     * as $translated and the appropriate source pattern ($single or $plural) as $text.
     * The interceptor will match via step 1b (exact on .mo result) or step 3 (printf-map).
     * We return the translated TEMPLATE so WordPress's own sprintf() fills numbers in.
     */
    public static function ngettext_intercept( string $translation, string $single, string $plural, int $number, string $domain ): string {
        // Pick the grammatically-correct English source pattern to pass as $text
        $source = ( 1 === $number ) ? $single : $plural;
        $result = self::gettext_intercept( $translation, $source, $domain );
        // If no match via source, try the other form (some locales use same string for both)
        if ( $result === $translation ) {
            $result = self::gettext_intercept( $translation, ( 1 === $number ) ? $plural : $single, $domain );
        }
        return $result;
    }

    /**
     * Handles ngettext_with_context filter (6th param = context, ignored here).
     */
    public static function ngettext_intercept_ctx( string $translation, string $single, string $plural, int $number, string $context, string $domain ): string {
        return self::ngettext_intercept( $translation, $single, $plural, $number, $domain );
    }

    // ── Frontend: widget text / block content interception ─────────────────
    //
    // Applies the same VI→current-lang swap to widget Text content and block-
    // based widget markup. Also used for the WooCommerce product search form.
    //
    // Accepts extra arguments passed by widget_text/widget_block_content
    // (instance array and widget object) and silently ignores them.

    public static function widget_content_intercept( string $content, $instance = null, $widget = null ): string {
        return self::the_content_intercept( $content );
    }

    /**
     * Intercept widget titles.
     * Filter: widget_title( $title, $instance, $id_base )
     */
    public static function widget_title_intercept( string $title, $instance = null, $id_base = '' ): string {
        static $vi_map   = null;
        static $def_lang = null;

        if ( null === $vi_map ) {
            $def_lang = MML_Languages::get_default_code();
            $vi_map   = [];
            foreach ( MML_Strings::get_all() as $row ) {
                $t = json_decode( $row->translations, true );
                if ( is_array( $t ) && isset( $t[ $def_lang ] ) ) {
                    $vi_map[ $t[ $def_lang ] ] = $t;
                }
            }
        }

        if ( empty( $vi_map ) ) {
            return $title;
        }

        $current = defined( 'MML_LANG' ) ? MML_LANG : $def_lang;
        if ( $current === $def_lang ) {
            return $title;
        }

        // Exact match first
        if ( isset( $vi_map[ $title ], $vi_map[ $title ][ $current ] ) ) {
            return $vi_map[ $title ][ $current ];
        }

        // Substring swap — title may contain one registered phrase
        foreach ( $vi_map as $vi_text => $translations ) {
            if ( isset( $translations[ $current ] )
                 && $translations[ $current ] !== ''
                 && strpos( $title, $vi_text ) !== false
            ) {
                $title = str_replace( $vi_text, $translations[ $current ], $title );
            }
        }

        return $title;
    }

    // ── Frontend: the_content interception (UX Blocks) ──────────────────────
    //
    // Swaps registered Vietnamese strings inside rendered post_content.
    // Used for Flatsome UX Blocks whose text is not passed through gettext.
    // The same static $vi_map cache as gettext_intercept is shared via the
    // MML_Strings::get_all() call — built once per request, O(1) lookups.

    public static function the_content_intercept( string $content ): string {
        static $vi_map   = null;
        static $def_lang = null;

        if ( null === $vi_map ) {
            $def_lang = MML_Languages::get_default_code();
            $vi_map   = [];
            foreach ( MML_Strings::get_all() as $row ) {
                $t = json_decode( $row->translations, true );
                if ( is_array( $t ) && isset( $t[ $def_lang ] ) ) {
                    $vi_map[ $t[ $def_lang ] ] = $t;
                }
            }
        }

        if ( empty( $vi_map ) ) {
            return $content;
        }

        $current = defined( 'MML_LANG' ) ? MML_LANG : $def_lang;
        if ( $current === $def_lang ) {
            return $content;
        }

        foreach ( $vi_map as $vi_text => $translations ) {
            if ( isset( $translations[ $current ] ) && strpos( $content, $vi_text ) !== false ) {
                $content = str_replace( $vi_text, $translations[ $current ], $content );
            }
        }

        return $content;
    }

    // ── WooCommerce notice translation ──────────────────────────────────────────
    //
    // Hooks: woocommerce_add_error | woocommerce_add_success | woocommerce_add_notice
    //
    // WC 7+ passes an array  : [ 'notice' => $message, 'data' => [...] ]
    // WC 5/6 passes a string : $message
    //
    // Two jobs:
    //   1. Translate the notice text via substring swap (the_content_intercept)
    //      whenever a registered VI string appears inside it.
    //   2. Auto-register previously unseen VI notices (is_autoscanned = 1) so
    //      the user can add translations in the String Translation UI.
    //      Auto-registration uses a deterministic md5-based key so the same
    //      notice text always maps to the same row — safe to call repeatedly.

    /**
     * Filter entry-point — normalises string/array formats across WC versions.
     *
     * @param string|array $notice
     * @return string|array
     */
    public static function wc_notice_intercept( $notice ) {
        if ( is_array( $notice ) && isset( $notice['notice'] ) ) {
            $notice['notice'] = self::do_wc_notice_translate( $notice['notice'] );
            return $notice;
        }
        if ( is_string( $notice ) ) {
            return self::do_wc_notice_translate( $notice );
        }
        return $notice;
    }

    /**
     * Core logic: translate or auto-register a single WC notice message.
     */
    private static function do_wc_notice_translate( string $message ): string {
        if ( ! defined( 'MML_LANG' ) ) {
            return $message;
        }

        $def_lang = MML_Languages::get_default_code();
        $current  = MML_LANG;

        // ── 1. Substring-based translation (same as the_content_intercept) ─────
        // Catches any registered VI phrase embedded in the notice, including
        // notices that contain HTML (product name in <a> tag, etc.).
        $translated = self::the_content_intercept( $message );
        if ( $translated !== $message ) {
            return $translated;
        }

        // ── 2. Auto-register unrecognised default-lang notices ────────────────
        // Only when: current lang ≠ default AND text is still in default language.
        // We detect default language by checking for Vietnamese characters —
        // avoids accidentally registering already-translated (English) strings.
        if ( $current === $def_lang ) {
            return $message;
        }

        $plain = trim( html_entity_decode( wp_strip_all_tags( $message ), ENT_QUOTES, 'UTF-8' ) );
        if ( empty( $plain ) || mb_strlen( $plain ) > 250 ) {
            return $message;
        }

        if ( ! class_exists( 'MML_Scanner' ) || ! MML_Scanner::contains_vietnamese( $plain ) ) {
            return $message; // Not a VI-language string; don't register.
        }

        // Deterministic key: 'wc_' + 12 hex chars of md5(plain text).
        // Same text always produces the same key → INSERT IGNORE is idempotent.
        $key = 'wc_' . substr( md5( $plain ), 0, 12 );

        global $wpdb;
        $table  = $wpdb->prefix . 'my_strings';
        $exists = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore
            "SELECT id FROM `{$table}` WHERE string_key = %s",
            $key
        ) );

        if ( ! $exists ) {
            $wpdb->insert(
                $table,
                [
                    'string_key'     => $key,
                    'translations'   => wp_json_encode( [ $def_lang => $plain ], JSON_UNESCAPED_UNICODE ),
                    'is_autoscanned' => 1,
                ],
                [ '%s', '%s', '%d' ]
            );
            // Bust the MML_Strings row cache so subsequent get_value() calls see the new row.
            MML_Strings::clear_cache();
        }

        // No translation available yet — user populates it in the UI.
        return $message;
    }

    // ── WooCommerce result count — direct HTML intercept ─────────────────────
    //
    // Filter: woocommerce_result_count( $html, $args, $wp_query )
    // Acts as a belt-and-braces fallback on top of the gettext/ngettext
    // interceptors. Rebuilds the result-count paragraph when the gettext layer
    // couldn't intercept it (e.g. WC caches the HTML in a transient).
    //
    // Uses pre-registered keys in wp_my_strings:
    //   wc_showing_single_result      — exactly 1 result
    //   wc_showing_all_d_results      — all results fit on one page
    //   wc_showing_d_d_of_d_results   — paginated

    public static function wc_result_count_intercept( string $html, array $args, $wp_query ): string {
        if ( ! defined( 'MML_LANG' ) ) {
            return $html;
        }

        $def_lang = MML_Languages::get_default_code();
        $lang     = MML_LANG;

        if ( $lang === $def_lang ) {
            return $html;
        }

        $total    = (int) ( $args['total']    ?? 0 );
        $per_page = (int) ( $args['per_page'] ?? 0 );
        $paged    = (int) ( $args['paged']    ?? 1 );

        if ( $total <= 0 ) {
            return $html;
        }

        if ( $total === 1 ) {
            $text = MML_Strings::get_value( 'wc_showing_single_result', $lang );
        } elseif ( $per_page <= 0 || $total <= $per_page ) {
            // All results on a single page
            $template = MML_Strings::get_value( 'wc_showing_all_d_results', $lang );
            // Only use if it looks like a real translated template (contains %d)
            if ( strpos( $template, '%' ) !== false ) {
                $text = sprintf( $template, $total );
            } else {
                return $html; // fall back to gettext interceptor result
            }
        } else {
            // Paginated
            $first    = ( $per_page * ( $paged - 1 ) ) + 1;
            $last     = min( $total, $per_page * $paged );
            $template = MML_Strings::get_value( 'wc_showing_d_d_of_d_results', $lang );
            if ( strpos( $template, '%' ) !== false ) {
                $text = sprintf( $template, $first, $last, $total );
            } else {
                return $html;
            }
        }

        // Sanity: if get_value returned the key placeholder, bail
        if ( $text === '' || $text[0] === '[' ) {
            return $html;
        }

        return '<p class="woocommerce-result-count">' . esc_html( $text ) . '</p>';
    }
}
