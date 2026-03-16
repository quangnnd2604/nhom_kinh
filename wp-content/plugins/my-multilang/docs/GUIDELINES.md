# My Multilang — Developer Golden Rules

> **Phải tuân thủ tuyệt đối.**
> These rules are the Core Constitution of this plugin.
> Read this file before writing any code that touches content sync, translation, or DB restoration.

---

## RULE 1 — GOLDEN SOURCE CLONING _(The Master Rule)_

Every cloning, syncing, or translation action MUST use the **Default Language** (Golden Source) as the **only** authoritative input.

### What this means

| Allowed | Forbidden |
|---|---|
| `vi → en` | `en → ko` |
| `vi → ko` | `en → th` |
| `vi → th` | Cloning ANY non-default object |

### How it is enforced — Defence in depth

```
Entry point 1 (Magic Sync discovery)
  └─ ajax_discover() filters every term/post through get_lang_for_object()
     → skips any item where lang_code ∉ {null, $default_lang}

Entry point 2 (Magic Sync execution)
  └─ ajax_execute_item() safety gate
     → if received item is a clone, resolve the VI source before proceeding

Entry point 3 (Manual clone button / any direct caller)
  └─ MML_Cloner::clone_post() Golden Source Guard
     └─ MML_Cloner::clone_term() Golden Source Guard
        → resolve canonical default-language ID from same translation group
        → return WP_Error('no_source') if no default-language origin exists
```

### Rule for new entry points

Any new feature (WP-CLI command, REST endpoint, cron job, bulk action) that clones or syncs content **does not need its own guard** — call `MML_Cloner::clone_post()` or `clone_term()` and the guard fires automatically. Never bypass the Cloner by calling `wp_insert_post()` directly for translation purposes.

### Key methods

```php
// Determine which language owns an object
MML_Translations::get_lang_for_object( int $object_id, 'post'|'term' ): ?string

// Find the default-language sibling in the same group
MML_Translations::get_translated_id( int $object_id, $default_lang, 'post'|'term' ): ?int

// Get the current default language code (never hardcode 'vi')
MML_Languages::get_default_code(): string
```

---

## RULE 2 — SMART SHORTCODE FALLBACK

All translatable text fragments stored in `post_content` MUST use the full shortcode format with the `original=` attribute.

### Required format

```
[my_trans key="UNIQUE_KEY" original="Văn bản gốc Tiếng Việt"]
```

### Resolution priority

```
1. Active language translation from wp_my_strings  ← best case
2. original= attribute value                        ← key missing from DB
3. Empty string                                     ← should never happen with this rule
```

### Implementation note

`MML_Strings::get_value()` returns the sentinel `'[key]'` when a key does not exist. The shortcode renderer in `render_my_trans()` detects the sentinel and falls back to `original=`. If `original=` is absent (legacy format), the output is an empty string — this is a content regression and must be fixed with the Rescue Scanner.

### Checklist

- [ ] Smart Scan replace action always embeds `original=` (it does today — never remove this)
- [ ] Any code that programmatically inserts a `[my_trans]` shortcode includes `original="..."`
- [ ] Old `[my_trans key="X"]` shortcodes are upgraded via the Rescue Scanner before go-live

---

## RULE 3 — ATOMIC RESTORATION & CLEANUP

"Restore All" must execute as a single **ordered, non-interruptible** sequence. The steps below must not be reordered or split.

### Canonical sequence

```
1. SELECT oldest backup row per post_id   (Golden Source row)
2. wp_update_post()  ← restore content from Golden Source
3. DELETE wp_my_strings WHERE is_autoscanned = 1
4. Re-seed protected system strings       ← seed_wc_result_count_strings()
                                             seed_rem_category_grid_strings()
5. TRUNCATE wp_mml_backups
```

### Why this order is mandatory

- Step 3 deletes auto-scanned keys including potentially the WC/theme system keys.
- Step 4 immediately restores those system keys — they must **never** be absent from the DB.
- Reversing 3 and 4 is a no-op (IGNORE handles duplicates), but the wrong order would leave a window where the frontend shows blank WC strings.

### What is never deleted

| Data | Deleted on Restore? |
|---|---|
| `is_autoscanned = 1` string rows | **Yes** |
| `is_autoscanned = 0` (manual) string rows | **No** |
| `wp_my_languages` rows | **No** |
| `wp_my_translations` mappings | **No** |

### Owner

`MML_Backup::restore_session()` — do not duplicate this logic outside this method.

---

## RULE 4 — PRE-FLIGHT CONFIRMATION (Magic Sync)

Any AI-assisted batch operation (Magic Sync) that creates, modifies, or deletes translated content MUST show a confirmation modal before starting.

### Required modal elements

1. **Target language** — full name + code: `Korean (ko)`
2. **Sample translation** — demonstrates the actual translation direction: `"Xin chào" → "안녕하세요"`
3. **Scope warning** — describes what will be created/overwritten
4. **Abort button** — "Hủy bỏ" — closes modal, no action taken
5. **Confirm button** — "✔ Xác nhận & Bắt đầu" — triggers `ajax_discover()` AJAX call

### Constraints

- The AJAX discovery call fires **only** on confirm. Never on the initial button press.
- `exampleMap` (from `mml_language_registry_by_code()`) supplies sample text per code. Languages not in the registry show name/code but no sample line.
- Implemented in `admin/class-mml-magic-sync-ui.php`. `<option>` tags carry `data-name` and `data-ai` attributes consumed by the modal JS.

---

## Pre-Coding Checklist

Copy this checklist into your mental review before each task:

```
□ R1  Is every clone/sync sourced from the DEFAULT LANGUAGE object?
      → Did I call get_lang_for_object() + resolve via get_translated_id()?
      → Am I going through MML_Cloner, not wp_insert_post() directly?

□ R2  If inserting [my_trans] shortcode programmatically:
      → Does it include original="..."?

□ R3  If touching the restore flow:
      → Is the delete-then-reseed sequence intact and in the correct order?
      → Am I NOT deleting is_autoscanned = 0 rows?

□ R4  If adding a new batch AI/sync operation:
      → Is there a modal confirmation before any AJAX work starts?
      → Does the modal include language name, sample translation, and warning?
```

---

## Relationship Model Reference

```
wp_my_translations  (flat peer model — NO chain translations)

 group_id │ object_type │ object_id │ lang_code
──────────┼─────────────┼───────────┼──────────
 uuid-A   │ post        │ 101       │ vi   ← CANONICAL (default/Golden Source)
 uuid-A   │ post        │ 102       │ en   ── sibling
 uuid-A   │ post        │ 103       │ ko   ── sibling
 uuid-A   │ post        │ 104       │ th   ── sibling
```

All languages sit as **direct peers** under one `group_id`. There is no stored "parent ID" — any translation is resolved by: (1) look up `group_id` for the object, (2) find the row with the desired `lang_code`. Adding a 10th language adds a 10th peer row — no schema change, no chain.

---

_Last updated: 2026-03-16 — Golden Rules formalised in v1.2.2_
