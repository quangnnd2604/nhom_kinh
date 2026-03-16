<?php
/**
 * CRUD for wp_my_translations — links posts/terms across languages via group_id (UUID).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MML_Translations {

    public static function init(): void {
        add_action( 'delete_post',     [ self::class, 'handle_delete_post' ] );
        add_action( 'pre_delete_term', [ self::class, 'handle_delete_term' ], 10, 2 );
    }

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'my_translations';
    }

    // ── Linking ────────────────────────────────────────────────────────────

    /**
     * Link a source post and its translated copy under the same group_id.
     * If the source already belongs to a group, the new post joins that group.
     * Otherwise a new group is created for both.
     *
     * @param int    $source_id
     * @param int    $new_id
     * @param string $target_lang
     * @param string $source_lang  Defaults to MML_LANG (the active language).
     */
    public static function link_posts( int $source_id, int $new_id, string $target_lang, string $source_lang = '' ): void {
        if ( empty( $source_lang ) ) {
            // Always fall back to the configured default language code.
            // Using MML_LANG here was unsafe: admin cookie could set MML_LANG to
            // a non-default value (e.g. 'zh-cn'), causing the VI source post to be
            // registered with the wrong lang_code and then skipped by Golden Source
            // guards in subsequent discover operations.
            $source_lang = MML_Languages::get_default_code();
        }

        $group_id = self::get_group_id( $source_id, 'post' );
        if ( ! $group_id ) {
            $group_id = wp_generate_uuid4();
            // Register the source with its language
            self::insert_row( $group_id, 'post', $source_id, $source_lang );
        }

        // Register the new translation
        self::insert_row( $group_id, 'post', $new_id, $target_lang );
    }

    /**
     * Link a source term and its translated copy.
     */
    public static function link_terms( int $source_term_id, int $new_term_id, string $target_lang, string $source_lang = '' ): void {
        if ( empty( $source_lang ) ) {
            // Always fall back to the configured default language code (see link_posts note).
            $source_lang = MML_Languages::get_default_code();
        }

        $group_id = self::get_group_id( $source_term_id, 'term' );
        if ( ! $group_id ) {
            $group_id = wp_generate_uuid4();
            self::insert_row( $group_id, 'term', $source_term_id, $source_lang );
        }

        self::insert_row( $group_id, 'term', $new_term_id, $target_lang );
    }

    // ── Deletion (Cleanup) ─────────────────────────────────────────────────

    /**
     * Fired when a post is permanently deleted (delete_post).
     */
    public static function handle_delete_post( $post_id ): void {
        global $wpdb;
        $wpdb->delete(
            self::table(),
            [ 'object_type' => 'post', 'object_id' => $post_id ],
            [ '%s', '%d' ]
        );
    }

    /**
     * Fired when a term is permanently deleted (pre_delete_term).
     */
    public static function handle_delete_term( $term_id, $taxonomy ): void {
        global $wpdb;
        $wpdb->delete(
            self::table(),
            [ 'object_type' => 'term', 'object_id' => $term_id ],
            [ '%s', '%d' ]
        );
    }

    // ── Getters ────────────────────────────────────────────────────────────

    /**
     * Get the group_id for a given object.
     *
     * @param int    $object_id
     * @param string $type  'post' | 'term'
     * @return string|null UUID or null if not registered.
     */
    public static function get_group_id( int $object_id, string $type ): ?string {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT `group_id` FROM `{$table}` WHERE `object_type` = %s AND `object_id` = %d LIMIT 1",
                $type,
                $object_id
            )
        );
    }

    /**
     * Get all variants in a group: array of [ lang_code => object_id ].
     *
     * @param string $group_id
     * @return array
     */
    public static function get_all_in_group( string $group_id ): array {
        global $wpdb;
        $table = self::table();
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT `lang_code`, `object_id` FROM `{$table}` WHERE `group_id` = %s",
                $group_id
            )
        );

        $map = [];
        foreach ( $rows as $row ) {
            $map[ $row->lang_code ] = (int) $row->object_id;
        }
        return $map;
    }

    /**
     * Get the translated object ID for a given source object + target language.
     *
     * @param int    $object_id
     * @param string $target_lang
     * @param string $type  'post' | 'term'
     * @return int|null
     */
    public static function get_translated_id( int $object_id, string $target_lang, string $type = 'post' ): ?int {
        $group_id = self::get_group_id( $object_id, $type );
        if ( ! $group_id ) {
            return null;
        }
        $variants = self::get_all_in_group( $group_id );
        return isset( $variants[ $target_lang ] ) ? (int) $variants[ $target_lang ] : null;
    }

    /**
     * Get the language code registered for a specific object.
     *
     * @param int    $object_id
     * @param string $type
     * @return string|null
     */
    public static function get_lang_for_object( int $object_id, string $type = 'post' ): ?string {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT `lang_code` FROM `{$table}` WHERE `object_type` = %s AND `object_id` = %d LIMIT 1",
                $type,
                $object_id
            )
        );
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private static function insert_row( string $group_id, string $type, int $object_id, string $lang_code ): void {
        global $wpdb;
        // INSERT IGNORE to avoid duplicates on the UNIQUE KEY (object_type, object_id)
        $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO `" . self::table() . "` (`group_id`, `object_type`, `object_id`, `lang_code`) VALUES (%s, %s, %d, %s)",
                $group_id,
                $type,
                $object_id,
                $lang_code
            )
        );
    }
}
