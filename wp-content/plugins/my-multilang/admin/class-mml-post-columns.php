<?php
/**
 * Admin columns on post/page/product and taxonomy term list tables.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MML_Post_Columns {

    /** Post types that get language columns */
    private const POST_TYPES = [ 'page', 'post', 'product' ];

    /** Taxonomies that get language columns */
    private const TAXONOMIES = [ 'category', 'product_cat', 'product_tag' ];

    public function __construct() {
        // Post list columns
        foreach ( self::POST_TYPES as $pt ) {
            add_filter( "manage_{$pt}_posts_columns",        [ $this, 'add_post_columns' ] );
            add_action( "manage_{$pt}_posts_custom_column",  [ $this, 'render_post_column' ], 10, 2 );
        }

        // Taxonomy term list columns
        foreach ( self::TAXONOMIES as $tax ) {
            add_filter( "manage_edit-{$tax}_columns",    [ $this, 'add_term_columns' ] );
            add_filter( "manage_{$tax}_custom_column",   [ $this, 'render_term_column' ], 10, 3 );
        }
    }

    // ── Post Columns ───────────────────────────────────────────────────────

    public function add_post_columns( array $columns ): array {
        $languages = MML_Languages::get_all();
        foreach ( $languages as $lang ) {
            if ( $lang->is_default ) {
                continue; // Source language column not needed
            }
            $columns[ 'mml_lang_' . $lang->code ] = $this->flag_label( $lang );
        }
        return $columns;
    }

    public function render_post_column( string $column, int $post_id ): void {
        if ( strpos( $column, 'mml_lang_' ) !== 0 ) {
            return;
        }

        $lang_code   = substr( $column, 9 ); // strip 'mml_lang_'
        $translated  = MML_Translations::get_translated_id( $post_id, $lang_code, 'post' );

        if ( $translated ) {
            $post   = get_post( $translated );
            $status = $post ? $post->post_status : '';
            $label  = ( $status === 'publish' ) ? '✓' : '✎';
            $class  = ( $status === 'publish' ) ? 'mml-status-check' : 'mml-status-draft';
            printf(
                '<a href="%s" class="%s" title="%s">%s</a>',
                esc_url( get_edit_post_link( $translated, 'raw' ) ),
                esc_attr( $class ),
                esc_attr( ucfirst( $status ) ),
                $label
            );
        } else {
            // No translation yet — show clone button
            printf(
                '<a href="#" class="mml-clone-btn mml-status-plus" data-type="post" data-id="%d" data-lang="%s" title="%s">+</a>',
                $post_id,
                esc_attr( $lang_code ),
                esc_attr__( 'Create translation', 'my-multilang' )
            );
        }
    }

    // ── Term Columns ───────────────────────────────────────────────────────

    public function add_term_columns( array $columns ): array {
        $languages = MML_Languages::get_all();
        foreach ( $languages as $lang ) {
            if ( $lang->is_default ) {
                continue;
            }
            $columns[ 'mml_lang_' . $lang->code ] = $this->flag_label( $lang );
        }
        return $columns;
    }

    /**
     * @param string $content    Existing HTML (filter, not action).
     * @param string $column
     * @param int    $term_id
     * @return string
     */
    public function render_term_column( string $content, string $column, int $term_id ): string {
        if ( strpos( $column, 'mml_lang_' ) !== 0 ) {
            return $content;
        }

        $lang_code  = substr( $column, 9 );
        $translated = MML_Translations::get_translated_id( $term_id, $lang_code, 'term' );

        // Detect current taxonomy from the query string
        $taxonomy = sanitize_key( $_GET['taxonomy'] ?? 'category' ); // phpcs:ignore

        if ( $translated ) {
            $edit_link = get_edit_term_link( $translated, $taxonomy );
            return sprintf(
                '<a href="%s" class="mml-status-check" title="%s">✓</a>',
                esc_url( $edit_link ),
                esc_attr__( 'Edit translation', 'my-multilang' )
            );
        }

        return sprintf(
            '<a href="#" class="mml-clone-btn mml-status-plus" data-type="term" data-id="%d" data-lang="%s" data-taxonomy="%s" title="%s">+</a>',
            $term_id,
            esc_attr( $lang_code ),
            esc_attr( $taxonomy ),
            esc_attr__( 'Create translation', 'my-multilang' )
        );
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function flag_label( object $lang ): string {
        $flag = '';
        if ( ! empty( $lang->flag_id ) ) {
            $flag = wp_get_attachment_image( (int) $lang->flag_id, [ 16, 12 ], false, [ 'style' => 'margin-right:4px;vertical-align:middle;' ] );
        }
        return $flag . esc_html( strtoupper( $lang->code ) );
    }
}
