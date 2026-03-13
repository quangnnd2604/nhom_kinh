<?php
/**
 * Database installer — creates all 3 custom tables.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MML_Installer {

    /**
     * Run on plugin activation.
     */
    public static function activate(): void {
        self::create_tables();
        self::seed_default_language();
        self::seed_wc_result_count_strings();
        self::seed_rem_category_grid_strings();
        update_option( 'mml_version', MML_VERSION );
    }

    /**
     * Create tables using dbDelta (safe to run multiple times).
     */
    public static function create_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        // ── wp_my_languages ────────────────────────────────────────────────
        $sql_languages = "CREATE TABLE `{$wpdb->prefix}my_languages` (
            `id`                INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`              VARCHAR(100) NOT NULL,
            `code`              VARCHAR(10)  NOT NULL,
            `flag_id`           BIGINT(20) UNSIGNED DEFAULT 0,
            `is_default`        TINYINT(1) NOT NULL DEFAULT 0,
            `sort_order`        INT(11) NOT NULL DEFAULT 0,
            `use_english_slug`  TINYINT(1) NOT NULL DEFAULT 0,
            `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `code_idx` (`code`)
        ) {$charset};";

        // ── wp_my_strings ──────────────────────────────────────────────────
        $sql_strings = "CREATE TABLE `{$wpdb->prefix}my_strings` (
            `id`              INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `string_key`      VARCHAR(100) NOT NULL,
            `translations`    LONGTEXT NOT NULL,
            `is_autoscanned`  TINYINT(1) NOT NULL DEFAULT 0,
            `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `key_idx` (`string_key`)
        ) {$charset};";

        // ── wp_my_translations ─────────────────────────────────────────────
        $sql_translations = "CREATE TABLE `{$wpdb->prefix}my_translations` (
            `id`           INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `group_id`     VARCHAR(36)  NOT NULL,
            `object_type`  VARCHAR(20)  NOT NULL,
            `object_id`    BIGINT(20) UNSIGNED NOT NULL,
            `lang_code`    VARCHAR(10)  NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_object` (`object_type`, `object_id`),
            KEY `group_idx` (`group_id`),
            KEY `lang_idx` (`lang_code`)
        ) {$charset};";

        dbDelta( $sql_languages );
        dbDelta( $sql_strings );
        dbDelta( $sql_translations );

        // ── wp_mml_backups ─────────────────────────────────────────────────
        $sql_backups = "CREATE TABLE `{$wpdb->prefix}mml_backups` (
            `id`           INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `session_id`   VARCHAR(36) NOT NULL,
            `post_id`      BIGINT(20) UNSIGNED NOT NULL,
            `post_content` LONGTEXT NOT NULL,
            `string_keys`  LONGTEXT NOT NULL DEFAULT '',
            `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `session_idx` (`session_id`),
            KEY `post_idx` (`post_id`)
        ) {$charset};";

        dbDelta( $sql_backups );
    }

    /**
     * Run schema migrations for plugin updates.
     * Uses ALTER TABLE — safe to call repeatedly (idempotent checks via SHOW COLUMNS).
     */
    public static function maybe_upgrade(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'my_languages';

        // v1.1.0: add use_english_slug column
        $col = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'use_english_slug'" ); // phpcs:ignore
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `use_english_slug` TINYINT(1) NOT NULL DEFAULT 0 AFTER `sort_order`" ); // phpcs:ignore
        }

        // v1.1.0: create wp_mml_backups table if it doesn't exist yet
        self::create_tables();

        // v1.2.0: add is_autoscanned column to wp_my_strings
        $strings_table = $wpdb->prefix . 'my_strings';
        $col2 = $wpdb->get_results( "SHOW COLUMNS FROM `{$strings_table}` LIKE 'is_autoscanned'" ); // phpcs:ignore
        if ( empty( $col2 ) ) {
            $wpdb->query( "ALTER TABLE `{$strings_table}` ADD COLUMN `is_autoscanned` TINYINT(1) NOT NULL DEFAULT 0 AFTER `translations`" ); // phpcs:ignore
        }

        // Always (re-)seed WC result-count strings so they survive a DB wipe
        self::seed_wc_result_count_strings();
        self::seed_rem_category_grid_strings();
    }

    /**
     * Seed Vietnamese as the default language (only if table is empty).
     */
    private static function seed_default_language(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'my_languages';

        $exists = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore
        if ( $exists > 0 ) {
            return;
        }

        $wpdb->insert(
            $table,
            [
                'name'       => 'Tiếng Việt',
                'code'       => 'vi',
                'flag_id'    => 0,
                'is_default' => 1,
                'sort_order' => 1,
            ],
            [ '%s', '%s', '%d', '%d', '%d' ]
        );
    }

    /**
     * Self-healing check for core system strings.
     *
     * Compares the 5 known core string keys against what is currently in
     * wp_my_strings. Any missing row is re-seeded immediately (INSERT with
     * full vi + en translations). The MML_Strings in-memory cache is flushed
     * so subsequent get_value() calls see the restored rows within the same
     * request.
     *
     * @return int  Number of strings that were re-inserted (0 = nothing missing).
     */
    public static function maybe_heal_wc_strings(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'my_strings';

        // All core strings that must always exist.
        $core = [
            [
                'string_key' => 'wc_showing_all_d_results',
                'vi'         => 'Hiển thị tất cả %d kết quả',
                'en'         => 'Showing all %d results',
            ],
            [
                'string_key' => 'wc_showing_single_result',
                'vi'         => 'Hiển thị kết quả duy nhất',
                'en'         => 'Showing the single result',
            ],
            [
                'string_key' => 'wc_showing_d_d_of_d_results',
                'vi'         => 'Hiển thị %1$d–%2$d trong tổng số %3$d kết quả',
                'en'         => 'Showing %1$d–%2$d of %3$d results',
            ],
            [
                'string_key' => 'rem_cat_card_btn',
                'vi'         => 'Xem thêm',
                'en'         => 'View More',
            ],
            [
                'string_key' => 'rem_cat_view_all',
                'vi'         => 'Xem tất cả',
                'en'         => 'View All',
            ],
        ];

        // Fetch which keys currently exist (one query).
        $placeholders = implode( ',', array_fill( 0, count( $core ), '%s' ) );
        $keys         = array_column( $core, 'string_key' );
        $existing     = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore
            "SELECT `string_key` FROM `{$table}` WHERE `string_key` IN ({$placeholders})",
            ...$keys
        ) );
        $existing_set = array_flip( $existing );

        $healed = 0;
        foreach ( $core as $seed ) {
            if ( isset( $existing_set[ $seed['string_key'] ] ) ) {
                continue; // Already present — preserve any user edits.
            }

            $wpdb->insert(
                $table,
                [
                    'string_key'     => $seed['string_key'],
                    'translations'   => wp_json_encode(
                        [ 'vi' => $seed['vi'], 'en' => $seed['en'] ],
                        JSON_UNESCAPED_UNICODE
                    ),
                    'is_autoscanned' => 1,
                ],
                [ '%s', '%s', '%d' ]
            );
            $healed++;
        }

        if ( $healed > 0 && class_exists( 'MML_Strings' ) ) {
            MML_Strings::clear_cache();
        }

        return $healed;
    }

    /**
     * Seed the 3 WooCommerce result-count printf-pattern strings into wp_my_strings.
     * These appear in the String Translation UI so the user can fill in translations.
     * Safe to run multiple times — uses INSERT IGNORE (via upsert key check).
     *
     * Default language = vi (Vietnamese). Translations JSON stores:
     *   vi = standard Vietnamese from WooCommerce vi_VN .mo
     *   en = English source pattern (for English frontend views)
     */
    public static function seed_wc_result_count_strings(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'my_strings';

        $seeds = [
            [
                'string_key'   => 'wc_showing_all_d_results',
                'vi'           => 'Hiển thị tất cả %d kết quả',
                'en'           => 'Showing all %d results',
            ],
            [
                'string_key'   => 'wc_showing_single_result',
                'vi'           => 'Hiển thị kết quả duy nhất',
                'en'           => 'Showing the single result',
            ],
            [
                'string_key'   => 'wc_showing_d_d_of_d_results',
                'vi'           => 'Hiển thị %1$d–%2$d trong tổng số %3$d kết quả',
                'en'           => 'Showing %1$d–%2$d of %3$d results',
            ],
        ];

        foreach ( $seeds as $seed ) {
            // Skip if key already registered (don't overwrite user edits)
            $exists = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore
                "SELECT id FROM `{$table}` WHERE string_key = %s",
                $seed['string_key']
            ) );
            if ( $exists ) {
                continue;
            }

            $translations = wp_json_encode(
                [ 'vi' => $seed['vi'], 'en' => $seed['en'] ],
                JSON_UNESCAPED_UNICODE
            );

            $wpdb->insert(
                $table,
                [
                    'string_key'     => $seed['string_key'],
                    'translations'   => $translations,
                    'is_autoscanned' => 1,
                ],
                [ '%s', '%s', '%d' ]
            );
        }
    }

    /**
     * Seed UI strings for rem_category_grid shortcode buttons.
     * Safe to run multiple times — skips if key already exists.
     */
    public static function seed_rem_category_grid_strings(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'my_strings';

        $seeds = [
            [
                'string_key' => 'rem_cat_card_btn',
                'vi'         => 'Xem thêm',
                'en'         => 'View More',
            ],
            [
                'string_key' => 'rem_cat_view_all',
                'vi'         => 'Xem tất cả',
                'en'         => 'View All',
            ],
        ];

        foreach ( $seeds as $seed ) {
            $exists = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore
                "SELECT id FROM `{$table}` WHERE string_key = %s",
                $seed['string_key']
            ) );
            if ( $exists ) {
                continue;
            }

            $translations = wp_json_encode(
                [ 'vi' => $seed['vi'], 'en' => $seed['en'] ],
                JSON_UNESCAPED_UNICODE
            );

            $wpdb->insert(
                $table,
                [
                    'string_key'     => $seed['string_key'],
                    'translations'   => $translations,
                    'is_autoscanned' => 1,
                ],
                [ '%s', '%s', '%d' ]
            );
        }
    }
}
