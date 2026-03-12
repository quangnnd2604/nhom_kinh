<?php
/**
 * Menu switching and hreflang injection.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MML_Menu {

    /**
     * Register all hooks.
     * Called once from my-multilang.php.
     */
    public static function init(): void {
        add_filter( 'wp_nav_menu_args',    [ self::class, 'swap_menu' ] );
        add_filter( 'wp_nav_menu_objects', [ self::class, 'append_lang_to_menu_items' ], 10, 2 );
        add_action( 'wp_head',             [ self::class, 'print_hreflang' ] );
    }

    /**
     * Auto-append ?lang=xx to every nav menu item URL when browsing in a
     * non-default language. This ensures language persists through navigation
     * without relying on cookies or session.
     *
     * hooked: wp_nav_menu_objects
     *
     * @param WP_Post[] $items  Menu item post objects.
     * @param stdClass  $args   Menu display args.
     * @return WP_Post[]
     */
    public static function append_lang_to_menu_items( array $items, $args ): array {
        $current_lang = defined( 'MML_LANG' ) ? MML_LANG : MML_Languages::get_default_code();
        $default_lang = MML_Languages::get_default_code();

        // Only modify links when NOT viewing the default language
        if ( $current_lang === $default_lang ) {
            return $items;
        }

        $home = home_url();

        foreach ( $items as $item ) {
            // Only modify internal (same-site) links
            if ( isset( $item->url ) && str_starts_with( $item->url, $home ) ) {
                // Don't double-add the param if already present
                if ( strpos( $item->url, 'lang=' ) === false ) {
                    $item->url = add_query_arg( 'lang', $current_lang, $item->url );
                }
            }
        }

        return $items;
    }

    /**
     * Swap nav_menu theme location based on active language.
     * Looks for a menu registered with slug: {theme_location}_{lang_code}.
     *
     * Example: Primary Menu (location: primary) + lang=en
     *   → looks for a menu assigned to location "primary_en"
     *   OR a menu with slug/name containing "_en"
     *
     * hooked: wp_nav_menu_args
     *
     * @param array $args
     * @return array
     */
    public static function swap_menu( array $args ): array {
        $current_lang = defined( 'MML_LANG' ) ? MML_LANG : MML_Languages::get_default_code();
        $default_lang = MML_Languages::get_default_code();

        if ( $current_lang === $default_lang ) {
            return $args; // No swap needed for default language
        }

        if ( empty( $args['theme_location'] ) ) {
            return $args;
        }

        // Check if a menu is explicitly assigned to "{location}_{lang}" theme location
        $target_location = $args['theme_location'] . '_' . $current_lang;
        $locations       = get_nav_menu_locations();

        if ( isset( $locations[ $target_location ] ) && $locations[ $target_location ] ) {
            $args['theme_location'] = $target_location;
            return $args;
        }

        // Fallback: look for a nav menu whose name ends with "_{lang_code}" or " {LANG}"
        $all_menus = wp_get_nav_menus();
        foreach ( $all_menus as $menu ) {
            if (
                str_ends_with( strtolower( $menu->slug ), '_' . $current_lang ) ||
                str_ends_with( $menu->name, ' ' . strtoupper( $current_lang ) )
            ) {
                // Override with a direct menu object instead of theme_location
                $args['menu']           = $menu;
                $args['theme_location'] = ''; // clear location to avoid double-lookup
                return $args;
            }
        }

        return $args;
    }

    /**
     * Print hreflang link tags in <head>.
     * hooked: wp_head
     */
    public static function print_hreflang(): void {
        if ( is_admin() ) {
            return;
        }

        $object_id = get_queried_object_id();
        if ( ! $object_id ) {
            return;
        }

        $queried = get_queried_object();
        if ( $queried instanceof WP_Post ) {
            $type = 'post';
        } elseif ( $queried instanceof WP_Term ) {
            $type = 'term';
        } else {
            return;
        }

        $group_id = MML_Translations::get_group_id( $object_id, $type );
        if ( ! $group_id ) {
            return;
        }

        $variants     = MML_Translations::get_all_in_group( $group_id );
        $default_code = MML_Languages::get_default_code();

        foreach ( $variants as $lang_code => $obj_id ) {
            if ( $type === 'post' ) {
                $base_url = get_permalink( $obj_id );
            } else {
                $base_url = get_term_link( (int) $obj_id );
                if ( is_wp_error( $base_url ) ) {
                    continue;
                }
            }

            $url = ( $lang_code === $default_code )
                ? $base_url
                : add_query_arg( 'lang', $lang_code, $base_url );

            printf(
                '<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
                esc_attr( $lang_code ),
                esc_url( $url )
            );
        }

        // x-default always points to the default language URL
        if ( isset( $variants[ $default_code ] ) ) {
            if ( $type === 'post' ) {
                $default_url = get_permalink( $variants[ $default_code ] );
            } else {
                $default_url = get_term_link( (int) $variants[ $default_code ] );
                if ( is_wp_error( $default_url ) ) {
                    $default_url = home_url( '/' );
                }
            }
            printf( '<link rel="alternate" hreflang="x-default" href="%s" />' . "\n", esc_url( $default_url ) );
        }
    }
}
