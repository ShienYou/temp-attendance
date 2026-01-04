<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ------------------------------------------------------------------
 * Newcomers Core Service (count-only)
 *
 * Responsibility (SSOT v1.5):
 * - Leader can read/write newcomers_count for (session + leader scope)
 * - No person creation, no names
 *
 * Boundary:
 * - NO UI rendering
 * - NO direct $wpdb usage (must go through utils/db.php)
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../utils/db.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sessions.php';

function tr_as_newcomers_get(int $user_id, int $session_id): array {

    // Leader feature (admin can test if admin also has leader cap, or via fallback below)
    tr_as_auth_assert_capability($user_id, TR_AS_CAP_LEADER);

    // Ensure the session is available for this user (open + in range)
    $available = tr_as_sessions_get_available_for_user($user_id);
    $ok = false;
    foreach ((array)$available as $s) {
        if ((int)($s['session_id'] ?? 0) === (int)$session_id) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        tr_as_throw_wp_error('tr_as_session_not_available', 'session not available');
    }

    $scope  = tr_as_auth_get_user_scope($user_id);
    $leader = $scope['leader'] ?? null;

    // Admin fallback (so admin can test without creating leader-test account immediately)
    if (!is_array($leader) || empty($leader['group_name'])) {
        if (user_can($user_id, TR_AS_CAP_ADMIN)) {
            $leader = array(
                'branch_id'  => 0,
                'region'     => '',
                'sub_region' => '',
                'group_name' => '',
                'team_no'    => '',
            );
        } else {
            tr_as_throw_wp_error('tr_as_no_leader_scope', 'leader scope missing');
        }
    }

    $t = TR_AS_TABLE_NEWCOMERS;

    $sql = tr_as_db_prepare(
        "SELECT session_id, newcomers_count, marked_by_user_id, marked_at
         FROM {$t}
         WHERE session_id=%d
           AND branch_id=%d
           AND region=%s
           AND sub_region=%s
           AND group_name=%s
           AND team_no=%s
         LIMIT 1",
        array(
            (int)$session_id,
            (int)($leader['branch_id'] ?? 0),
            (string)($leader['region'] ?? ''),
            (string)($leader['sub_region'] ?? ''),
            (string)($leader['group_name'] ?? ''),
            (string)($leader['team_no'] ?? ''),
        )
    );

    $row = tr_as_db_get_row($sql, ARRAY_A);

    if (!is_array($row)) {
        return array(
            'session_id'        => $session_id,
            'newcomers_count'   => 0,
            'marked_by_user_id' => null,
            'marked_at'         => null,
        );
    }

    return array(
        'session_id'        => (int)($row['session_id'] ?? $session_id),
        'newcomers_count'   => (int)($row['newcomers_count'] ?? 0),
        'marked_by_user_id' => isset($row['marked_by_user_id']) ? (int)$row['marked_by_user_id'] : null,
        'marked_at'         => $row['marked_at'] ?? null,
    );
}

function tr_as_newcomers_submit(int $user_id, int $session_id, int $newcomers_count): void {

    tr_as_auth_assert_capability($user_id, TR_AS_CAP_LEADER);

    // Writable gate (open + editable)
    tr_as_sessions_assert_writable($session_id);

    // Ensure the session is available for this user
    $available = tr_as_sessions_get_available_for_user($user_id);
    $ok = false;
    foreach ((array)$available as $s) {
        if ((int)($s['session_id'] ?? 0) === (int)$session_id) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        tr_as_throw_wp_error('tr_as_session_not_available', 'session not available');
    }

    $scope  = tr_as_auth_get_user_scope($user_id);
    $leader = $scope['leader'] ?? null;

    // Admin fallback: allow save without leader scope for testing
    if (!is_array($leader) || empty($leader['group_name'])) {
        if (user_can($user_id, TR_AS_CAP_ADMIN)) {
            $leader = array(
                'branch_id'  => 0,
                'region'     => '',
                'sub_region' => '',
                'group_name' => '',
                'team_no'    => '',
            );
        } else {
            tr_as_throw_wp_error('tr_as_no_leader_scope', 'leader scope missing');
        }
    }

    $count = max(0, (int)$newcomers_count);
    $t = TR_AS_TABLE_NEWCOMERS;

    $marked_at = current_time('mysql');

    // Explicit UPSERT (no dependency on non-existent helpers)
    $sql = tr_as_db_prepare(
        "INSERT INTO {$t}
            (session_id, branch_id, region, sub_region, group_name, team_no, newcomers_count, marked_by_user_id, marked_at)
         VALUES
            (%d, %d, %s, %s, %s, %s, %d, %d, %s)
         ON DUPLICATE KEY UPDATE
            newcomers_count = VALUES(newcomers_count),
            marked_by_user_id = VALUES(marked_by_user_id),
            marked_at = VALUES(marked_at)",
        array(
            (int)$session_id,
            (int)($leader['branch_id'] ?? 0),
            (string)($leader['region'] ?? ''),
            (string)($leader['sub_region'] ?? ''),
            (string)($leader['group_name'] ?? ''),
            (string)($leader['team_no'] ?? ''),
            (int)$count,
            (int)$user_id,
            (string)$marked_at,
        )
    );

    tr_as_db_transaction(function () use ($sql) {
        $ok = tr_as_db_execute($sql);
        if ($ok === false) {
            tr_as_throw_wp_error('tr_as_db_error', 'failed to save newcomers');
        }
    });
}
