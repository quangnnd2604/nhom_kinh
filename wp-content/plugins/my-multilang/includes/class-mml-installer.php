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
            `id`          INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`        VARCHAR(100) NOT NULL,
            `code`        VARCHAR(10)  NOT NULL,
            `flag_id`     BIGINT(20) UNSIGNED DEFAULT 0,
            `is_default`  TINYINT(1) NOT NULL DEFAULT 0,
            `sort_order`  INT(11) NOT NULL DEFAULT 0,
            `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `code_idx` (`code`)
        ) {$charset};";

        // ── wp_my_strings ──────────────────────────────────────────────────
        $sql_strings = "CREATE TABLE `{$wpdb->prefix}my_strings` (
            `id`            INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `string_key`    VARCHAR(100) NOT NULL,
            `translations`  LONGTEXT NOT NULL,
            `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
}
