<?php
/**
 * Magic Sync Backend API: Discovery, Translation Queue, and Menu Sync.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MML_Magic_Sync {

    public static function init(): void {
        add_action( 'wp_ajax_mml_magic_sync_discover',      [ self::class, 'ajax_discover' ] );
        add_action( 'wp_ajax_mml_magic_sync_execute_item',  [ self::class, 'ajax_execute_item' ] );
        add_action( 'wp_ajax_mml_magic_sync_menus',         [ self::class, 'ajax_sync_menus' ] );
        add_action( 'wp_ajax_mml_magic_sync_purge',         [ self::class, 'ajax_purge' ] );
    }

    /**
     * AJAX Endpoint 1: Find all items in default language that need translation.
     */
    public static function ajax_discover(): void {
        check_ajax_referer( 'mml_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $target_lang = isset( $_POST['target_lang'] ) ? sanitize_key( $_POST['target_lang'] ) : '';
        if ( ! $target_lang ) {
            wp_send_json_error( 'Missing target language.' );
        }

        $default_lang = MML_Languages::get_default_code();
        if ( $target_lang === $default_lang ) {
            wp_send_json_error( 'Cannot sync to default language.' );
        }

        // Terms MUST come before posts in the queue so that when a post is cloned,
        // its term translations already exist and copy_taxonomies() can remap them.
        $term_items = [];
        $post_items = [];

        // 1. Terms (Categories, Tags, Product Cats) — queued FIRST
        // Fetch full WP_Term objects (not just IDs) so we can access ->parent for
        // topological ordering. Parents MUST be queued before children so that when
        // clone_term() runs for a child it can look up the already-cloned parent ID.
        $taxonomies = get_taxonomies( [ 'public' => true ] );
        foreach ( $taxonomies as $tax ) {
            $terms = get_terms( [
                'taxonomy'   => $tax,
                'hide_empty' => false,
            ] );

            if ( is_wp_error( $terms ) || empty( $terms ) ) {
                continue;
            }

            // Build map: term_id => WP_Term for untranslated terms only
            $pending_map = [];
            foreach ( $terms as $term ) {
                $existing = MML_Translations::get_translated_id( $term->term_id, $target_lang, 'term' );
                if ( ! $existing ) {
                    $pending_map[ (int) $term->term_id ] = $term;
                }
            }

            // Topological sort: parents always before their children in the queue
            $sorted_terms = self::sort_terms_topologically( $pending_map );

            foreach ( $sorted_terms as $term ) {
                $term_items[] = [
                    'type' => 'term',
                    'id'   => $term->term_id,
                    'tax'  => $tax,
                ];
            }
        }

        // 2. Posts, Pages, Products (Public Post Types) — queued AFTER terms
        $post_types = get_post_types( [ 'public' => true ] );
        unset( $post_types['attachment'] );

        foreach ( $post_types as $pt ) {
            $posts = get_posts( [
                'post_type'      => $pt,
                'posts_per_page' => -1,
                'post_status'    => 'any',
                'fields'         => 'ids',
            ] );

            foreach ( $posts as $pid ) {
                $existing = MML_Translations::get_translated_id( $pid, $target_lang, 'post' );
                if ( ! $existing ) {
                    $post_items[] = [
                        'type' => 'post',
                        'id'   => $pid,
                    ];
                }
            }
        }

        // Merge: terms first so their translations exist when posts are cloned
        $items_to_sync = array_merge( $term_items, $post_items );

        wp_send_json_success( [
            'total' => count( $items_to_sync ),
            'items' => $items_to_sync,
        ] );
    }

    /**
     * AJAX Endpoint 2: Clone and Translate a single item.
     */
    public static function ajax_execute_item(): void {
        check_ajax_referer( 'mml_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        // Allow up to 5 minutes – translating long post_content with many text nodes
        // can take time due to individual Google Translate API calls.
        set_time_limit( 300 );

        $item_type    = sanitize_text_field( wp_unslash( $_POST['item_type'] ?? '' ) ); // 'post' or 'term'
        $item_id      = absint( $_POST['item_id'] ?? 0 );
        $target_lang  = sanitize_key( wp_unslash( $_POST['target_lang'] ?? '' ) );
        $default_lang = MML_Languages::get_default_code();

        if ( ! $item_type || ! $item_id || ! $target_lang ) {
            wp_send_json_error( 'Invalid parameters.' );
        }

        if ( $item_type === 'post' ) {
            // ── 1. Clone the post (copies content, meta, taxonomies) ──────
            $new_id = MML_Cloner::clone_post( $item_id, $target_lang );
            if ( is_wp_error( $new_id ) ) {
                wp_send_json_error( $new_id->get_error_message() );
            }

            $source_post = get_post( $item_id );

            // ── 2. Translate Title ────────────────────────────────────────
            $translated_title = MML_Auto_Translate::translate(
                $source_post->post_title,
                $default_lang,
                $target_lang
            );

            // ── 3. Translate Excerpt (may contain light HTML) ─────────────
            $translated_excerpt = MML_Auto_Translate::translate_content(
                $source_post->post_excerpt,
                $default_lang,
                $target_lang
            );

            // ── 4. Translate full post_content (Flatsome UX Builder shortcodes + HTML) ──
            $translated_content = MML_Auto_Translate::translate_content(
                $source_post->post_content,
                $default_lang,
                $target_lang
            );

            // ── 5. Derive a clean URL slug from the translated title ───────
            // If the target language has use_english_slug=1, translate the SOURCE
            // (VI) title to English and use that as the slug base (e.g. th-contact-us).
            // Otherwise fall back to sanitize_title of the already-translated title.
            $lang_obj    = MML_Languages::get_by_code( $target_lang );
            $use_en_slug = $lang_obj && ! empty( $lang_obj->use_english_slug );

            if ( $use_en_slug ) {
                $en_title_for_slug = MML_Auto_Translate::translate( $source_post->post_title, $default_lang, 'en' );
                $translated_slug   = sanitize_title( $en_title_for_slug );
                if ( empty( $translated_slug ) ) {
                    $translated_slug = $source_post->post_name . '-' . $target_lang;
                } else {
                    $translated_slug = $target_lang . '-' . $translated_slug;
                }
            } else {
                $translated_slug = sanitize_title( $translated_title );
                // If sanitize_title returned empty (e.g. CJK without romanization),
                // fall back to the source slug suffixed with the language code.
                if ( empty( $translated_slug ) ) {
                    $translated_slug = $source_post->post_name . '-' . $target_lang;
                }
            }

            // Ensure slug uniqueness (wp_unique_post_slug handles collision avoidance)
            $translated_slug = wp_unique_post_slug(
                $translated_slug,
                $new_id,
                $source_post->post_status,
                $source_post->post_type,
                $source_post->post_parent
            );

            // ── 6. Persist all translated fields ──────────────────────────
            wp_update_post( [
                'ID'           => $new_id,
                'post_title'   => $translated_title,
                'post_name'    => $translated_slug,
                'post_excerpt' => $translated_excerpt,
                'post_content' => $translated_content,
                'post_status'  => $source_post->post_status, // Inherit true status
            ] );

            wp_send_json_success( "Translated Post #{$new_id}: {$translated_title} [slug: {$translated_slug}]" );

        } elseif ( $item_type === 'term' ) {
            $tax = sanitize_text_field( wp_unslash( $_POST['item_tax'] ?? '' ) );
            if ( ! $tax ) {
                $source_term = get_term( $item_id );
                $tax = $source_term->taxonomy ?? '';
            }

            // ── 1. Clone the term ─────────────────────────────────────────
            $new_id = MML_Cloner::clone_term( $item_id, $tax, $target_lang );
            if ( is_wp_error( $new_id ) ) {
                wp_send_json_error( $new_id->get_error_message() );
            }

            $source_term = get_term( $item_id, $tax );

            // ── 2. Translate Term Name & Description ──────────────────────
            $translated_name = MML_Auto_Translate::translate(
                $source_term->name,
                $default_lang,
                $target_lang
            );

            $translated_desc = MML_Auto_Translate::translate_content(
                $source_term->description,
                $default_lang,
                $target_lang
            );

            // ── 3. Derive clean URL slug from translated name ───────────────
            // If use_english_slug=1, translate SOURCE name to English and prefix
            // with lang code (e.g. th-tempered-glass). Otherwise append -lang suffix.
            $lang_obj    = MML_Languages::get_by_code( $target_lang );
            $use_en_slug = $lang_obj && ! empty( $lang_obj->use_english_slug );

            if ( $use_en_slug ) {
                $en_name_for_slug = MML_Auto_Translate::translate( $source_term->name, $default_lang, 'en' );
                $translated_slug  = sanitize_title( $en_name_for_slug );
                if ( empty( $translated_slug ) ) {
                    $translated_slug = $source_term->slug . '-' . $target_lang;
                } else {
                    $translated_slug = $target_lang . '-' . $translated_slug;
                }
            } else {
                $translated_slug = sanitize_title( $translated_name );
                if ( empty( $translated_slug ) ) {
                    $translated_slug = $source_term->slug . '-' . $target_lang;
                }
                // Append lang suffix to guarantee uniqueness across taxonomies
                $translated_slug = $translated_slug . '-' . $target_lang;
            }

            wp_update_term( $new_id, $tax, [
                'name'        => $translated_name,
                'slug'        => $translated_slug,
                'description' => $translated_desc,
            ] );

            wp_send_json_success( "Translated Term #{$new_id}: {$translated_name} [slug: {$translated_slug}]" );
        }

        wp_send_json_error( 'Unknown item type.' );
    }

    /**
     * AJAX Endpoint 3: Purge all clones for a target language.
     * Permanently deletes translated posts/terms AND cloned menus, then cleans up translation links.
     */
    public static function ajax_purge(): void {
        check_ajax_referer( 'mml_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        set_time_limit( 300 );

        $target_lang  = sanitize_key( wp_unslash( $_POST['target_lang'] ?? '' ) );
        $default_lang = MML_Languages::get_default_code();

        if ( ! $target_lang ) {
            wp_send_json_error( 'Missing target language.' );
        }

        if ( $target_lang === $default_lang ) {
            wp_send_json_error( 'Cannot purge the default language.' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'my_translations';

        // 1. Fetch every object registered for this language
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT `object_id`, `object_type` FROM `{$table}` WHERE `lang_code` = %s",
                $target_lang
            )
        );

        $deleted_posts = 0;
        $deleted_terms = 0;

        foreach ( $rows as $row ) {
            $id = (int) $row->object_id;

            if ( $row->object_type === 'post' ) {
                // wp_delete_post fires 'delete_post' which auto-removes the translation row
                $result = wp_delete_post( $id, true );
                if ( $result ) {
                    $deleted_posts++;
                }
            } elseif ( $row->object_type === 'term' ) {
                $term = get_term( $id );
                if ( $term && ! is_wp_error( $term ) ) {
                    $result = wp_delete_term( $id, $term->taxonomy );
                    if ( $result && ! is_wp_error( $result ) ) {
                        $deleted_terms++;
                    }
                } else {
                    // Term may have already been deleted; clean up the row manually
                    $wpdb->delete(
                        $table,
                        [ 'object_type' => 'term', 'object_id' => $id ],
                        [ '%s', '%d' ]
                    );
                }
            }
        }

        // 2. Remove any orphaned translation rows that may remain for this lang
        $wpdb->delete(
            $table,
            [ 'lang_code' => $target_lang ],
            [ '%s' ]
        );

        // 3. Delete cloned menus: menus whose name ends with _{target_lang}
        $deleted_menus = 0;
        $all_menus     = wp_get_nav_menus();
        $suffix        = '_' . $target_lang;

        foreach ( $all_menus as $menu ) {
            if ( str_ends_with( strtolower( $menu->name ), strtolower( $suffix ) ) ) {
                $result = wp_delete_nav_menu( $menu->term_id );
                if ( $result && ! is_wp_error( $result ) ) {
                    $deleted_menus++;
                }
            }
        }

        wp_send_json_success( [
            'deleted_posts' => $deleted_posts,
            'deleted_terms' => $deleted_terms,
            'deleted_menus' => $deleted_menus,
            'message'       => sprintf(
                'Purge complete: %d posts/pages/products, %d terms, %d menus deleted.',
                $deleted_posts,
                $deleted_terms,
                $deleted_menus
            ),
        ] );
    }

    /**
     * AJAX Endpoint 4: Sync Menus
     */
    public static function ajax_sync_menus(): void {
        check_ajax_referer( 'mml_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $target_lang = sanitize_key( wp_unslash( $_POST['target_lang'] ?? '' ) );
        if ( ! $target_lang ) {
            wp_send_json_error( 'Missing target language.' );
        }

        $menus = wp_get_nav_menus();
        $processed = 0;

        foreach ( $menus as $menu ) {
            // Only process base language menus (don't sync a menu that ends with _en)
            if ( preg_match( '/_[a-z]{2}$/i', $menu->name ) ) {
                continue;
            }

            $target_name = $menu->name . '_' . $target_lang;
            $target_menu = wp_get_nav_menu_object( $target_name );

            if ( ! $target_menu ) {
                $target_menu_id = wp_create_nav_menu( $target_name );
                if ( is_wp_error( $target_menu_id ) ) {
                    continue;
                }
                $target_menu = wp_get_nav_menu_object( $target_menu_id );
            }

            // Sync items
            self::sync_menu_items( $menu->term_id, $target_menu->term_id, $target_lang );
            $processed++;
        }

        wp_send_json_success( "Synced {$processed} menus." );
    }

    /**
     * Core logic to copy menu items and map Object IDs to their translated counterparts.
     */
    private static function sync_menu_items( int $source_menu_id, int $target_menu_id, string $target_lang ): void {
        $source_items = wp_get_nav_menu_items( $source_menu_id );
        if ( ! $source_items ) {
            return;
        }

        // Clear existing items in target menu to avoid duplicates during resync
        $existing_target_items = wp_get_nav_menu_items( $target_menu_id );
        if ( $existing_target_items ) {
            foreach ( $existing_target_items as $item ) {
                wp_delete_post( $item->ID, true );
            }
        }

        // Create a map to maintain parent-child relationships
        $item_map = []; // source_menu_item_id => new_menu_item_id

        foreach ( $source_items as $item ) {
            $menu_item_data = [
                'menu-item-object-id'   => $item->object_id,
                'menu-item-object'      => $item->object,
                'menu-item-parent-id'   => 0, // Will update below
                'menu-item-position'    => $item->menu_order,
                'menu-item-type'        => $item->type,
                'menu-item-title'       => $item->title,
                'menu-item-url'         => $item->url,
                'menu-item-description' => $item->description,
                'menu-item-attr-title'  => $item->attr_title,
                'menu-item-target'      => $item->target,
                'menu-item-classes'     => implode( ' ', $item->classes ),
                'menu-item-xfn'         => $item->xfn,
                'menu-item-status'      => 'publish',
            ];

            // Resolve Parent hierarchy
            if ( $item->menu_item_parent && isset( $item_map[ $item->menu_item_parent ] ) ) {
                $menu_item_data['menu-item-parent-id'] = $item_map[ $item->menu_item_parent ];
            }

            // Map Object ID to the translated clone
            if ( $item->type === 'post_type' ) {
                $translated_obj_id = MML_Translations::get_translated_id( $item->object_id, $target_lang, 'post' );
                if ( $translated_obj_id ) {
                    $menu_item_data['menu-item-object-id'] = $translated_obj_id;
                    // Auto-translate Custom label if they overwrote the post title in menu
                    if ( $item->title ) {
                        $default_lang = MML_Languages::get_default_code();
                        $menu_item_data['menu-item-title'] = MML_Auto_Translate::translate( $item->title, $default_lang, $target_lang );
                    }
                }
            } elseif ( $item->type === 'taxonomy' ) {
                $translated_obj_id = MML_Translations::get_translated_id( $item->object_id, $target_lang, 'term' );
                if ( $translated_obj_id ) {
                    $menu_item_data['menu-item-object-id'] = $translated_obj_id;
                    if ( $item->title ) {
                        $default_lang = MML_Languages::get_default_code();
                        $menu_item_data['menu-item-title'] = MML_Auto_Translate::translate( $item->title, $default_lang, $target_lang );
                    }
                }
            } elseif ( $item->type === 'custom' ) {
                // For custom links, auto append ?lang= parameter if internal link, and translate title
                $default_lang = MML_Languages::get_default_code();
                if ( $item->title ) {
                    $menu_item_data['menu-item-title'] = MML_Auto_Translate::translate( $item->title, $default_lang, $target_lang );
                }
                if ( str_starts_with( $item->url, home_url() ) ) {
                    $menu_item_data['menu-item-url'] = add_query_arg( 'lang', $target_lang, $item->url );
                }
            }

            $new_item_id = wp_update_nav_menu_item( $target_menu_id, 0, $menu_item_data );
            if ( ! is_wp_error( $new_item_id ) ) {
                $item_map[ $item->ID ] = $new_item_id;
            }
        }
    }

    /**
     * Sort a flat map of [ term_id => WP_Term ] so that parent terms always
     * precede their children. Terms whose parent is outside the pending set
     * (already translated or a true root) are treated as roots.
     *
     * @param  array $terms_map  term_id (int) => WP_Term
     * @return WP_Term[]  Ordered array, parents first.
     */
    private static function sort_terms_topologically( array $terms_map ): array {
        $sorted  = [];
        $visited = [];

        $visit = function( int $term_id ) use ( &$visit, &$sorted, &$visited, &$terms_map ): void {
            if ( isset( $visited[ $term_id ] ) ) {
                return;
            }
            $visited[ $term_id ] = true;

            $term = $terms_map[ $term_id ] ?? null;
            if ( ! $term ) {
                return;
            }

            // Visit the parent first — but only if it is also in our pending set
            $parent_id = (int) $term->parent;
            if ( $parent_id > 0 && isset( $terms_map[ $parent_id ] ) ) {
                $visit( $parent_id );
            }

            $sorted[] = $term;
        };

        foreach ( array_keys( $terms_map ) as $term_id ) {
            $visit( (int) $term_id );
        }

        return $sorted;
    }
}
