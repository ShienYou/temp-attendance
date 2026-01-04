<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ------------------------------------------------------------------
 * Attendance Core Service
 *
 * Responsibility (SSOT v1.5):
 * - Attendance retrieval for a session (per-person records)
 * - Leader-scoped attendance matrix (people + status)
 * - Admin-scoped attendance matrix (filters + status)
 * - Mark / unmark attendance (UPSERT)
 *
 * Boundary:
 * - NO UI rendering
 * - NO headcount/newcomers logic
 * - NO statistics
 * - NO direct $wpdb usage (must go through includes/utils/db.php)
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../utils/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sessions.php';
require_once __DIR__ . '/people.php';

/**
 * ------------------------------------------------------------------
 * Allowed statuses (v1.x safe default)
 *
 * NOTE:
 * - Keep minimal and predictable.
 * - You can extend via filter hook without editing core.
 * ------------------------------------------------------------------
 */
function tr_as_attendance_allowed_statuses(): array {
    $base = ['unmarked', 'present', 'absent'];
    return (array) apply_filters('tr_as_attendance_allowed_statuses', $base);
}

/**
 * ------------------------------------------------------------------
 * Internal: normalize status
 * ------------------------------------------------------------------
 */
function tr_as_attendance_normalize_status(string $status): string {
    $status = strtolower(trim($status));
    if ($status === '') return 'unmarked';

    $allowed = tr_as_attendance_allowed_statuses();
    if (!in_array($status, $allowed, true)) {
        throw new WP_Error('tr_as_invalid_attend_status', 'Invalid attend_status.');
    }
    return $status;
}

/**
 * ------------------------------------------------------------------
 * Internal: fetch attendance rows for session + person_ids
 *
 * Returns array keyed by person_id.
 * ------------------------------------------------------------------
 */
function tr_as_attendance_fetch_map(int $session_id, array $person_ids): array {

    $table = TR_AS_TABLE_ATTENDANCE;

    $ids = array_values(array_filter(array_map('intval', $person_ids), function ($v) {
        return $v > 0;
    }));

    if (!$ids) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $args = array_merge([$session_id], $ids);

    $sql = "SELECT
                session_id,
                person_id,
                attend_status,
                attend_mode,
                marked_by_user_id,
                marked_at
            FROM {$table}
            WHERE session_id = %d
              AND person_id IN ({$placeholders})";

    $sql = tr_as_db_prepare($sql, $args);
    $rows = tr_as_db_get_results($sql, ARRAY_A);

    $map = [];
    foreach ($rows as $r) {
        $pid = (int) ($r['person_id'] ?? 0);
        if ($pid > 0) {
            $map[$pid] = $r;
        }
    }

    return $map;
}

/**
 * ------------------------------------------------------------------
 * Internal: assert person is inside leader scope
 *
 * v1.x rule:
 * - Scope match must align to auth.php leader snapshot:
 *   branch_id / region / sub_region / group_name are required
 * - team_no is optional; if leader has team_no, it must match
 * ------------------------------------------------------------------
 */
function tr_as_attendance_assert_person_in_leader_scope(int $user_id, int $person_id): void {

    tr_as_auth_assert_capability($user_id, TR_AS_CAP_LEADER);

    $scope = tr_as_auth_get_user_scope($user_id);
    if (empty($scope['leader']) || empty($scope['leader']['group_name'])) {
        throw new WP_Error('tr_as_no_leader_scope', 'Leader scope not properly assigned.');
    }

    $p = tr_as_people_get($person_id);

    $need_branch  = (int) ($scope['leader']['branch_id'] ?? 0);
    $need_region  = (string) ($scope['leader']['region'] ?? '');
    $need_sub     = (string) ($scope['leader']['sub_region'] ?? '');
    $need_group   = (string) ($scope['leader']['group_name'] ?? '');
    $need_team_no = (string) ($scope['leader']['team_no'] ?? '');

    // Required matches
    if ((int)$p['branch_id'] !== $need_branch ||
        (string)$p['region'] !== $need_region ||
        (string)$p['sub_region'] !== $need_sub ||
        (string)$p['group_name'] !== $need_group
    ) {
        throw new WP_Error('tr_as_forbidden_person', 'Person is outside leader scope.');
    }

    // Optional team_no constraint
    if ($need_team_no !== '' && (string)($p['team_no'] ?? '') !== $need_team_no) {
        throw new WP_Error('tr_as_forbidden_person', 'Person is outside leader team scope.');
    }
}

/**
 * ------------------------------------------------------------------
 * Get attendance record for (session, person)
 * ------------------------------------------------------------------
 */
function tr_as_attendance_get(int $session_id, int $person_id): array {

    $table = TR_AS_TABLE_ATTENDANCE;

    $sql = tr_as_db_prepare(
        "SELECT
            session_id,
            person_id,
            attend_status,
            attend_mode,
            marked_by_user_id,
            marked_at
         FROM {$table}
         WHERE session_id = %d AND person_id = %d",
        [$session_id, $person_id]
    );

    $row = tr_as_db_get_row($sql, ARRAY_A);

    if (!$row) {
        // Not marked yet -> return default shape
        return [
            'session_id'        => $session_id,
            'person_id'         => $person_id,
            'attend_status'     => 'unmarked',
            'attend_mode'       => null,
            'marked_by_user_id' => 0,
            'marked_at'         => null,
        ];
    }

    return $row;
}

/**
 * ------------------------------------------------------------------
 * Leader: get attendance matrix for a session (people + status)
 * ------------------------------------------------------------------
 */
function tr_as_attendance_get_matrix_for_leader(int $user_id, int $session_id, array $args = []): array {

    tr_as_auth_assert_capability($user_id, TR_AS_CAP_LEADER);

    // Leader can only work on available sessions; write checks happen in mark().
    // For reading, allow any available session visibility rule:
    // - admin sees all
    // - leader sees open only (sessions.php)
    $available = tr_as_sessions_get_available_for_user($user_id);
    $ok = false;
    foreach ($available as $s) {
        if ((int)($s['session_id'] ?? 0) === $session_id) { $ok = true; break; }
    }
    if (!$ok) {
        throw new WP_Error('tr_as_session_not_available', 'Session is not available for this user.');
    }

    // People list in leader scope (defaults to active only in people.php)
    $people = tr_as_people_list_for_leader($user_id, $args);

    $person_ids = array_map(function ($r) {
        return (int) ($r['person_id'] ?? 0);
    }, $people);

    $att_map = tr_as_attendance_fetch_map($session_id, $person_ids);

    $out = [];
    foreach ($people as $p) {
        $pid = (int) ($p['person_id'] ?? 0);
        $a = $att_map[$pid] ?? null;

        $out[] = array_merge($p, [
            'session_id'        => $session_id,
            'attend_status'     => $a ? (string)$a['attend_status'] : 'unmarked',
            'attend_mode'       => $a ? $a['attend_mode'] : null,
            'marked_by_user_id' => $a ? (int)$a['marked_by_user_id'] : 0,
            'marked_at'         => $a ? $a['marked_at'] : null,
        ]);
    }

    return $out;
}

/**
 * ------------------------------------------------------------------
 * Admin/Viewer: get attendance matrix for a session (people filters + status)
 *
 * NOTE:
 * - Viewer can read, but cannot write.
 * - Admin can read and write (via mark()).
 * ------------------------------------------------------------------
 */
function tr_as_attendance_get_matrix_for_admin(int $user_id, int $session_id, array $people_args = []): array {

    // Allow admin OR viewer to read.
    if (!user_can($user_id, TR_AS_CAP_ADMIN) && !user_can($user_id, TR_AS_CAP_VIEWER)) {
        throw new WP_Error('tr_as_forbidden', 'User does not have required capability.');
    }

    // For admin read, allow any session.
    // For viewer read, follow same rule as non-admin in sessions.php: open only.
    if (!user_can($user_id, TR_AS_CAP_ADMIN)) {
        $available = tr_as_sessions_get_available_for_user($user_id);
        $ok = false;
        foreach ($available as $s) {
            if ((int)($s['session_id'] ?? 0) === $session_id) { $ok = true; break; }
        }
        if (!$ok) {
            throw new WP_Error('tr_as_session_not_available', 'Session is not available for this user.');
        }
    }

    // Default: only active people unless explicitly provided
    if (!isset($people_args['filters']) || !is_array($people_args['filters'])) {
        $people_args['filters'] = [];
    }
    if (!array_key_exists('is_active', $people_args['filters'])) {
        $people_args['filters']['is_active'] = true;
    }

    // Default ordering
    if (empty($people_args['orderby'])) $people_args['orderby'] = 'group_name';
    if (empty($people_args['order']))   $people_args['order']   = 'ASC';

    $people = tr_as_people_list($people_args);

    $person_ids = array_map(function ($r) {
        return (int) ($r['person_id'] ?? 0);
    }, $people);

    $att_map = tr_as_attendance_fetch_map($session_id, $person_ids);

    $out = [];
    foreach ($people as $p) {
        $pid = (int) ($p['person_id'] ?? 0);
        $a = $att_map[$pid] ?? null;

        $out[] = array_merge($p, [
            'session_id'        => $session_id,
            'attend_status'     => $a ? (string)$a['attend_status'] : 'unmarked',
            'attend_mode'       => $a ? $a['attend_mode'] : null,
            'marked_by_user_id' => $a ? (int)$a['marked_by_user_id'] : 0,
            'marked_at'         => $a ? $a['marked_at'] : null,
        ]);
    }

    return $out;
}

/**
 * ------------------------------------------------------------------
 * Mark attendance (UPSERT)
 *
 * Who can mark:
 * - Admin: can mark anyone, any session (must be writable)
 * - Leader: can mark only people inside leader scope (must be writable)
 *
 * @return array Updated record (stable shape)
 * ------------------------------------------------------------------
 */
function tr_as_attendance_mark(
    int $user_id,
    int $session_id,
    int $person_id,
    string $attend_status,
    ?string $attend_mode = null
): array {

    // 1) session writable
    tr_as_sessions_assert_writable($session_id);

    // 2) permission + scope validation
    if (user_can($user_id, TR_AS_CAP_ADMIN)) {
        // admin ok
    } else {
        // leader only (v1.x)
        tr_as_attendance_assert_person_in_leader_scope($user_id, $person_id);
    }

    // 3) normalize status
    $attend_status = tr_as_attendance_normalize_status($attend_status);

    // 4) upsert
    $table = TR_AS_TABLE_ATTENDANCE;

    $mode = $attend_mode !== null ? trim((string)$attend_mode) : null;
    if ($mode === '') $mode = null;

    $now = current_time('mysql');

    $sql = tr_as_db_prepare(
        "INSERT INTO {$table}
            (session_id, person_id, attend_status, attend_mode, marked_by_user_id, marked_at)
         VALUES
            (%d, %d, %s, %s, %d, %s)
         ON DUPLICATE KEY UPDATE
            attend_status = VALUES(attend_status),
            attend_mode = VALUES(attend_mode),
            marked_by_user_id = VALUES(marked_by_user_id),
            marked_at = VALUES(marked_at)",
        [
            $session_id,
            $person_id,
            $attend_status,
            $mode,
            $user_id,
            $now,
        ]
    );

    $ok = tr_as_db_execute($sql);
    if ($ok === false) {
        throw new WP_Error('tr_as_db_error', 'Failed to mark attendance.');
    }

    return tr_as_attendance_get($session_id, $person_id);
}

/**
 * ------------------------------------------------------------------
 * Bulk mark (optional helper)
 *
 * $items: [
 *   ['person_id' => 123, 'attend_status' => 'present', 'attend_mode' => 'onsite'],
 *   ...
 * ]
 *
 * Returns summary only.
 * ------------------------------------------------------------------
 */
function tr_as_attendance_mark_bulk(int $user_id, int $session_id, array $items): array {

    $summary = [
        'updated' => 0,
        'skipped' => 0,
    ];

    foreach ($items as $it) {
        $pid = (int)($it['person_id'] ?? 0);
        $st  = (string)($it['attend_status'] ?? '');

        if ($pid <= 0 || $st === '') {
            $summary['skipped']++;
            continue;
        }

        try {
            tr_as_attendance_mark(
                $user_id,
                $session_id,
                $pid,
                $st,
                isset($it['attend_mode']) ? (string)$it['attend_mode'] : null
            );
            $summary['updated']++;
        } catch (Throwable $e) {
            $summary['skipped']++;
        }
    }

    return $summary;
}
