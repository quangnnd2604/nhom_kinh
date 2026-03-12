# Flatsome Compatibility – Content Cloning Strategy

## 1. Overview

Flatsome stores page/block content as **WordPress `post_content`** containing a mix of:
- Standard WordPress block content (Gutenberg blocks — rarely used in Flatsome)
- **Flatsome UX Builder shortcodes** (the primary content format):

```
[section][row][col span="6" span__sm="12"][ux_text text_color="dark"]
<h2>Chào mừng đến với Nhóm Kính</h2>
[/ux_text][/col][/row][/section]
```

All Flatsome layout data lives in `post_content`. Additional metadata (e.g., header settings, page template options) lives in `wp_postmeta`.

---

## 2. What Gets Cloned

### For Posts and Pages

| Data | Source | Clone method |
|------|--------|-------------|
| `post_content` | `wp_posts` | Translated by `translate_content()` (shortcodes + HTML preserved) |
| `post_title` | `wp_posts` | Translated by `MML_Auto_Translate::translate()` |
| `post_excerpt` | `wp_posts` | Translated by `translate_content()` |
| `post_status` | `wp_posts` | Inherited from source (published/draft) |
| `post_author` | `wp_posts` | Copied from source |
| `post_type` | `wp_posts` | Copied from source |
| `post_name` (slug) | `wp_posts` | Derived from translated title via `sanitize_title()` + `wp_unique_post_slug()` |
| All `wp_postmeta` | `wp_postmeta` | Bulk-copied for the new post ID |
| Featured image | `_thumbnail_id` meta | Copied (same image; translator may replace) |
| Flatsome page options | `_flatsome_*` meta | Copied as part of bulk meta copy |
| WooCommerce product data | `_price`, `_sku`, etc. | Copied as part of bulk meta copy |
| Taxonomies (categories, tags) | `wp_term_relationships` | Re-applied with **translated** term IDs (see §3) |

### For Product Categories and Post Categories (Terms)

| Data | Source | Clone method |
|------|--------|-------------|
| `name` | `wp_terms` | Translated by `MML_Auto_Translate::translate()` |
| `slug` | `wp_terms` | Derived from translated name via `sanitize_title()` + `-{lang}` suffix |
| `description` | `wp_terms` | Translated by `MML_Auto_Translate::translate()` |
| `parent` | `wp_terms` | Resolved to the **translated parent** via `MML_Translations::get_translated_id()` |
| Term meta | `wp_termmeta` | Bulk-copied (thumbnail, etc.) |

---

## 3. Cloning Algorithm

```php
/**
 * class-mml-cloner.php
 */
class MML_Cloner {

    /**
     * Clone a post for a target language.
     * Magic Sync calls this, then immediately overwrites title/excerpt/content
     * with translated text via MML_Auto_Translate.
     */
    public static function clone_post( int $source_id, string $target_lang ) {

        $source = get_post( $source_id );
        if ( ! $source ) {
            return new WP_Error( 'not_found', 'Source post not found.' );
        }

        // 1. Insert the new post (draft initially; Magic Sync sets real status after translation)
        $new_id = wp_insert_post( [
            'post_author'  => $source->post_author,
            'post_content' => $source->post_content,   // UX Builder shortcodes preserved
            'post_excerpt' => $source->post_excerpt,
            'post_status'  => 'draft',
            'post_title'   => $source->post_title . ' [' . strtoupper( $target_lang ) . ']',
            'post_type'    => $source->post_type,
            'post_parent'  => $source->post_parent,
            'menu_order'   => $source->menu_order,
        ], true );

        if ( is_wp_error( $new_id ) ) {
            return $new_id;
        }

        // 2. Copy all post meta
        self::copy_post_meta( $source_id, $new_id );

        // 3. Copy taxonomies — remapped to translated term IDs
        self::copy_taxonomies( $source_id, $new_id, $source->post_type, $target_lang );

        // 4. Register the translation link
        MML_Translations::link_posts( $source_id, $new_id, $target_lang );

        return $new_id;
    }

    /**
     * Re-apply all taxonomy terms from the source post to the cloned post.
     * Each term ID is remapped to its translated equivalent in $target_lang.
     * Falls back to the original term ID if no translation exists yet.
     *
     * IMPORTANT: Magic Sync queues terms before posts so translations already
     * exist in wp_my_translations when this runs.
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

    /**
     * Clone a taxonomy term for a target language.
     * Parent is resolved to its translated equivalent.
     */
    public static function clone_term( int $source_term_id, string $taxonomy, string $target_lang ) {

        $source = get_term( $source_term_id, $taxonomy );
        if ( ! $source || is_wp_error( $source ) ) {
            return new WP_Error( 'not_found', 'Source term not found.' );
        }

        // Resolve parent to the already-cloned counterpart in the target language
        $translated_parent = 0;
        if ( (int) $source->parent > 0 ) {
            $mapped = MML_Translations::get_translated_id( (int) $source->parent, $target_lang, 'term' );
            $translated_parent = $mapped ? $mapped : 0;
        }

        $new_term = wp_insert_term(
            $source->name . ' [' . strtoupper( $target_lang ) . ']',
            $taxonomy,
            [
                'description' => $source->description,
                'parent'      => $translated_parent,     // ← translated parent, not original
                'slug'        => $source->slug . '-' . $target_lang,
            ]
        );

        if ( is_wp_error( $new_term ) ) {
            return $new_term;
        }

        $new_term_id = $new_term['term_id'];

        self::copy_term_meta( $source_term_id, $new_term_id );
        MML_Translations::link_terms( $source_term_id, $new_term_id, $target_lang );

        return $new_term_id;
    }
}
```

---

## 4. Auto-Translation: `MML_Auto_Translate`

Magic Sync translates content using the free Google Translate API (`translate.googleapis.com/translate_a/single`). Falls back to `shell_exec curl.exe` on XAMPP environments where PHP cURL may not be enabled.

### Two translation methods:

**`translate( string $text, string $from, string $to ): string`**
Plain-text translation. Used for: `post_title`, term `name`, term `description`.

**`translate_content( string $html, string $from, string $to ): string`**
Flatsome + HTML-aware translation. Algorithm:
1. Tokenizes `$html` using `preg_split` with `PREG_SPLIT_DELIM_CAPTURE`, splitting on:
   - `[shortcodes]` and `[/shortcodes]` (Flatsome UX Builder)
   - `<html tags>` and `</html tags>`
2. Text nodes between tokens are translated.
3. Shortcode **attribute values** for human-readable attributes (`text`, `title`, `heading`, `sub_heading`, `caption`, `label`, `description`, `button_text`, etc.) are translated in-place using regex.
4. All non-text tokens (shortcode wrappers, HTML tags) are passed through **unchanged** — layout is fully preserved.

---

## 5. Admin UI: "+ / ✓" Column Logic

Same as before. The column renders differently depending on translation status:

```
Source post (vi)  →  [EN: +]  [ZH: +]  [RU: ✓]
                              ↑ draft      ↑ published (click to edit)
```

### Shortcode Preservation
UX Builder shortcodes are stored as plain text in `post_content`. Cloning copies them verbatim — the translator will edit text within the content editor and the UX Builder will parse the modified shortcodes correctly.

## 6. Flatsome UX Builder: Specific Considerations

### Shortcode Preservation in Auto-Translation
`translate_content()` tokenizes `post_content` into segments — shortcode wrappers (`[section]`, `[row]`, `[col]`, `[ux_text]`, etc.) and HTML tags are passed through untouched. Only the **text nodes between tags** and **human-readable shortcode attribute values** (e.g. `text="..."`, `heading="..."`, `button_text="..."`) are sent to Google Translate. This ensures the Flatsome layout structure is completely preserved after translation.

### UX Builder Preview After Translation
Once the cloned post is opened in the WP editor and "Launch UX Builder" is clicked, it loads the visual editor from `post_content` normally. The translated text nodes appear inline in the builder.

### WooCommerce Product Gallery
`_product_image_gallery` stores a comma-separated list of attachment IDs. Correctly copied by `copy_post_meta()`. Images are shared (not duplicated) — they are media library items and language-neutral.

---

## 7. Security & Nonce Handling for Clone Actions

All clone actions (both AJAX and page redirects) are protected by:

```php
check_admin_referer( 'mml_clone_post_' . $source_id );

if ( ! current_user_can( 'edit_posts' ) ) {
    wp_die( __( 'Permission denied.', 'my-multilang' ) );
}
```

Magic Sync endpoints use `check_ajax_referer( 'mml_admin_nonce', 'nonce' )` and `current_user_can( 'manage_options' )`.
