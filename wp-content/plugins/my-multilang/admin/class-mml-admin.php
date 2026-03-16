<?php
/**
 * Admin controller — registers menu pages, handles form saves, and AJAX endpoints.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MML_Admin {

    public function __construct() {
        add_action( 'admin_menu',         [ $this, 'add_menu_pages' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_post_mml_save_language', [ $this, 'handle_save_language' ] );
        add_action( 'admin_post_mml_delete_language', [ $this, 'handle_delete_language' ] );
        add_action( 'admin_post_mml_save_strings',   [ $this, 'handle_save_strings' ] );
        add_action( 'wp_ajax_mml_add_string',              [ $this, 'ajax_add_string' ] );
        add_action( 'wp_ajax_mml_delete_string',            [ $this, 'ajax_delete_string' ] );
        add_action( 'wp_ajax_mml_clone_object',             [ $this, 'ajax_clone_object' ] );
        add_action( 'wp_ajax_mml_auto_translate_strings',   [ $this, 'ajax_auto_translate_strings' ] );
    }

    // ── Menu Pages ─────────────────────────────────────────────────────────

    public function add_menu_pages(): void {
        add_menu_page(
            __( 'Multilang', 'my-multilang' ),
            __( 'Multilang', 'my-multilang' ),
            'manage_options',
            'mml-languages',
            [ $this, 'page_languages' ],
            'dashicons-translation',
            80
        );

        add_submenu_page(
            'mml-languages',
            __( 'String Translations', 'my-multilang' ),
            __( 'String Translations', 'my-multilang' ),
            'manage_options',
            'mml-strings',
            [ $this, 'page_strings' ]
        );

        add_submenu_page(
            'mml-languages',
            __( 'Magic Sync', 'my-multilang' ),
            __( 'Magic Sync', 'my-multilang' ),
            'manage_options',
            'mml-magic-sync',
            [ 'MML_Magic_Sync_UI', 'render_page' ]
        );

        add_submenu_page(
            'mml-languages',
            __( 'Smart Scan', 'my-multilang' ),
            __( 'Smart Scan', 'my-multilang' ),
            'manage_options',
            'mml-smart-scan',
            [ 'MML_Scanner_UI', 'render_page' ]
        );
    }

    // ── Asset enqueueing ───────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {

        // Pages that need FULL plugin assets (media picker + all JS)
        $plugin_pages = [
            'toplevel_page_mml-languages',
            'multilang_page_mml-strings',
        ];

        $scanner_pages = [
            'multilang_page_mml-smart-scan',
        ];

        $is_plugin_page  = in_array( $hook, $plugin_pages, true );
        $is_scanner_page = in_array( $hook, $scanner_pages, true );

        // $hook for list tables usually starts with "edit-" or is "edit.php"
        // And we also need it on single post edit screens ("post.php", "post-new.php") for the Meta Box.
        $is_list_page = ( 
            $hook === 'edit.php' || 
            str_starts_with( $hook, 'edit-' ) ||
            $hook === 'post.php' ||
            $hook === 'post-new.php'
        );

        if ( ! $is_plugin_page && ! $is_list_page && ! $is_scanner_page ) {
            return;
        }

        // Always load CSS (status badges, clone button styles)
        wp_enqueue_style( 'mml-admin', MML_URL . 'admin/assets/admin.css', [], MML_VERSION );

        // On plugin pages also load media picker for flag image selection
        if ( $is_plugin_page ) {
            wp_enqueue_media();
        }

        // Always load the JS (it handles both clone buttons AND plugin-page UI)
        wp_enqueue_script( 'mml-admin', MML_URL . 'admin/assets/admin.js', [ 'jquery' ], MML_VERSION, true );

        // Build a code-indexed language registry for JS (languages page + magic sync page)
        $js_registry = [];
        if ( function_exists( 'mml_get_language_registry' ) ) {
            foreach ( mml_get_language_registry() as $entry ) {
                $js_registry[] = [
                    'name'    => $entry['name'],
                    'code'    => $entry['code'],
                    'ai_name' => $entry['ai_name'],
                    'example' => $entry['example'],
                ];
            }
        }

        wp_localize_script( 'mml-admin', 'mmlAdmin', [
            'ajaxurl'              => admin_url( 'admin-ajax.php' ),
            'adminurl'             => admin_url(),
            'nonce'                => wp_create_nonce( 'mml_admin_nonce' ),
            'confirmDelete'        => __( 'Are you sure you want to clone this content?', 'my-multilang' ),
            'confirmDeleteStr'     => __( 'Delete this string key and all its translations?', 'my-multilang' ),
            'confirmRestore'       => __( 'Restore will revert post content and remove all registered string keys for this session. Continue?', 'my-multilang' ),
            'confirmDiscard'       => __( 'Discard the backup without restoring? This cannot be undone.', 'my-multilang' ),
            'langRegistry'         => $js_registry,
            // i18n strings for admin.js — all user-visible JS text goes here so it
            // can be translated via standard WordPress .po/.mo files.
            'i18n'                 => [
                'serverError'      => __( 'Server error.', 'my-multilang' ),
                'cloneFailed'      => __( 'Clone failed.', 'my-multilang' ),
                'deleteFailed'     => __( 'Delete failed.', 'my-multilang' ),
                'processing'       => __( 'Processing…', 'my-multilang' ),
                'processSelected'  => __( 'Process selected items', 'my-multilang' ),
                'restoring'        => __( 'Restoring…', 'my-multilang' ),
                'restore'          => __( 'Restore', 'my-multilang' ),
                'discarding'       => __( 'Discarding…', 'my-multilang' ),
                'discard'          => __( 'Discard', 'my-multilang' ),
                'restoreError'     => __( 'Restore error.', 'my-multilang' ),
                'previewError'     => __( 'Cannot load preview.', 'my-multilang' ),
                'scanCounting'     => __( 'Counting system options…', 'my-multilang' ),
                'scanOrphaned'     => __( 'Scanning for orphaned strings in posts…', 'my-multilang' ),
                'scanWcGettext'    => __( 'Scanning WooCommerce gettext strings…', 'my-multilang' ),
                'scanUxBlocks'     => __( 'Scanning UX Blocks…', 'my-multilang' ),
                'scanStopped'      => __( 'Stopped.', 'my-multilang' ),
                'scanCountError'   => __( 'Cannot count data.', 'my-multilang' ),
                'scanError'        => __( 'Error during scan.', 'my-multilang' ),
                'scanNoData'            => __( 'No data found to scan.', 'my-multilang' ),
                'manualAddText'         => __( 'Please enter text.', 'my-multilang' ),
                'autoTranslateSelect'   => __( 'Please select a language first.', 'my-multilang' ),
                'autoTranslating'       => __( 'Translating…', 'my-multilang' ),
                'autoTranslateDone'     => __( 'Successfully translated %d string(s) into %s.', 'my-multilang' ),
                'autoTranslateNone'     => __( 'All strings are already translated for this language.', 'my-multilang' ),
                'autoTranslateBtn'      => __( 'Auto-translate missing strings', 'my-multilang' ),
            ],
        ] );
    }

    // ── Page: Language Manager ─────────────────────────────────────────────

    public function page_languages(): void {
        $languages   = MML_Languages::get_all();
        $edit_id     = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
        $edit_lang   = $edit_id ? $this->get_lang_by_id( $edit_id, $languages ) : null;
        $saved_msg   = isset( $_GET['saved'] ) ? esc_html__( 'Language saved.', 'my-multilang' ) : '';
        $deleted_msg = isset( $_GET['deleted'] ) ? esc_html__( 'Language deleted (strings purged).', 'my-multilang' ) : '';
        $error_msg   = isset( $_GET['error'] ) ? esc_html( urldecode( $_GET['error'] ) ) : ''; // phpcs:ignore

        // Clone-block notice — set when deletion was refused because clones exist.
        $clone_block = null;
        if ( isset( $_GET['error_type'] ) && $_GET['error_type'] === 'has_clones' ) {
            $clone_block = [
                'code'  => sanitize_key( wp_unslash( $_GET['error_lang']  ?? '' ) ),
                'count' => absint( $_GET['error_count'] ?? 0 ),
            ];
        }

        include MML_PATH . 'admin/views/languages.php';
    }

    // ── Page: String Translations ──────────────────────────────────────────

    public function page_strings(): void {
        $strings   = MML_Strings::get_all();
        $languages = MML_Languages::get_all();
        $saved_msg = isset( $_GET['saved'] ) ? esc_html__( 'Strings saved.', 'my-multilang' ) : '';

        include MML_PATH . 'admin/views/strings.php';
    }

    // ── Form Handlers ──────────────────────────────────────────────────────

    /**
     * Handle add/edit language form submission.
     */
    public function handle_save_language(): void {
        check_admin_referer( 'mml_save_language' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'my-multilang' ) );
        }

        $id   = isset( $_POST['lang_id'] ) ? absint( $_POST['lang_id'] ) : 0;
        $data = [
            'name'             => sanitize_text_field( wp_unslash( $_POST['lang_name']    ?? '' ) ),
            'code'             => sanitize_key( wp_unslash( $_POST['lang_code']           ?? '' ) ),
            'ai_name'          => sanitize_text_field( wp_unslash( $_POST['lang_ai_name'] ?? '' ) ),
            'flag_id'          => absint( $_POST['flag_id']                               ?? 0 ),
            'is_default'       => ! empty( $_POST['is_default'] ) ? 1 : 0,
            'sort_order'       => absint( $_POST['sort_order']                            ?? 0 ),
            'use_english_slug' => ! empty( $_POST['use_english_slug'] ) ? 1 : 0,
        ];

        if ( empty( $data['name'] ) || empty( $data['code'] ) ) {
            wp_redirect( add_query_arg( 'error', urlencode( __( 'Name and Code are required.', 'my-multilang' ) ), admin_url( 'admin.php?page=mml-languages' ) ) );
            exit;
        }

        if ( $id ) {
            MML_Languages::update( $id, $data );
        } else {
            MML_Languages::insert( $data );
            // New language added — invalidate the heal transient so admin_init
            // runs heal_system_string_languages() on the very next page load.
            delete_transient( 'mml_lang_strings_healed' );
        }

        wp_redirect( add_query_arg( 'saved', '1', admin_url( 'admin.php?page=mml-languages' ) ) );
        exit;
    }

    /**
     * Handle language deletion — three-step safety protocol:
     *   1. Count clones; block if any exist.
     *   2. Purge string translations for this language.
     *   3. Delete the language row.
     */
    public function handle_delete_language(): void {
        check_admin_referer( 'mml_delete_language' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'my-multilang' ) );
        }

        $id   = absint( $_GET['lang_id'] ?? 0 );
        $lang = MML_Languages::get_by_id( $id );

        if ( ! $lang ) {
            wp_redirect( add_query_arg(
                'error', urlencode( __( 'Language not found.', 'my-multilang' ) ),
                admin_url( 'admin.php?page=mml-languages' )
            ) );
            exit;
        }

        // ── Step 1: Clone Detection ───────────────────────────────────────
        // Refuse deletion when posts/terms still exist for this language in
        // wp_my_translations. The admin must purge clones first via Magic Sync.
        $clone_count = MML_Languages::count_clones( $lang->code );
        if ( $clone_count > 0 ) {
            wp_redirect( add_query_arg(
                [
                    'error_type'  => 'has_clones',
                    'error_lang'  => $lang->code,
                    'error_count' => $clone_count,
                ],
                admin_url( 'admin.php?page=mml-languages' )
            ) );
            exit;
        }

        // ── Step 2: String Translation Purge ─────────────────────────────
        // Remove this language's key from every JSON blob in wp_my_strings
        // BEFORE the language row is deleted, so no ghost entries remain.
        MML_Languages::purge_string_translations( $lang->code );

        // ── Step 3: Delete language row ───────────────────────────────────
        $result = MML_Languages::delete( $id );

        if ( is_wp_error( $result ) ) {
            wp_redirect( add_query_arg(
                'error', urlencode( $result->get_error_message() ),
                admin_url( 'admin.php?page=mml-languages' )
            ) );
        } else {
            wp_redirect( add_query_arg( 'deleted', '1', admin_url( 'admin.php?page=mml-languages' ) ) );
        }
        exit;
    }

    /**
     * Handle bulk save of all string translations.
     */
    public function handle_save_strings(): void {
        check_admin_referer( 'mml_save_strings' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'my-multilang' ) );
        }

        $strings_data = $_POST['mml_strings'] ?? []; // phpcs:ignore
        foreach ( $strings_data as $id => $lang_values ) {
            $id  = absint( $id );
            $map = [];
            foreach ( $lang_values as $code => $text ) {
                $map[ sanitize_key( $code ) ] = wp_kses_post( wp_unslash( $text ) );
            }
            MML_Strings::update( $id, wp_json_encode( $map, JSON_UNESCAPED_UNICODE ) );
        }

        wp_redirect( add_query_arg( 'saved', '1', admin_url( 'admin.php?page=mml-strings' ) ) );
        exit;
    }

    // ── AJAX Handlers ──────────────────────────────────────────────────────

    /**
     * AJAX: Add new string key.
     */
    public function ajax_add_string(): void {
        check_ajax_referer( 'mml_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'my-multilang' ) ] );
        }

        $key = sanitize_key( wp_unslash( $_POST['key'] ?? '' ) );
        if ( empty( $key ) ) {
            wp_send_json_error( [ 'message' => __( 'Key cannot be empty.', 'my-multilang' ) ] );
        }
        if ( in_array( $key, MML_Strings::get_all_keys(), true ) ) {
            wp_send_json_error( [ 'message' => __( 'Key already exists.', 'my-multilang' ) ] );
        }

        $new_id = MML_Strings::insert( $key );
        if ( ! $new_id ) {
            wp_send_json_error( [ 'message' => __( 'Failed to insert key.', 'my-multilang' ) ] );
        }

        wp_send_json_success( [ 'id' => $new_id, 'key' => $key ] );
    }

    /**
     * AJAX: Delete string row.
     */
    public function ajax_delete_string(): void {
        check_ajax_referer( 'mml_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'my-multilang' ) ] );
        }

        $id     = absint( $_POST['id'] ?? 0 );
        $result = MML_Strings::delete( $id );

        $result
            ? wp_send_json_success()
            : wp_send_json_error( [ 'message' => __( 'Failed to delete.', 'my-multilang' ) ] );
    }

    /**
     * AJAX: Clone a post or term.
     * Returns the edit URL of the newly created clone.
     */
    public function ajax_clone_object(): void {
        check_ajax_referer( 'mml_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'my-multilang' ) ] );
        }

        $type    = sanitize_key( wp_unslash( $_POST['object_type'] ?? 'post' ) );
        $id      = absint( $_POST['object_id'] ?? 0 );
        $lang    = sanitize_key( wp_unslash( $_POST['lang'] ?? '' ) );
        $tax     = sanitize_key( wp_unslash( $_POST['taxonomy'] ?? '' ) );

        if ( ! $id || ! $lang ) {
            wp_send_json_error( [ 'message' => __( 'Missing parameters.', 'my-multilang' ) ] );
        }

        if ( is_wp_error( $result ?? null ) ) {
            // pre-check not needed, handled below
        }

        if ( $type === 'term' ) {
            $result = MML_Cloner::clone_term( $id, $tax ?: 'category', $lang );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( [ 'message' => $result->get_error_message() ] );
                return;
            }
            $edit_link = get_edit_term_link( (int) $result, $tax );
            $edit_url  = ( $edit_link && ! is_wp_error( $edit_link ) ) ? esc_url_raw( $edit_link ) : admin_url( 'edit-tags.php?taxonomy=' . $tax );
        } else {
            $result = MML_Cloner::clone_post( $id, $lang );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( [ 'message' => $result->get_error_message() ] );
                return;
            }
            $edit_link = get_edit_post_link( (int) $result, 'raw' );
            $edit_url  = $edit_link ? esc_url_raw( $edit_link ) : admin_url( 'edit.php' );
        }

        wp_send_json_success( [ 'edit_url' => $edit_url ] );
    }

    /**
     * AJAX: Auto-translate all missing strings for a given target language.
     *
     * Each call processes one batch (up to 20 strings). The JS loops until
     * the server reports `done = true`. Only empty/null cells are touched —
     * existing translations are never overwritten (Golden Source rule).
     */
    public function ajax_auto_translate_strings(): void {
        check_ajax_referer( 'mml_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'my-multilang' ) ] );
        }

        $target_lang = sanitize_key( wp_unslash( $_POST['lang_code'] ?? '' ) );
        if ( empty( $target_lang ) ) {
            wp_send_json_error( [ 'message' => __( 'Missing language code.', 'my-multilang' ) ] );
        }

        $default_lang = MML_Languages::get_default_code();
        if ( $target_lang === $default_lang ) {
            wp_send_json_error( [ 'message' => __( 'Cannot translate to the default language.', 'my-multilang' ) ] );
        }

        $batch_size = 20;

        global $wpdb;
        $table = $wpdb->prefix . 'my_strings';

        // Fetch all rows; filter in PHP for cross-MySQL-version JSON compatibility.
        $all_rows = $wpdb->get_results(
            "SELECT `id`, `string_key`, `translations` FROM `{$table}` ORDER BY `id` ASC"
        );

        // Only rows that (a) lack the target-lang translation AND (b) have a source text.
        $missing = [];
        foreach ( $all_rows as $row ) {
            $t           = json_decode( $row->translations, true ) ?: [];
            $source_text = trim( $t[ $default_lang ] ?? '' );
            $target_text = trim( $t[ $target_lang ]  ?? '' );
            if ( $target_text === '' && $source_text !== '' ) {
                $missing[] = [ 'row' => $row, 'translations' => $t, 'source' => $source_text ];
            }
        }

        $total_missing = count( $missing );

        if ( $total_missing === 0 ) {
            wp_send_json_success( [
                'translated'    => 0,
                'total_missing' => 0,
                'remaining'     => 0,
                'done'          => true,
            ] );
        }

        // Slice exactly one batch from the top of the missing list.
        $batch            = array_slice( $missing, 0, $batch_size );
        $translated_count = 0;

        foreach ( $batch as $item ) {
            $translated = MML_Auto_Translate::translate( $item['source'], $default_lang, $target_lang );

            // Preserve source text as fallback — still store even if identical to avoid
            // re-picking the row on the next batch call.
            $t                   = $item['translations'];
            $t[ $target_lang ]   = $translated;
            MML_Strings::update( (int) $item['row']->id, wp_json_encode( $t, JSON_UNESCAPED_UNICODE ) );
            $translated_count++;

            usleep( 150000 ); // 150 ms between requests to respect rate limits.
        }

        // Recalculate remaining AFTER this batch (DB was just updated).
        $remaining = max( 0, $total_missing - count( $batch ) );

        wp_send_json_success( [
            'translated'    => $translated_count,
            'batch_size'    => count( $batch ),
            'total_missing' => $total_missing,
            'remaining'     => $remaining,
            'done'          => ( $remaining === 0 ),
        ] );
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function get_lang_by_id( int $id, array $languages ): ?object {
        foreach ( $languages as $lang ) {
            if ( (int) $lang->id === $id ) {
                return $lang;
            }
        }
        return null;
    }
}
