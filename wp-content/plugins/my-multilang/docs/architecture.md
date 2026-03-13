# Architecture – My Multilang Plugin

## 1. Database Schema

### Table: `wp_my_languages`

Stores the list of active languages on the site.

```sql
CREATE TABLE `wp_my_languages` (
  `id`                INT(11)      UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`              VARCHAR(100) NOT NULL,           -- "Tiếng Anh"
  `code`              VARCHAR(10)  NOT NULL UNIQUE,     -- "en", "zh", "ru"
  `flag_id`           BIGINT(20)   UNSIGNED DEFAULT 0,  -- Attachment ID from Media Library
  `is_default`        TINYINT(1)   NOT NULL DEFAULT 0,  -- 1 = default language (Vietnamese)
  `sort_order`        INT(11)      NOT NULL DEFAULT 0,
  `use_english_slug`  TINYINT(1)   NOT NULL DEFAULT 0,  -- 1 = generate URL slugs in English
  `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `code_idx` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Notes:**
- Default language (Vietnamese, `vi`) is seeded on plugin activation.
- `flag_id` stores a WordPress attachment ID; `wp_get_attachment_image_url()` resolves the actual URL.
- Only one row may have `is_default = 1`. Enforced in PHP before INSERT/UPDATE.
- `use_english_slug = 1` causes Magic Sync to translate the VI source title/name to English before calling `sanitize_title()`, producing Latin-character slugs prefixed with the language code (e.g. `th-contact-us`). Recommended for Thai, Chinese, Russian and other non-Latin scripts.

---

### Table: `wp_my_strings`

Stores global translatable string keys and their translations.

```sql
CREATE TABLE `wp_my_strings` (
  `id`             INT(11)      UNSIGNED NOT NULL AUTO_INCREMENT,
  `string_key`     VARCHAR(100) NOT NULL,         -- shortcode key, e.g. "gioi_thieu"
  `translations`   LONGTEXT     NOT NULL,         -- JSON: {"vi":"...", "en":"...", "ru":"..."}
  `is_autoscanned` TINYINT(1)   NOT NULL DEFAULT 0,  -- 1 = inserted by Smart Scan (safe to delete on restore)
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key_idx` (`string_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**`is_autoscanned` flag:**  
When the Smart Scan system replaces a text snippet in `post_content` with `[my_trans key="X"]`, the newly created row is written with `is_autoscanned = 1`. The **Restore Session** flow uses this flag: it DELETEs all `is_autoscanned = 1` rows added during the session, then re-seeds protected system strings.  
Manually created string keys (from the Strings admin page) are always `is_autoscanned = 0` and are never deleted during a restore.

**JSON storage rationale:**
- Avoids adding a column per language (schema change on language add).
- LONGTEXT supports arbitrarily long content (HTML, shortcodes).
- Decoded once per request; the result is cached in a static PHP variable.

---

### Table: `wp_mml_backups`

Stores pre-scan snapshots of `post_content` so the Smart Scan session can be fully reversed.

```sql
CREATE TABLE `wp_mml_backups` (
  `id`           INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id`   VARCHAR(36)          NOT NULL,   -- UUID shared across all backups in one scan session
  `post_id`      BIGINT(20) UNSIGNED  NOT NULL,   -- ID of the post whose content was modified
  `post_content` LONGTEXT             NOT NULL,   -- Original post_content before any replacements
  `string_keys`  LONGTEXT             NOT NULL DEFAULT '',  -- JSON array of keys inserted for this post
  `created_at`   DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `session_idx` (`session_id`),
  KEY `post_idx` (`post_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Notes:**
- One row per post per session. If a post is scanned in multiple sessions, each session has its own row.
- The **oldest** backup row for each post is called the **Golden Source** — it holds `post_content` as it existed before any scan ever ran.
- `restore_session()` in `MML_Backup` restores from the Golden Source row, then TRUNCATEs the entire table so the next scan starts clean.
- `string_keys` is used to know which `wp_my_strings` rows to DELETE during the rollback step (combined with `is_autoscanned = 1`).

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
├── my-multilang.php                  ← v1.2.0; bootstrap, language detection, all frontend hooks;
│                                       admin_init self-heal; admin_notices for healed strings
├── uninstall.php                     ← Drop custom tables on uninstall
├── docs/
│   ├── architecture.md               ← This file
│   ├── flatsome-compatibility.md
│   └── ui-ux-design.md
├── includes/
│   ├── class-mml-installer.php       ← DB creation (dbDelta), maybe_upgrade(), seed_wc_result_count_strings(),
│   │                                   seed_rem_category_grid_strings(), maybe_heal_wc_strings()
│   ├── class-mml-languages.php       ← CRUD for wp_my_languages (incl. use_english_slug)
│   ├── class-mml-strings.php         ← CRUD for wp_my_strings; clear_cache(); get_value() sentinel '[key]'
│   ├── class-mml-translations.php    ← CRUD for wp_my_translations
│   ├── class-mml-shortcodes.php      ← [my_trans key="X" original="..."] with fallback; [my_lang_flags]
│   ├── class-mml-cloner.php          ← Content cloning engine (smart English slug on use_english_slug=1)
│   ├── class-mml-menu.php            ← Menu switching filter
│   ├── class-mml-meta-box.php        ← Language sidebar meta box on edit screens
│   ├── class-mml-auto-translate.php  ← Google Translate API wrapper (translate + translate_content)
│   ├── class-mml-magic-sync.php      ← Magic Sync AJAX endpoints (discover, execute, purge, menus)
│   ├── class-mml-scanner.php         ← Batch content scanner; orphaned scanner; rescue scanner
│   ├── class-mml-backup.php          ← Golden Source backup; restore_session() with re-seed
│   └── class-mml-smart-scan.php      ← All Smart Scan AJAX endpoints + frontend gettext/WC interceptors
└── admin/
    ├── class-mml-admin.php                  ← Menu pages, settings, asset enqueue
    ├── class-mml-list-table-strings.php     ← WP_List_Table for strings
    ├── class-mml-post-columns.php           ← Language columns in post lists
    ├── class-mml-magic-sync-ui.php          ← Magic Sync admin UI (progress dashboard + Danger Zone)
    ├── class-mml-scanner-ui.php             ← Smart Scanner admin page registration
    └── assets/
        ├── admin.css
        └── admin.js                         ← Includes rescue scanner JS (bindRescueScanner + 4 methods)
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

### v1.1.0: Smart English Slug for Non-Latin Languages (Added)
Added `use_english_slug` column to `wp_my_languages`. When enabled for a language (Thai, Chinese, Russian, etc.), Magic Sync and manual clone actions translate the **Vietnamese source title/name to English** before generating the URL slug, producing clean Latin-character slugs prefixed with the language code (e.g. `th-contact-us`, `zh-tempered-glass`). Schema migration is handled automatically by `MML_Installer::maybe_upgrade()` on the first page load after the plugin updates to v1.1.0.

### v1.2.0: Smart Scan, Shortcode Fallback, Self-Healing, Rescue Scanner (Added)
See §11–14 for full details. Summary:
- **Smart Scan** (`class-mml-scanner.php` + `class-mml-smart-scan.php`): batch-scans `post_content`, replaces text with `[my_trans key="X" original="..."]`, backs up originals in `wp_mml_backups`, and provides a Restore Session flow.
- **`[my_trans]` shortcode** (`class-mml-shortcodes.php`): enhanced with `original=` attribute. If the key is missing from `wp_my_strings`, renders the `original` value instead of blank.
- **Self-Healing** (`class-mml-installer.php` + `my-multilang.php`): `admin_init` hook checks 5 protected system strings on every admin page load; re-seeds any missing ones and shows a dismissible admin notice.
- **Restore Session** (`class-mml-backup.php`): re-seeds WooCommerce + `rem_category_grid` strings after the DELETE step so they are never accidentally wiped.
- **Rescue Scanner** (Phase D in `admin/views/scanner.php`): scans for old-format `[my_trans key="X"]` shortcodes without `original=`, looks up the VI translation in `wp_my_strings`, and rewrites them to the new format.
- **Version bump**: `MML_VERSION = '1.2.0'`; `mml_version` option updated in DB; JS cache busted.

---

## 10. Magic Sync Engine (Batch Auto-Translate)

To prevent server timeouts, Magic Sync uses an **AJAX Batch Processing Queue**:

1. **Discovery Endpoint** (`mml_magic_sync_discover`): Finds all untranslated objects. Queue order: **terms first (topologically sorted — parents before children), then posts/products/pages**.
2. **AJAX Loop**: Browser iterates the queue, sending one `$_POST` request per item. `set_time_limit(300)` on each request.
3. **Execution Endpoint** (`mml_magic_sync_execute_item`):
   - **Terms**: `MML_Cloner::clone_term()` → `MML_Auto_Translate::translate()` for name/description → smart slug (see below).
   - **Posts/Products**: `MML_Cloner::clone_post()` (with translated taxonomy remapping) → `translate()` for title → `translate_content()` for excerpt + `post_content` (Flatsome shortcodes + HTML tags preserved via tokenization) → smart slug via `wp_unique_post_slug()` → `wp_update_post()`.
4. **Purge Endpoint** (`mml_magic_sync_purge`): Deletes all clones for a selected target language. Requires double-confirmation in the UI.
5. **Menu Sync** (`mml_magic_sync_menus`): Rebuilds nav menus using translated post/term equivalents from `wp_my_translations`.

### Smart Slug Logic (v1.1.0)

Slug generation branches on the target language's `use_english_slug` flag:

| `use_english_slug` | Slug generation for **posts** | Slug generation for **terms** |
|----|----|----|  
| `0` (default) | `sanitize_title(translated_title)` → fallback to `{source_slug}-{lang}` | `sanitize_title(translated_name)-{lang}` |
| `1` (English slugs) | Translate **source VI title → English** via `MML_Auto_Translate::translate(..., 'en')`, then `{lang}-sanitize_title(en_title)` | Same: translate **source VI name → English**, then `{lang}-sanitize_title(en_name)` |

**Example**: Source = "Liên hệ", target = Thai (`th`)
- `use_english_slug = 0` → slug uses sanitized Thai characters (unusual-looking URL)
- `use_english_slug = 1` → `th-contact-us` (clean Latin URL)

The same logic runs in `MML_Cloner::clone_post()` / `clone_term()` for manual single-item clones triggered from the post/term list.

---

## 11. Smart Scan & String Interception System

The Smart Scan system allows admins to batch-replace hardcoded text in `post_content` with `[my_trans]` shortcodes so they become translatable without re-coding the theme.

### Architecture overview

```
Browser (admin.js)
  │
  ├─ AJAX → mml_scan_batch          → MML_Scanner::scan_batch()
  │         (find text matches in post_content)
  │
  ├─ AJAX → mml_scan_process        → MML_Smart_Scan::ajax_process()
  │         (replace selected snippets; write backup; write string rows)
  │
  ├─ AJAX → mml_scan_get_sessions   → lists wp_mml_backups sessions
  ├─ AJAX → mml_scan_restore        → MML_Backup::restore_session()
  ├─ AJAX → mml_scan_delete_session → hard-delete one session row
  ├─ AJAX → mml_scan_count          → count scannable posts
  ├─ AJAX → mml_scan_add_manual_string
  ├─ AJAX → mml_scan_orphaned       → MML_Scanner::scan_orphaned_batch()
  ├─ AJAX → mml_scan_rescue_scan    → MML_Scanner::scan_rescue_batch()
  └─ AJAX → mml_scan_rescue_upgrade → MML_Scanner::run_rescue_upgrade()
```

### Replace action detail (`ajax_process`)

1. Receives `post_id`, `original_text`, `string_key`, `replacement_vi` from the browser.
2. Backs up `post_content` to `wp_mml_backups` (one row per post per session UUID).
3. Inserts into `wp_my_strings` (`is_autoscanned = 1`) with the VI text pre-populated.
4. Performs `str_replace` of `original_text` → `[my_trans key="X" original="VI text"]` in `post_content`.
5. Calls `wp_update_post()`.

### Frontend Interceptors (priority 999)

Registered on non-admin requests by `MML_Smart_Scan`:

| Hook | Purpose |
|---|---|
| `gettext`, `gettext_with_context` | Intercepts translated WordPress core strings |
| `ngettext`, `ngettext_with_context` | Plural forms |
| `the_content`, `widget_text`, `widget_block_content`, `widget_title` | Theme/widget content |
| `get_product_search_form` | WooCommerce search form |
| `woocommerce_result_count` | "Showing X–Y of Z results" string |
| `woocommerce_add_error/success/notice` | WC notice messages |

All interceptors call `MML_Strings::get_value( $key )` with a known key. If `get_value()` returns the sentinel `'[key]'` (key missing from DB), the original untranslated string is returned as-is.

**Guard in `wc_result_count_intercept()`:**
```php
if ( isset( $text[0] ) && $text[0] === '[' ) {
    return $text; // already a shortcode or sentinel — pass through unchanged
}
```

### Golden Source Backup

Each scan session gets a UUID. The **first** backup row written for a given `post_id` across all sessions is called the **Golden Source** — it holds the truly original content before any scan ever ran.

`restore_session()` flow:
1. SELECT the oldest backup row per `post_id` (Golden Source).
2. `wp_update_post()` for each — restores original content.
3. DELETE `wp_my_strings` rows where `is_autoscanned = 1`.
4. Re-seed: `MML_Installer::seed_wc_result_count_strings()` + `seed_rem_category_grid_strings()`.
5. TRUNCATE `wp_mml_backups`.
6. Return `{ restored_posts: int, removed_keys: int }`.

---

## 12. `[my_trans]` Shortcode & Fallback System

### Shortcode format (v1.2.0)

```
[my_trans key="UNIQUE_KEY" original="Văn bản gốc Tiếng Việt"]
```

The `original` attribute is embedded by the Smart Scan replace action at write time. It holds the **original Vietnamese text** as a fallback string.

### Resolution logic in `render_my_trans()`

```php
function render_my_trans( array $atts ): string {
    $atts = shortcode_atts( [ 'key' => '', 'original' => '' ], $atts );
    $key  = sanitize_key( $atts['key'] );
    if ( ! $key ) {
        return esc_html( $atts['original'] );
    }

    $value = MML_Strings::get_value( $key );   // returns '[key]' sentinel if missing

    if ( $value !== '[' . $key . ']' ) {
        return $value;                          // found in DB → render translation
    }

    // Key missing from DB — use original= fallback
    if ( $atts['original'] !== '' ) {
        return esc_html( $atts['original'] );
    }

    return '';
}
```

**Why this matters:** Without the `original=` fallback, deleting or expiring a string key would cause the frontend to show a blank space. With `original=`, the page degrades gracefully to the source Vietnamese text.

### Old vs new format

| Format | Written by | `original=` | Fallback |
|---|---|---|---|
| `[my_trans key="X"]` | Pre-v1.2.0 Smart Scan / manual | No | Empty string |
| `[my_trans key="X" original="Văn bản"]` | v1.2.0 Smart Scan replace action | Yes | Source VI text |

Old-format shortcodes can be upgraded in bulk using the Rescue Scanner (§13).

---

## 13. Orphaned String Recovery & Rescue Scanner

### Orphaned Scanner

**Purpose:** Find `[my_trans key="X"]` shortcodes in `post_content` whose key **no longer exists** in `wp_my_strings` (e.g. key was manually deleted from the Strings page).

**Methods in `MML_Scanner`:**
- `count_orphaned_my_trans(): int` — fast COUNT query
- `scan_orphaned_batch( int $offset, int $limit ): array` — returns `{ items[], post_count }`
  - Each item has `source_type = 'orphaned'`, `post_id`, `post_title`, `key`
- `extract_orphaned_my_trans()` *(private)* — regex `\[my_trans\s+key="([^"]+)"\]` parser

**AJAX endpoint:** `mml_scan_orphaned` → `ajax_scan_orphaned()`

### Rescue Scanner (Phase D)

**Purpose:** Find old-format `[my_trans key="X"]` shortcodes (no `original=` attribute) and upgrade them in-place by looking up the VI translation from `wp_my_strings`.

**Two outcomes per shortcode:**
- **Upgradeable**: The key exists in `wp_my_strings` and has a `vi` translation → can rewrite to `[my_trans key="X" original="VI text"]`.
- **Unresolvable**: Key exists but has no `vi` translation, or key doesn't exist at all → cannot auto-upgrade; needs manual attention.

**Methods in `MML_Scanner`:**
- `count_rescue_targets(): int`
- `scan_rescue_batch( int $offset, int $limit ): array` — returns `{ upgradeable[], unresolvable[], post_count }`
- `run_rescue_upgrade(): array` — rewrites in-place, returns `{ upgraded: int, posts_changed: int, unresolvable: int }`
- `extract_rescue_targets()` *(private)* — classifies shortcodes

**AJAX endpoints:**
- `mml_scan_rescue_scan` → `ajax_rescue_scan()`
- `mml_scan_rescue_upgrade` → `ajax_rescue_upgrade()` (calls `run_rescue_upgrade()`, flushes string cache, returns message)

**UI (Phase D card in `admin/views/scanner.php`):**
```
┌──────────────────────────────────────────────────────────┐
│  🚑 Phase D — Rescue Scanner                             │
├──────────────────────────────────────────────────────────┤
│  [Step 1: Scan for old-format shortcodes]                │
│                                                          │
│  Upgradeable (X found):                                  │
│  ┌────────────┬───────────┬──────────────────────────┐   │
│  │ Post       │ Key       │ VI Translation           │   │
│  └────────────┴───────────┴──────────────────────────┘   │
│                                                          │
│  Unresolvable (Y found):                                 │
│  ┌────────────┬───────────┬─────────────────────────┐    │
│  │ Post       │ Key       │ Reason                  │    │
│  └────────────┴───────────┴─────────────────────────┘    │
│                                                          │
│  [Step 2: Upgrade All Upgradeable]  ← shown after scan   │
└──────────────────────────────────────────────────────────┘
```

**JS flow (`admin.js`):**
1. `bindRescueScanner()` — wires up button click handlers (called in `$(document).ready` with `typeof` guard)
2. `startRescueScan()` → `_runRescueScanBatch(0)` — paginated AJAX, accumulates results with key-level deduplication
3. `_rescueScanDone()` — updates status text, renders tables, shows Step 2 button if upgradeable > 0
4. `runRescueUpgrade()` — confirm dialog → POST → shows result notice, hides upgrade button

---

## 14. Self-Healing Core Strings

### Problem

Certain string keys are seeded during plugin install/upgrade to handle WooCommerce output and theme-specific strings. If these keys are accidentally deleted (e.g. by a botched Restore Session or manual DB edit), the frontend shows the `[key]` sentinel or a blank until an admin manually re-adds them.

### Solution: `maybe_heal_wc_strings()`

```php
// MML_Installer::maybe_heal_wc_strings(): int
public static function maybe_heal_wc_strings(): int {
    global $wpdb;
    $protected = [ 'wc_result_count_one', 'wc_result_count_range', 'wc_result_count_total',
                   'rem_cat_card_btn', 'rem_cat_view_all' ];

    $existing = $wpdb->get_col( $wpdb->prepare(
        "SELECT string_key FROM {$wpdb->prefix}my_strings WHERE string_key IN ("
        . implode( ',', array_fill(0, count($protected), '%s') ) . ")",
        ...$protected
    ) );

    $missing = array_diff( $protected, $existing );
    if ( empty( $missing ) ) return 0;

    // Re-seed only the missing ones
    self::seed_wc_result_count_strings();
    self::seed_rem_category_grid_strings();
    MML_Strings::clear_cache();
    return count( $missing );
}
```

### Hook registration (`my-multilang.php`)

```php
add_action( 'admin_init', function () {
    $healed = MML_Installer::maybe_heal_wc_strings();
    if ( $healed > 0 ) {
        set_transient( 'mml_heal_notice', $healed, 60 );
    }
} );

add_action( 'admin_notices', function () {
    $healed = get_transient( 'mml_heal_notice' );
    if ( ! $healed ) return;
    delete_transient( 'mml_heal_notice' );
    printf(
        '<div class="notice notice-warning is-dismissible"><p>'
        . '<strong>My Multilang:</strong> %d system string(s) were missing and have been restored. '
        . '<a href="%s">View Strings</a></p></div>',
        (int) $healed,
        esc_url( admin_url( 'admin.php?page=mml-strings' ) )
    );
} );
```

### Protected keys

| Key | Description |
|---|---|
| `wc_result_count_one` | "Showing the single result" (WC, 1 product) |
| `wc_result_count_range` | "Showing X–Y of Z results" (WC, paginated) |
| `wc_result_count_total` | "Showing all X results" (WC, all on one page) |
| `rem_cat_card_btn` | "Xem sản phẩm" button on `[rem_category_grid]` cards |
| `rem_cat_view_all` | "Xem tất cả" view-all link on `[rem_category_grid]` |

These 5 keys are inserted with `INSERT IGNORE` so re-seeding is always safe and idempotent.
