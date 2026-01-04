<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Tab: plan
 * - UI: 匯入每月讀經進度 + 檢查表
 * - Handler: bc_handle_import_plan()
 * - Helper: bc_fmt_ymd_to_md()
 */

function bc_admin_render_tab_plan($current_project_id) {
    ?>
    <!-- 匯入每月讀經進度 -->
    <h2>匯入每月讀經進度</h2>
    <form method="post">
        <?php wp_nonce_field('bc_admin_action', 'bc_admin_nonce'); ?>
        <input type="hidden" name="bc_action" value="import_plan">

        月份：
        <select name="month_no">
            <?php for ($m=1;$m<=12;$m++): ?>
                <option value="<?php echo (int)$m; ?>"><?php echo (int)$m; ?> 月</option>
            <?php endfor; ?>
        </select>

        <br><br>
        <textarea name="plan_text" rows="10" cols="90" required
            placeholder="1/1: 創 1-10"></textarea>

        <p>
            <button class="button button-primary">匯入本月進度</button>
        </p>
    </form>

    <hr>

    <!-- 已匯入進度（檢查用） -->
    <h2>目前專案已匯入的讀經進度</h2>

    <?php
    $months = bc_get_plan_months($current_project_id); // 回傳 month_key + day_count
    if ( $months ):
    ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>月份</th>
                    <th>天數</th>
                    <th>每日內容（檢查用）</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $months as $row ):
                $month_key = $row['month_key'];
                $days = bc_get_plan_days_by_month_key($current_project_id, $month_key);
            ?>
                <tr>
                    <td><?php echo esc_html($month_key); ?></td>
                    <td><?php echo esc_html($row['day_count']); ?> 天</td>
                    <td>
                        <?php if ($days): ?>
                            <details>
                                <summary>查看每日章節</summary>
                                <ul style="margin-left:1.2em;">
                                    <?php foreach ($days as $d): ?>
                                        <li>
                                            <?php
                                            echo esc_html(
                                                bc_fmt_ymd_to_md($d['ymd']) . ' ' . $d['display_text']
                                            );
                                            ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>尚未匯入任何讀經進度。</p>
    <?php endif; ?>

    <hr>
    <?php
}

/**
 * 匯入每月讀經進度（ymd + month_key）
 * - 同專案同月份：先刪再插
 * - month_key = YYYY-MM
 */
function bc_handle_import_plan() {
    global $wpdb;

    $project_id = bc_get_current_project_id();
    $project    = bc_get_project($project_id);
    if ( ! $project ) {
        bc_admin_error('找不到目前專案');
        return;
    }

    $month = intval($_POST['month_no']);
    if ($month < 1 || $month > 12) {
        bc_admin_error('月份不正確');
        return;
    }

    $month_key = sprintf('%04d-%02d', (int)$project['year'], (int)$month);

    // 先刪除該月舊資料（同專案同月份）
    $wpdb->delete(
        bc_table_plan_days(),
        ['project_id'=>$project_id,'month_key'=>$month_key]
    );

    $lines = preg_split('/\r\n|\r|\n/', trim((string)$_POST['plan_text']));
    $added = 0;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        // 支援：1/1 創 1-10、1/1: 創 1-10、1/1：創 1-10
        if (!preg_match('/^(\d{1,2})\/(\d{1,2})\s*[:：]?\s*(.+)$/u', $line, $m)) {
            continue;
        }

        $day = intval($m[2]);
        if ($day < 1 || $day > 31) continue;

        $ymd = sprintf('%04d%02d%02d', (int)$project['year'], (int)$month, (int)$day);

        $ok = $wpdb->insert(
            bc_table_plan_days(),
            [
                'project_id'   => $project_id,
                'ymd'          => $ymd,
                'display_text' => trim($m[3]),
                'month_key'    => $month_key,
                'created_at'   => current_time('mysql'),
            ],
            ['%d','%s','%s','%s','%s']
        );

        if ($ok !== false) $added++;
    }

    bc_admin_notice("讀經進度已成功匯入（新增 {$added} 筆）");
}

function bc_fmt_ymd_to_md($ymd) {
    $ymd = preg_replace('/\D+/', '', (string)$ymd);
    if (strlen($ymd) !== 8) return $ymd;
    $m = intval(substr($ymd, 4, 2));
    $d = intval(substr($ymd, 6, 2));
    return "{$m}/{$d}";
}
