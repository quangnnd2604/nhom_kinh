<?php
/**
 * Runs on plugin uninstall — drops all custom tables.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$tables = [
    $wpdb->prefix . 'my_languages',
    $wpdb->prefix . 'my_strings',
    $wpdb->prefix . 'my_translations',
];

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore
}

// Remove any saved options
delete_option( 'mml_version' );
delete_option( 'mml_settings' );
