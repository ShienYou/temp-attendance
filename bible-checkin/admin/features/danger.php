<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Tab: danger
 * - UI: 刪除舊專案 + 一鍵清空（admin only）
 * - Handlers: bc_handle_delete_project(), bc_handle_reset_all_dev()
 */

function bc_admin_render_tab_danger($current_project_id, $projects) {
    ?>
    <!-- 刪除舊專案（高風險） -->
    <h2 style="color:#a00;">刪除舊專案</h2>
    <?php foreach ($projects as $p):
        if ($p['id'] == $current_project_id) continue;
    ?>
        <form method="post" style="margin-bottom:1em;">
            <?php wp_nonce_field('bc_admin_action', 'bc_admin_nonce'); ?>
            <input type="hidden" name="bc_action" value="delete_project">
            <input type="hidden" name="project_id" value="<?php echo esc_attr($p['id']); ?>">
            刪除 <?php echo esc_html($p['church_name'] . '｜' . $p['project_name'] . '（' . $p['year'] . '）'); ?>：
            <input type="text" name="confirm_text"
                   placeholder="DELETE <?php echo esc_html($p['year']); ?>" required>
            <button class="button">刪除</button>
        </form>
    <?php endforeach; ?>

    <hr>

    <!-- 開發用：一鍵清空（admin only） -->
    <?php if ( current_user_can('administrator') ): ?>
        <div style="border:2px solid #b00;padding:12px;border-radius:6px;background:#fff5f5;">
            <h2 style="color:#b00;margin-top:0;">開發用：一鍵清空（危險）</h2>
            <p style="margin-top:-6px;color:#444;">
                這個按鈕會 <b>DROP 所有 bc_* 表</b>，並清除 plugin options。<br>
                用途：開發期回到乾淨狀態。正式上線前請保持隱藏，不要給同工看到。
            </p>

            <form method="post">
                <?php wp_nonce_field('bc_admin_action', 'bc_admin_nonce'); ?>
                <input type="hidden" name="bc_action" value="reset_all">
                <p>
                    請輸入 <b>RESET ALL</b> 才能執行：
                    <input type="text" name="confirm_text" style="width:220px;" placeholder="RESET ALL" required>
                    <button class="button button-danger" style="background:#b00;color:#fff;border-color:#900;">
                        一鍵清空（DROP 表 + 清設定）
                    </button>
                </p>
                <p class="description">
                    清空後請到外掛頁：<b>停用 → 啟用</b>，讓 install.php 重建資料表與 seed。
                </p>
            </form>
        </div>
    <?php endif; ?>
    <?php
}

function bc_handle_delete_project() {
    global $wpdb;

    $project_id = intval($_POST['project_id']);
    if ( $project_id === bc_get_current_project_id() ) {
        bc_admin_error('不可刪除目前專案');
        return;
    }

    $project = bc_get_project($project_id);
    if (!$project) return;

    if (trim((string)$_POST['confirm_text']) !== 'DELETE '.$project['year']) return;

    foreach ([bc_table_plan_days(),bc_table_checkins(),bc_table_people(),bc_table_teams(),bc_table_settings(),bc_table_branches()] as $t) {
        $wpdb->delete($t,['project_id'=>$project_id]);
    }
    $wpdb->delete(bc_table_projects(),['id'=>$project_id]);

    bc_admin_notice('專案已刪除');
}

/* ===== Dev reset (admin-only) ===== */

function bc_handle_reset_all_dev() {
    if ( ! current_user_can('administrator') ) {
        bc_admin_error('權限不足');
        return;
    }

    $confirm = trim((string)($_POST['confirm_text'] ?? ''));
    if ($confirm !== 'RESET ALL') {
        bc_admin_error('確認文字不正確（必須輸入：RESET ALL）');
        return;
    }

    global $wpdb;
    $tables = [
        bc_table_checkins(),
        bc_table_plan_days(),
        bc_table_people(),
        bc_table_teams(),
        bc_table_settings(),
        bc_table_branches(),
        bc_table_projects(),
    ];

    foreach ($tables as $t) {
        $wpdb->query("DROP TABLE IF EXISTS {$t}");
    }

    delete_option('bc_current_project_id');
    delete_option('bc_db_version');

    bc_admin_notice('已清空所有資料表與設定。請到外掛頁：停用 → 啟用，重建資料庫。');
}
