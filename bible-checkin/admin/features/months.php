<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Tab: months
 * - UI: 月份開放/鎖定設定
 * - Handler: bc_handle_save_month_settings()
 */

function bc_admin_render_tab_months($current_project_id) {
    $months = bc_get_plan_months($current_project_id);
    ?>
    <h2>月份開放 / 鎖定設定</h2>
    <p style="color:#666;margin-top:-6px;">
        這裡用來控制前台各月份是否可顯示、是否可編輯打卡。
    </p>

    <?php if ($months): ?>
    <form method="post">
        <?php wp_nonce_field('bc_admin_action', 'bc_admin_nonce'); ?>
        <input type="hidden" name="bc_action" value="save_month_settings">

        <table class="widefat striped">
            <thead>
                <tr>
                    <th>月份</th>
                    <th>開放顯示</th>
                    <th>可編輯打卡</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($months as $row):
                $month_key = $row['month_key'];
                $open_key = 'month_open_' . $month_key;
                $edit_key = 'month_editable_' . $month_key;

                $is_open = (int) bc_get_setting($open_key, 1);
                $is_edit = (int) bc_get_setting($edit_key, 1);
            ?>
                <tr>
                    <td><?php echo esc_html($month_key); ?></td>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="month_open[<?php echo esc_attr($month_key); ?>]"
                                   value="1"
                                   <?php checked($is_open, 1); ?>>
                            開放
                        </label>
                    </td>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="month_editable[<?php echo esc_attr($month_key); ?>]"
                                   value="1"
                                   <?php checked($is_edit, 1); ?>>
                            可編輯
                        </label>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p style="margin-top:12px;">
            <button class="button button-primary">儲存月份設定</button>
        </p>
    </form>
    <?php else: ?>
        <p>尚未匯入任何月份，請先匯入讀經進度。</p>
    <?php endif; ?>

    <hr>
    <?php
}

function bc_handle_save_month_settings() {
    $project_id = bc_get_current_project_id();
    if ($project_id <= 0) {
        bc_admin_error('找不到目前專案');
        return;
    }

    $open = $_POST['month_open'] ?? [];
    $edit = $_POST['month_editable'] ?? [];

    // 所有出現過的月份（避免漏存）
    $months = array_unique(
        array_merge(
            array_keys($open),
            array_keys($edit)
        )
    );

    foreach ($months as $month_key) {
        $month_key = sanitize_text_field($month_key);

        // 原始值
        $open_val = isset($open[$month_key]) ? 1 : 0;
        $edit_val = isset($edit[$month_key]) ? 1 : 0;

        /**
         * 狀態機保護（定案）
         * 允許：
         *  - 1 / 1 開放且可編輯
         *  - 1 / 0 開放但鎖定
         *  - 0 / 0 完全關閉
         * 禁止：
         *  - 0 / 1（不顯示卻可編輯，語意錯誤）
         */
        if ($open_val === 0 && $edit_val === 1) {
            $edit_val = 0;
        }

        bc_set_setting(
            'month_open_' . $month_key,
            $open_val,
            $project_id
        );

        bc_set_setting(
            'month_editable_' . $month_key,
            $edit_val,
            $project_id
        );
    }

    bc_admin_notice('月份設定已儲存');
}
