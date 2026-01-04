<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Tab: projects
 * - UI: 新增專案
 * - Handler: bc_handle_create_project()
 */

function bc_admin_render_tab_projects($current_project_id, $projects, $project) {
    ?>
    <!-- 新增專案 -->
    <h2>新增讀經專案</h2>
    <form method="post">
        <?php wp_nonce_field('bc_admin_action', 'bc_admin_nonce'); ?>
        <input type="hidden" name="bc_action" value="create_project">

        <table class="form-table">
            <tr>
                <th scope="row">教會 / 分堂名稱</th>
                <td>
                    <input type="text" name="church_name" class="regular-text"
                           placeholder="例如：台北復興堂（東區）" required>
                </td>
            </tr>
            <tr>
                <th scope="row">專案名稱</th>
                <td>
                    <input type="text" name="project_name" class="regular-text"
                           placeholder="例如：全年讀經計畫" required>
                </td>
            </tr>
            <tr>
                <th scope="row">年度</th>
                <td>
                    <input type="number" name="year"
                           value="<?php echo esc_attr( date('Y') + 1 ); ?>" required>
                </td>
            </tr>
        </table>

        <p>
            <button class="button button-primary">建立新專案</button>
        </p>
    </form>

    <hr>
    <?php
}

function bc_handle_create_project() {
    global $wpdb;

    $church_name  = sanitize_text_field($_POST['church_name']);
    $project_name = sanitize_text_field($_POST['project_name']);
    $year         = intval($_POST['year']);

    $wpdb->insert(
        bc_table_projects(),
        [
            'project_key'  => 'project_' . time(),
            'project_name' => $project_name,
            'church_name'  => $church_name,
            'year'         => $year,
            'status'       => 'active',
            'created_at'   => current_time('mysql'),
        ]
    );

    bc_admin_notice('新專案已建立');
}
