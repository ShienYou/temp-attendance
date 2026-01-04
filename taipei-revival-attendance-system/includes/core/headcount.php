<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ------------------------------------------------------------------
 * Headcount Core Service (usher aggregate entries)
 *
 * Responsibility (SSOT v1.5):
 * - Usher can read/write headcount entries for a session
 * - Entries are aggregate rows (venue / audience / mode / headcount / note)
 * - Submit is "bulk replace for this user + session" to avoid half-success
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

function tr_as_headcount_get_entries(int $user_id, int $session_id): array {
    tr_as_auth_assert_capability($user_id, TR_AS_CAP_USHER);

    // Ensure the session is available for this user (open + in range)
    $available = tr_as_sessions_get_available_for_user($user_id);
    $ok = false;
    foreach ($available as $s) {
        if ((int)($s['session_id'] ?? 0) === (int)$session_id) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        throw new WP_Error('tr_as_session_not_available', 'session not available');
    }

    $session = tr_as_sessions_get($session_id);

    $t = TR_AS_TABLE_HEADCOUNT;
    $sql = tr_as_db_prepare(
        "SELECT id AS entry_id, venue, audience, mode, headcount, note, reported_by_user_id, reported_at
         FROM {$t}
         WHERE session_id=%d AND reported_by_user_id=%d
         ORDER BY id ASC",
        $session_id,
        $user_id
    );

    $rows = tr_as_db_get_results($sql, ARRAY_A);
    if (!is_array($rows)) $rows = [];

    return [
        'session' => [
            'session_id'    => (int)($session['session_id'] ?? $session_id),
            'meeting_type'  => (string)($session['meeting_type'] ?? ''),
            'ymd'           => (string)($session['ymd'] ?? ''),
            'service_slot'  => (string)($session['service_slot'] ?? ''),
            'display_text'  => (string)($session['display_text'] ?? ''),
            'is_open'       => (int)($session['is_open'] ?? 0),
            'is_editable'   => (int)($session['is_editable'] ?? 0),
        ],
        'entries' => array_map(function ($r) {
            return [
                'entry_id'            => (int)($r['entry_id'] ?? 0),
                'venue'               => (string)($r['venue'] ?? ''),
                'audience'            => (string)($r['audience'] ?? ''),
                'mode'                => (string)($r['mode'] ?? ''),
                'headcount'           => (int)($r['headcount'] ?? 0),
                'note'                => (string)($r['note'] ?? ''),
                'reported_by_user_id' => (int)($r['reported_by_user_id'] ?? 0),
                'reported_at'          => $r['reported_at'] ?? null,
            ];
        }, $rows),
    ];
}

function tr_as_headcount_submit_bulk(int $user_id, int $session_id, array $entries): array {
    tr_as_auth_assert_capability($user_id, TR_AS_CAP_USHER);

    // Writable gate
    tr_as_sessions_assert_writable($session_id);

    // Ensure the session is available for this user
    $available = tr_as_sessions_get_available_for_user($user_id);
    $ok = false;
    foreach ($available as $s) {
        if ((int)($s['session_id'] ?? 0) === (int)$session_id) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        throw new WP_Error('tr_as_session_not_available', 'session not available');
    }

    $session = tr_as_sessions_get($session_id);

    // Scope (usher)
    $scope = tr_as_auth_get_user_scope($user_id);
    $usher = $scope['usher'] ?? null;
    if (!is_array($usher)) {
        throw new WP_Error('tr_as_no_usher_scope', 'usher scope missing');
    }

    // Enforce meeting_type/service_slot scope if defined
    $mt_scope = isset($usher['meeting_type_scope']) && is_array($usher['meeting_type_scope']) ? $usher['meeting_type_scope'] : [];
    $ss_scope = isset($usher['service_slot_scope']) && is_array($usher['service_slot_scope']) ? $usher['service_slot_scope'] : [];
    $venue_scope = isset($usher['venue_scope']) && is_array($usher['venue_scope']) ? $usher['venue_scope'] : [];

    $meeting_type = (string)($session['meeting_type'] ?? '');
    $service_slot = (string)($session['service_slot'] ?? '');

    if ($mt_scope && !in_array($meeting_type, $mt_scope, true)) {
        throw new WP_Error('tr_as_forbidden', 'meeting_type out of scope');
    }
    if ($ss_scope && !in_array($service_slot, $ss_scope, true)) {
        throw new WP_Error('tr_as_forbidden', 'service_slot out of scope');
    }

    // Normalize entries
    $normalized = [];
    foreach ($entries as $e) {
        if (!is_array($e)) continue;

        $venue = isset($e['venue']) ? sanitize_text_field((string)$e['venue']) : '';
        $audience = isset($e['audience']) ? sanitize_text_field((string)$e['audience']) : '';
        $mode = isset($e['mode']) ? sanitize_text_field((string)$e['mode']) : (isset($e['mode_channel']) ? sanitize_text_field((string)$e['mode_channel']) : '');
        $count = isset($e['headcount']) ? (int)$e['headcount'] : (isset($e['count']) ? (int)$e['count'] : 0);
        $note = isset($e['note']) ? sanitize_textarea_field((string)$e['note']) : '';

        $count = max(0, $count);

        // Skip totally empty row
        if ($venue === '' && $audience === '' && $mode === '' && $count === 0 && $note === '') {
            continue;
        }

        if ($venue_scope && $venue !== '' && !in_array($venue, $venue_scope, true)) {
            throw new WP_Error('tr_as_forbidden', 'venue out of scope');
        }

        $normalized[] = [
            'venue'    => $venue,
            'audience' => $audience,
            'mode'     => $mode,
            'headcount'=> $count,
            'note'     => $note,
        ];
    }

    $t = TR_AS_TABLE_HEADCOUNT;

    $result = tr_as_db_transaction(function () use ($t, $user_id, $session_id, $normalized) {

        // Replace pattern: delete my entries for this session, then insert normalized rows
        $delete_sql = tr_as_db_prepare(
            "DELETE FROM {$t} WHERE session_id=%d AND reported_by_user_id=%d",
            $session_id,
            $user_id
        );

        $deleted = tr_as_db_execute($delete_sql);
        if ($deleted === false) {
            throw new WP_Error('tr_as_db_error', 'failed to clear existing entries');
        }

        $saved = 0;
        foreach ($normalized as $e) {
            $data = [
                'session_id'          => $session_id,
                'venue'               => $e['venue'],
                'audience'            => $e['audience'],
                'mode'                => $e['mode'],
                'headcount'           => (int)$e['headcount'],
                'reported_by_user_id' => $user_id,
                'reported_at'         => current_time('mysql'),
                'note'                => $e['note'],
            ];

            $formats = ['%d','%s','%s','%s','%d','%d','%s','%s'];

            $insert_sql = tr_as_db_prepare_insert(TR_AS_TABLE_HEADCOUNT, $data, $formats);
            $ok = tr_as_db_execute($insert_sql);
            if ($ok === false) {
                throw new WP_Error('tr_as_db_error', 'failed to insert headcount');
            }
            $saved++;
        }

        return [
            'saved'   => $saved,
            'deleted' => (int)$deleted,
            'updated' => 0,
        ];
    });

    return is_array($result) ? $result : ['saved'=>0,'deleted'=>0,'updated'=>0];
}
