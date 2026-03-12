<?php
/**
 * Cloning engine — copies posts, products, and taxonomy terms for translation.
 * Preserves Flatsome UX Builder shortcodes (stored as post_content plain text).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MML_Cloner {

    // ── Post / Product cloning ─────────────────────────────────────────────

    /**
     * Clone a post (page, post, product) for a target language.
     *
     * @param int    $source_id   Source post ID (typically the default-language version).
     * @param string $target_lang Target language code, e.g. 'en'.
     * @return int|WP_Error       New post ID or WP_Error.
     */
    public static function clone_post( int $source_id, string $target_lang ) {
        $source = get_post( $source_id );
        if ( ! $source ) {
            return new WP_Error( 'not_found', __( 'Source post not found.', 'my-multilang' ) );
        }

        // Block cloning if a translation already exists
        $existing = MML_Translations::get_translated_id( $source_id, $target_lang, 'post' );
        if ( $existing ) {
            return new WP_Error( 'exists', sprintf(
                __( 'A translation for language "%s" already exists (post #%d).', 'my-multilang' ),
                $target_lang,
                $existing
            ) );
        }

        // 1. Insert the cloned draft post
        $new_id = wp_insert_post( [
            'post_author'    => $source->post_author,
            'post_content'   => $source->post_content,  // Flatsome UX Builder shortcodes preserved verbatim
            'post_excerpt'   => $source->post_excerpt,
            'post_status'    => 'draft',
            'post_title'     => $source->post_title . ' [' . strtoupper( $target_lang ) . ']',
            'post_type'      => $source->post_type,
            'post_parent'    => $source->post_parent,
            'menu_order'     => $source->menu_order,
            'comment_status' => $source->comment_status,
            'ping_status'    => $source->ping_status,
        ], true );

        if ( is_wp_error( $new_id ) ) {
            return $new_id;
        }

        // 2. Copy all post meta (includes _flatsome_*, _price, _sku, etc.)
        self::copy_post_meta( $source_id, $new_id );

        // 3. Copy taxonomies (categories, tags, product_cat, etc.) — remap to translated terms
        self::copy_taxonomies( $source_id, $new_id, $source->post_type, $target_lang );

        // 4. Register translation link in wp_my_translations
        MML_Translations::link_posts( $source_id, $new_id, $target_lang );

        /**
         * Action fires after a post has been cloned.
         *
         * @param int    $new_id      The newly created post ID.
         * @param int    $source_id   The original post ID.
         * @param string $target_lang The target language code.
         */
        do_action( 'mml_after_clone_post', $new_id, $source_id, $target_lang );

        return $new_id;
    }

    /**
     * Copy all post meta from one post to another.
     * Skips internal WordPress meta that must remain unique or be regenerated.
     *
     * @param int $from_id
     * @param int $to_id
     */
    private static function copy_post_meta( int $from_id, int $to_id ): void {
        global $wpdb;

        $skip_keys = [
            '_edit_lock',
            '_edit_last',
            '_wp_old_slug',
            '_wp_trash_meta_status',
            '_wp_trash_meta_time',
        ];

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT `meta_key`, `meta_value` FROM `' . $wpdb->postmeta . '` WHERE `post_id` = %d',
                $from_id
            )
        );

        foreach ( $rows as $row ) {
            if ( in_array( $row->meta_key, $skip_keys, true ) ) {
                continue;
            }
            // maybe_unserialize handles arrays/objects stored serialized
            add_post_meta( $to_id, $row->meta_key, maybe_unserialize( $row->meta_value ) );
        }
    }

    /**
     * Re-apply all taxonomy terms from the source post to the cloned post,
     * remapping each term to its translated counterpart in $target_lang.
     *
     * Example: source product in VI category #5 → clone gets EN category #23
     * (the EN clone of #5), NOT the original VI #5.
     *
     * If no translation exists for a term, the original term is used as fallback
     * (e.g. tags or taxonomies that haven't been synced yet).
     *
     * @param int    $from_id
     * @param int    $to_id
     * @param string $post_type
     * @param string $target_lang
     */
    private static function copy_taxonomies( int $from_id, int $to_id, string $post_type, string $target_lang = '' ): void {
        $taxonomies = get_object_taxonomies( $post_type );
        foreach ( $taxonomies as $taxonomy ) {
            $terms = wp_get_object_terms( $from_id, $taxonomy, [ 'fields' => 'ids' ] );
            if ( is_wp_error( $terms ) || empty( $terms ) ) {
                continue;
            }

            if ( $target_lang ) {
                $mapped = [];
                foreach ( $terms as $term_id ) {
                    $translated = MML_Translations::get_translated_id( (int) $term_id, $target_lang, 'term' );
                    $mapped[]   = $translated ? $translated : (int) $term_id;
                }
                $terms = array_unique( $mapped );
            }

            wp_set_object_terms( $to_id, $terms, $taxonomy );
        }
    }

    // ── Term (Category) cloning ────────────────────────────────────────────

    /**
     * Clone a taxonomy term for a target language.
     *
     * @param int    $source_term_id
     * @param string $taxonomy        e.g. 'category', 'product_cat'
     * @param string $target_lang
     * @return int|WP_Error  New term ID or WP_Error.
     */
    public static function clone_term( int $source_term_id, string $taxonomy, string $target_lang ) {
        $source = get_term( $source_term_id, $taxonomy );
        if ( ! $source || is_wp_error( $source ) ) {
            return new WP_Error( 'not_found', __( 'Source term not found.', 'my-multilang' ) );
        }

        // Check for existing translation
        $existing = MML_Translations::get_translated_id( $source_term_id, $target_lang, 'term' );
        if ( $existing ) {
            return new WP_Error( 'exists', sprintf(
                __( 'A translation for language "%s" already exists (term #%d).', 'my-multilang' ),
                $target_lang,
                $existing
            ) );
        }

        // Resolve parent: look up the already-cloned counterpart of the source parent
        // so that cloned categories form their OWN hierarchy instead of nesting
        // under the original (default-language) parent.
        $translated_parent = 0;
        if ( (int) $source->parent > 0 ) {
            $mapped_parent = MML_Translations::get_translated_id( (int) $source->parent, $target_lang, 'term' );
            $translated_parent = $mapped_parent ? $mapped_parent : 0;
        }

        $new_term = wp_insert_term(
            $source->name . ' [' . strtoupper( $target_lang ) . ']',
            $taxonomy,
            [
                'description' => $source->description,
                'parent'      => $translated_parent,
                'slug'        => $source->slug . '-' . $target_lang,
            ]
        );

        if ( is_wp_error( $new_term ) ) {
            return $new_term;
        }

        $new_term_id = (int) $new_term['term_id'];

        // Copy term meta (thumbnail, etc.)
        self::copy_term_meta( $source_term_id, $new_term_id );

        // Register translation link
        MML_Translations::link_terms( $source_term_id, $new_term_id, $target_lang );

        do_action( 'mml_after_clone_term', $new_term_id, $source_term_id, $target_lang, $taxonomy );

        return $new_term_id;
    }

    /**
     * Copy all term meta from one term to another.
     *
     * @param int $from_id
     * @param int $to_id
     */
    private static function copy_term_meta( int $from_id, int $to_id ): void {
        $meta = get_term_meta( $from_id );
        foreach ( $meta as $key => $values ) {
            foreach ( $values as $value ) {
                add_term_meta( $to_id, $key, maybe_unserialize( $value ) );
            }
        }
    }
}
