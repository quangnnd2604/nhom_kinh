<?php
/**
 * CRUD for wp_my_languages table.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MML_Languages {

    /** @var array|null Static cache for current request */
    private static ?array $cache = null;

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'my_languages';
    }

    /**
     * Get all languages, ordered by sort_order.
     *
     * @return array Array of stdClass rows.
     */
    public static function get_all(): array {
        if ( self::$cache !== null ) {
            return self::$cache;
        }

        global $wpdb;
        $results = $wpdb->get_results(
            'SELECT * FROM `' . self::table() . '` ORDER BY `sort_order` ASC, `id` ASC'
        );
        self::$cache = $results ?: [];
        return self::$cache;
    }

    /**
     * Get active language codes as a plain array, e.g. ['vi', 'en', 'ru'].
     */
    public static function get_active_codes(): array {
        return array_column( self::get_all(), 'code' );
    }

    /**
     * Get the default language code (e.g. 'vi').
     */
    public static function get_default_code(): string {
        foreach ( self::get_all() as $lang ) {
            if ( $lang->is_default ) {
                return $lang->code;
            }
        }
        return 'vi'; // ultimate fallback
    }

    /**
     * Get a single language by code.
     */
    public static function get_by_code( string $code ): ?object {
        foreach ( self::get_all() as $lang ) {
            if ( $lang->code === $code ) {
                return $lang;
            }
        }
        return null;
    }

    /**
     * Insert a new language.
     *
     * @param array $data { name, code, flag_id, is_default, sort_order }
     * @return int|false New row ID or false on failure.
     */
    public static function insert( array $data ) {
        global $wpdb;

        // Only one default allowed
        if ( ! empty( $data['is_default'] ) ) {
            $wpdb->update( self::table(), [ 'is_default' => 0 ], [ 'is_default' => 1 ], [ '%d' ], [ '%d' ] );
        }

        $result = $wpdb->insert(
            self::table(),
            [
                'name'             => sanitize_text_field( $data['name'] ),
                'code'             => sanitize_key( $data['code'] ),
                'flag_id'          => absint( $data['flag_id'] ?? 0 ),
                'is_default'       => empty( $data['is_default'] ) ? 0 : 1,
                'sort_order'       => absint( $data['sort_order'] ?? 0 ),
                'use_english_slug' => empty( $data['use_english_slug'] ) ? 0 : 1,
            ],
            [ '%s', '%s', '%d', '%d', '%d', '%d' ]
        );

        self::$cache = null; // invalidate cache
        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Update an existing language row.
     *
     * @param int   $id
     * @param array $data
     * @return bool
     */
    public static function update( int $id, array $data ): bool {
        global $wpdb;

        if ( ! empty( $data['is_default'] ) ) {
            $wpdb->update( self::table(), [ 'is_default' => 0 ], [ 'is_default' => 1 ], [ '%d' ], [ '%d' ] );
        }

        $result = $wpdb->update(
            self::table(),
            [
                'name'             => sanitize_text_field( $data['name'] ),
                'code'             => sanitize_key( $data['code'] ),
                'flag_id'          => absint( $data['flag_id'] ?? 0 ),
                'is_default'       => empty( $data['is_default'] ) ? 0 : 1,
                'sort_order'       => absint( $data['sort_order'] ?? 0 ),
                'use_english_slug' => empty( $data['use_english_slug'] ) ? 0 : 1,
            ],
            [ 'id' => $id ],
            [ '%s', '%s', '%d', '%d', '%d', '%d' ],
            [ '%d' ]
        );

        self::$cache = null;
        return false !== $result;
    }

    /**
     * Delete a language (blocks deleting the default language).
     *
     * @param int $id
     * @return bool|WP_Error
     */
    public static function delete( int $id ) {
        global $wpdb;

        $lang = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . self::table() . '` WHERE id = %d', $id ) );
        if ( ! $lang ) {
            return new WP_Error( 'not_found', __( 'Language not found.', 'my-multilang' ) );
        }
        if ( $lang->is_default ) {
            return new WP_Error( 'is_default', __( 'Cannot delete the default language.', 'my-multilang' ) );
        }

        $result = $wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
        self::$cache = null;
        return (bool) $result;
    }
}
