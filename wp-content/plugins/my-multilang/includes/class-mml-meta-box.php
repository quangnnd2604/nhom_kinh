<?php
/**
 * Adds a Language Sidebar Meta Box to public post types.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MML_Meta_Box {

    public static function init(): void {
        add_action( 'add_meta_boxes', [ self::class, 'register_meta_box' ] );
    }

    public static function register_meta_box(): void {
        // Only show on post types that are public (so ignoring revisions, menus, etc.)
        $post_types = get_post_types( [ 'public' => true ], 'names' );

        foreach ( $post_types as $pt ) {
            add_meta_box(
                'mml_language_meta_box',
                __( 'Languages', 'my-multilang' ),
                [ self::class, 'render_meta_box' ],
                $pt,
                'side',
                'high'
            );
        }
    }

    public static function render_meta_box( WP_Post $post ): void {
        $languages     = MML_Languages::get_all();
        $default_lang  = MML_Languages::get_default_code();
        
        // Find current post language
        $current_lang = MML_Translations::get_lang_for_object( $post->ID, 'post' ) ?: $default_lang;

        echo '<div class="mml-meta-box-wrapper">';
        echo '<ul class="mml-meta-box-list">';

        foreach ( $languages as $lang ) {
            
            // Build the flag label
            $flag_html = '';
            if ( ! empty( $lang->flag_id ) ) {
                $flag_html = wp_get_attachment_image( (int) $lang->flag_id, [ 16, 12 ], false, [ 'style' => 'margin-right:8px;vertical-align:middle;' ] );
            }
            $label = $flag_html . '<strong>' . esc_html( $lang->name ) . '</strong>';

            echo '<li>';
            echo '<div class="mml-mb-lang-name">' . $label . '</div>';
            echo '<div class="mml-mb-actions">';

            // 1. If this is the current language being edited:
            if ( $lang->code === $current_lang ) {
                echo '<span class="mml-mb-current">Curent</span>';
            } else {
                // 2. See if there is a translation for this language
                $translated_id = MML_Translations::get_translated_id( $post->ID, $lang->code, 'post' );
                
                if ( $translated_id ) {
                    // It exists -> Show Edit link
                    $edit_url = get_edit_post_link( $translated_id, 'raw' );
                    printf(
                        '<a href="%s" class="mml-status-check mml-mb-action-btn" title="%s">✓</a>',
                        esc_url( $edit_url ),
                        esc_attr__( 'Edit translation', 'my-multilang' )
                    );
                } else {
                    // Doesn't exist -> Show Clone (+) button
                    // Note: Ensure admin.js loaded on post.php can handle this!
                    printf(
                        '<a href="#" class="mml-clone-btn mml-status-plus mml-mb-action-btn" data-type="post" data-id="%d" data-lang="%s" title="%s">+</a>',
                        $post->ID,
                        esc_attr( $lang->code ),
                        esc_attr__( 'Create translation', 'my-multilang' )
                    );
                }
            }

            echo '</div>'; // close .mml-mb-actions
            echo '</li>';
        }

        echo '</ul>';
        echo '</div>';
    }
}
