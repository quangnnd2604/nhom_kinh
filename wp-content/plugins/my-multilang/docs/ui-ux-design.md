# UI/UX Design – Admin Interface

## 1. Plugin Menu Structure

```
WordPress Admin Sidebar
└── 🌐 Multilang                        ← Top-level menu (dashicons-translation)
    ├── Languages                        ← Language Manager
    ├── String Translations              ← Spreadsheet-like string table
    ├── Smart Scanner                    ← Batch content scan + Rescue Scanner
    ├── Magic Sync                       ← Batch auto-translate + purge
    └── Settings                         ← General options (future)
```

---

## 2. Language Manager Page

**URL:** `wp-admin/admin.php?page=mml-languages`

### Layout

```
┌─────────────────────────────────────────────────────────────────┐
│  🌐 Language Manager                              [+ Add Language]│
├─────────────────────────────────────────────────────────────────┤
│  ┌──────┬──────────────┬──────┬────────┬──────────┬───────┬───────────────┐ │
│  │ Flag │ Language Name│ Code │ Default│ EN Slug  │ Order │ Actions       │ │
│  ├──────┼──────────────┼──────┼────────┼──────────┼───────┼───────────────┤ │
│  │ 🆻🇳  │ Tiếng Việt   │  vi  │  ★     │    —    │  1    │ Edit          │ │
│  │ 🇬🇧  │ Tiếng Anh    │  en  │        │    —    │  2    │ Edit | Delete │ │
│  │ 🇨🇳  │ Tiếng Trung  │  zh  │        │  ✓ EN  │  3    │ Edit | Delete │ │
│  │ 🇷🇺  │ Tiếng Nga    │  ru  │        │  ✓ EN  │  4    │ Edit | Delete │ │
│  └──────┴──────────────┴──────┴────────┴──────────┴───────┴───────────────┘ │
└─────────────────────────────────────────────────────────────────┘
```

### Add/Edit Language (Modal or Inline Form)

```
Language Name:   [__________________________]   e.g. Tiếng Anh
Language Code:   [____]                         e.g. en  (max 5 chars)
Flag Icon:       [Select Image ▼]               WordPress Media Library picker
Set as Default:  [ ] (checkbox)
Use English for Slugs: [ ] (checkbox)
    Generate URL slugs in English (e.g. th-contact-us) instead of
    native characters. Recommended for Thai, Chinese, Russian, etc.

                              [Cancel]  [Save Language]
```

**Validation rules:**
- `code` must be unique (checked server-side via Settings API callback).
- `code` must be alphanumeric + hyphens only (`sanitize_key()`).
- Only one language can be default; saving a new default unsets the previous one.
- Delete is blocked if the language is set as default (UI shows disabled button with tooltip).

### Implementation Notes
- Uses **WordPress Settings API** (`register_setting`, `add_settings_section`, `add_settings_field`) for the form.
- Language list is a plain HTML table (not WP_List_Table) for simplicity and drag-to-reorder via WP's `wp-lists` or custom JS sortable.
- Flag image preview updates instantly when an image is selected via `wp.media` JS API.

---

## 3. String Translation Page

**URL:** `wp-admin/admin.php?page=mml-strings`

### Spreadsheet UI Layout

```
┌──────────────────────────────────────────────────────────────────────────┐
│  📝 String Translations                              [+ Add String Key]   │
├──────────────────────────────────────────────────────────────────────────┤
│ [Search strings...]                                      [Save All Changes]│
├──────────────┬──────────────────┬────────────────┬──────────────┬────────┤
│ Shortcode    │ 🇻🇳 Tiếng Việt   │ 🇬🇧 Tiếng Anh  │ 🇷🇺 Tiếng Nga │ Delete │
│ Key          │ (default)        │                │              │        │
├──────────────┼──────────────────┼────────────────┼──────────────┼────────┤
│[gioi_thieu]  │ <textarea>       │ <textarea>     │ <textarea>   │  🗑️    │
│[cta_button]  │ <textarea>       │ <textarea>     │ <textarea>   │  🗑️    │
│[footer_copy] │ <textarea>       │ <textarea>     │ <textarea>   │  🗑️    │
└──────────────┴──────────────────┴────────────────┴──────────────┴────────┘
```

### How JSON Data Is Rendered into Columns

The `translations` column in `wp_my_strings` stores:
```json
{
  "vi": "<p>Chào mừng đến với Nhóm Kính</p>",
  "en": "<p>Welcome to Nhom Kinh</p>",
  "ru": "<p>Добро пожаловать в Nhom Kinh</p>"
}
```

**Rendering logic:**

```php
// In the list table's column_default() method:
$languages  = MML_Languages::get_all();     // ['vi', 'en', 'ru', ...]
$row_index  = 0;

foreach ( $strings as $string ) {
    $translations = json_decode( $string->translations, true ) ?: [];
    echo '<tr>';
    echo '<td><code>[' . esc_html( $string->string_key ) . ']</code></td>';

    foreach ( $languages as $lang ) {
        $value = $translations[ $lang->code ] ?? '';
        printf(
            '<td><textarea name="mml_strings[%d][%s]" rows="3">%s</textarea></td>',
            (int) $string->id,
            esc_attr( $lang->code ),
            esc_textarea( $value )
        );
    }
    echo '<td><button class="mml-delete-row" data-id="' . $string->id . '">🗑️</button></td>';
    echo '</tr>';
    $row_index++;
}
```

**Saving logic:**

```php
// admin/class-mml-admin.php  →  handle_save_strings()
if ( isset( $_POST['mml_strings'] ) ) {
    check_admin_referer( 'mml_save_strings' );

    foreach ( $_POST['mml_strings'] as $id => $lang_values ) {
        $id     = absint( $id );
        $json   = [];
        foreach ( $lang_values as $code => $text ) {
            $json[ sanitize_key( $code ) ] = wp_kses_post( $text );
        }
        MML_Strings::update( $id, wp_json_encode( $json ) );
    }

    wp_redirect( add_query_arg( 'saved', '1', $_SERVER['HTTP_REFERER'] ) );
    exit;
}
```

### "Add String Key" Modal

```
Shortcode Key:  [________________]   (lowercase, underscores only)
                e.g. "gioi_thieu" → will create [gioi_thieu] shortcode

                              [Cancel]  [Add Key]
```

- Key is validated: `preg_match('/^[a-z0-9_]+$/', $key)`.
- Duplicate keys are rejected with an inline error.
- On success, the new row appears at the top of the table with empty textarea columns ready for translation.

### "Delete String" Confirmation

- Clicking 🗑️ triggers a JS `confirm()` dialog.
- On confirm, sends AJAX to `admin-ajax.php` with action `mml_delete_string`, `id`, and nonce.
- Row is removed from the DOM on success.

### Performance Consideration

All strings are loaded in **one DB query** (no pagination needed unless > 1,000 strings). The entire table is submitted as a single POST on save. For very large string sets (> 500 rows), a future enhancement could use auto-save on blur per-cell via AJAX.

---

## 4. Admin Columns on Post List Pages

### Pages / Posts / Products

```
Title            | Status | Date   | 🇻🇳 VI | 🇬🇧 EN | 🇨🇳 ZH | 🇷🇺 RU
Trang chủ        | Published | ...  |  ★     |  ✓ pub |  +     |  +
Về chúng tôi     | Published | ...  |  ★     |  ✓ dft |  +     |  +
Sản phẩm nóng    | Published | ...  |  ★     |  +     |  +     |  +
```

Legend:
- `★` = This is the source (default language) post
- `✓ pub` = Translated version exists, **published** — click to edit
- `✓ dft` = Translated version exists, **draft** — click to edit
- `+` = No translation yet — click to clone and create

### Product Categories / Post Categories (Term List)

Same column pattern added via `{$taxonomy}_add_form_fields` equivalent: uses `{$taxonomy}_column` and `manage_{$taxonomy}_custom_column` hooks.

There's also a specific width fix `width: 60px; text-align: center;` in `admin.css` to prevent language column squeezing on busy screens.

---

## 4.5. Edit Screen: Language Sidebar Meta Box

On the individual post, page, or product edit screen (e.g. `post.php`), a dedicated **Languages** Meta Box is injected into the right "save" sidebar.

### Layout Example:

```
┌────────────────────────────────┐
│ 🌐 Languages                    │
├────────────────────────────────┤
│ 🇻🇳 Tiếng Việt   [ CURRENT ]    │
│ 🇬🇧 Tiếng Anh    [    ✓    ]    │
│ 🇨🇳 Tiếng Trung  [    +    ]    │
└────────────────────────────────┘
```

- **[ CURRENT ]**: Identifies the currently active language of the post.
- **`✓` (Green Check)**: A translation exists. Clicking directly edits that translation.
- **`+` (Blue Plus)**: No translation. Clicking runs AJAX clone to instantly duplicate the layout into the target language.

This provides the exact same robust UX as WPML, making translating specific pages drastically faster without leaving the edit screen.

---

## 5. Language Switcher Shortcode: `[my_lang_flags]`

### Output example:

```html
<div class="mml-lang-switcher">
  <a href="/product-category/rem-cua/?lang=vi" class="mml-lang active" hreflang="vi">
    <img src="..." alt="VI"> Tiếng Việt
  </a>
  <a href="/product-category/curtain-en/?lang=en" class="mml-lang" hreflang="en">
    <img src="..." alt="EN"> English
  </a>
</div>
```

### URL-swapping logic in `get_variant_urls()`:

The switcher builds the correct target URL per language by detecting the current page context:

| Context | URL built |
|---|---|
| **Taxonomy archive** (category/tag/product_cat) | Looks up translated `WP_Term` via `MML_Translations`, calls `get_term_link()` on it → e.g. `/product-category/curtain-en/?lang=en` |
| **Single post / page / product** | Looks up translated post via `MML_Translations`, calls `get_permalink()` → e.g. `/?lang=vi` for front page |
| **Archive / search / 404 / other** | Keeps current URL, swaps only `?lang=xx` |

For both taxonomy and post contexts the lookup works **bidirectionally**: if `get_translated_id()` returns null (caller is already on the translated version), `get_all_in_group()` is used to find the right variant for each language.

### Shortcode attributes:
```html
[my_lang_flags show_name="true" show_flag="true" style="inline"]
```

---

## 6. Magic Sync Page

**URL:** `wp-admin/admin.php?page=mml-magic-sync`

### Layout

```
┌──────────────────────────────────────────────────────────┐
│  ⚡ Magic Sync                                            │
├──────────────────────────────────────────────────────────┤
│  Sync to language:  [English (en) ▼]                     │
│                                                          │
│  [▶ Start Magic Sync]                                    │
│                                                          │
│  Progress:  ████████████░░░░  47 / 83                    │
│  Status: Translating "Rèm vải 2 lớp..." → EN            │
│                                                          │
│  ✓ Terms      28 / 28  (categories synced first)         │
│  ✓ Posts      19 / 55                                    │
│  ○ Menus       0 /  2  (queued after posts)              │
└──────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────── ─┐
│  🗑️  Danger Zone                          (red border)   │
├──────────────────────────────────────────────────────────┤
│  Delete all clones for:  [English (en) ▼]                │
│                                                          │
│  [Delete All EN Clones]  ← requires double-confirmation  │
│  "Type DELETE to confirm" prompt before proceeding       │
└──────────────────────────────────────────────────────────┘
```

### Processing order (important for correctness)

The discovery queue is built as: **taxonomy terms first (topological sort — parents before children), then posts/pages/products**. This ensures that when `clone_post()` runs `copy_taxonomies()`, all term translations already exist in `wp_my_translations` and can be remapped correctly.

### Auto-translate behaviour

| Field | Method |
|---|---|
| `post_title` | `MML_Auto_Translate::translate()` — plain text |
| `post_excerpt` | `translate_content()` — HTML-aware |
| `post_content` | `translate_content()` — Flatsome shortcodes + HTML preserved |
| Term `name` | `translate()` |
| Term `description` | `translate()` |
| Post slug (`use_english_slug=0`) | `sanitize_title(translated_title)` + `wp_unique_post_slug()` |
| Post slug (`use_english_slug=1`) | `translate(source_title → 'en')` → `{lang}-sanitize_title(en_title)` + `wp_unique_post_slug()` |
| Term slug (`use_english_slug=0`) | `sanitize_title(translated_name)-{lang}` |
| Term slug (`use_english_slug=1`) | `translate(source_name → 'en')` → `{lang}-sanitize_title(en_name)` |

### Purge (Danger Zone)
AJAX endpoint `mml_magic_sync_purge`: fetches all `object_id` rows for `$target_lang` from `wp_my_translations`, calls `wp_delete_post(true)` / `wp_delete_term()` on each, deletes nav menus ending with `_{lang}`, then removes orphan rows. Requires `manage_options` capability and nonce.

---

## 7. CSS Design Tokens (admin.css)

```css
/* Language switcher badge colors */
.mml-status-plus    { color: #2271b1; font-weight: bold; }
.mml-status-check   { color: #00a32a; }
.mml-status-draft   { color: #dba617; }
.mml-status-default { color: #1d2327; }

/* String translation table */
.mml-strings-table textarea { width: 100%; min-height: 60px; font-size: 12px; }
.mml-strings-table code { background: #f6f7f7; padding: 2px 5px; border-radius: 3px; }

/* Danger Zone card */
.mml-danger-zone { border: 2px solid #d63638; border-radius: 4px; padding: 16px; }
```

---

## 8. Smart Scanner Page

**URL:** `wp-admin/admin.php?page=mml-scanner`

The scanner page is divided into four progressive phases (A → D) rendered as cards.

### Phase A — Scan Controls

```
┌──────────────────────────────────────────────────────────────┐
│  🔍 Phase A — Scan Content                                    │
├──────────────────────────────────────────────────────────────┤
│  Scan options:                                                │
│  [x] Scan UX Builder blocks (post_content)                   │
│  [x] Scan WooCommerce gettext strings                        │
│                                                              │
│  [▶ Start Scan]              X posts will be scanned         │
│                                                              │
│  Progress:  ████████████░░░  35 / 48                         │
└──────────────────────────────────────────────────────────────┘
```

The scan paginates through `post_content` in batches of 20 posts via `mml_scan_batch`. Found matches are displayed in the Phase B review table.

### Phase B — Review & Approve

```
┌─────────────────────────────────────────────────────────────────────┐
│  📋 Phase B — Review Matches                                         │
├──────────┬─────────────────┬──────────────────────────┬─────────────┤
│ Post     │ Found Text      │ Suggested Key            │ Action      │
├──────────┼─────────────────┼──────────────────────────┼─────────────┤
│ Trang chủ│ Chào mừng...    │ [welcome_heading       ] │ [✓ Replace] │
│ Về chúng │ Nhóm Kính là... │ [about_intro           ] │ [✓ Replace] │
└──────────┴─────────────────┴──────────────────────────┴─────────────┘
```

Clicking **Replace** fires `mml_scan_process`:
- Backs up original `post_content` to `wp_mml_backups`
- Writes new row to `wp_my_strings` (`is_autoscanned = 1`) with the VI text pre-populated
- Replaces the literal text with `[my_trans key="X" original="Văn bản gốc"]`

**Manual Add String** panel (below Phase B): allows the admin to manually create a string key + VI text without a scan match.

### Phase C — Restore Sessions

```
┌──────────────────────────────────────────────────────────────┐
│  ↩ Phase C — Restore Sessions                                │
├──────────────┬─────────┬────────────┬────────────────────────┤
│ Session      │ Posts   │ Keys Added │ Actions                │
├──────────────┼─────────┼────────────┼────────────────────────┤
│ 2024-01-15   │    5    │     12     │ [Restore] [Discard]    │
└──────────────┴─────────┴────────────┴────────────────────────┘
```

- **Restore**: Calls `mml_scan_restore` → reverts all modified posts to their Golden Source backup, deletes `is_autoscanned = 1` string rows, re-seeds system strings, truncates backup table.
- **Discard**: Calls `mml_scan_delete_session` → removes the session row from `wp_mml_backups` without restoring content. Used when the admin is happy with the replacements and wants to clear the history.

### Phase D — Rescue Scanner

```
┌──────────────────────────────────────────────────────────────┐
│  🚑 Phase D — Rescue Scanner                                 │
├──────────────────────────────────────────────────────────────┤
│  Scans for old-format [my_trans key="X"] shortcodes that     │
│  are missing the original= attribute (pre-v1.2.0 format).   │
│                                                              │
│  [Step 1: Scan for old-format shortcodes]                    │
│                                                              │
│  Upgradeable (12 found):          Unresolvable (3 found):    │
│  ┌──────────┬──────────────────┐  ┌──────────┬────────────┐  │
│  │ Post     │ Key / VI text    │  │ Post     │ Reason     │  │
│  └──────────┴──────────────────┘  └──────────┴────────────┘  │
│                                                              │
│  [Step 2: Upgrade All Upgradeable]  ← shown only if > 0     │
└──────────────────────────────────────────────────────────────┘
```

- Step 1 paginates via `mml_scan_rescue_scan`; results deduplicated by key.
- Step 2 fires `mml_scan_rescue_upgrade` after a JS `confirm()` dialog.
- After upgrade the button hides and a success notice shows the count.

---

## 9. Admin Notices — Self-Healing Strings

When the `admin_init` hook detects that one or more of the 5 protected system strings are missing from `wp_my_strings`, it re-seeds them and sets a short-lived transient. The `admin_notices` hook then renders a **yellow dismissible notice** at the top of every admin page:

```
┌────────────────────────────────────────────────────────────┐
│  ⚠  My Multilang: 2 system string(s) were missing and     │
│     have been restored.  [View Strings]              [✕]  │
└────────────────────────────────────────────────────────────┘
```

- **View Strings** links to `wp-admin/admin.php?page=mml-strings`.
- The notice is dismissed by clicking ✕ (standard WordPress `is-dismissible` class).
- The transient TTL is 60 seconds — shown once per healing event, not on every page load.
