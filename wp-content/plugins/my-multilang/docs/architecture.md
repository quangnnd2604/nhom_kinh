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

### v1.2.2: Golden Source Enforcement (Added)
- `MML_Cloner::clone_post()` and `clone_term()` now include a **Golden Source Guard** that resolves non-default-language source IDs to their canonical default-language original before cloning begins. Returns `WP_Error('no_source')` if no default-language original is found in the translation group.
- `MML_Magic_Sync::ajax_discover()` filters both the term and post loops via `MML_Translations::get_lang_for_object()` — only default-language items (or unregistered items) are added to the queue.
- `MML_Magic_Sync::ajax_execute_item()` adds a second safety gate per item before executing, guarding against stale queue data from previous-version discovers.

### v1.2.3: Language Deletion Safety Protocol (Added)

Deleting a language now follows a mandatory **three-step sequence** enforced in `MML_Admin::handle_delete_language()`. Attempting to delete a language that still has content clones is refused with a descriptive notice and a direct link to Magic Sync.

**Step 1 — Clone Detection (`MML_Languages::count_clones(string $code): int`)**
Queries `wp_my_translations` for the count of all objects (posts and terms) registered under the target language code. If the count is > 0, the deletion is aborted and the admin is redirected back to the Language Manager with `error_type=has_clones`, `error_lang`, and `error_count` query parameters. The Language Manager view renders a blocking error notice:

> *"Hiện đang có N bản clone … liên quan đến ngôn ngữ "XX". Bạn phải thực hiện xóa tất cả bản clone …"*
> [⚡ Đi đến Magic Sync → Danger Zone để xóa tất cả bản sao "XX"]

**Step 2 — String Translation Purge (`MML_Languages::purge_string_translations(string $code): void`)**
Iterates every row in `wp_my_strings`, decodes the JSON `translations` blob, removes the key matching `$code`, and writes the updated JSON back. Runs before the language row is deleted so no ghost translation values remain in the strings table. Flushes `MML_Strings` cache afterwards.

**Step 3 — Language Row Deletion (`MML_Languages::delete(int $id): bool|WP_Error`)**
The existing method — deletes the `wp_my_languages` row and invalidates the static cache.

**UX change:** The success redirect message was updated to *"Language deleted (strings purged)."* to make the string-cleanup step visible to the admin.

**New methods added to `MML_Languages`:**

| Method | Purpose |
|---|---|
| `get_by_id( int $id ): ?object` | Cache-backed lookup by primary key |
| `count_clones( string $code ): int` | Counts objects in `wp_my_translations` for a lang code |
| `purge_string_translations( string $code ): void` | Strips a lang's key from all `wp_my_strings` JSON blobs |

### v1.3.0: Auto-Translate Missing Strings, Scanner Phase A/B/C, Final Cleanup (Added)

**Auto-Translate Missing Strings** (`MML_Admin::ajax_auto_translate_strings()`):
- New AJAX endpoint `mml_auto_translate_strings` reads the default-language value for every string key that has no translation for a chosen target language, calls `MML_Auto_Translate::translate()` on each, and writes the result back (no-overwrite rule — existing translations are never clobbered).
- UI: language selector + "Dịch tự động …" button + live progress bar added to the String Translation admin view (`admin/views/strings.php`).
- JS: `bindAutoTranslate()` / `_runAutoTranslate()` loop in `admin/assets/admin.js` with batch size 20 and 1.8 s post-completion reload.
- `mmlAdmin.i18n` extended with five new keys; all `\u2026` Unicode escape sequences replaced with literal `…` characters throughout (PHP does not support `\uXXXX` escapes).

**Scanner 3-Phase Rewrite (`MML_Scanner::extract_from_content()`)**:
- Phase A — Shortcode attribute values (existing behaviour preserved).
- Phase B — Inline-tag isolation: strips `<strong>`, `<em>`, `<span>`, `<a>` wrappers to surface short labels hidden inside inline HTML.
- Phase C — Block-boundary segmentation: splits on `<br>`, `</p>`, `</div>` etc. to detect short UI labels that span a single block element.
- New private `add_candidate()` helper applies dual acceptance criteria: Vietnamese diacritics present **OR** UI-label pattern (ends with `:`, 2–60 chars). Prevents flooding the string table with noise.

**Final cleanup:**
- `test_gg.php` (standalone Google Translate API dev test) deleted — was never referenced by the plugin.
- Informal `// Debug:` comment in `my-multilang.php` rephrased to `// Emit a diagnostic log entry when WP_DEBUG_LOG is enabled`.
- `MML_Auto_Translate::translate_array()` removed — was defined but had zero call sites; batch iteration is handled inline by the AJAX handler.
- All PHP files pass `php -l` syntax check.

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

---

## 15. Golden Rules of Multilang Development

> **Phải tuân thủ tuyệt đối — These rules are the Core Constitution of this plugin.**
> Every new feature must be verified against this checklist before a single line of code is written.

---

### RULE 1 — GOLDEN SOURCE CLONING (The Master Rule)

**Definition:** Every cloning, syncing, or translation action MUST use the **Default Language** version (Golden Source) as the ONLY authoritative input. All content, titles, slugs, and metadata for a new language are derived exclusively from the default-language object.

**Constraints:**
- Never clone from a clone. `vi → en` is valid. `en → ko` is **forbidden**.
- Never use a translated version as the input for a new translation.
- The `is_default = 1` row in `wp_my_languages` is the single source of truth at runtime. Use `MML_Languages::get_default_code()` — never hardcode `'vi'`.

**Implementation Checkpoints:**

| Layer | Guard | Added in |
|---|---|---|
| `MML_Cloner::clone_post()` | Calls `MML_Translations::get_lang_for_object($source_id, 'post')`. If result is non-null and not `$default_lang`, resolves the canonical default-language post via `get_translated_id()` before proceeding. Returns `WP_Error('no_source')` if no canonical origin is found. | v1.2.2 |
| `MML_Cloner::clone_term()` | Same guard for terms: resolves the default-language original from the same translation group. | v1.2.2 |
| `MML_Magic_Sync::ajax_discover()` | Term loop and post loop both call `get_lang_for_object()` and skip any item where `lang_code !== null && lang_code !== $default_lang`. Only VI originals (and unregistered objects) enter the queue. | v1.2.2 |
| `MML_Magic_Sync::ajax_execute_item()` | Safety gate for both post and term branches: if the received queue item is a non-default clone, resolve the VI source before cloning. Returns error if no source found. | v1.2.2 |

**Defense-in-depth:** The guard exists at BOTH the high level (`ajax_discover`) AND the low level (`clone_post`/`clone_term`). Adding a new entry point (e.g. a WP-CLI command or REST endpoint) does not require new guards — the Cloner enforces the rule for every caller automatically.

---

### RULE 2 — SMART SHORTCODE FALLBACK

**Definition:** All translatable content fragments must be stored using the `[my_trans]` shortcode with an `original=` fallback attribute so that the frontend never displays a blank or a raw key sentinel.

**Required format:**
```
[my_trans key="UNIQUE_KEY" original="Văn bản gốc Tiếng Việt"]
```

**Constraints:**
- The `original=` attribute MUST hold the **Vietnamese (default-language) text** as written at scan time.
- `MML_Strings::get_value()` returns the sentinel `'[key]'` when a key is missing from the DB. The shortcode renderer detects this and falls back to `original=` instead of returning an empty string — see `render_my_trans()` in §12.
- Old-format shortcodes without `original=` must be upgraded with the Rescue Scanner (§13) before they can benefit from this fallback.
- Any new code that programmatically inserts shortcodes into `post_content` MUST include the `original=` attribute.

---

### RULE 3 — ATOMIC RESTORATION & CLEANUP

**Definition:** The "Restore All" operation must be executed as a single, **ordered, atomic sequence**. Partial restores that leave the DB in a mixed state are not acceptable.

**Required sequence (must not be reordered):**
1. **SELECT** the Golden Source row (oldest backup per `post_id`) from `wp_mml_backups`.
2. **Restore** `post_content` from the Golden Source row for every backed-up post via `wp_update_post()`.
3. **DELETE** all `wp_my_strings` rows where `is_autoscanned = 1` (auto-scanned keys added during any session).
4. **Re-seed** protected system strings: `seed_wc_result_count_strings()` + `seed_rem_category_grid_strings()` — these must NEVER be wiped.
5. **TRUNCATE** `wp_mml_backups` so the next scan session starts from a clean state.

**Constraints:**
- Steps 3 and 4 form an inseparable pair: delete-then-reseed. Deleting without reseeding breaks WooCommerce and theme output.
- Manually-created string keys (`is_autoscanned = 0`) are **never deleted** by a Restore.
- The restore flow is implemented in `MML_Backup::restore_session()`. Do not duplicate or split this logic.

---

### RULE 4 — PRE-FLIGHT CONFIRMATION (Magic Sync)

**Definition:** Any AI-assisted batch operation (Magic Sync) that will create, modify, or delete content for a target language MUST show a UI confirmation modal to the admin before any processing begins.

**Required modal content:**
- Target language name and code: **"Ngôn ngữ đích: Korean (ko)"**
- A live sample translation demonstrating the source-to-target: `"Xin chào" → "[Korean equivalent]"`
- A warning about the scope and irreversibility of the operation
- Two action buttons: **Hủy bỏ** (abort) and **✔ Xác nhận & Bắt đầu** (confirm and start)

**Constraints:**
- The actual AJAX discovery call must only fire when the admin clicks **Xác nhận & Bắt đầu** — never on the initial button press.
- The `exampleMap` object (populated from `mml_language_registry_by_code()`) provides the sample translation per language code. If a language is not in the registry, the modal still shows the language name/code but omits the sample line.
- Implemented in `admin/class-mml-magic-sync-ui.php`. The `<option>` tags have `data-name` and `data-ai` attributes used by the modal JavaScript.

---

### RULE 5 — ZERO HARDCODE (Strings & Paths)

**Definition:** No user-visible string, no file system path, and no language comparison value may be written literally in PHP or JavaScript source code.

**PHP strings:** Every string shown to the user (error messages, status text, labels) passed through `wp_send_json_*`, `wp_die()`, or echoed to the browser **MUST** be wrapped with `__( '...', 'my-multilang' )`, `_e()`, or `esc_html__()` so it can be translated via `.po`/`.mo` files.

```php
// ✅ Correct
wp_send_json_error( __( 'Permission denied.', 'my-multilang' ) );

// ❌ Wrong — bare string bypasses i18n
wp_send_json_error( 'Permission denied.' );
```

**JavaScript strings:** All user-visible JS strings must be passed from PHP to JS via `wp_localize_script` under the `mmlAdmin.i18n` sub-object (for `admin.js`) or a dedicated PHP-generated JS variable (for inline scripts like `class-mml-magic-sync-ui.php` → `mmlSyncI18n`). Never embed Vietnamese or English UI text directly in `.js` files.

```php
// ✅ Pass from PHP
'i18n' => [ 'serverError' => __( 'Server error.', 'my-multilang' ) ]

// ✅ Use in JS
alert( mmlAdmin.i18n.serverError );

// ❌ Wrong — hardcoded in JS
alert( 'Lỗi server.' );
```

**Table names:** Always use `$wpdb->prefix . 'table_name'` — never write `wp_` prefixes literally.

**Paths:** Use `plugin_dir_path( __FILE__ )`, `plugin_dir_url( __FILE__ )`, or WordPress constants. Never hardcode `/Applications/...` or similar absolute paths.

**Language code checks:** Never compare against a hardcoded language code (e.g., `=== 'vi'`) in application logic. Use the registry helpers (`get_default_language_code()`, `mml_language_registry_by_code()`) instead.

---

### Pre-Coding Checklist

Before writing or reviewing any code that touches content sync, cloning, slug generation, or translation:

- [ ] **R1** — Am I sourcing content from the default-language object? Have I called `get_lang_for_object()` and resolved the canonical ID?
- [ ] **R2** — If I am inserting a `[my_trans]` shortcode programmatically, does it include `original="..."`?
- [ ] **R3** — If I am modifying the restore flow, is the delete-then-reseed sequence intact and in the correct order?
- [ ] **R4** — If I am adding or modifying a batch AI operation, is there a modal confirmation step before any destructive work begins?
- [ ] **R5** — Are all new user-facing strings wrapped in `__()` / `_e()` in PHP? Are all new JS UI strings added to `mmlAdmin.i18n` (or a named i18n object for inline scripts)?

---

## 16. String Translation System (`wp_my_strings`)

### Overview

The String Translation system manages short, reusable text strings that appear across the frontend (headers, labels, CTAs, etc.). Strings are stored in `wp_my_strings` as `string_key` + a JSON `translations` blob keyed by language code. The shortcode `[my_trans key="X"]` renders the correct language version at runtime.

### Admin UI (`admin/views/strings.php`)

Rendered at **Multilang → String Translations** (`page=mml-strings`). Provides:

| Action | Mechanism |
|---|---|
| Add key | AJAX `mml_add_string` → `MML_Admin::ajax_add_string()` |
| Edit/save translations | Form POST `mml_save_strings` → `MML_Admin::handle_save_strings()` |
| Delete key + all translations | AJAX `mml_delete_string` → `MML_Admin::ajax_delete_string()` |
| **Auto-fill missing** | AJAX `mml_auto_translate_strings` → `MML_Admin::ajax_auto_translate_strings()` *(v1.3.0)* |

### Auto-Translate Missing Strings (v1.3.0 — Self-Healing/Auto-Fill)

Allows the admin to fill every empty translation cell for a selected target language with a single button click, without touching existing translations.

#### UI

A panel above the strings table contains:
- A `<select>` listing all non-default languages from `wp_my_languages`
- A **"Dịch tự động các chuỗi chưa dịch"** button
- An inline progress bar + status text
- A result notice (success / info / error) shown on completion

#### Backend: `MML_Admin::ajax_auto_translate_strings()`

**Hook:** `wp_ajax_mml_auto_translate_strings`

**Algorithm (per batch call):**

1. Validate nonce (`mml_admin_nonce`) and `manage_options` capability.  
2. Reject if `lang_code` equals `MML_Languages::get_default_code()` — never translate *to* the default language.  
3. Fetch all rows from `wp_my_strings`.  
4. Filter to only rows where:  
   - `translations[$target_lang]` is empty/null (missing)  
   - `translations[$default_lang]` is non-empty (has source text to translate from)  
5. Slice the first **20 rows** (batch size).  
6. For each row call `MML_Auto_Translate::translate( $source, $default_lang, $target_lang )`.  
7. Write only the new key back into the JSON blob — **existing language values are never modified**.  
8. Call `MML_Strings::update()` which flushes the PHP cache.  
9. `usleep(150000)` between API calls to respect rate limits.  
10. Return JSON: `{ translated, total_missing, remaining, done }`.

**Golden Source enforcement:** Source text is always read from `$translations[$default_lang]` — the same language that was used as the authoritative source when the string was first registered. No cloning chain possible.

#### Frontend: `MMLAdmin.bindAutoTranslate()` / `_runAutoTranslate()`

- Calls `runBatch()` recursively (via `setTimeout(runBatch, 300)`) until `res.data.done === true`.
- Accumulates `totalTranslated` across batches and updates the progress bar.
- On completion: shows success notice, then reloads the page after 1.8 s so textareas display the new translations.
- On "nothing to translate": shows an info-level notice without reloading.

#### Constraint: No-Overwrite Rule

```
if ( $target_text === '' && $source_text !== '' ) → translate and write
if ( $target_text !== '' )                        → SKIP — never overwrite
```

This is enforced entirely server-side, making it safe to re-run at any time.
