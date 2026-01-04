<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Tab: branches
 * - UI: 分堂管理
 * - Handlers: bc_handle_create_branch(), bc_handle_update_branch()
 */

function bc_admin_render_tab_branches($current_project_id) {
    ?>
    <!-- 分堂管理 -->
    <h2>分堂管理（目前專案）</h2>
    <p style="color:#666;margin-top:-6px;">
        分堂清單只影響「新增人員時的下拉選單」。不影響前台打卡、不影響既有資料。
    </p>

    <h3>新增分堂</h3>
    <form method="post">
        <?php wp_nonce_field('bc_admin_action', 'bc_admin_nonce'); ?>
        <input type="hidden" name="bc_action" value="create_branch">
        <input type="text" name="branch_name" class="regular-text" placeholder="例如：台北堂 / 新店堂" required>
        <input type="number" name="sort_order" value="0" style="width:90px;">
        <button class="button button-primary">新增分堂</button>
    </form>

    <h3 style="margin-top:16px;">分堂清單</h3>
    <?php
    $branches_all = bc_get_branches($current_project_id, false);
    if ($branches_all):
    ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>分堂名稱</th>
                    <th>排序</th>
                    <th>啟用</th>
                    <th>更新</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($branches_all as $b): ?>
                <tr>
                    <td><?php echo esc_html($b['branch_name']); ?></td>
                    <td><?php echo esc_html($b['sort_order']); ?></td>
                    <td><?php echo $b['is_active'] ? '是' : '否'; ?></td>
                    <td>
                        <form method="post" style="display:flex;gap:8px;align-items:center;">
                            <?php wp_nonce_field('bc_admin_action', 'bc_admin_nonce'); ?>
                            <input type="hidden" name="bc_action" value="update_branch">
                            <input type="hidden" name="branch_id" value="<?php echo esc_attr($b['id']); ?>">
                            <input type="text" name="branch_name" value="<?php echo esc_attr($b['branch_name']); ?>" style="width:240px;">
                            <input type="number" name="sort_order" value="<?php echo esc_attr($b['sort_order']); ?>" style="width:90px;">
                            <select name="is_active">
                                <option value="1" <?php selected((int)$b['is_active'], 1); ?>>啟用</option>
                                <option value="0" <?php selected((int)$b['is_active'], 0); ?>>停用</option>
                            </select>
                            <button class="button">更新</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>尚未建立任何分堂。你可以先新增分堂，讓新增人員時可以用選單選。</p>
    <?php endif; ?>

    <hr>
    <?php
}

/* ===== Branch handlers ===== */

function bc_handle_create_branch() {
    $name = sanitize_text_field($_POST['branch_name']);
    $sort = intval($_POST['sort_order']);
    $id = bc_insert_branch($name, $sort, bc_get_current_project_id());

    if ($id) bc_admin_notice('分堂已新增');
    else bc_admin_error('分堂新增失敗');
}

function bc_handle_update_branch() {
    $branch_id = intval($_POST['branch_id']);
    $name = sanitize_text_field($_POST['branch_name']);
    $sort = intval($_POST['sort_order']);
    $active = intval($_POST['is_active']) ? 1 : 0;

    $ok = bc_update_branch($branch_id, [
        'branch_name' => $name,
        'sort_order'  => $sort,
        'is_active'   => $active,
    ], bc_get_current_project_id());

    if ($ok) bc_admin_notice('分堂已更新');
    else bc_admin_error('分堂更新失敗（可能沒變更）');
}
