<?php
/**
 * Smart Scan admin page renderer.
 * Registered as a submenu under "Multilang" by class-mml-admin.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MML_Scanner_UI {

    public static function render_page(): void {
        $sessions = MML_Backup::get_sessions();
        include MML_PATH . 'admin/views/scanner.php';
    }
}
