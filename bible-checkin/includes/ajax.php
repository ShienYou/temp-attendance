<?php
/**
 * Bible Check-in Plugin
 * AJAX Handlers (Admin / Management)
 *
 * 職責：
 * - CSV 匯出 / 匯入（人員）
 * - 僅處理管理行為
 * - 不處理前台打卡（那在 frontend/ajax.php）
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* =====================================================
 * Export People CSV
 * ===================================================== */

/**
 * AJAX：匯出人員 CSV
 */
function bc_ajax_export_people_csv() {

    // 權限檢查
    if ( ! current_user_can( 'bible_checkin_manage' ) ) {
        wp_die( 'Permission denied.' );
    }

    // nonce 驗證
    check_ajax_referer( 'bc_admin_action', 'nonce' );

    $project_id = bc_get_current_project_id();
    if ( $project_id <= 0 ) {
        wp_die( 'Invalid project.' );
    }

    $csv = bc_csv_export_people( $project_id );

    // 強制下載 CSV（Excel 直接可開）
    nocache_headers();
    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename=people.csv' );
    header( 'Content-Length: ' . strlen( $csv ) );

    echo $csv;
    exit;
}
add_action( 'wp_ajax_bc_export_people_csv', 'bc_ajax_export_people_csv' );


/* =====================================================
 * Import People CSV
 * ===================================================== */

/**
 * AJAX：匯入人員 CSV
 *
 * 回傳格式（只回傳摘要，不含明細）：
 * {
 *   inserted: number,
 *   duplicates: number,
 *   branches_created: number,
 *   skipped: number
 * }
 */
function bc_ajax_import_people_csv() {

    // 權限檢查
    if ( ! current_user_can( 'bible_checkin_manage' ) ) {
        wp_send_json_error( [
            'message' => 'Permission denied.',
        ] );
    }

    // nonce 驗證
    check_ajax_referer( 'bc_admin_action', 'nonce' );

    if ( empty( $_FILES['csv_file'] ) || empty( $_FILES['csv_file']['tmp_name'] ) ) {
        wp_send_json_error( [
            'message' => 'No CSV file uploaded.',
        ] );
    }

    $project_id = bc_get_current_project_id();
    if ( $project_id <= 0 ) {
        wp_send_json_error( [
            'message' => 'Invalid project.',
        ] );
    }

    $summary = bc_csv_import_people(
        $project_id,
        $_FILES['csv_file']['tmp_name']
    );

    /**
     * ⚠️ 注意：
     * - 不回傳任何明細
     * - 不因 duplicate / auto-branch 視為 error
     * - 永遠 success，只看 summary 數字
     */
    wp_send_json_success( [
        'inserted'         => (int) ( $summary['inserted'] ?? 0 ),
        'duplicates'       => (int) ( $summary['duplicates'] ?? 0 ),
        'branches_created' => (int) ( $summary['branches_created'] ?? 0 ),
        'skipped'          => (int) ( $summary['skipped'] ?? 0 ),
    ] );
}
add_action( 'wp_ajax_bc_import_people_csv', 'bc_ajax_import_people_csv' );
