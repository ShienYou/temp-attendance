<?php
/**
 * Bible Check-in System
 * AJAX Handler (Frontend)
 *
 * ✅ 功能：
 * - bc_toggle_checkin：打卡切換（支援前台多專案）
 * - bc_get_checkins_snapshot：抓最新狀態（矯正手機快取不同步）
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

add_action('wp_ajax_bc_toggle_checkin', 'bc_toggle_checkin');
add_action('wp_ajax_nopriv_bc_toggle_checkin', 'bc_toggle_checkin');

add_action('wp_ajax_bc_get_checkins_snapshot', 'bc_get_checkins_snapshot');
add_action('wp_ajax_nopriv_bc_get_checkins_snapshot', 'bc_get_checkins_snapshot');

/**
 * 強制 AJAX no-cache（加固版）
 *
 * 調整重點：
 * - 最嚴格的 no-store header 先送
 * - 再呼叫 nocache_headers()
 * - 行為不變，只是提高在「外掛亂送 header」環境下的安全性
 */
function bc_ajax_nocache_headers() {
    if ( ! headers_sent() ) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-BC-AJAX: 1');
    }
    nocache_headers();
}

function bc_toggle_checkin() {

    bc_ajax_nocache_headers();
    check_ajax_referer('bc_checkin_nonce', 'nonce');

    global $wpdb;

    // 🔒 project_id guard（寫入/刪除不准 fallback）
    $project_id = intval($_POST['project_id'] ?? 0);
    if ( $project_id <= 0 ) {
        wp_send_json_error(['message' => 'invalid_project']);
    }

    $person_id  = intval($_POST['person_id'] ?? 0);
    $ymd_raw    = sanitize_text_field($_POST['ymd'] ?? '');
    $ymd        = preg_replace('/\D+/', '', (string)$ymd_raw);

    if ( $person_id <= 0 || strlen($ymd) !== 8 ) {
        wp_send_json_error(['message' => 'invalid_data']);
    }

    $table = bc_table_checkins();

    $exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE project_id = %d
               AND person_id  = %d
               AND ymd        = %s
             LIMIT 1",
            $project_id,
            $person_id,
            $ymd
        )
    );

    if ( $exists ) {
        $wpdb->delete(
            $table,
            [
                'project_id' => $project_id,
                'person_id'  => $person_id,
                'ymd'        => $ymd,
            ],
            [ '%d', '%d', '%s' ]
        );

        wp_send_json_success(['status' => 'unchecked']);
    }

    $ok = $wpdb->insert(
        $table,
        [
            'project_id' => $project_id,
            'person_id'  => $person_id,
            'ymd'        => $ymd,
            'created_at' => current_time('mysql'),
        ],
        [ '%d', '%d', '%s', '%s' ]
    );

    if ( false === $ok ) {
        wp_send_json_error(['message' => 'db_insert_failed']);
    }

    wp_send_json_success(['status' => 'checked']);
}

function bc_get_checkins_snapshot() {

    bc_ajax_nocache_headers();
    check_ajax_referer('bc_checkin_nonce', 'nonce');

    global $wpdb;

    $project_id = intval($_POST['project_id'] ?? 0);
    if ( $project_id <= 0 ) {
        $project_id = (int) bc_get_current_project_id();
    }
    if ( $project_id <= 0 ) {
        wp_send_json_error(['message' => 'invalid_project']);
    }

    $person_ids = $_POST['person_ids'] ?? [];
    $ymds       = $_POST['ymds'] ?? [];

    if ( ! is_array($person_ids) ) $person_ids = [];
    if ( ! is_array($ymds) ) $ymds = [];

    $person_ids = array_slice(array_values(array_filter(array_map('intval', $person_ids), function($v){
        return $v > 0;
    })), 0, 500);

    $ymds = array_slice(array_values(array_filter(array_map(function($v){
        $v = preg_replace('/\D+/', '', (string)$v);
        return (strlen($v) === 8) ? $v : '';
    }, $ymds), function($v){
        return $v !== '';
    })), 0, 500);

    if ( empty($person_ids) || empty($ymds) ) {
        wp_send_json_success(['checked' => []]);
    }

    $table = bc_table_checkins();

    $pid_placeholders = implode(',', array_fill(0, count($person_ids), '%d'));
    $ymd_placeholders = implode(',', array_fill(0, count($ymds), '%s'));

    $sql = "
        SELECT person_id, ymd
        FROM {$table}
        WHERE project_id = %d
          AND person_id IN ({$pid_placeholders})
          AND ymd IN ({$ymd_placeholders})
    ";

    $params = array_merge([$project_id], $person_ids, $ymds);

    $rows = $wpdb->get_results(
        $wpdb->prepare($sql, $params),
        ARRAY_A
    );

    $checked = [];
    if ( is_array($rows) ) {
        foreach ($rows as $r) {
            $checked[] = intval($r['person_id']) . '_' . preg_replace('/\D+/', '', (string)$r['ymd']);
        }
    }

    wp_send_json_success(['checked' => $checked]);
}
