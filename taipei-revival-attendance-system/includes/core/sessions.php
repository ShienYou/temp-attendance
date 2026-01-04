<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ------------------------------------------------------------------
 * Sessions Core Service
 *
 * Responsibility (SSOT v1.5):
 * - Session listing & retrieval
 * - Writable state assertion
 * - Admin bulk creation
 *
 * Boundary:
 * - NO UI logic
 * - NO attendance / headcount logic
 * - NO statistics
 * - NO direct $wpdb usage (must go through includes/utils/db.php)
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../utils/db.php';
require_once __DIR__ . '/auth.php';

/**
 * ------------------------------------------------------------------
 * List sessions (admin / viewer)
 * ------------------------------------------------------------------
 */
function tr_as_sessions_list(array $filters = []): array {

    $table = TR_AS_TABLE_SESSIONS;

    $where = [];
    $args  = [];

    if (!empty($filters['meeting_type'])) {
        $where[] = 'meeting_type = %s';
        $args[]  = (string) $filters['meeting_type'];
    }

    if (!empty($filters['ymd_from'])) {
        $where[] = 'ymd >= %s';
        $args[]  = (string) $filters['ymd_from'];
    }

    if (!empty($filters['ymd_to'])) {
        $where[] = 'ymd <= %s';
        $args[]  = (string) $filters['ymd_to'];
    }

    if (isset($filters['is_open'])) {
        $where[] = 'is_open = %d';
        $args[]  = $filters['is_open'] ? 1 : 0;
    }

    $sql = "SELECT id AS session_id,
                   meeting_type,
                   ymd,
                   service_slot,
                   display_text,
                   is_open,
                   is_editable,
                   created_at
            FROM {$table}";

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY ymd DESC, meeting_type ASC, service_slot ASC';

    $sql = tr_as_db_prepare($sql, $args);
    return tr_as_db_get_results($sql, ARRAY_A);
}

/**
 * ------------------------------------------------------------------
 * Get single session
 * ------------------------------------------------------------------
 */
function tr_as_sessions_get(int $session_id): array {

    $table = TR_AS_TABLE_SESSIONS;

    $sql = tr_as_db_prepare(
        "SELECT id AS session_id,
                meeting_type,
                ymd,
                service_slot,
                display_text,
                is_open,
                is_editable,
                created_at
         FROM {$table}
         WHERE id = %d",
        [$session_id]
    );

    $row = tr_as_db_get_row($sql, ARRAY_A);

    if (!$row) {
        throw new WP_Error('tr_as_session_not_found', 'Session not found.');
    }

    return $row;
}

/**
 * ------------------------------------------------------------------
 * Get available sessions for user
 *
 * NOTE:
 * - Visibility rules are minimal in v1.x.
 * - Admin sees all.
 * - Leader / viewer / usher see open sessions only.
 * ------------------------------------------------------------------
 */
function tr_as_sessions_get_available_for_user(int $user_id): array {

    if (user_can($user_id, TR_AS_CAP_ADMIN)) {
        return tr_as_sessions_list([]);
    }

    return tr_as_sessions_list([
        'is_open' => true,
    ]);
}

/**
 * ------------------------------------------------------------------
 * Assert session is writable
 * ------------------------------------------------------------------
 */
function tr_as_sessions_assert_writable(int $session_id): void {

    $session = tr_as_sessions_get($session_id);

    if (empty($session['is_open']) || empty($session['is_editable'])) {
        throw new WP_Error(
            'tr_as_session_not_writable',
            'Session is not open for editing.'
        );
    }
}

/**
 * ------------------------------------------------------------------
 * Admin: bulk create sessions
 *
 * IMPORTANT:
 * - service_slot / display_text are stored as NOT NULL DEFAULT ''
 * - so we normalize null -> '' here.
 * ------------------------------------------------------------------
 */
function tr_as_sessions_create_bulk(int $user_id, array $items): array {

    tr_as_auth_assert_admin_only($user_id);

    $table = TR_AS_TABLE_SESSIONS;

    $inserted   = 0;
    $duplicated = 0;
    $skipped    = 0;

    foreach ($items as $item) {

        if (empty($item['meeting_type']) || empty($item['ymd'])) {
            $skipped++;
            continue;
        }

        $service_slot = isset($item['service_slot']) ? (string) $item['service_slot'] : '';
        $display_text = isset($item['display_text']) ? (string) $item['display_text'] : '';

        $sql = tr_as_db_prepare(
            "INSERT IGNORE INTO {$table}
             (meeting_type, ymd, service_slot, display_text, is_open, is_editable)
             VALUES (%s, %s, %s, %s, %d, %d)",
            [
                (string) $item['meeting_type'],
                (string) $item['ymd'],
                $service_slot,
                $display_text,
                isset($item['is_open']) ? ((int)!!$item['is_open']) : 1,
                isset($item['is_editable']) ? ((int)!!$item['is_editable']) : 1,
            ]
        );

        $result = tr_as_db_execute($sql);

        // INSERT IGNORE: success returns 1, ignored duplicate returns 0
        if ($result === 1) $inserted++;
        else $duplicated++;
    }

    return [
        'inserted'   => $inserted,
        'duplicated' => $duplicated,
        'skipped'    => $skipped,
    ];
}

/**
 * ------------------------------------------------------------------
 * Admin: update session flags
 *
 * Allowed fields:
 * - is_open (0/1)
 * - is_editable (0/1)
 * - display_text (string)
 * ------------------------------------------------------------------
 */
function tr_as_sessions_update_flags(int $user_id, int $session_id, array $patch): void {

    tr_as_auth_assert_admin_only($user_id);

    $sets = [];
    $args = [];

    if (array_key_exists('is_open', $patch)) {
        $sets[] = "is_open = %d";
        $args[] = $patch['is_open'] ? 1 : 0;
    }

    if (array_key_exists('is_editable', $patch)) {
        $sets[] = "is_editable = %d";
        $args[] = $patch['is_editable'] ? 1 : 0;
    }

    if (array_key_exists('display_text', $patch)) {
        $sets[] = "display_text = %s";
        $args[] = (string) $patch['display_text'];
    }

    if (!$sets) {
        return;
    }

    $args[] = $session_id;

    $sql = tr_as_db_prepare(
        "UPDATE " . TR_AS_TABLE_SESSIONS .
        " SET " . implode(', ', $sets) .
        " WHERE id = %d",
        $args
    );

    tr_as_db_execute($sql);
}
