<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ------------------------------------------------------------------
 * AJAX Entry (shared)
 *
 * Responsibility (SSOT v1.5):
 * - Register AJAX endpoints only
 * - Permission + nonce verification
 * - Call core services (NO business logic here)
 * ------------------------------------------------------------------
 */

function tr_as_ajax_require_login(): int {
    $user_id = get_current_user_id();
    if ($user_id <= 0) {
        wp_send_json_error(['message' => 'not logged in'], 401);
    }
    return $user_id;
}

function tr_as_ajax_require_nonce(): void {
    if (!isset($_REQUEST['nonce'])) {
        wp_send_json_error(['message' => 'missing nonce'], 400);
    }
    check_ajax_referer('tr_as_ajax', 'nonce');
}

/**
 * Normalize any error-ish value into WP_Error
 */
function tr_as_ajax_to_wp_error($err): WP_Error {

    // pass-through
    if ($err instanceof WP_Error) return $err;

    // our wrapper
    if (class_exists('TR_AS_WPError_Exception') && $err instanceof TR_AS_WPError_Exception) {
        if ($err->wp_error instanceof WP_Error) {
            return $err->wp_error;
        }
        return new WP_Error('tr_as_error', $err->getMessage());
    }

    // native throwable
    if ($err instanceof Throwable) {
        return new WP_Error('tr_as_exception', $err->getMessage());
    }

    if (is_string($err) && trim($err) !== '') {
        return new WP_Error('tr_as_error', $err);
    }

    return new WP_Error('tr_as_error', 'error');
}

function tr_as_ajax_send_error($err, int $status = 400): void {
    $wp_err = tr_as_ajax_to_wp_error($err);

    wp_send_json_error([
        'code'    => $wp_err->get_error_code(),
        'message' => $wp_err->get_error_message(),
        'data'    => $wp_err->get_error_data(),
    ], $status);
}

function tr_as_ajax_try_assert_cap(int $user_id, string $cap): bool {
    if (!function_exists('tr_as_auth_assert_capability')) {
        return user_can($user_id, $cap);
    }
    try {
        tr_as_auth_assert_capability($user_id, $cap);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function tr_as_ajax_read_entries_from_post(): array {
    if (!isset($_POST['entries'])) return [];

    $raw = $_POST['entries'];

    if (is_string($raw)) {
        $raw = trim($raw);
        if ($raw === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    return is_array($raw) ? $raw : [];
}

/**
 * ------------------------------------------------------------------
 * GET: sessions available for current user
 * ------------------------------------------------------------------
 */
add_action('wp_ajax_tr_as_get_sessions', function () {

    nocache_headers();

    $user_id = tr_as_ajax_require_login();
    tr_as_ajax_require_nonce();

    try {
        if (!function_exists('tr_as_sessions_get_available_for_user')) {
            tr_as_ajax_send_error(new WP_Error('tr_as_missing_service', 'sessions service missing'), 500);
        }

        $sessions = tr_as_sessions_get_available_for_user($user_id);
        if ($sessions instanceof WP_Error) {
            tr_as_ajax_send_error($sessions, 400);
        }

        wp_send_json_success([
            'sessions' => is_array($sessions) ? $sessions : [],
        ]);

    } catch (Throwable $e) {
        tr_as_ajax_send_error($e, 500);
    }
});

/**
 * ------------------------------------------------------------------
 * GET: attendance matrix for a session (people + status)
 * ------------------------------------------------------------------
 */
add_action('wp_ajax_tr_as_get_attendance_matrix', function () {

    nocache_headers();

    $user_id = tr_as_ajax_require_login();
    tr_as_ajax_require_nonce();

    $session_id = isset($_REQUEST['session_id']) ? (int) $_REQUEST['session_id'] : 0;
    if ($session_id <= 0) {
        tr_as_ajax_send_error('invalid session_id', 400);
    }

    $people_args = [];
    $filters = [];

    if (isset($_REQUEST['branch_id']) && $_REQUEST['branch_id'] !== '') {
        $filters['branch_id'] = (int) $_REQUEST['branch_id'];
    }
    if (isset($_REQUEST['region']) && $_REQUEST['region'] !== '') {
        $filters['region'] = sanitize_text_field((string) $_REQUEST['region']);
    }
    if (isset($_REQUEST['sub_region']) && $_REQUEST['sub_region'] !== '') {
        $filters['sub_region'] = sanitize_text_field((string) $_REQUEST['sub_region']);
    }
    if (isset($_REQUEST['group_name']) && $_REQUEST['group_name'] !== '') {
        $filters['group_name'] = sanitize_text_field((string) $_REQUEST['group_name']);
    }
    if (isset($_REQUEST['team_no']) && $_REQUEST['team_no'] !== '') {
        $filters['team_no'] = sanitize_text_field((string) $_REQUEST['team_no']);
    }
    if (isset($_REQUEST['q']) && $_REQUEST['q'] !== '') {
        $filters['q'] = sanitize_text_field((string) $_REQUEST['q']);
    }
    if (isset($_REQUEST['is_active']) && ($_REQUEST['is_active'] === '0' || $_REQUEST['is_active'] === '1')) {
        $filters['is_active'] = ((int) $_REQUEST['is_active']) ? 1 : 0;
    }
    if ($filters) {
        $people_args['filters'] = $filters;
    }

    if (isset($_REQUEST['orderby']) && $_REQUEST['orderby'] !== '') {
        $people_args['orderby'] = sanitize_key((string) $_REQUEST['orderby']);
    }
    if (isset($_REQUEST['order']) && $_REQUEST['order'] !== '') {
        $people_args['order'] = strtoupper(sanitize_key((string) $_REQUEST['order']));
    }
    if (isset($_REQUEST['limit']) && $_REQUEST['limit'] !== '') {
        $people_args['limit'] = max(0, (int) $_REQUEST['limit']);
    }
    if (isset($_REQUEST['offset']) && $_REQUEST['offset'] !== '') {
        $people_args['offset'] = max(0, (int) $_REQUEST['offset']);
    }

    try {
        if (!function_exists('tr_as_attendance_get_matrix_for_leader') || !function_exists('tr_as_attendance_get_matrix_for_admin')) {
            tr_as_ajax_send_error(new WP_Error('tr_as_missing_service', 'attendance service missing'), 500);
        }

        if (tr_as_ajax_try_assert_cap($user_id, TR_AS_CAP_ADMIN) || tr_as_ajax_try_assert_cap($user_id, TR_AS_CAP_VIEWER)) {
            $rows = tr_as_attendance_get_matrix_for_admin($user_id, $session_id, $people_args);
        } else {
            if (function_exists('tr_as_auth_assert_capability')) {
                tr_as_auth_assert_capability($user_id, TR_AS_CAP_LEADER);
            }
            $rows = tr_as_attendance_get_matrix_for_leader($user_id, $session_id, $people_args);
        }

        if ($rows instanceof WP_Error) {
            tr_as_ajax_send_error($rows, 400);
        }

        wp_send_json_success([
            'session_id' => $session_id,
            'rows'       => is_array($rows) ? $rows : [],
        ]);

    } catch (Throwable $e) {
        tr_as_ajax_send_error($e, 500);
    }
});

/**
 * ------------------------------------------------------------------
 * POST: mark attendance (UPSERT)
 * ------------------------------------------------------------------
 */
add_action('wp_ajax_tr_as_mark_attendance', function () {

    nocache_headers();

    $user_id = tr_as_ajax_require_login();
    tr_as_ajax_require_nonce();

    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        tr_as_ajax_send_error('method not allowed', 405);
    }

    $session_id = isset($_POST['session_id']) ? (int) $_POST['session_id'] : 0;
    $person_id  = isset($_POST['person_id']) ? (int) $_POST['person_id'] : 0;
    $status     = isset($_POST['attend_status']) ? (string) $_POST['attend_status'] : '';
    $mode       = isset($_POST['attend_mode']) ? (string) $_POST['attend_mode'] : null;

    if ($session_id <= 0 || $person_id <= 0 || trim($status) === '') {
        tr_as_ajax_send_error('missing params', 400);
    }

    try {
        if (!function_exists('tr_as_attendance_mark')) {
            tr_as_ajax_send_error(new WP_Error('tr_as_missing_service', 'attendance service missing'), 500);
        }

        $record = tr_as_attendance_mark($user_id, $session_id, $person_id, $status, $mode);
        if ($record instanceof WP_Error) {
            $code = $record->get_error_code();
            $http = 400;
            if (in_array($code, ['tr_as_forbidden', 'tr_as_forbidden_person'], true)) $http = 403;
            if (in_array($code, ['tr_as_session_not_available'], true)) $http = 403;
            if (in_array($code, ['tr_as_session_not_writable', 'tr_as_session_locked'], true)) $http = 400;
            tr_as_ajax_send_error($record, $http);
        }

        wp_send_json_success(['record' => $record]);

    } catch (Throwable $e) {
        tr_as_ajax_send_error($e, 500);
    }
});

/**
 * ------------------------------------------------------------------
 * GET: newcomers count (leader)
 * ------------------------------------------------------------------
 */
$tr_as_get_newcomers_cb = function () {

    nocache_headers();

    $user_id = tr_as_ajax_require_login();
    tr_as_ajax_require_nonce();

    $session_id = isset($_REQUEST['session_id']) ? (int) $_REQUEST['session_id'] : 0;
    if ($session_id <= 0) {
        tr_as_ajax_send_error('invalid session_id', 400);
    }

    try {
        if (!function_exists('tr_as_newcomers_get')) {
            tr_as_ajax_send_error(new WP_Error('tr_as_missing_service', 'newcomers service missing'), 500);
        }

        $row = tr_as_newcomers_get($user_id, $session_id);
        if ($row instanceof WP_Error) {
            tr_as_ajax_send_error($row, 400);
        }

        wp_send_json_success([
            'session_id' => $session_id,
            'newcomers'  => $row,
        ]);

    } catch (Throwable $e) {
        tr_as_ajax_send_error($e, 500);
    }
};

add_action('wp_ajax_tr_as_get_newcomers', $tr_as_get_newcomers_cb);
// alias (防你前端 action 打錯一個 s)
add_action('wp_ajax_tr_as_get_newcomer',  $tr_as_get_newcomers_cb);

/**
 * ------------------------------------------------------------------
 * POST: submit newcomers count (leader)
 * ------------------------------------------------------------------
 */
$tr_as_submit_newcomers_cb = function () {

    nocache_headers();

    $user_id = tr_as_ajax_require_login();
    tr_as_ajax_require_nonce();

    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        tr_as_ajax_send_error('method not allowed', 405);
    }

    $session_id = isset($_POST['session_id']) ? (int) $_POST['session_id'] : 0;
    $count = isset($_POST['newcomers_count']) ? (int) $_POST['newcomers_count'] : null;

    if ($session_id <= 0 || $count === null) {
        tr_as_ajax_send_error('missing params', 400);
    }

    try {
        if (!function_exists('tr_as_newcomers_submit')) {
            tr_as_ajax_send_error(new WP_Error('tr_as_missing_service', 'newcomers service missing'), 500);
        }

        tr_as_newcomers_submit($user_id, $session_id, (int)$count);

        wp_send_json_success(['ok' => true]);

    } catch (Throwable $e) {
        tr_as_ajax_send_error($e, 500);
    }
};

add_action('wp_ajax_tr_as_submit_newcomers', $tr_as_submit_newcomers_cb);
// alias (防你前端 action 打錯一個 s)
add_action('wp_ajax_tr_as_submit_newcomer',  $tr_as_submit_newcomers_cb);
