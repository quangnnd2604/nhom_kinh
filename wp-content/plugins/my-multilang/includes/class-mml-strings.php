<?php
/**
 * CRUD for wp_my_strings — global translatable shortcode strings.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MML_Strings {

    /** @var array|null Static cache: all rows for current request */
    private static ?array $cache = null;

    /** @var array Decoded translation values keyed [key][lang] */
    private static array $value_cache = [];

    /**
     * Flush in-memory caches (call after bulk mutations like restore or import).
     */
    public static function clear_cache(): void {
        self::$cache       = null;
        self::$value_cache = [];
    }

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'my_strings';
    }

    /**
     * Get all string rows.
     *
     * @return array Array of stdClass: id, string_key, translations (JSON string), updated_at
     */
    public static function get_all(): array {
        if ( self::$cache !== null ) {
            return self::$cache;
        }
        global $wpdb;
        $rows = $wpdb->get_results( 'SELECT * FROM `' . self::table() . '` ORDER BY `string_key` ASC' );
        self::$cache = $rows ?: [];
        return self::$cache;
    }

    /**
     * Get the translated value of a string key for a given language.
     * Falls back to default language, then to the key itself.
     *
     * @param string $key  e.g. 'gioi_thieu'
     * @param string $lang e.g. 'en'
     * @return string
     */
    public static function get_value( string $key, string $lang ): string {
        if ( isset( self::$value_cache[ $key ][ $lang ] ) ) {
            return self::$value_cache[ $key ][ $lang ];
        }

        // Build value cache from all rows on first access
        if ( empty( self::$value_cache ) ) {
            foreach ( self::get_all() as $row ) {
                $translations = json_decode( $row->translations, true ) ?: [];
                self::$value_cache[ $row->string_key ] = $translations;
            }
        }

        $translations = self::$value_cache[ $key ] ?? [];

        // Priority: requested lang → default lang → key name
        if ( isset( $translations[ $lang ] ) && $translations[ $lang ] !== '' ) {
            return $translations[ $lang ];
        }
        $default = MML_Languages::get_default_code();
        if ( isset( $translations[ $default ] ) && $translations[ $default ] !== '' ) {
            return $translations[ $default ];
        }
        return '[' . $key . ']';
    }

    /**
     * Get all string keys as a simple array.
     *
     * @return string[]
     */
    public static function get_all_keys(): array {
        return array_column( self::get_all(), 'string_key' );
    }

    /**
     * Insert a new string key with empty translations.
     *
     * @param string $key
     * @param bool   $is_autoscanned  Pass true when inserting via Smart Scan.
     * @return int|false
     */
    public static function insert( string $key, bool $is_autoscanned = false ) {
        global $wpdb;
        $key = preg_replace( '/[^a-z0-9_]/', '', strtolower( $key ) );
        if ( empty( $key ) ) {
            return false;
        }
        $result = $wpdb->insert(
            self::table(),
            [
                'string_key'     => $key,
                'translations'   => '{}',
                'is_autoscanned' => $is_autoscanned ? 1 : 0,
            ],
            [ '%s', '%s', '%d' ]
        );
        self::$cache       = null;
        self::$value_cache = [];
        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Update the translations JSON for a row.
     *
     * @param int    $id
     * @param string $json_string  Valid JSON string.
     * @return bool
     */
    public static function update( int $id, string $json_string ): bool {
        global $wpdb;
        $result = $wpdb->update(
            self::table(),
            [ 'translations' => $json_string ],
            [ 'id' => $id ],
            [ '%s' ],
            [ '%d' ]
        );
        self::$cache       = null;
        self::$value_cache = [];
        return false !== $result;
    }

    /**
     * Delete a string row by ID.
     *
     * @param int $id
     * @return bool
     */
    public static function delete( int $id ): bool {
        global $wpdb;
        $result = $wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
        self::$cache       = null;
        self::$value_cache = [];
        return (bool) $result;
    }

    /**
     * Get a string row's ID by its key.
     *
     * @param string $key
     * @return int|null
     */
    public static function get_id_by_key( string $key ): ?int {
        foreach ( self::get_all() as $row ) {
            if ( $row->string_key === $key ) {
                return (int) $row->id;
            }
        }
        return null;
    }

    /**
     * Insert or get the ID of a string key (manual add — is_autoscanned = 0).
     * Used by Smart Scan to register new keys without duplicating.
     *
     * @param string $key
     * @param string $vi_text  Default language (VI) value.
     * @return int|false
     */
    public static function upsert( string $key, string $vi_text ) {
        return self::upsert_with_flag( $key, $vi_text, false );
    }

    /**
     * Insert or get the ID of a string key registered by the auto-scanner
     * (is_autoscanned = 1). Does NOT overwrite the flag if the key already
     * exists — the existing row keeps whatever flag it had.
     *
     * @param string $key
     * @param string $vi_text
     * @return int|false
     */
    public static function upsert_autoscanned( string $key, string $vi_text ) {
        return self::upsert_with_flag( $key, $vi_text, true );
    }

    /**
     * Shared insert-or-fetch logic.
     *
     * @param string $key
     * @param string $vi_text
     * @param bool   $is_autoscanned
     * @return int|false
     */
    private static function upsert_with_flag( string $key, string $vi_text, bool $is_autoscanned ) {
        $existing_id = self::get_id_by_key( $key );
        if ( $existing_id ) {
            return $existing_id;
        }
        $new_id = self::insert( $key, $is_autoscanned );
        if ( ! $new_id ) {
            return false;
        }
        $default_lang = MML_Languages::get_default_code();
        self::update( $new_id, wp_json_encode( [ $default_lang => $vi_text ], JSON_UNESCAPED_UNICODE ) );
        return $new_id;
    }

    /**
     * Check if a string key was created by the auto-scanner.
     *
     * @param string $key
     * @return bool
     */
    public static function is_autoscanned( string $key ): bool {
        foreach ( self::get_all() as $row ) {
            if ( $row->string_key === $key ) {
                return (bool) ( $row->is_autoscanned ?? 0 );
            }
        }
        return false;
    }
}
