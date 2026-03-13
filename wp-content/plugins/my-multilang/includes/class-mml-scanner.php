<?php
/**
 * Smart Scan engine — scans wp_options for hardcoded Vietnamese strings.
 *
 * Target option groups: widget_*, woocommerce_*, theme_mods_*, sidebars_widgets.
 *
 * NO wp_options rows are ever modified. Approved strings are registered in
 * wp_my_strings and swapped on the frontend via the gettext filter in MML_Smart_Scan.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MML_Scanner {

    // ── Vietnamese transliteration map ────────────────────────────────────

    private static array $vi_map = [
        // Lowercase
        'à'=>'a','á'=>'a','ả'=>'a','ã'=>'a','ạ'=>'a',
        'ă'=>'a','ắ'=>'a','ặ'=>'a','ằ'=>'a','ẳ'=>'a','ẵ'=>'a',
        'â'=>'a','ấ'=>'a','ậ'=>'a','ầ'=>'a','ẩ'=>'a','ẫ'=>'a',
        'è'=>'e','é'=>'e','ẻ'=>'e','ẽ'=>'e','ẹ'=>'e',
        'ê'=>'e','ề'=>'e','ế'=>'e','ể'=>'e','ễ'=>'e','ệ'=>'e',
        'ì'=>'i','í'=>'i','ỉ'=>'i','ĩ'=>'i','ị'=>'i',
        'ò'=>'o','ó'=>'o','ỏ'=>'o','õ'=>'o','ọ'=>'o',
        'ô'=>'o','ồ'=>'o','ố'=>'o','ổ'=>'o','ỗ'=>'o','ộ'=>'o',
        'ơ'=>'o','ờ'=>'o','ớ'=>'o','ở'=>'o','ỡ'=>'o','ợ'=>'o',
        'ù'=>'u','ú'=>'u','ủ'=>'u','ũ'=>'u','ụ'=>'u',
        'ư'=>'u','ừ'=>'u','ứ'=>'u','ử'=>'u','ữ'=>'u','ự'=>'u',
        'ỳ'=>'y','ý'=>'y','ỷ'=>'y','ỹ'=>'y','ỵ'=>'y',
        'đ'=>'d',
        // Uppercase
        'À'=>'A','Á'=>'A','Ả'=>'A','Ã'=>'A','Ạ'=>'A',
        'Ă'=>'A','Ắ'=>'A','Ặ'=>'A','Ằ'=>'A','Ẳ'=>'A','Ẵ'=>'A',
        'Â'=>'A','Ấ'=>'A','Ậ'=>'A','Ầ'=>'A','Ẩ'=>'A','Ẫ'=>'A',
        'È'=>'E','É'=>'E','Ẻ'=>'E','Ẽ'=>'E','Ẹ'=>'E',
        'Ê'=>'E','Ề'=>'E','Ế'=>'E','Ể'=>'E','Ễ'=>'E','Ệ'=>'E',
        'Ì'=>'I','Í'=>'I','Ỉ'=>'I','Ĩ'=>'I','Ị'=>'I',
        'Ò'=>'O','Ó'=>'O','Ỏ'=>'O','Õ'=>'O','Ọ'=>'O',
        'Ô'=>'O','Ồ'=>'O','Ố'=>'O','Ổ'=>'O','Ỗ'=>'O','Ộ'=>'O',
        'Ơ'=>'O','Ờ'=>'O','Ớ'=>'O','Ở'=>'O','Ỡ'=>'O','Ợ'=>'O',
        'Ù'=>'U','Ú'=>'U','Ủ'=>'U','Ũ'=>'U','Ụ'=>'U',
        'Ư'=>'U','Ừ'=>'U','Ứ'=>'U','Ử'=>'U','Ữ'=>'U','Ự'=>'U',
        'Ỳ'=>'Y','Ý'=>'Y','Ỷ'=>'Y','Ỹ'=>'Y','Ỵ'=>'Y',
        'Đ'=>'D',
    ];

    // ── Public API ─────────────────────────────────────────────────────────

    /**
     * Count total scannable wp_options rows (used for the progress bar).
     */
    public static function count_options(): int {
        global $wpdb;
        return (int) $wpdb->get_var( // phpcs:ignore
            "SELECT COUNT(*) FROM {$wpdb->options}
             WHERE option_name LIKE 'widget\_%' ESCAPE '\\\\'
                OR option_name LIKE 'woocommerce\_%' ESCAPE '\\\\'
                OR option_name LIKE 'theme\_mods\_%' ESCAPE '\\\\'
                OR option_name = 'sidebars_widgets'"
        );
    }

    /**
     * Scan a batch of wp_options rows and return found Vietnamese strings.
     *
     * @param int $offset  Starting offset into the option-names list.
     * @param int $limit   How many option keys to process per batch.
     * @return array { items: array, option_count: int }
     */
    public static function scan_batch( int $offset, int $limit = 20 ): array {
        $option_names = self::get_option_names();
        $batch        = array_slice( $option_names, $offset, $limit );

        $items = [];

        foreach ( $batch as $option_name ) {
            $value = get_option( $option_name );
            if ( false === $value || null === $value ) {
                continue;
            }

            $strings = self::extract_from_value( $value );
            foreach ( $strings as $text ) {
                $items[] = [
                    'text'        => $text,
                    'key'         => self::generate_key( $text ),
                    'option_name' => $option_name,
                ];
            }
        }

        // Exclude strings already registered in wp_my_strings (no redundant approvals).
        $registered = self::get_registered_texts();
        $items = array_values( array_filter( $items, fn( $i ) => ! isset( $registered[ $i['text'] ] ) ) );

        return [
            'items'        => $items,
            'option_count' => count( $batch ),
        ];
    }

    /**
     * Walk an option value (string / array / object) recursively and collect
     * unique Vietnamese strings.
     *
     * @param  mixed $value  The option value (already unserialized by get_option).
     * @return string[]
     */
    public static function extract_from_value( $value ): array {
        $found = [];
        self::walk_value( $value, $found, 0 );
        return array_values( array_unique( $found ) );
    }

    /**
     * Recursive walker — populates &$found with Vietnamese text nodes.
     */
    private static function walk_value( $value, array &$found, int $depth ): void {
        if ( $depth > 8 ) {
            return;
        }

        if ( is_string( $value ) ) {
            $len = mb_strlen( $value );
            if ( $len < 3 || $len > 500 ) {
                return;
            }

            $cleaned = trim( preg_replace( '/\s+/', ' ', $value ) );

            // Skip URLs, HTML blocks, shortcodes
            if ( preg_match( '/^https?:\/\//i', $cleaned ) ) { return; }
            if ( strpos( $cleaned, '<' ) !== false && strpos( $cleaned, '>' ) !== false ) { return; }
            if ( $cleaned[0] === '[' ) { return; }

            if ( self::contains_vietnamese( $cleaned ) ) {
                $found[] = $cleaned;
            }
            return;
        }

        if ( is_array( $value ) ) {
            static $skip_keys = [
                '_multiwidget', 'class', 'id', 'url', 'link', 'image',
                'file', 'path', 'src', 'href', 'icon', 'color', 'type',
            ];
            foreach ( $value as $k => $v ) {
                if ( is_string( $k ) && in_array( $k, $skip_keys, true ) ) {
                    continue;
                }
                self::walk_value( $v, $found, $depth + 1 );
            }
            return;
        }

        if ( is_object( $value ) ) {
            foreach ( (array) $value as $v ) {
                self::walk_value( $v, $found, $depth + 1 );
            }
        }
    }

    /**
     * Generate a safe shortcode key from a Vietnamese string.
     * Rule: first 3 words transliterated to ASCII + _ + 3-char random suffix.
     * E.g. "Danh mục sản phẩm" → "danh_muc_san_a1b"
     *
     * @param string $text
     * @return string
     */
    public static function generate_key( string $text ): string {
        $ascii = strtr( $text, self::$vi_map );

        // Split into individual words (skip punctuation-only tokens)
        $words = preg_split( '/\s+/', trim( $ascii ) );
        $words = array_values( array_filter( $words, fn( $w ) => preg_match( '/[a-zA-Z0-9]/', $w ) ) );
        $words = array_slice( $words, 0, 3 );

        $base = implode( '_', $words );
        $base = preg_replace( '/[^a-z0-9_]/i', '', strtolower( $base ) );
        $base = preg_replace( '/_+/', '_', $base );
        $base = trim( $base, '_' );

        if ( empty( $base ) ) {
            $base = 'chuoi';
        }

        // 3-char alphanumeric suffix (a-z + 0-9 = 36 chars)
        $chars  = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $suffix = '';
        for ( $i = 0; $i < 3; $i++ ) {
            $suffix .= $chars[ random_int( 0, 35 ) ];
        }

        return $base . '_' . $suffix;
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Check whether a string contains at least one Vietnamese-specific character.
     * Covers Latin Extended Additional (U+1EA0–U+1EF9) used exclusively in Vietnamese.
     */
    public static function contains_vietnamese( string $text ): bool {
        return (bool) preg_match( '/[\x{1EA0}-\x{1EF9}]/u', $text );
    }

    /**
     * Return a hash-set of all default-language texts already registered in wp_my_strings.
     * Used to filter scan results so already-registered strings are never returned again.
     * Built once per request and cached statically.
     *
     * @return array<string, true>
     */
    private static function get_registered_texts(): array {
        static $cache = null;
        if ( null === $cache ) {
            $cache   = [];
            $default = MML_Languages::get_default_code();
            foreach ( MML_Strings::get_all() as $row ) {
                $t = json_decode( $row->translations, true );
                if ( is_array( $t ) && isset( $t[ $default ] ) && $t[ $default ] !== '' ) {
                    $cache[ $t[ $default ] ] = true;
                }
            }
        }
        return $cache;
    }

    /**
     * Return list of option_name values matching the target prefixes.
     *
     * @return string[]
     */
    private static function get_option_names(): array {
        global $wpdb;
        $rows = $wpdb->get_results( // phpcs:ignore
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name LIKE 'widget\_%' ESCAPE '\\\\'
                OR option_name LIKE 'woocommerce\_%' ESCAPE '\\\\'
                OR option_name LIKE 'theme\_mods\_%' ESCAPE '\\\\'
                OR option_name = 'sidebars_widgets'
             ORDER BY option_id ASC
             LIMIT 500"
        );
        return array_column( $rows ?: [], 'option_name' );
    }

    // ── UX Blocks (Flatsome `blocks` post type) ────────────────────────────

    /**
     * Count published UX Blocks (Flatsome `blocks` post type).
     */
    public static function count_ux_blocks(): int {
        global $wpdb;
        return (int) $wpdb->get_var( // phpcs:ignore
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'blocks' AND post_status = 'publish'"
        );
    }

    /**
     * Scan a batch of UX Block post_content rows for Vietnamese strings.
     *
     * @param int $offset  Block index offset.
     * @param int $limit   How many blocks to process per batch.
     * @return array { items: array, block_count: int }
     */
    public static function scan_ux_blocks_batch( int $offset, int $limit = 10 ): array {
        global $wpdb;

        $posts = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
            "SELECT ID, post_title, post_content
             FROM {$wpdb->posts}
             WHERE post_type = 'blocks' AND post_status = 'publish'
             ORDER BY ID ASC
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        ) );

        $items = [];
        foreach ( $posts as $post ) {
            $strings = self::extract_from_content( $post->post_content );
            foreach ( $strings as $text ) {
                $items[] = [
                    'text'        => $text,
                    'key'         => self::generate_key( $text ),
                    'post_id'     => (int) $post->ID,
                    'post_title'  => $post->post_title,
                    'source_type' => 'ux_block',
                    'option_name' => '',
                ];
            }
        }

        // Exclude strings already registered in wp_my_strings.
        $registered = self::get_registered_texts();
        $items = array_values( array_filter( $items, fn( $i ) => ! isset( $registered[ $i['text'] ] ) ) );

        return [
            'items'       => $items,
            'block_count' => count( $posts ),
        ];
    }

    /**
     * Extract Vietnamese strings from raw post_content (HTML + Flatsome shortcodes).
     *
     * Strategy:
     *  1. Strip shortcode tags (e.g. [ux_banner ...]) but keep inner content.
     *  2. Strip HTML tags via wp_strip_all_tags().
     *  3. Split by line breaks and collect Vietnamese text segments (3–500 chars).
     *
     * @param string $content Raw post_content.
     * @return string[]
     */
    public static function extract_from_content( string $content ): array {
        // Remove shortcode opening/closing tags but preserve their inner text
        $text = preg_replace( '/\[\/?\w[^\]]*\]/', ' ', $content );
        // Strip HTML tags
        $text = wp_strip_all_tags( $text );
        // Split into segments on newlines/tabs
        $segments = preg_split( '/[\n\r\t]+/', $text );

        $found = [];
        foreach ( $segments as $seg ) {
            $seg = trim( preg_replace( '/\s+/', ' ', $seg ) );
            $len = mb_strlen( $seg );
            if ( $len < 3 || $len > 500 ) {
                continue;
            }
            if ( self::contains_vietnamese( $seg ) ) {
                $found[] = $seg;
            }
        }

        return array_values( array_unique( $found ) );
    }

    // ── WooCommerce Gettext Scanner ─────────────────────────────────────────

    /**
     * Count how many curated WooCommerce strings currently return a Vietnamese
     * translation via the active .mo file.
     */
    public static function count_woocommerce_strings(): int {
        return count( self::get_woocommerce_vi_strings() );
    }

    /**
     * Scan a batch of curated WooCommerce gettext strings.
     *
     * @param int $offset
     * @param int $limit
     * @return array { items: array, string_count: int }
     */
    public static function scan_woocommerce_batch( int $offset, int $limit = 30 ): array {
        $all   = self::get_woocommerce_vi_strings();
        $batch = array_slice( $all, $offset, $limit );
        $items = [];
        foreach ( $batch as $entry ) {
            $items[] = [
                'text'        => $entry['vi'],
                'key'         => self::generate_key( $entry['vi'] ),
                'option_name' => 'woocommerce: ' . $entry['en'],
                'source_type' => 'gettext',
                'post_id'     => 0,
            ];
        }
        // Exclude strings already registered in wp_my_strings.
        $registered = self::get_registered_texts();
        $items = array_values( array_filter( $items, fn( $i ) => ! isset( $registered[ $i['text'] ] ) ) );

        return [
            'items'        => $items,
            'string_count' => count( $batch ),
        ];
    }

    /**
     * Build the list of WooCommerce strings that actually translate to Vietnamese.
     * Calls __() on a curated list — works regardless of which VI .mo file is loaded.
     *
     * @return array[]  Each element: { en: string, vi: string }
     */
    private static function get_woocommerce_vi_strings(): array {
        static $cached = null;
        if ( null !== $cached ) {
            return $cached;
        }

        $curated = [
            'Sort by',
            'Default sorting',
            'Sort by popularity',
            'Sort by average rating',
            'Sort by latest',
            'Sort by price: low to high',
            'Sort by price: high to low',
            'Search products&hellip;',
            'Filter by price',
            'Filter',
            'Reset',
            'Apply',
            'Add to cart',
            'View cart',
            'Checkout',
            'My account',
            'Shop',
            'Sale!',
            'Out of stock',
            'Products',
            'Related products',
            'Return to shop',
            'Continue shopping',
            'No products in the cart.',
            'Showing all %d results',
            'Showing the single result',
            'Showing %1$d\u2013%2$d of %3$d results',
            // WooCommerce notices (static)
            'Your cart is currently empty.',
            'Cart updated.',
            'Coupon code applied successfully.',
            'Coupon code removed successfully.',
            'Please enter a coupon code.',
            'Sorry, this product cannot be purchased.',
            'You cannot add that amount to the cart.',
            'Checkout is not available whilst your cart is empty.',
            'Please fill in your details above to see available shipping options.',
            // WooCommerce notices (printf — captured by the printf-pattern matcher)
            '%s has been added to your cart.',
            '%s has been removed from your cart.',
            '%s has been removed. %s',
            'Product categories',
            'Search results',
            'Price',
            'Category',
            'Tags',
            'Reviews',
            'Description',
            'Additional information',
        ];

        $found = [];
        foreach ( array_unique( $curated ) as $en ) {
            $vi = __( $en, 'woocommerce' );
            if ( $vi !== $en && self::contains_vietnamese( $vi ) ) {
                $found[] = [ 'en' => $en, 'vi' => $vi ];
            }
        }

        $cached = $found;
        return $cached;
    }

    // ── Orphaned [my_trans] Shortcode Scanner ──────────────────────────────

    /**
     * Count all published posts that contain at least one orphaned [my_trans] shortcode.
     * "Orphaned" = the key attribute is no longer present in wp_my_strings.
     */
    public static function count_orphaned_my_trans(): int {
        global $wpdb;
        $rows = $wpdb->get_results( // phpcs:ignore
            "SELECT post_content FROM `{$wpdb->posts}`
             WHERE post_status = 'publish'
               AND post_content LIKE '%[my_trans%'"
        ) ?: [];

        $registered = array_flip( MML_Strings::get_all_keys() );
        $count = 0;
        foreach ( $rows as $row ) {
            if ( ! empty( self::extract_orphaned_my_trans( $row->post_content, $registered ) ) ) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Scan a batch of published posts for orphaned [my_trans] shortcodes.
     *
     * Returns items suitable for passing back through the existing
     * "Process Approved Items" workflow (ajax_process endpoint):
     *   text        = original attribute value (the Vietnamese fallback text)
     *   key         = the existing shortcode key (preserved, not re-generated)
     *   post_id     = 0 (no post_content modification needed — shortcode stays)
     *   source_type = 'orphaned' (treated as options-only registration)
     *
     * @param int $offset  Offset into the set of posts with [my_trans] content.
     * @param int $limit   Posts to examine per batch.
     * @return array { items: array, post_count: int }
     */
    public static function scan_orphaned_batch( int $offset, int $limit = 10 ): array {
        global $wpdb;

        $posts = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
            "SELECT ID, post_title, post_content
             FROM `{$wpdb->posts}`
             WHERE post_status = 'publish'
               AND post_content LIKE '%[my_trans%'
             ORDER BY ID ASC
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        ) ) ?: [];

        $registered = array_flip( MML_Strings::get_all_keys() );
        $items      = [];

        foreach ( $posts as $post ) {
            $orphans = self::extract_orphaned_my_trans( $post->post_content, $registered );
            foreach ( $orphans as $orphan ) {
                $items[] = [
                    'text'        => $orphan['original'],
                    'key'         => $orphan['key'],
                    'post_id'     => 0,          // do NOT re-run str_replace
                    'post_title'  => $post->post_title,
                    'source_type' => 'orphaned', // treated as options-only by ajax_process
                    'option_name' => 'orphaned shortcode in post ' . (int) $post->ID,
                ];
            }
        }

        // Deduplicate by key (same [my_trans] key may appear in multiple posts).
        $seen  = [];
        $items = array_values( array_filter( $items, function ( $item ) use ( &$seen ) {
            if ( isset( $seen[ $item['key'] ] ) ) {
                return false;
            }
            $seen[ $item['key'] ] = true;
            return true;
        } ) );

        return [
            'items'      => $items,
            'post_count' => count( $posts ),
        ];
    }

    /**
     * Parse all [my_trans key="..." original="..."] shortcodes from $content
     * and return those whose key is NOT in $registered_keys.
     *
     * @param string             $content         Raw post_content.
     * @param array<string,true> $registered_keys Keys already in wp_my_strings (array_flip result).
     * @return array[]  Each element: { key: string, original: string }
     */
    private static function extract_orphaned_my_trans( string $content, array $registered_keys ): array {
        $found = [];
        if ( false === strpos( $content, '[my_trans' ) ) {
            return $found;
        }

        preg_match_all( '/\[my_trans\b([^\]]*)\]/', $content, $matches, PREG_SET_ORDER );
        foreach ( $matches as $match ) {
            $atts     = shortcode_parse_atts( $match[1] );
            $key      = isset( $atts['key'] ) ? sanitize_key( $atts['key'] ) : '';
            $original = isset( $atts['original'] ) ? trim( (string) $atts['original'] ) : '';

            if ( empty( $key ) ) {
                continue;
            }
            if ( isset( $registered_keys[ $key ] ) ) {
                continue; // not orphaned
            }

            $found[] = [
                'key'      => $key,
                'original' => $original !== '' ? $original : $key,
            ];
        }

        return $found;
    }

    // ── Rescue Scanner — upgrade old-format [my_trans] shortcodes ──────────
    //
    // "Old format" = [my_trans key="X"] with NO `original` attribute.
    //
    // Three outcomes per shortcode:
    //   upgradeable   — key exists in DB → we can add original="vi_text" inline
    //   unresolvable  — key deleted from DB, no original attr → cannot recover text
    //
    // The rescue AJAX endpoint rewrites post_content for upgradeable shortcodes
    // so future DB deletions are covered by the Layer-1 `original` fallback.

    /**
     * Count posts that contain at least one old-format [my_trans] shortcode
     * (i.e., the shortcode is present but has no `original` attribute).
     * Used to show/hide the Rescue panel UI.
     */
    public static function count_rescue_targets(): int {
        global $wpdb;
        // Quick pre-filter: posts that contain [my_trans but no original= attr.
        $rows = $wpdb->get_results( // phpcs:ignore
            "SELECT ID, post_content FROM `{$wpdb->posts}`
             WHERE post_status IN ('publish','draft')
               AND post_content LIKE '%[my_trans%'"
        ) ?: [];

        $count = 0;
        foreach ( $rows as $row ) {
            $targets = self::extract_rescue_targets( $row->post_content, [] );
            if ( ! empty( $targets['upgradeable'] ) || ! empty( $targets['unresolvable'] ) ) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Scan a batch of posts for old-format [my_trans] shortcodes.
     *
     * @param int $offset
     * @param int $limit
     * @return array {
     *   upgradeable:  array of {key, vi_text, post_id, post_title},
     *   unresolvable: array of {key, post_id, post_title},
     *   post_count:   int
     * }
     */
    public static function scan_rescue_batch( int $offset, int $limit = 10 ): array {
        global $wpdb;

        $posts = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
            "SELECT ID, post_title, post_content
             FROM `{$wpdb->posts}`
             WHERE post_status IN ('publish','draft')
               AND post_content LIKE '%[my_trans%'
             ORDER BY ID ASC
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        ) ) ?: [];

        // Build lookup: key → vi translation text (one query for all registered strings)
        $vi_map = [];
        $def    = MML_Languages::get_default_code();
        foreach ( MML_Strings::get_all() as $row ) {
            $t = json_decode( $row->translations, true );
            if ( is_array( $t ) && isset( $t[ $def ] ) && $t[ $def ] !== '' ) {
                $vi_map[ $row->string_key ] = $t[ $def ];
            }
        }

        $upgradeable  = [];
        $unresolvable = [];

        foreach ( $posts as $post ) {
            $targets = self::extract_rescue_targets( $post->post_content, $vi_map );

            foreach ( $targets['upgradeable'] as $tgt ) {
                $upgradeable[] = [
                    'key'        => $tgt['key'],
                    'vi_text'    => $tgt['vi_text'],
                    'post_id'    => (int) $post->ID,
                    'post_title' => $post->post_title,
                ];
            }
            foreach ( $targets['unresolvable'] as $tgt ) {
                $unresolvable[] = [
                    'key'        => $tgt['key'],
                    'post_id'    => (int) $post->ID,
                    'post_title' => $post->post_title,
                ];
            }
        }

        // Deduplicate upgradeable by key (same shortcode may appear in multiple posts).
        $seen = [];
        $upgradeable = array_values( array_filter( $upgradeable, function ( $i ) use ( &$seen ) {
            if ( isset( $seen[ $i['key'] ] ) ) return false;
            $seen[ $i['key'] ] = true;
            return true;
        } ) );

        return [
            'upgradeable'  => $upgradeable,
            'unresolvable' => $unresolvable,
            'post_count'   => count( $posts ),
        ];
    }

    /**
     * Execute the rescue upgrade: rewrite post_content so every old-format
     * [my_trans key="X"] becomes [my_trans key="X" original="VI_TEXT"].
     *
     * Only processes shortcodes whose key currently EXISTS in wp_my_strings.
     * Shortcodes already bearing `original=` are untouched.
     *
     * @return array { upgraded: int (shortcodes updated), posts_changed: int, unresolvable: int }
     */
    public static function run_rescue_upgrade(): array {
        global $wpdb;

        $posts = $wpdb->get_results( // phpcs:ignore
            "SELECT ID, post_content
             FROM `{$wpdb->posts}`
             WHERE post_status IN ('publish','draft')
               AND post_content LIKE '%[my_trans%'"
        ) ?: [];

        // Build vi_map for all registered strings
        $vi_map = [];
        $def    = MML_Languages::get_default_code();
        foreach ( MML_Strings::get_all() as $row ) {
            $t = json_decode( $row->translations, true );
            if ( is_array( $t ) && isset( $t[ $def ] ) && $t[ $def ] !== '' ) {
                $vi_map[ $row->string_key ] = $t[ $def ];
            }
        }

        $upgraded      = 0;
        $posts_changed = 0;
        $unresolvable  = 0;

        foreach ( $posts as $post ) {
            $content = $post->post_content;

            if ( false === strpos( $content, '[my_trans' ) ) {
                continue;
            }

            preg_match_all( '/\[my_trans\b([^\]]*)\]/', $content, $matches, PREG_SET_ORDER );
            $new_content = $content;

            foreach ( $matches as $match ) {
                $atts     = shortcode_parse_atts( $match[1] );
                $key      = isset( $atts['key'] ) ? sanitize_key( $atts['key'] ) : '';
                $has_orig = isset( $atts['original'] ) && $atts['original'] !== '';

                if ( empty( $key ) || $has_orig ) {
                    continue; // already fine
                }

                if ( ! isset( $vi_map[ $key ] ) ) {
                    $unresolvable++;
                    continue; // key gone, can't add original
                }

                // Build upgraded shortcode with original attribute
                $old_sc = $match[0];
                $new_sc = '[my_trans key="' . esc_attr( $key ) . '" original="' . esc_attr( $vi_map[ $key ] ) . '"]';
                $new_content = str_replace( $old_sc, $new_sc, $new_content );
                $upgraded++;
            }

            if ( $new_content !== $content ) {
                wp_update_post( [
                    'ID'           => (int) $post->ID,
                    'post_content' => $new_content,
                ] );
                $posts_changed++;
            }
        }

        return [
            'upgraded'      => $upgraded,
            'posts_changed' => $posts_changed,
            'unresolvable'  => $unresolvable,
        ];
    }

    /**
     * Parse a post_content and classify old-format [my_trans] shortcodes.
     *
     * "Old-format" = shortcode with `key` but WITHOUT a non-empty `original` attribute.
     *
     * @param string               $content  Raw post_content.
     * @param array<string,string> $vi_map   [ string_key => vi_text ] for all registered strings.
     * @return array {
     *   upgradeable:  array of {key, vi_text},
     *   unresolvable: array of {key}
     * }
     */
    private static function extract_rescue_targets( string $content, array $vi_map ): array {
        $upgradeable  = [];
        $unresolvable = [];

        if ( false === strpos( $content, '[my_trans' ) ) {
            return [ 'upgradeable' => $upgradeable, 'unresolvable' => $unresolvable ];
        }

        preg_match_all( '/\[my_trans\b([^\]]*)\]/', $content, $matches, PREG_SET_ORDER );
        $seen = [];

        foreach ( $matches as $match ) {
            $atts     = shortcode_parse_atts( $match[1] );
            $key      = isset( $atts['key'] ) ? sanitize_key( $atts['key'] ) : '';
            $has_orig = isset( $atts['original'] ) && $atts['original'] !== '';

            if ( empty( $key ) || $has_orig || isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;

            if ( isset( $vi_map[ $key ] ) ) {
                $upgradeable[] = [ 'key' => $key, 'vi_text' => $vi_map[ $key ] ];
            } else {
                $unresolvable[] = [ 'key' => $key ];
            }
        }

        return [ 'upgradeable' => $upgradeable, 'unresolvable' => $unresolvable ];
    }
}
