<?php
/**
 * Backup & Restore engine for the Smart Scan feature.
 *
 * For options-based scans: one row per session (post_id = 0, post_content = '').
 * The string_keys column stores a comma-separated list of all registered keys,
 * which are removed from wp_my_strings on restore.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MML_Backup {

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'mml_backups';
    }

    // ── Write ──────────────────────────────────────────────────────────────

    /**
     * Create a new session UUID.
     */
    public static function new_session_id(): string {
        return wp_generate_uuid4();
    }

    /**
     * Snapshot a post's content — Golden Source edition.
     *
     * The FIRST snapshot of a post_id is the canonical original and is
     * NEVER overwritten, regardless of which session triggers the call.
     * Subsequent scan sessions that touch the same post simply return true
     * (the golden backup is already secure).
     *
     * @param string $session_id UUID (kept for API compatibility; not used in the exists check).
     * @param int    $post_id
     * @param string $post_content Original content BEFORE replacements.
     * @return bool
     */
    public static function snapshot( string $session_id, int $post_id, string $post_content ): bool {
        global $wpdb;
        $table = self::table();

        // Golden Source: if this post has been backed up in ANY previous session,
        // keep the original intact and skip.
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE `post_id` = %d",
                $post_id
            )
        ); // phpcs:ignore

        if ( $exists > 0 ) {
            return true;
        }

        return (bool) $wpdb->insert(
            $table,
            [
                'session_id'   => $session_id,
                'post_id'      => $post_id,
                'post_content' => $post_content,
                'string_keys'  => '',
            ],
            [ '%s', '%d', '%s', '%s' ]
        );
    }

    /**
     * Attach a list of string keys to a backup row (for cleanup on restore).
     *
     * @param string   $session_id
     * @param int      $post_id
     * @param string[] $keys
     */
    public static function attach_keys( string $session_id, int $post_id, array $keys ): void {
        global $wpdb;
        $table = self::table();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, string_keys FROM `{$table}` WHERE `session_id` = %s AND `post_id` = %d",
                $session_id,
                $post_id
            )
        ); // phpcs:ignore

        if ( ! $row ) {
            return;
        }

        $existing = $row->string_keys ? array_filter( explode( ',', $row->string_keys ) ) : [];
        $merged   = array_unique( array_merge( $existing, $keys ) );

        $wpdb->update(
            $table,
            [ 'string_keys' => implode( ',', $merged ) ],
            [ 'id' => (int) $row->id ],
            [ '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Log a list of registered string keys for an options-based scan session.
     * Inserts a single row with post_id=0 and the keys as a CSV string.
     *
     * @param string   $session_id
     * @param string[] $keys
     * @return bool
     */
    public static function log_keys( string $session_id, array $keys ): bool {
        global $wpdb;
        return (bool) $wpdb->insert(
            self::table(),
            [
                'session_id'   => $session_id,
                'post_id'      => 0,
                'post_content' => '',
                'string_keys'  => implode( ',', array_filter( $keys ) ),
            ],
            [ '%s', '%d', '%s', '%s' ]
        );
    }

    // ── Read ───────────────────────────────────────────────────────────────

    /**
     * List all backup sessions (one row per session, latest first).
     *
     * @return array[]  Each item: { session_id, post_count, key_count, created_at }
     */
    public static function get_sessions(): array {
        global $wpdb;
        $table = self::table();

        $rows = $wpdb->get_results(
            "SELECT `session_id`,
                    COUNT(*) AS post_count,
                    MIN(`created_at`) AS created_at,
                    GROUP_CONCAT(`string_keys` SEPARATOR ',') AS all_keys
             FROM `{$table}`
             GROUP BY `session_id`
             ORDER BY MIN(`created_at`) DESC
             LIMIT 20"
        ); // phpcs:ignore

        if ( ! $rows ) {
            return [];
        }

        foreach ( $rows as $row ) {
            $keys           = array_filter( explode( ',', (string) $row->all_keys ) );
            $row->key_count = count( $keys );
        }

        return $rows;
    }

    /**
     * Get all backup rows for a session.
     *
     * @param string $session_id
     * @return object[]
     */
    public static function get_session_rows( string $session_id ): array {
        global $wpdb;
        $table = self::table();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE `session_id` = %s",
                $session_id
            )
        ) ?: []; // phpcs:ignore
    }

    // ── Restore ────────────────────────────────────────────────────────────

    /**
     * Restore all posts from their golden backups, then delete the backup rows.
     *
     * Under the Golden Source model:
     *  - The EARLIEST backup row for each post_id is the true original.
     *  - ALL posts that were ever backed up are restored in a single operation.
     *  - wp_my_strings is NEVER touched — translations persist after restore.
     *  - Backup rows are deleted once the restore is complete (clean slate).
     *
     * @param string $session_id  Kept for API compatibility; not used in restore logic.
     * @return array { restored_posts: int }
     */
    public static function restore_session( string $session_id ): array {
        global $wpdb;
        $table = self::table();

        // ── 1. Collect golden (oldest) backup for each post ───────────────────
        $all_rows = $wpdb->get_results( // phpcs:ignore
            "SELECT post_id, post_content FROM `{$table}` WHERE post_id > 0 ORDER BY id ASC"
        ) ?: [];

        // First occurrence of each post_id (ordered by PK ASC) = the true original.
        $golden = [];
        foreach ( $all_rows as $row ) {
            $pid = (int) $row->post_id;
            if ( ! isset( $golden[ $pid ] ) ) {
                $golden[ $pid ] = $row->post_content;
            }
        }

        // ── 2. Restore each post to its golden content ────────────────────────
        $restored_posts = 0;
        foreach ( $golden as $post_id => $original_content ) {
            $result = wp_update_post( [
                'ID'           => $post_id,
                'post_content' => $original_content,
            ] );
            if ( $result && ! is_wp_error( $result ) ) {
                $restored_posts++;
            }
        }

        // ── 3. Atomic cleanup: delete auto-scanned strings ──────────────────────
        // Removes all strings appended by scanner runs (is_autoscanned = 1).
        // Manual strings (is_autoscanned = 0) are always preserved.
        // This must run BEFORE truncating backup rows so both happen together.
        $removed_keys = (int) $wpdb->get_var( // phpcs:ignore
            "SELECT COUNT(*) FROM `{$wpdb->prefix}my_strings` WHERE is_autoscanned = 1"
        );
        $wpdb->query( "DELETE FROM `{$wpdb->prefix}my_strings` WHERE is_autoscanned = 1" ); // phpcs:ignore

        // ── 3b. Re-inject permanent system strings ───────────────────────────────
        // These are functional strings required by the plugin's frontend filters
        // (WC result count, rem_category_grid buttons). They are re-seeded
        // immediately after every restore so the String Translation UI always
        // shows them and the frontend never loses translations.
        MML_Installer::seed_wc_result_count_strings();
        MML_Installer::seed_rem_category_grid_strings();

        // ── 4. Clear all backup rows (clean slate for next scan cycle) ──────────
        $wpdb->query( 'TRUNCATE TABLE `' . $table . '`' ); // phpcs:ignore

        return [
            'restored_posts' => $restored_posts,
            'removed_keys'   => $removed_keys,
        ];
    }

    // ── Delete ─────────────────────────────────────────────────────────────

    /**
     * Delete all rows for a session without restoring.
     */
    public static function delete_session( string $session_id ): void {
        global $wpdb;
        $wpdb->delete(
            self::table(),
            [ 'session_id' => $session_id ],
            [ '%s' ]
        );
    }

    /**
     * Delete ALL backup rows ("Clear Backup" admin action).
     * This permanently discards all golden snapshots.
     * Use only when the user explicitly clicks "Clear All Backups".
     */
    public static function delete_all(): void {
        global $wpdb;
        $wpdb->query( 'TRUNCATE TABLE `' . self::table() . '`' ); // phpcs:ignore
    }
}
