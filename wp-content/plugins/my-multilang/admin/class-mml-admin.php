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
        add_action( 'wp_ajax_mml_add_string',    [ $this, 'ajax_add_string' ] );
        add_action( 'wp_ajax_mml_delete_string', [ $this, 'ajax_delete_string' ] );
        add_action( 'wp_ajax_mml_clone_object',  [ $this, 'ajax_clone_object' ] );
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
        wp_localize_script( 'mml-admin', 'mmlAdmin', [
            'ajaxurl'              => admin_url( 'admin-ajax.php' ),
            'adminurl'             => admin_url(),
            'nonce'                => wp_create_nonce( 'mml_admin_nonce' ),
            'confirmDelete'        => __( 'Are you sure you want to clone this content?', 'my-multilang' ),
            'confirmDeleteStr'     => __( 'Delete this string key and all its translations?', 'my-multilang' ),
            'confirmRestore'       => __( 'Restore will revert post content and remove all registered string keys for this session. Continue?', 'my-multilang' ),
            'confirmDiscard'       => __( 'Discard the backup without restoring? This cannot be undone.', 'my-multilang' ),
        ] );
    }

    // ── Page: Language Manager ─────────────────────────────────────────────

    public function page_languages(): void {
        $languages   = MML_Languages::get_all();
        $edit_id     = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
        $edit_lang   = $edit_id ? $this->get_lang_by_id( $edit_id, $languages ) : null;
        $saved_msg   = isset( $_GET['saved'] ) ? esc_html__( 'Language saved.', 'my-multilang' ) : '';
        $deleted_msg = isset( $_GET['deleted'] ) ? esc_html__( 'Language deleted.', 'my-multilang' ) : '';
        $error_msg   = isset( $_GET['error'] ) ? esc_html( urldecode( $_GET['error'] ) ) : ''; // phpcs:ignore

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
            'name'             => sanitize_text_field( wp_unslash( $_POST['lang_name'] ?? '' ) ),
            'code'             => sanitize_key( wp_unslash( $_POST['lang_code'] ?? '' ) ),
            'flag_id'          => absint( $_POST['flag_id'] ?? 0 ),
            'is_default'       => ! empty( $_POST['is_default'] ) ? 1 : 0,
            'sort_order'       => absint( $_POST['sort_order'] ?? 0 ),
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
        }

        wp_redirect( add_query_arg( 'saved', '1', admin_url( 'admin.php?page=mml-languages' ) ) );
        exit;
    }

    /**
     * Handle language deletion.
     */
    public function handle_delete_language(): void {
        check_admin_referer( 'mml_delete_language' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'my-multilang' ) );
        }

        $id     = absint( $_GET['lang_id'] ?? 0 );
        $result = MML_Languages::delete( $id );

        if ( is_wp_error( $result ) ) {
            wp_redirect( add_query_arg( 'error', urlencode( $result->get_error_message() ), admin_url( 'admin.php?page=mml-languages' ) ) );
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
