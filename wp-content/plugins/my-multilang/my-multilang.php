<?php
/**
 * Plugin Name:  My Multilang
 * Plugin URI:   https://github.com/quangnnd2604/nhom_kinh
 * Description:  Custom lightweight multilingual plugin optimised for Flatsome + WooCommerce. No WPML, no Polylang.
 * Version:      1.0.0
 * Author:       Nhóm Kính Dev
 * Text Domain:  my-multilang
 * Domain Path:  /languages
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Constants ──────────────────────────────────────────────────────────────
define( 'MML_VERSION', '1.0.0' );
define( 'MML_PATH',    plugin_dir_path( __FILE__ ) );
define( 'MML_URL',     plugin_dir_url( __FILE__ ) );

// ── Autoload ───────────────────────────────────────────────────────────────
$mml_files = [
    // Core
    MML_PATH . 'includes/class-mml-installer.php',
    MML_PATH . 'includes/class-mml-languages.php',
    MML_PATH . 'includes/class-mml-strings.php',
    MML_PATH . 'includes/class-mml-translations.php',
    MML_PATH . 'includes/class-mml-shortcodes.php',
    MML_PATH . 'includes/class-mml-cloner.php',
    MML_PATH . 'includes/class-mml-menu.php',
    MML_PATH . 'includes/class-mml-meta-box.php',
    MML_PATH . 'includes/class-mml-auto-translate.php',
    MML_PATH . 'includes/class-mml-magic-sync.php',
    MML_PATH . 'test_gg.php',
];

foreach ( $mml_files as $file ) {
    if ( file_exists( $file ) ) {
        require_once $file;
    }
}

// Admin-only files
if ( is_admin() ) {
    $mml_admin_files = [
        MML_PATH . 'admin/class-mml-admin.php',
        MML_PATH . 'admin/class-mml-list-table-strings.php',
        MML_PATH . 'admin/class-mml-post-columns.php',
        MML_PATH . 'admin/class-mml-magic-sync-ui.php',
    ];
    foreach ( $mml_admin_files as $file ) {
        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }
    new MML_Admin();
    new MML_Post_Columns();
}

// ── Activation / Deactivation ──────────────────────────────────────────────
register_activation_hook( __FILE__, [ 'MML_Installer', 'activate' ] );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

// ── Cancel canonical redirect entirely when ?lang= is present ──────────────
// WordPress redirect_canonical() skips redirect when filter returns falsy.
// This ensures /home/?lang=en, /about/?lang=en etc. are never stripped.
add_filter( 'redirect_canonical', 'mml_prevent_lang_redirect', 1, 2 );
function mml_prevent_lang_redirect( $redirect_url, $requested_url ) {
    // If the current request has a lang param, cancel any canonical redirect.
    // This prevents WordPress from stripping ?lang=en off non-front-page URLs.
    if ( isset( $_GET['lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
        return false;
    }
    return $redirect_url;
}

// ── Protect the static front page when ?lang= is present ───────────────────
// When ?lang=vi is added to the front page URL (/?lang=vi), WordPress's query
// parser may override the is_page/is_front_page routing. This filter restores it.
add_filter( 'request', 'mml_restore_front_page_query' );
function mml_restore_front_page_query( array $query_vars ): array {
    // Only act on the front page (the static page set in Settings > Reading)
    $front_page_id = (int) get_option( 'page_on_front' );
    if ( ! $front_page_id ) {
        return $query_vars; // No static front page configured
    }

    // Check if we're requesting the site root (home URL path)
    $request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : ''; // phpcs:ignore
    $home_path    = parse_url( home_url( '/' ), PHP_URL_PATH );

    if ( rtrim( $request_uri, '/' ) === rtrim( $home_path, '/' ) ) {
        // We're at the front page URL — ensure WordPress serves the static page
        $query_vars = [
            'page_id' => $front_page_id,
            'lang'    => $query_vars['lang'] ?? '',
        ];
    }

    return $query_vars;
}

// ── Language Detection (runs as early as possible) ─────────────────────────
add_action( 'plugins_loaded', 'mml_detect_language', 1 );

function mml_detect_language(): void {
    $lang = null;

    // 1. URL query param — check both $_GET and the raw REQUEST_URI
    //    (WordPress may have processed it into $wp->query_vars already)
    if ( isset( $_GET['lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
        $lang = sanitize_key( wp_unslash( $_GET['lang'] ) ); // phpcs:ignore
    }

    // Save to cookie whenever a lang param is present (do it regardless of headers_sent
    // since we only USE $lang below; the cookie is just persistence for next request)
    if ( $lang ) {
        if ( ! headers_sent() ) {
            setcookie( 'my_lang', $lang, time() + MONTH_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
        }
    }

    // 2. Cookie (fallback when no ?lang= in URL)
    if ( ! $lang && isset( $_COOKIE['my_lang'] ) ) {
        $lang = sanitize_key( wp_unslash( $_COOKIE['my_lang'] ) );
    }

    // 3. Accept-Language header (first-visit auto-detect)
    if ( ! $lang && isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
        $lang = substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ), 0, 2 );
    }

    // Validate against DB; fall back to site default
    $valid_codes  = MML_Languages::get_active_codes();
    $default_code = MML_Languages::get_default_code();
    $lang         = ( $lang && in_array( $lang, $valid_codes, true ) ) ? $lang : $default_code;

    if ( ! defined( 'MML_LANG' ) ) {
        define( 'MML_LANG', $lang );
    }
}

// ── Frontend hooks (outside admin) ─────────────────────────────────────────
add_action( 'init', [ 'MML_Shortcodes', 'register' ], 20 );
add_action( 'init', [ 'MML_Menu', 'init' ], 5 );
add_action( 'init', [ 'MML_Translations', 'init' ], 10 );
add_action( 'init', [ 'MML_Meta_Box', 'init' ], 10 );
add_action( 'init', [ 'MML_Magic_Sync', 'init' ], 10 );

add_action( 'wp_enqueue_scripts', function () {
    wp_enqueue_style( 'mml-front', MML_URL . 'assets/front.css', [], MML_VERSION );
} );

// ── Language Switcher: Cookie-First 2-Step Mechanism ───────────────────────
// When visiting /?mml_set_lang=en&redirect_to=/home/:
//   1. Sets cookie my_lang=en reliably at root URL (no rewrite rules interference)
//   2. Redirects to the translated page (/home/)
//   3. On /home/ (no ?lang= param), cookie is read → MML_LANG='en' → shortcodes work
//
// This is needed because WordPress pretty-permalink rewrite rules can strip
// unknown query params (like ?lang=en) from non-root URLs on some server configs.
add_action( 'template_redirect', 'mml_handle_set_lang', 1 );
function mml_handle_set_lang(): void {
    if ( ! isset( $_GET['mml_set_lang'] ) ) { // phpcs:ignore
        return;
    }

    $lang = sanitize_key( wp_unslash( $_GET['mml_set_lang'] ) ); // phpcs:ignore

    // Validate against active languages
    $valid_codes = MML_Languages::get_active_codes();
    if ( ! in_array( $lang, $valid_codes, true ) ) {
        wp_redirect( home_url( '/' ) );
        exit;
    }

    // Set the language cookie
    setcookie( 'my_lang', $lang, time() + MONTH_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );

    // Redirect to the target page (default: home)
    $redirect_to = isset( $_GET['redirect_to'] ) // phpcs:ignore
        ? wp_sanitize_redirect( wp_unslash( $_GET['redirect_to'] ) ) // phpcs:ignore
        : home_url( '/' );

    // Safety: only allow same-site redirects
    if ( ! str_starts_with( $redirect_to, home_url() ) ) {
        $redirect_to = home_url( '/' );
    }

    wp_redirect( $redirect_to, 302 );
    exit;
}

// ── Frontend: Redirect taxonomy archives to the translated term URL ────────
// When MML_LANG ≠ default and the visitor lands on a default-language category
// URL (e.g. /product-category/kinh-cuong-luc/), redirect them to the translated
// term's permalink (e.g. /product-category/tempered-glass-en/?lang=en).
add_action( 'template_redirect', 'mml_taxonomy_archive_lang_redirect', 5 );
function mml_taxonomy_archive_lang_redirect(): void {
    if ( is_admin() || ! defined( 'MML_LANG' ) ) {
        return;
    }

    $default_lang = MML_Languages::get_default_code();
    if ( MML_LANG === $default_lang ) {
        return;
    }

    // Only act on taxonomy (category / tag / custom taxonomy) archive pages
    if ( ! is_tax() && ! is_category() && ! is_tag() ) {
        return;
    }

    $queried = get_queried_object();
    if ( ! ( $queried instanceof WP_Term ) ) {
        return;
    }

    // Look up the MML_LANG version of the currently viewed term
    $translated_id = MML_Translations::get_translated_id( $queried->term_id, MML_LANG, 'term' );

    // No translation, or we are already viewing the translated term → nothing to do
    if ( ! $translated_id || $translated_id === $queried->term_id ) {
        return;
    }

    $translated_term = get_term( $translated_id, $queried->taxonomy );
    if ( ! $translated_term || is_wp_error( $translated_term ) ) {
        return;
    }

    $url = get_term_link( $translated_term );
    if ( is_wp_error( $url ) ) {
        return;
    }

    // Carry ?lang= so detection fires on arrival without relying on the cookie alone
    $url = add_query_arg( 'lang', MML_LANG, $url );

    wp_redirect( $url, 302 );
    exit;
}

// ── Helpers: batch-load term ID sets once per request (static cache) ────────
// Instead of one DB query per term (N+1 problem), we load two lookup arrays
// a single time and reuse them everywhere below.

/**
 * Return all term IDs registered for $lang in wp_my_translations.
 * Statically cached — safe to call many times per request.
 */
function mml_get_lang_term_ids( string $lang ): array {
    global $wpdb;
    static $cache = [];
    if ( array_key_exists( $lang, $cache ) ) {
        return $cache[ $lang ];
    }
    $table = $wpdb->prefix . 'my_translations';
    $rows  = $wpdb->get_col( $wpdb->prepare(
        "SELECT `object_id` FROM `{$table}` WHERE `object_type` = 'term' AND `lang_code` = %s",
        $lang
    ) );
    $cache[ $lang ] = array_map( 'intval', $rows ?: [] );
    return $cache[ $lang ];
}

/**
 * Return ALL term IDs that appear in wp_my_translations (any language).
 * Used to distinguish "managed" versus "unmanaged/legacy" terms.
 * Statically cached — safe to call many times per request.
 */
function mml_get_all_managed_term_ids(): array {
    global $wpdb;
    static $ids = null;
    if ( $ids !== null ) {
        return $ids;
    }
    $table = $wpdb->prefix . 'my_translations';
    $rows  = $wpdb->get_col(
        "SELECT `object_id` FROM `{$table}` WHERE `object_type` = 'term'"
    );
    $ids = array_map( 'intval', $rows ?: [] );
    return $ids;
}

// ── Frontend: Language filter + parent expansion at the SQL level ─────────
// Applies for EVERY active language (including default VI). This ensures:
//   VI active → SQL returns only VI terms + unmanaged  (excludes EN, ZH, RU…)
//   EN active → SQL returns only EN terms + unmanaged  (excludes VI, ZH, RU…)
//   ZH active → SQL returns only ZH terms + unmanaged  (excludes VI, EN, RU…)
//   … and so on for any number of languages.
//
// Part A – (non-default lang only) expand  t.parent = X  →  t.parent IN (vi_id, en_id, …)
//   so old clones whose parent column still holds the default-lang parent ID are found.
//
// Part B – always restrict  t.term_id  to the active language OR unmanaged terms:
//   t.term_id IN (active_lang_term_ids)  OR  t.term_id NOT IN (all_managed_ids)
add_filter( 'terms_clauses', 'mml_terms_clauses_lang_filter', 10, 3 );
function mml_terms_clauses_lang_filter( array $clauses, array $taxonomies, array $args ): array {
    if ( is_admin() || ! defined( 'MML_LANG' ) ) {
        return $clauses;
    }

    $default_lang = MML_Languages::get_default_code();

    // ── Skip slug/name lookups — WordPress uses these to resolve taxonomy
    //    archive URLs (e.g., calls WP_Term_Query with slug='kinh-cuong-luc'
    //    while parsing /product-category/kinh-cuong-luc/).
    //    If we filter here, the VI term is excluded before the
    //    mml_taxonomy_archive_lang_redirect can fire, causing a 404.
    //    The redirect still works: once it fires it sends the browser to the
    //    EN slug, which IS in the EN lang_ids and passes Part B just fine.
    if ( ! empty( $args['slug'] ) || ! empty( $args['name__in'] ) || ! empty( $args['name'] ) ) {
        return $clauses;
    }

    // ── Part A: expand parent filter (needed for non-default languages) ───
    if ( MML_LANG !== $default_lang ) {
        $parent_id = isset( $args['parent'] ) ? (int) $args['parent'] : 0;
        if ( $parent_id > 0 ) {
            $group_id = MML_Translations::get_group_id( $parent_id, 'term' );
            if ( $group_id ) {
                $variants = MML_Translations::get_all_in_group( $group_id );
                if ( count( $variants ) > 1 ) {
                    $all_ids  = implode( ',', array_map( 'intval', array_values( $variants ) ) );
                    $pattern  = '/\bt\.parent\s*=\s*\'?' . preg_quote( (string) $parent_id, '/' ) . '\'?/';
                    $clauses['where'] = preg_replace(
                        $pattern,
                        "t.parent IN ($all_ids)",
                        $clauses['where'] ?? ''
                    );
                }
            }
        }
    }

    // ── Part B: restrict to CURRENT language + unmanaged terms ───────────
    $lang_ids    = mml_get_lang_term_ids( MML_LANG );
    $managed_ids = mml_get_all_managed_term_ids();

    if ( empty( $managed_ids ) ) {
        return $clauses; // Nothing in the plugin's table — nothing to filter
    }

    $managed_list = implode( ',', $managed_ids );

    if ( ! empty( $lang_ids ) ) {
        $lang_list = implode( ',', $lang_ids );
        $clauses['where'] .= " AND (t.term_id IN ($lang_list) OR t.term_id NOT IN ($managed_list))";
    } else {
        // No translated terms for this language yet — show only unmanaged terms
        $clauses['where'] .= " AND t.term_id NOT IN ($managed_list)";
    }

    return $clauses;
}

// ── Frontend: Fix parent field on term objects returned from SQL ──────────
// SQL returns only correct-language terms. This PHP filter remaps the `parent`
// field when a cloned term was saved with a wrong-language parent ID.
// Runs for ALL languages so switching back to default also works correctly.
add_filter( 'get_terms', 'mml_fix_get_terms_parents', 10, 4 );
function mml_fix_get_terms_parents( $terms, $taxonomies, $args, $term_query ) {
    if ( is_admin() || ! defined( 'MML_LANG' ) || ! is_array( $terms ) || empty( $terms ) ) {
        return $terms;
    }

    static $in_filter = false;
    if ( $in_filter ) {
        return $terms;
    }
    $in_filter = true;

    $default_lang = MML_Languages::get_default_code();
    $result = [];
    foreach ( $terms as $term ) {
        $result[] = ( $term instanceof WP_Term )
            ? mml_fix_term_parent( $term, MML_LANG, $default_lang )
            : $term;
    }

    $in_filter = false;
    return $result;
}

/**
 * Fix a term object's `parent` property so it points to the same-language
 * parent instead of a different-language parent ID (old clone issue).
 * Clones the WP_Term rather than mutating the object cache entry.
 */
function mml_fix_term_parent( WP_Term $term, string $current_lang, string $default_lang ): WP_Term {
    if ( $term->parent <= 0 ) {
        return $term;
    }

    // If parent is already in the current-language set, nothing to fix
    $current_ids = mml_get_lang_term_ids( $current_lang );
    if ( in_array( (int) $term->parent, $current_ids, true ) ) {
        return $term;
    }

    // Parent belongs to a different language — find the current-lang equivalent
    $group_id = MML_Translations::get_group_id( (int) $term->parent, 'term' );
    if ( ! $group_id ) {
        return $term;
    }
    $variants       = MML_Translations::get_all_in_group( $group_id );
    $correct_parent = isset( $variants[ $current_lang ] ) ? (int) $variants[ $current_lang ] : 0;

    if ( ! $correct_parent || $correct_parent === (int) $term->parent ) {
        return $term;
    }

    $fixed         = clone $term;
    $fixed->parent = $correct_parent;
    return $fixed;
}

// ── Frontend: Swap terms served via the WP object cache (get_the_terms) ───
// WooCommerce caches product terms early (before our terms_clauses filter
// runs). `get_the_terms` fires after the cache read, so we remap any wrong-
// language terms using in-memory ID maps (no extra DB queries).
// Runs for ALL languages including default.
add_filter( 'get_the_terms', 'mml_swap_the_terms', 10, 3 );
function mml_swap_the_terms( $terms, $post_id, $taxonomy ) {
    if ( is_admin() || ! defined( 'MML_LANG' ) || ! is_array( $terms ) || empty( $terms ) ) {
        return $terms;
    }

    static $in_swap = false;
    if ( $in_swap ) {
        return $terms;
    }
    $in_swap = true;

    $default_lang = MML_Languages::get_default_code();
    $lang_ids     = mml_get_lang_term_ids( MML_LANG );
    $managed_ids  = mml_get_all_managed_term_ids();
    $result       = [];
    $seen_ids     = [];

    foreach ( $terms as $term ) {
        if ( ! ( $term instanceof WP_Term ) ) {
            $result[] = $term;
            continue;
        }
        if ( in_array( $term->term_id, $seen_ids, true ) ) {
            continue;
        }

        if ( in_array( $term->term_id, $lang_ids, true ) ) {
            // Already the correct language — just fix parent if needed
            $result[]   = mml_fix_term_parent( $term, MML_LANG, $default_lang );
            $seen_ids[] = $term->term_id;

        } elseif ( in_array( $term->term_id, $managed_ids, true ) ) {
            // Term belongs to a DIFFERENT language — swap to current lang
            $trans_id = MML_Translations::get_translated_id( $term->term_id, MML_LANG, 'term' );
            if ( $trans_id && ! in_array( $trans_id, $seen_ids, true ) ) {
                $trans = get_term( $trans_id, $term->taxonomy );
                if ( $trans && ! is_wp_error( $trans ) ) {
                    $result[]   = mml_fix_term_parent( $trans, MML_LANG, $default_lang );
                    $seen_ids[] = $trans_id;
                    continue;
                }
            }
            // No translation found — show original as fallback
            $result[]   = $term;
            $seen_ids[] = $term->term_id;

        } else {
            // Unmanaged term (not in plugin table) — show as-is
            $result[]   = $term;
            $seen_ids[] = $term->term_id;
        }
    }

    $in_swap = false;
    return $result;
}

