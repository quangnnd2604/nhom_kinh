# Architecture – My Multilang Plugin

## 1. Database Schema

### Table: `wp_my_languages`

Stores the list of active languages on the site.

```sql
CREATE TABLE `wp_my_languages` (
  `id`           INT(11)      UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`         VARCHAR(100) NOT NULL,           -- "Tiếng Anh"
  `code`         VARCHAR(10)  NOT NULL UNIQUE,     -- "en", "zh", "ru"
  `flag_id`      BIGINT(20)   UNSIGNED DEFAULT 0,  -- Attachment ID from Media Library
  `is_default`   TINYINT(1)   NOT NULL DEFAULT 0,  -- 1 = default language (Vietnamese)
  `sort_order`   INT(11)      NOT NULL DEFAULT 0,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `code_idx` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Notes:**
- Default language (Vietnamese, `vi`) is seeded on plugin activation.
- `flag_id` stores a WordPress attachment ID; `wp_get_attachment_image_url()` resolves the actual URL.
- Only one row may have `is_default = 1`. Enforced in PHP before INSERT/UPDATE.

---

### Table: `wp_my_strings`

Stores global translatable string keys and their translations.

```sql
CREATE TABLE `wp_my_strings` (
  `id`           INT(11)      UNSIGNED NOT NULL AUTO_INCREMENT,
  `string_key`   VARCHAR(100) NOT NULL UNIQUE,  -- shortcode key, e.g. "gioi_thieu"
  `translations` LONGTEXT     NOT NULL,         -- JSON: {"vi":"...", "en":"...", "ru":"..."}
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `key_idx` (`string_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**JSON storage rationale:**
- Avoids adding a column per language (schema change on language add).
- LONGTEXT supports arbitrarily long content (HTML, shortcodes).
- Decoded once per request; the result is cached in a static PHP variable.

---

### Table: `wp_my_translations`

Links source posts/terms to their translated counterparts.

```sql
CREATE TABLE `wp_my_translations` (
  `id`           INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id`     VARCHAR(36)  NOT NULL,   -- UUID shared across all translations of one piece of content
  `object_type`  VARCHAR(20)  NOT NULL,   -- "post" | "term"
  `object_id`    BIGINT(20)   UNSIGNED NOT NULL,
  `lang_code`    VARCHAR(10)  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_object` (`object_type`, `object_id`),
  KEY `group_idx` (`group_id`),
  KEY `lang_idx` (`lang_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Group ID:** A UUID (generated via `wp_generate_uuid4()`) ties together source + all translated copies. The language switcher queries by `group_id` to find all language variants of the current page.

---

## 2. Language Detection Logic

Detection runs via a **WordPress hook fired early** (`plugins_loaded`, priority 1).

### Priority order:

```
1. URL query parameter  ?lang=en           → highest priority
2. Saved user cookie    my_lang=en         → for returning visitors
3. Browser Accept-Language header          → auto-detect on first visit
4. Site default language                   → ultimate fallback
```

### Implementation:

```php
// my-multilang.php (bootstrap)
add_action( 'plugins_loaded', 'mml_detect_language', 1 );

function mml_detect_language() {
    $lang = null;

    // 1. Query param (also saves to cookie for next visit)
    if ( isset( $_GET['lang'] ) ) {
        $lang = sanitize_key( $_GET['lang'] );
        setcookie( 'my_lang', $lang, time() + MONTH_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );
    }

    // 2. Cookie
    if ( ! $lang && isset( $_COOKIE['my_lang'] ) ) {
        $lang = sanitize_key( $_COOKIE['my_lang'] );
    }

    // 3. Accept-Language
    if ( ! $lang && isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
        $lang = substr( $_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2 );
    }

    // Validate against active languages in DB; fall back to default
    $valid_codes  = mml_get_active_codes(); // cached static
    $default_code = mml_get_default_code();
    $lang         = in_array( $lang, $valid_codes, true ) ? $lang : $default_code;

    define( 'MML_LANG', $lang );
}
```

The constant `MML_LANG` is available everywhere in WordPress after `plugins_loaded`.

---

## 3. Caching Strategy

| Data | Cache mechanism | TTL |
|------|-----------------|-----|
| Active language list | Static PHP var + `wp_cache_set()` | Request / Object Cache |
| String translations | Static PHP var (loaded once per request) | Request |
| Translation links | `wp_cache_set()` keyed by `group_id + lang` | Object Cache |

For sites without a persistent object cache (Redis/Memcached), data is reloaded on each page load but still kept in a static variable to prevent duplicate DB queries within the same request.

---

## 4. SEO Considerations

### URL Structure

Languages are handled via query parameter (`?lang=en`) for simplicity and zero server-config changes. This works on shared hosting without mod_rewrite customisation.

```
https://example.com/san-pham/rem-cua/          → Vietnamese (default, clean URL)
https://example.com/san-pham/rem-cua/?lang=en  → English
https://example.com/san-pham/rem-cua/?lang=zh  → Chinese
```

### hreflang Tags

Injected into `<head>` via `wp_head` hook:

```php
add_action( 'wp_head', 'mml_print_hreflang_tags' );

function mml_print_hreflang_tags() {
    $group_id = mml_get_current_group_id(); // from wp_my_translations
    if ( ! $group_id ) return;

    $variants = mml_get_all_variants( $group_id ); // [ 'vi' => $url, 'en' => $url, ... ]
    foreach ( $variants as $lang => $url ) {
        printf( '<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
            esc_attr( $lang ), esc_url( $url ) );
    }
    // x-default = default language URL
    printf( '<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
        esc_url( $variants[ mml_get_default_code() ] ?? home_url( '/' ) ) );
}
```

### Canonical Tags

WooCommerce and Yoast/RankMath already output `<link rel="canonical">`. The plugin hooks into `wpseo_canonical` / `rank_math/frontend/canonical` to append `?lang=xx` only for non-default languages, preventing duplicate-content penalties.

---

## 5. Plugin File Structure

```
wp-content/plugins/my-multilang/
├── my-multilang.php                  ← Bootstrap, constants, language detection, all frontend hooks
├── uninstall.php                     ← Drop custom tables on uninstall
├── docs/
│   ├── architecture.md               ← This file
│   ├── flatsome-compatibility.md
│   └── ui-ux-design.md
├── includes/
│   ├── class-mml-installer.php       ← DB table creation (dbDelta)
│   ├── class-mml-languages.php       ← CRUD for wp_my_languages
│   ├── class-mml-strings.php         ← CRUD for wp_my_strings
│   ├── class-mml-translations.php    ← CRUD for wp_my_translations
│   ├── class-mml-shortcodes.php      ← [gioi_thieu], [my_lang_flags] (with URL-swapping switcher)
│   ├── class-mml-cloner.php          ← Content cloning engine (with translated taxonomy remapping)
│   ├── class-mml-menu.php            ← Menu switching filter
│   ├── class-mml-meta-box.php        ← Language sidebar meta box on edit screens
│   ├── class-mml-auto-translate.php  ← Google Translate API wrapper (translate + translate_content)
│   └── class-mml-magic-sync.php      ← Magic Sync AJAX endpoints (discover, execute, purge, menus)
└── admin/
    ├── class-mml-admin.php                  ← Menu pages, settings
    ├── class-mml-list-table-strings.php     ← WP_List_Table for strings
    ├── class-mml-post-columns.php           ← Language columns in post lists
    ├── class-mml-magic-sync-ui.php          ← Magic Sync admin UI (progress dashboard + Danger Zone)
    └── assets/
        ├── admin.css
        └── admin.js
```

---

## 7. Frontend Term & Post Filtering

All frontend filtering runs in `my-multilang.php` via three hooks. They apply to **every** language including the default (Vietnamese), ensuring each language sees only its own terms/posts.

### `terms_clauses` — SQL-level term filtering

```
active lang VI → AND (t.term_id IN (vi_ids) OR t.term_id NOT IN (all_managed_ids))
active lang EN → AND (t.term_id IN (en_ids) OR t.term_id NOT IN (all_managed_ids))
```

**Important exception:** Queries that include `slug`, `name__in`, or `name` args are **not filtered**. WordPress uses these arg types internally to parse taxonomy archive URLs (e.g. resolves `/product-category/rem-cua/` via a slug lookup). Filtering them would result in a 404 before the `template_redirect` redirect can fire.

**Part A (non-default lang only):** expands `t.parent = X` → `t.parent IN (vi_id, en_id, …)` so old clones whose `parent` column holds a different-language parent ID are still correctly nested.

### `get_terms` — PHP parent-field fix

After SQL returns the correct-language terms, this PHP filter remaps the `parent` property on `WP_Term` objects whose `parent` column still stores a wrong-language ID (old clones created before the parent-fix was implemented).

### `get_the_terms` — WooCommerce object-cache bypass

WooCommerce caches product terms early (before `terms_clauses` runs). This filter intercepts cached results and swaps any wrong-language terms using `MML_Translations::get_translated_id()`, querying `get_term()` only when a swap is actually needed.

### Helper functions (statically cached — zero N+1 queries per request)

```php
mml_get_lang_term_ids( string $lang ): array      // All term IDs for one language
mml_get_all_managed_term_ids(): array             // All term IDs across ALL languages
```

---

## 8. Taxonomy Archive Language Redirect

Hook: `template_redirect` (priority 5), function `mml_taxonomy_archive_lang_redirect()`.

When a non-default-language visitor lands on a default-language category URL:
1. Detects `is_tax() || is_category() || is_tag()`
2. Gets queried `WP_Term`
3. Looks up the `MML_LANG` translation via `MML_Translations::get_translated_id()`
4. Calls `get_term_link()` on the translated term and redirects (302) with `?lang=` appended

The redirect works because slug lookups are excluded from `terms_clauses` filtering (see §7), so WordPress can always find the VI term by slug even when EN is active.

---

## 9. Known Issues & Fixes (Changelog)

### Orphaned Translations in Database
When a cloned post or term is permanently deleted from WordPress, its mapping in `wp_my_translations` must be actively removed. Handled via:
- `delete_post` → `MML_Translations::handle_delete_post()`
- `pre_delete_term` → `MML_Translations::handle_delete_term()`

### AJAX Clone Redirect Bug
In `class-mml-admin.php`, `esc_url_raw()` must be used (not `esc_url()`) when returning `edit_url` in JSON. `esc_url()` converts `&` → `&#038;`, corrupting `post.php?post=123&action=edit`.

### Default-Language Terms Visible in All Languages (Fixed)
All three frontend filters (`terms_clauses`, `get_terms`, `get_the_terms`) previously had `if ( MML_LANG === $default_lang ) { return early; }` guards, so Vietnamese returned all languages' terms mixed together. **Fix:** removed all early-return guards — filters now apply equally for every language.

### 404 on Non-Default Category URLs (Fixed)
`terms_clauses` was filtering slug-lookup queries, causing WordPress to get 0 results when resolving a VI category URL while EN was active → 404 before the redirect could fire. **Fix:** skip Part B entirely when the query args include `slug`, `name__in`, or `name`.

### Cloned Products Linked to Wrong Category (Fixed)
`copy_taxonomies()` was copying source term IDs verbatim to the cloned post, so the EN product ended up in the VI category. **Fix:** for each source term ID, call `MML_Translations::get_translated_id()` to get the translated category and use that ID instead. Falls back to the original ID if no translation exists yet.

### Magic Sync Queue Order Bug (Fixed)
Products were processed before categories in the discovery queue, so when `copy_taxonomies()` ran, the translated category didn't exist yet → fallback to VI category. **Fix:** terms are now queued **first** (topologically sorted), posts **second** (`array_merge($term_items, $post_items)`).

### Hierarchical Category Parent Wrong ID (Fixed)
`clone_term()` was using `$source->parent` (the VI parent ID) for the cloned term's parent. **Fix:** calls `MML_Translations::get_translated_id( $source->parent, $target_lang, 'term' )` to resolve the correct translated parent ID before inserting.

---

## 10. Magic Sync Engine (Batch Auto-Translate)

To prevent server timeouts, Magic Sync uses an **AJAX Batch Processing Queue**:

1. **Discovery Endpoint** (`mml_magic_sync_discover`): Finds all untranslated objects. Queue order: **terms first (topologically sorted — parents before children), then posts/products/pages**.
2. **AJAX Loop**: Browser iterates the queue, sending one `$_POST` request per item. `set_time_limit(300)` on each request.
3. **Execution Endpoint** (`mml_magic_sync_execute_item`):
   - **Terms**: `MML_Cloner::clone_term()` → `MML_Auto_Translate::translate()` for name/description → unique slug.
   - **Posts/Products**: `MML_Cloner::clone_post()` (with translated taxonomy remapping) → `translate()` for title → `translate_content()` for excerpt + `post_content` (Flatsome shortcodes + HTML tags preserved via tokenization) → unique slug via `wp_unique_post_slug()` → `wp_update_post()`.
4. **Purge Endpoint** (`mml_magic_sync_purge`): Deletes all clones for a selected target language. Requires double-confirmation in the UI.
5. **Menu Sync** (`mml_magic_sync_menus`): Rebuilds nav menus using translated post/term equivalents from `wp_my_translations`.
