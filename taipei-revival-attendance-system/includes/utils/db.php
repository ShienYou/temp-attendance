<?php
if (!defined('ABSPATH')) { exit; }

/**
 * DB helper (LOW-LEVEL ONLY)
 *
 * Boundary (SSOT v1.5):
 * - Provide low-level helpers only.
 * - NO business queries.
 * - NO table-specific logic.
 * - Core layer is the ONLY caller.
 */

/**
 * Get wpdb instance
 *
 * @return wpdb
 */
function tr_as_db(): wpdb {
    global $wpdb;
    return $wpdb;
}

/**
 * Prepare SQL safely
 *
 * Usage:
 *   tr_as_db_prepare(
 *     "SELECT * FROM {$table} WHERE id = %d AND status = %s",
 *     [$id, $status]
 *   );
 *
 * @param string $sql
 * @param array  $args
 * @return string
 */
function tr_as_db_prepare(string $sql, array $args = []): string {
    $wpdb = tr_as_db();

    if (empty($args)) {
        return $sql;
    }

    // wpdb::prepare accepts either variadic or array
    return $wpdb->prepare($sql, $args);
}

/**
 * Execute a write query (INSERT / UPDATE / DELETE)
 *
 * - Throws no exception.
 * - Returns affected rows or false.
 *
 * @param string $sql
 * @return int|false
 */
function tr_as_db_execute(string $sql) {
    $wpdb = tr_as_db();
    return $wpdb->query($sql);
}

/**
 * Execute a SELECT query and return results as array
 *
 * @param string $sql
 * @param string $output OBJECT|ARRAY_A|ARRAY_N
 * @return array
 */
function tr_as_db_get_results(string $sql, string $output = OBJECT): array {
    $wpdb = tr_as_db();
    return (array) $wpdb->get_results($sql, $output);
}

/**
 * Execute a SELECT query and return single row
 *
 * @param string $sql
 * @param string $output OBJECT|ARRAY_A|ARRAY_N
 * @return mixed
 */
function tr_as_db_get_row(string $sql, string $output = OBJECT) {
    $wpdb = tr_as_db();
    return $wpdb->get_row($sql, $output);
}

/**
 * Execute a SELECT query and return single value
 *
 * @param string $sql
 * @return mixed
 */
function tr_as_db_get_var(string $sql) {
    $wpdb = tr_as_db();
    return $wpdb->get_var($sql);
}

/**
 * Transaction wrapper (optional)
 *
 * NOTE:
 * - MySQL InnoDB only.
 * - Safe to call even if nested (best-effort).
 * - Core decides whether to use transaction or not.
 *
 * @param callable $callback
 * @return mixed
 */
function tr_as_db_transaction(callable $callback) {

    $wpdb = tr_as_db();

    // Start transaction (best-effort)
    $wpdb->query('START TRANSACTION');

    try {
        $result = $callback();

        // Commit if no exception
        $wpdb->query('COMMIT');
        return $result;

    } catch (Throwable $e) {

        // Rollback on error
        $wpdb->query('ROLLBACK');

        // Re-throw to core layer
        throw $e;
    }
}
