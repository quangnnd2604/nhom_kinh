<?php
/**
 * Shortcode engine — registers dynamic shortcodes from wp_my_strings,
 * plus the [my_lang_flags] switcher.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MML_Shortcodes {

    /**
     * Called on 'init' hook (priority 20).
     * Registers one shortcode per string key, plus [my_lang_flags].
     */
    public static function register(): void {
        // Register global string shortcodes
        $keys = MML_Strings::get_all_keys();
        foreach ( $keys as $key ) {
            add_shortcode( $key, [ self::class, 'render_string' ] );
        }

        // Language switcher
        add_shortcode( 'my_lang_flags', [ self::class, 'render_lang_flags' ] );
    }



    /**
     * Generic handler for all string shortcodes.
     * $tag = the shortcode key (string_key from DB).
     *
     * @param array  $atts
     * @param string $content
     * @param string $tag
     * @return string
     */
    public static function render_string( array $atts, string $content = '', string $tag = '' ): string {
        $lang = defined( 'MML_LANG' ) ? MML_LANG : MML_Languages::get_default_code();
        $value = MML_Strings::get_value( $tag, $lang );

        // The stored value may itself contain shortcodes — do_shortcode on it
        return do_shortcode( $value );
    }

    /**
     * [my_lang_flags] shortcode.
     *
     * Attributes:
     *   show_name  = "true"  (default: true)  — show language name text
     *   show_flag  = "true"  (default: true)  — show flag image
     *   style      = "horizontal"             — future: "dropdown"
     *
     * @param array $atts
     * @return string HTML
     */
    public static function render_lang_flags( array $atts ): string {
        $atts = shortcode_atts(
            [
                'show_name' => 'false',
                'show_flag' => 'true',
                'style'     => 'dropdown', // modern UX default
            ],
            $atts,
            'my_lang_flags'
        );

        $show_name = filter_var( $atts['show_name'], FILTER_VALIDATE_BOOLEAN );
        $show_flag = filter_var( $atts['show_flag'], FILTER_VALIDATE_BOOLEAN );

        $current_lang = defined( 'MML_LANG' ) ? MML_LANG : MML_Languages::get_default_code();
        $default_code = MML_Languages::get_default_code();
        $languages    = MML_Languages::get_all();

        // Get translated URLs for all languages using the current page/post
        $variant_urls = self::get_variant_urls();

        if ( empty( $languages ) ) {
            return '';
        }

        $html  = '<div class="mml-lang-switcher mml-style-' . esc_attr( $atts['style'] ) . '">';

        foreach ( $languages as $lang ) {
            $code   = $lang->code;
            $name   = $lang->name;
            $active = ( $code === $current_lang );

            // Build the URL for this language
            if ( isset( $variant_urls[ $code ] ) ) {
                $url = $variant_urls[ $code ];
            } else {
                // No translation — link to the same page but switch language
                $url = self::current_url_with_lang( $code, $default_code );
            }

            $flag_html = '';
            if ( $show_flag && ! empty( $lang->flag_id ) ) {
                $flag_html = wp_get_attachment_image( (int) $lang->flag_id, [ 28, 20 ], false, [
                    'alt'   => esc_attr( $name ),
                    'class' => 'mml-flag-img',
                ] );
            }

            $class = 'mml-lang-item' . ( $active ? ' mml-active' : '' );

            $html .= sprintf(
                '<a href="%s" class="%s" hreflang="%s" title="%s">%s%s</a>',
                esc_url( $url ),
                esc_attr( $class ),
                esc_attr( $code ),
                esc_attr( $name ),
                $flag_html,
                $show_name ? '<span class="mml-lang-name">' . esc_html( $name ) . '</span>' : ''
            );
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Build a map of lang_code → URL for the language switcher.
     *
     * Swaps the slug to the correct translated equivalent for:
     *   • Taxonomy archives  (category, tag, product_cat, …)
     *   • Single posts / pages / products / custom post types
     *
     * Falls back to same URL + ?lang=xx when no translation is found.
     *
     * @return array  e.g. [ 'vi' => '/trang-chu/?lang=vi', 'en' => '/home-page/?lang=en' ]
     */
    private static function get_variant_urls(): array {
        // Build current full URL without doubling the path.
        $protocol    = is_ssl() ? 'https://' : 'http://';
        $host        = isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : 'localhost'; // phpcs:ignore
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore
        $current_url = $protocol . $host . $request_uri;
        $current_url = remove_query_arg( [ 'lang', 'mml_set_lang', 'redirect_to' ], $current_url );

        // ── Detect page context ───────────────────────────────────────────

        // 1. Taxonomy archive (category, tag, product_cat, custom tax)
        $queried_term = null;
        if ( is_tax() || is_category() || is_tag() ) {
            $obj = get_queried_object();
            if ( $obj instanceof WP_Term ) {
                $queried_term = $obj;
            }
        }

        // 2. Single post / page / product / custom post type
        $queried_post = null;
        if ( ! $queried_term && ( is_singular() || is_front_page() || is_home() ) ) {
            $obj = get_queried_object();
            if ( $obj instanceof WP_Post ) {
                $queried_post = $obj;
            }
        }

        $urls = [];
        foreach ( MML_Languages::get_all() as $lang_obj ) {
            $code = $lang_obj->code;

            // ── Case A: taxonomy archive ──────────────────────────────────
            if ( $queried_term ) {
                $target_id = MML_Translations::get_translated_id( $queried_term->term_id, $code, 'term' );

                // If get_translated_id returns null, the current term might already
                // be the $code version — look up the full group instead.
                if ( ! $target_id ) {
                    $group_id = MML_Translations::get_group_id( $queried_term->term_id, 'term' );
                    if ( $group_id ) {
                        $variants  = MML_Translations::get_all_in_group( $group_id );
                        $target_id = isset( $variants[ $code ] ) ? (int) $variants[ $code ] : null;
                    }
                }

                if ( $target_id && $target_id !== $queried_term->term_id ) {
                    $trans_term = get_term( $target_id, $queried_term->taxonomy );
                    if ( $trans_term && ! is_wp_error( $trans_term ) ) {
                        $term_url = get_term_link( $trans_term );
                        if ( ! is_wp_error( $term_url ) ) {
                            $urls[ $code ] = add_query_arg( 'lang', $code, $term_url );
                            continue;
                        }
                    }
                } elseif ( $target_id === $queried_term->term_id ) {
                    // Already on the correct-language term page
                    $urls[ $code ] = add_query_arg( 'lang', $code, $current_url );
                    continue;
                }

                // Fallback
                $urls[ $code ] = add_query_arg( 'lang', $code, $current_url );
                continue;
            }

            // ── Case B: single post / page / product ─────────────────────
            if ( $queried_post ) {
                $target_id = MML_Translations::get_translated_id( $queried_post->ID, $code, 'post' );

                // If null, current post might itself be the $code version — look up group
                if ( ! $target_id ) {
                    $group_id = MML_Translations::get_group_id( $queried_post->ID, 'post' );
                    if ( $group_id ) {
                        $variants  = MML_Translations::get_all_in_group( $group_id );
                        $target_id = isset( $variants[ $code ] ) ? (int) $variants[ $code ] : null;
                    }
                }

                if ( $target_id && $target_id !== $queried_post->ID ) {
                    $post_url = get_permalink( $target_id );
                    if ( $post_url ) {
                        $urls[ $code ] = add_query_arg( 'lang', $code, $post_url );
                        continue;
                    }
                } elseif ( $target_id === $queried_post->ID ) {
                    // Already on the correct-language post
                    $urls[ $code ] = add_query_arg( 'lang', $code, $current_url );
                    continue;
                }

                // Fallback
                $urls[ $code ] = add_query_arg( 'lang', $code, $current_url );
                continue;
            }

            // ── Case C: archive / search / 404 — just swap lang param ────
            $urls[ $code ] = add_query_arg( 'lang', $code, $current_url );
        }
        return $urls;
    }

    /**
     * Fallback: build the current URL with only the lang param swapped.
     */
    private static function current_url_with_lang( string $lang_code, string $default_code ): string {
        $protocol    = is_ssl() ? 'https://' : 'http://';
        $host        = isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : 'localhost'; // phpcs:ignore
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore
        $url         = $protocol . $host . $request_uri;
        $url         = remove_query_arg( [ 'lang', 'mml_set_lang', 'redirect_to' ], $url );
        return add_query_arg( 'lang', $lang_code, $url );
    }
}
