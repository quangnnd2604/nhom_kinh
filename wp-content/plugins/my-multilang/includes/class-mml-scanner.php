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
            // Delegate to the shared candidate evaluator used by extract_from_content().
            // This ensures label patterns like "Hotline:" are also captured in options.
            self::add_candidate( $value, $found );
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
     * Extract translatable strings from raw post_content (HTML + Flatsome shortcodes).
     *
     * Three-phase strategy to capture both long paragraphs AND short UI labels:
     *
     *  Phase A — Shortcode attribute values (Flatsome / UX Builder).
     *    Attributes like text="Hotline:" are lost when shortcode brackets are
     *    stripped, so they must be read first.
     *
     *  Phase B — Inline-element isolation.
     *    Pulls inner text of <strong>, <b>, <h1>–<h6>, <li>, <dt>, <th>,
     *    <label> as separate candidates so labels are separated from adjacent
     *    non-translatable content (phone numbers, email addresses, etc.).
     *
     *  Phase C — Standard line-by-line pass.
     *    Block-closing tags are replaced with newlines so single-line UX Builder
     *    HTML is properly segmented before wp_strip_all_tags() runs.
     *
     * Acceptance: see add_candidate() — Vietnamese diacritics OR UI-label pattern.
     *
     * @param string $content Raw post_content.
     * @return string[]
     */
    public static function extract_from_content( string $content ): array {
        $candidates = [];

        // ── Phase A: Flatsome / UX Builder shortcode attribute values ─────────
        // Visible text is often stored in shortcode attributes such as
        // text="Hotline:" or title="Liên hệ". Stripping shortcode brackets
        // first would destroy these values, so we read them before any stripping.
        static $attr_pattern = null;
        if ( null === $attr_pattern ) {
            $attr_names   = implode( '|', [
                'text', 'title', 'subtitle', 'description', 'label',
                'heading', 'subheading', 'button_text', 'hover_text',
                'placeholder', 'caption', 'alt',
            ] );
            $attr_pattern = '/\b(?:' . $attr_names . ')=(["\'])(.+?)\1/is';
        }
        if ( preg_match_all( $attr_pattern, $content, $attr_m, PREG_SET_ORDER ) ) {
            foreach ( $attr_m as $m ) {
                self::add_candidate(
                    html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
                    $candidates
                );
            }
        }

        // ── Phase B: Inline / heading tag isolation ───────────────────────────
        // Extract the inner text of short inline elements BEFORE stripping all
        // HTML. This separates labels from adjacent non-translatable content,
        // e.g. "<strong>Hotline:</strong> 097 123 4567" yields "Hotline:" as its
        // own candidate so the phone number is never bundled into the key.
        if ( preg_match_all(
            '/<(?:strong|b|em|h[1-6]|li|dt|th|label)\b[^>]*>(.*?)<\/(?:strong|b|em|h[1-6]|li|dt|th|label)>/is',
            $content,
            $tag_m
        ) ) {
            foreach ( $tag_m[1] as $inner ) {
                self::add_candidate(
                    html_entity_decode( wp_strip_all_tags( $inner ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
                    $candidates
                );
            }
        }

        // ── Phase C: Standard pass with block-boundary segmentation ──────────
        // Inject newlines before block-closing tags so that single-line HTML
        // (common in Flatsome/UX Builder) is properly split into sentences.
        $std = preg_replace( '/<\/(p|div|li|h[1-6]|td|th|blockquote)\s*>/i', "\n", $content );
        $std = preg_replace( '/<br\b[^>]*>/i', "\n", $std );
        $std = preg_replace( '/\[\/?\w[^\]]*\]/', ' ', $std );  // strip shortcode tags
        $std = wp_strip_all_tags( $std );
        $std = html_entity_decode( $std, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        foreach ( preg_split( '/[\n\r\t]+/', $std ) as $seg ) {
            self::add_candidate( $seg, $candidates );
        }

        return array_values( array_unique( $candidates ) );
    }

    /**
     * Evaluate one raw text value and push it onto $found if it qualifies
     * as a translatable string.
     *
     * Accepted when:
     *   (a) Contains at least one Vietnamese diacritic (U+1EA0–U+1EF9), OR
     *   (b) UI-label pattern — ends with ":", 2–60 chars, has at least one letter.
     *       Catches borrowed labels like "Hotline:", "Email:", "Tel:", "Fax:".
     *
     * Rejected when:
     *   • length < 2 or > 500
     *   • starts with http(s):// (URL)
     *   • is a CSS hex colour (#fff, #1a2b3c)
     *   • starts with "[" (leftover shortcode fragment)
     *   • contains both "<" and ">" (raw HTML)
     *   • looks like a standalone phone/ID number (digits + separators only)
     *
     * @param string   $raw   Unsanitised candidate text.
     * @param string[] $found Accumulator (passed by reference).
     */
    private static function add_candidate( string $raw, array &$found ): void {
        $text = trim( preg_replace( '/\s+/', ' ', $raw ) );
        $len  = mb_strlen( $text );

        if ( $len < 2 || $len > 500 )                                           { return; }
        if ( preg_match( '/^https?:\/\//i', $text ) )                           { return; }
        if ( preg_match( '/^#[0-9a-fA-F]{3,8}$/', $text ) )                    { return; } // hex colour
        if ( isset( $text[0] ) && $text[0] === '[' )                            { return; } // shortcode
        if ( strpos( $text, '<' ) !== false && strpos( $text, '>' ) !== false ) { return; } // raw HTML
        if ( preg_match( '/^\d[\d\s\-+().]{3,}$/', $text ) )                   { return; } // phone / number

        if ( self::contains_vietnamese( $text ) ) {
            $found[] = $text;
            return;
        }

        // UI-label pattern: ends with ":", short, contains at least one letter.
        if ( $len <= 60
             && substr( $text, -1 ) === ':'
             && preg_match( '/[a-zA-Z\p{L}]/u', $text ) ) {
            $found[] = $text;
        }
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

            // Layer 3: no `original` attr → try to recover text from the backup table.
            if ( $original === '' ) {
                $backup_map = self::build_backup_text_map();
                $original   = $backup_map[ $key ] ?? '';
            }

            $found[] = [
                'key'      => $key,
                'original' => $original !== '' ? $original : $key,
            ];
        }

        return $found;
    }

    /**
     * Compute the deterministic prefix part of a generated key (first 3 words,
     * transliterated to ASCII, joined with underscores — NO random suffix).
     * Used when matching backup texts to orphaned keys.
     *
     * @param string $text Vietnamese text.
     * @return string
     */
    private static function compute_key_prefix( string $text ): string {
        $ascii = strtr( $text, self::$vi_map );
        $words = preg_split( '/\s+/', trim( $ascii ) );
        $words = array_values( array_filter( $words, fn( $w ) => preg_match( '/[a-zA-Z0-9]/', $w ) ) );
        $words = array_slice( $words, 0, 3 );
        $base  = implode( '_', $words );
        $base  = preg_replace( '/[^a-z0-9_]/i', '', strtolower( $base ) );
        $base  = preg_replace( '/_+/', '_', $base );
        return trim( $base, '_' ) ?: 'chuoi';
    }

    /**
     * Build a map of { key => original_vi_text } by scanning the backup table.
     *
     * Strategy (Layer 3):
     *   1. Load all backup rows that have string_keys and post_content.
     *   2. For each backup row, extract Vietnamese strings from the original content.
     *   3. For each registered key in that row, find the text whose key prefix matches.
     *
     * The key prefix is the deterministic part of generate_key() — first 3 transliterated
     * words joined with underscores — without the 3-char random suffix.
     * e.g. key = "danh_muc_san_a1b" → prefix = "danh_muc_san"
     *
     * Built once per request and cached statically.
     *
     * @return array<string,string>  { key => vi_text }
     */
    private static function build_backup_text_map(): array {
        static $map = null;
        if ( null !== $map ) {
            return $map;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'mml_backups';
        $map   = [];

        $rows = $wpdb->get_results( // phpcs:ignore
            "SELECT `string_keys`, `post_content`
             FROM `{$table}`
             WHERE `string_keys` != '' AND `post_content` != ''
             ORDER BY `id` ASC"
        ) ?: [];

        foreach ( $rows as $row ) {
            $keys = array_filter( array_map( 'trim', explode( ',', $row->string_keys ) ) );
            if ( empty( $keys ) ) {
                continue;
            }

            // Extract Vietnamese strings from the golden-source backup content.
            $texts = self::extract_from_content( $row->post_content );

            foreach ( $keys as $bk ) {
                if ( isset( $map[ $bk ] ) ) {
                    continue; // already resolved in an earlier backup row
                }

                // key format: "prefix_XXX" where prefix is 1+ words and XXX is 3 random chars.
                // Strip the "_XXX" suffix to get the deterministic prefix.
                $bk_prefix = ( strlen( $bk ) > 4 ) ? substr( $bk, 0, -4 ) : $bk;

                foreach ( $texts as $text ) {
                    if ( self::compute_key_prefix( $text ) === $bk_prefix ) {
                        $map[ $bk ] = $text;
                        break;
                    }
                }
            }
        }

        return $map;
    }

}
