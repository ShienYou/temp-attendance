<?php
/**
 * Admin - Bible Check-in System
 * Single page admin UI (改成分頁 tabs，但不改功能)
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Load feature modules (tabs + handlers are grouped together).
 * Only split admin.php. Other parts of plugin remain unchanged.
 */
$bc_admin_features_dir = __DIR__ . '/features';

require_once $bc_admin_features_dir . '/projects.php';
require_once $bc_admin_features_dir . '/plan.php';
require_once $bc_admin_features_dir . '/months.php';
require_once $bc_admin_features_dir . '/branches.php';
require_once $bc_admin_features_dir . '/people.php';

/* === 新增：唯讀總覽 tabs（不影響既有功能） === */
require_once $bc_admin_features_dir . '/regions.php';     // 牧區總覽
require_once $bc_admin_features_dir . '/subregions.php';  // 小區總覽
require_once $bc_admin_features_dir . '/groups.php';      // 小組總覽
/* === 新增結束 === */

require_once $bc_admin_features_dir . '/teams.php';

/* === 新增：獎勵結算 === */
require_once $bc_admin_features_dir . '/rewards.php';
/* === 新增結束 === */

require_once $bc_admin_features_dir . '/danger.php';

/**
 * Capability rules:
 * - bible_checkin_access : can enter plugin admin
 * - bible_checkin_manage : full admin inside plugin
 * - bible_checkin_view   : read-only inside plugin
 */
function bc_admin_has_access() : bool {
    return current_user_can('manage_options') || current_user_can('bible_checkin_access') || current_user_can('bible_checkin_manage') || current_user_can('bible_checkin_view');
}
function bc_admin_can_manage() : bool {
    return current_user_can('manage_options') || current_user_can('bible_checkin_manage');
}
function bc_admin_can_view() : bool {
    return bc_admin_can_manage() || current_user_can('bible_checkin_view') || current_user_can('bible_checkin_access');
}

/**
 * Menu registration:
 * Use bible_checkin_access so both church-admin and church-viewer can see it.
 * (Administrators also have access via manage_options anyway.)
 */
add_action('admin_menu', function () {

    add_menu_page(
        '讀經打卡系統',
        '讀經打卡',
        'bible_checkin_access',
        'bible-checkin',
        'bc_admin_page',
        'dashicons-book-alt',
        26
    );

});

function bc_admin_page() {
    if ( ! bc_admin_has_access() ) return;

    // Determine current tab first (used for permission gating)
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'projects';

    /**
     * Tabs definition depends on permission:
     * - Manage: all tabs
     * - Viewer: read-only tabs only (no write/critical ops)
     */
    if ( bc_admin_can_manage() ) {
        $tabs = [
            'projects'   => '專案',
            'plan'       => '讀經進度',
            'months'     => '月份設定',
            'branches'   => '分堂管理',
            'people'     => '人員管理',

            'regions'    => '牧區總覽',
            'subregions' => '小區總覽',
            'groups'     => '小組總覽',

            'teams'      => '小隊總覽',
            'rewards'    => '獎勵結算',

            'danger'     => '危險操作',
        ];
    } else {
        // Viewer: only safe read-only tabs
        $tabs = [
            'regions'    => '牧區總覽',
            'subregions' => '小區總覽',
            'groups'     => '小組總覽',
            'teams'      => '小隊總覽',
            'rewards'    => '獎勵結算',
        ];
        // Default viewer tab
        if ( empty($tab) || ! isset($tabs[$tab]) ) {
            $tab = 'regions';
        }
    }

    // Final validation
    if ( ! isset($tabs[$tab]) ) {
        // For managers fallback to projects, for viewers fallback to regions
        $tab = bc_admin_can_manage() ? 'projects' : 'regions';
    }

    /**
     * Handle POST actions
     * - Only managers can execute write actions.
     * - Viewers are blocked even if they forge POST.
     */
    if ( isset($_POST['bc_action']) ) {

        // Always verify nonce if they POST anything
        check_admin_referer('bc_admin_action', 'bc_admin_nonce');

        // Viewers cannot run any bc_action write ops
        if ( ! bc_admin_can_manage() ) {
            bc_admin_error('你沒有操作權限（此帳號為唯讀）。');
        } else {

            switch ( sanitize_text_field($_POST['bc_action']) ) {

                case 'switch_project':
                    bc_set_current_project_id( intval($_POST['project_id']) );
                    bc_admin_notice('目前專案已切換');
                    break;

                case 'create_project':
                    bc_handle_create_project();
                    break;

                case 'import_plan':
                    bc_handle_import_plan();
                    break;

                case 'delete_project':
                    bc_handle_delete_project();
                    break;

                case 'create_branch':
                    bc_handle_create_branch();
                    break;

                case 'update_branch':
                    bc_handle_update_branch();
                    break;

                case 'create_person':
                    bc_handle_create_person();
                    break;

                case 'toggle_person_active':
                    bc_handle_toggle_person_active();
                    break;

                case 'reset_all':
                    bc_handle_reset_all_dev();
                    break;

                case 'rebuild_teams':
                    bc_handle_rebuild_teams();
                    break;

                case 'delete_person':
                    bc_handle_delete_person();
                    break;

                case 'save_month_settings':
                    bc_handle_save_month_settings();
                    break;

                /* === rewards actions === */
                case 'run_settlement':
                    if ( function_exists('bc_handle_run_settlement') ) {
                        bc_handle_run_settlement();
                    } else {
                        bc_admin_error('rewards.php 尚未提供 bc_handle_run_settlement()');
                    }
                    break;

                case 'export_rewards_csv':
                    bc_admin_error('匯出已改為「獨立下載流程」（避免 header already sent）。請到【獎勵結算】頁面按「匯出目前結果 CSV」。');
                    break;
                /* === rewards actions end === */
            }
        }
    }

    // Notices
    if ( $msg = get_option('bc_admin_notice') ) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        delete_option('bc_admin_notice');
    }
    if ( $err = get_option('bc_admin_error') ) {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($err) . '</p></div>';
        delete_option('bc_admin_error');
    }

    $projects           = bc_get_all_projects();
    $current_project_id = bc_get_current_project_id();
    $project            = bc_get_project($current_project_id);
    ?>

    <div class="wrap">
        <h1>讀經打卡系統</h1>

        <h2 class="nav-tab-wrapper" style="margin-bottom:12px;">
            <?php foreach ($tabs as $k => $label):
                $url = admin_url('admin.php?page=bible-checkin&tab=' . $k);
                $cls = 'nav-tab' . ( $tab === $k ? ' nav-tab-active' : '' );
            ?>
                <a href="<?php echo esc_url($url); ?>" class="<?php echo esc_attr($cls); ?>">
                    <?php echo esc_html($label); ?>
                </a>
            <?php endforeach; ?>
        </h2>

        <?php if ( bc_admin_can_manage() ): ?>
            <!-- 目前專案（只有管理同工可見） -->
            <h2>目前專案</h2>
            <form method="post">
                <?php wp_nonce_field('bc_admin_action', 'bc_admin_nonce'); ?>
                <input type="hidden" name="bc_action" value="switch_project">
                <select name="project_id" onchange="this.form.submit()">
                    <?php foreach ($projects as $p): ?>
                        <option value="<?php echo esc_attr($p['id']); ?>"
                            <?php selected($p['id'], $current_project_id); ?>>
                            <?php echo esc_html($p['church_name'] . '｜' . $p['project_name'] . '（' . $p['year'] . '）'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <?php if ($project): ?>
                <p style="margin-top:8px;color:#444;">
                    目前專案：
                    <?php
                    echo esc_html(
                        $project['church_name']
                        . '｜'
                        . $project['project_name']
                        . '（'
                        . $project['year']
                        . '）'
                    );
                    ?>
                </p>

                <p style="margin-top:4px;color:#666;font-size:13px;">
                    Shortcode：
                    <code>[bible_checkin project="<?php echo (int)$project['id']; ?>"]</code>
                </p>
            <?php endif; ?>

            <hr>
        <?php else: ?>
            <!-- Viewer（唯讀）：不顯示專案切換與 shortcode，避免誤操作與混亂 -->
        <?php endif; ?>

        <?php
        // Render selected tab
        switch ($tab) {

            // Manager-only tabs
            case 'projects':
                if ( bc_admin_can_manage() ) bc_admin_render_tab_projects($current_project_id, $projects, $project);
                else bc_admin_error('你沒有操作權限（此帳號為唯讀）。');
                break;

            case 'plan':
                if ( bc_admin_can_manage() ) bc_admin_render_tab_plan($current_project_id);
                else bc_admin_error('你沒有操作權限（此帳號為唯讀）。');
                break;

            case 'months':
                if ( bc_admin_can_manage() ) bc_admin_render_tab_months($current_project_id);
                else bc_admin_error('你沒有操作權限（此帳號為唯讀）。');
                break;

            case 'branches':
                if ( bc_admin_can_manage() ) bc_admin_render_tab_branches($current_project_id);
                else bc_admin_error('你沒有操作權限（此帳號為唯讀）。');
                break;

            case 'people':
                if ( bc_admin_can_manage() ) bc_admin_render_tab_people($current_project_id);
                else bc_admin_error('你沒有操作權限（此帳號為唯讀）。');
                break;

            // Read-only overview tabs (allowed for both manager and viewer)
            case 'regions':
                bc_admin_render_tab_regions($current_project_id);
                break;

            case 'subregions':
                bc_admin_render_tab_subregions($current_project_id);
                break;

            case 'groups':
                bc_admin_render_tab_groups($current_project_id);
                break;

            case 'teams':
                bc_admin_render_tab_teams($current_project_id);
                break;

            // Rewards: allow viewer to see; settlement/run actions are already blocked by POST gate above
            case 'rewards':
                if ( function_exists('bc_admin_render_tab_rewards') ) {
                    bc_admin_render_tab_rewards($current_project_id);
                } else {
                    echo '<p style="color:#a00;">rewards.php 尚未提供 bc_admin_render_tab_rewards()</p>';
                }
                break;

            case 'danger':
                if ( bc_admin_can_manage() ) bc_admin_render_tab_danger($current_project_id, $projects);
                else bc_admin_error('你沒有操作權限（此帳號為唯讀）。');
                break;

            default:
                // Safe fallback
                if ( bc_admin_can_manage() ) {
                    bc_admin_render_tab_projects($current_project_id, $projects, $project);
                } else {
                    bc_admin_render_tab_regions($current_project_id);
                }
                break;
        }
        ?>

    </div>
<?php }

/* =========================
 * Notices (保持原本 function 名稱與行為)
 * ========================= */

function bc_admin_notice($msg) {
    update_option('bc_admin_notice', (string)$msg);
}

function bc_admin_error($msg) {
    update_option('bc_admin_error', (string)$msg);
}
