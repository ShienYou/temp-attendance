<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Tab: rewards
 * - 顯示結算摘要
 * - 一鍵重新計算（寫回 bc_people）
 * - 列表查看（分頁/篩選）
 * - 獎勵快捷篩選（6 顆按鈕）
 * - 匯出目前篩選結果 CSV（看到什麼就匯出什麼，走 admin-post endpoint，避免 headers already sent）
 *
 * 依賴：
 * - includes/settlement.php: bc_settlement_run()
 * - includes/db.php: bc_get_branches(), bc_get_setting()
 * - admin.php: bc_admin_notice(), bc_admin_error()
 */

$bc_root = dirname(__DIR__, 2);
if ( file_exists($bc_root . '/includes/settlement.php') ) {
    require_once $bc_root . '/includes/settlement.php';
}

/**
 * 匯出 endpoint：用 admin-post.php 走獨立 request，確保 headers 能正常送出
 */
add_action('admin_post_bc_export_rewards_csv', 'bc_export_rewards_csv_endpoint');
function bc_export_rewards_csv_endpoint() {

    if ( ! current_user_can('bible_checkin_manage') ) {
        wp_die('Forbidden', 403);
    }

    // nonce（獨立 action）
    if ( empty($_POST['bc_export_nonce']) || ! wp_verify_nonce($_POST['bc_export_nonce'], 'bc_export_rewards_csv') ) {
        wp_die('Bad nonce', 400);
    }

    $project_id = function_exists('bc_get_current_project_id') ? (int) bc_get_current_project_id() : 0;
    if ( $project_id <= 0 ) {
        wp_die('No project', 400);
    }

    // 讀取「目前看到的篩選條件」（由 rewards tab 匯出 form hidden fields 帶進來）
    $branch_id     = isset($_POST['branch_id']) ? (int)$_POST['branch_id'] : 0;
    $region        = isset($_POST['region']) ? sanitize_text_field($_POST['region']) : '';
    $sub_region    = isset($_POST['sub_region']) ? sanitize_text_field($_POST['sub_region']) : '';
    $group_name    = isset($_POST['group_name']) ? sanitize_text_field($_POST['group_name']) : '';
    $q             = isset($_POST['q']) ? sanitize_text_field($_POST['q']) : '';
    $only_rewarded = isset($_POST['only_rewarded']) && (string)$_POST['only_rewarded'] === '1';

    $reward_filter = isset($_POST['reward_filter']) ? sanitize_key($_POST['reward_filter']) : 'all';
    if ( ! in_array($reward_filter, bc_rewards_allowed_reward_filter_keys(), true) ) {
        $reward_filter = 'all';
    }

    global $wpdb;
    $t_people = function_exists('bc_table_people') ? bc_table_people() : ($wpdb->prefix . 'bc_people');
    $t_br     = function_exists('bc_table_branches') ? bc_table_branches() : ($wpdb->prefix . 'bc_branches');

    list($where_sql, $params) = bc_rewards_build_people_where_sql_and_params(
        $project_id,
        $branch_id,
        $region,
        $sub_region,
        $group_name,
        $q,
        $only_rewarded,
        $reward_filter
    );

    // 匯出不受分頁影響：抓全部符合條件的 rows
    $sql_rows = "
        SELECT
            p.*,
            b.branch_name
        FROM {$t_people} p
        LEFT JOIN {$t_br} b
               ON b.id = p.branch_id
              AND b.project_id = p.project_id
        {$where_sql}
        ORDER BY b.branch_name, p.region, p.sub_region, p.group_name, CAST(p.team_no AS UNSIGNED), p.team_no, p.name, p.id
    ";
    $rows = $wpdb->get_results($wpdb->prepare($sql_rows, $params), ARRAY_A);
    $rows = is_array($rows) ? $rows : array();

    // 檔名（含條件）
    $filter_tag = $reward_filter ?: 'all';
    $ts = date('Ymd_His');
    $filename = sanitize_file_name('rewards_' . $project_id . '_' . $filter_tag . '_' . $ts . '.csv');

    // ✅ 超保險：清掉所有 output buffer，避免任何 stray output 影響 header
    while ( ob_get_level() ) { @ob_end_clean(); }

    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');

    // Excel 友善：UTF-8 BOM
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');

    // Header
    fputcsv($out, array(
        '姓名',
        '分堂',
        '牧區',
        '小區',
        '小組',
        '小隊',
        '1月狀態',
        '2月狀態',
        '3月狀態',
        '4月狀態',
        '獎勵',
    ));

    foreach ($rows as $r) {
        $m1 = isset($r['month_01_status']) ? (int)$r['month_01_status'] : -1;
        $m2 = isset($r['month_02_status']) ? (int)$r['month_02_status'] : -1;
        $m3 = isset($r['month_03_status']) ? (int)$r['month_03_status'] : -1;
        $m4 = isset($r['month_04_status']) ? (int)$r['month_04_status'] : -1;

        fputcsv($out, array(
            (string)($r['name'] ?? ''),
            (string)(($r['branch_name'] ?? '') ?: '（未指定）'),
            (string)($r['region'] ?? ''),
            (string)($r['sub_region'] ?? ''),
            (string)($r['group_name'] ?? ''),
            (string)($r['team_no'] ?? ''),
            $m1,
            $m2,
            $m3,
            $m4,
            (string)($r['reward_summary'] ?? ''),
        ));
    }

    fclose($out);
    exit;
}

/**
 * 安全取得 bc_people 欄位的 distinct options（用來做階層式下拉）
 */
function bc_rewards_distinct_people_field_values($project_id, $field, $branch_id = 0, $region = '', $sub_region = '', $group_name = '') {
    global $wpdb;

    $project_id = (int)$project_id;
    if ($project_id <= 0) return array();

    $allowed = array(
        'region'     => 'region',
        'sub_region' => 'sub_region',
        'group_name' => 'group_name',
    );
    if ( ! isset($allowed[$field]) ) return array();

    $col = $allowed[$field];

    $t_people = function_exists('bc_table_people') ? bc_table_people() : ($wpdb->prefix . 'bc_people');

    $where = array('project_id = %d', 'is_active = 1');
    $params = array($project_id);

    if ($branch_id > 0) {
        $where[] = 'branch_id = %d';
        $params[] = (int)$branch_id;
    }
    if ($region !== '') {
        $where[] = 'region = %s';
        $params[] = $region;
    }
    if ($sub_region !== '') {
        $where[] = 'sub_region = %s';
        $params[] = $sub_region;
    }
    if ($group_name !== '') {
        $where[] = 'group_name = %s';
        $params[] = $group_name;
    }

    $where[] = "{$col} <> ''";
    $where_sql = 'WHERE ' . implode(' AND ', $where);

    $sql = "
        SELECT DISTINCT {$col} AS v
        FROM {$t_people}
        {$where_sql}
        ORDER BY {$col} ASC
    ";

    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    if ( ! is_array($rows) ) return array();

    $out = array();
    foreach ($rows as $r) {
        $v = isset($r['v']) ? (string)$r['v'] : '';
        $v = trim($v);
        if ($v !== '') $out[] = $v;
    }
    return $out;
}

function bc_rewards_allowed_reward_filter_keys(): array {
    return array(
        'all',
        'coffee_01',
        'coffee_02',
        'coffee_03',
        'april_recovery',
        'meal_full_attendance',
    );
}

function bc_rewards_build_people_where_sql_and_params(
    int $project_id,
    int $branch_id,
    string $region,
    string $sub_region,
    string $group_name,
    string $q,
    bool $only_rewarded,
    string $reward_filter
): array {
    global $wpdb;

    $where = array('p.project_id = %d', 'p.is_active = 1');
    $params = array($project_id);

    if ( $branch_id > 0 ) {
        $where[] = 'p.branch_id = %d';
        $params[] = $branch_id;
    }
    if ( $region !== '' ) {
        $where[] = 'p.region = %s';
        $params[] = $region;
    }
    if ( $sub_region !== '' ) {
        $where[] = 'p.sub_region = %s';
        $params[] = $sub_region;
    }
    if ( $group_name !== '' ) {
        $where[] = 'p.group_name = %s';
        $params[] = $group_name;
    }
    if ( $q !== '' ) {
        $where[] = 'p.name LIKE %s';
        $params[] = '%' . $wpdb->esc_like($q) . '%';
    }

    if ( $only_rewarded ) {
        $where[] = "(p.reward_coffee_01=1 OR p.reward_coffee_02=1 OR p.reward_coffee_03=1 OR p.reward_meal_full_attendance=1 OR p.reward_april_recovery=1)";
    }

    switch ($reward_filter) {
        case 'coffee_01':
            $where[] = 'p.reward_coffee_01 = 1';
            break;
        case 'coffee_02':
            $where[] = 'p.reward_coffee_02 = 1';
            break;
        case 'coffee_03':
            $where[] = 'p.reward_coffee_03 = 1';
            break;
        case 'april_recovery':
            $where[] = 'p.reward_april_recovery = 1';
            break;
        case 'meal_full_attendance':
            $where[] = 'p.reward_meal_full_attendance = 1';
            break;
        case 'all':
        default:
            break;
    }

    $where_sql = 'WHERE ' . implode(' AND ', $where);
    return array($where_sql, $params);
}

/**
 * Tab renderer
 * - ✅ 放行 view 進入（唯讀）
 * - ✅ 寫入/匯出/重算仍只給 manage
 */
function bc_admin_render_tab_rewards($current_project_id) {

    if (
        ! current_user_can('bible_checkin_manage') &&
        ! current_user_can('bible_checkin_view')
    ) {
        echo '<p style="color:#a00;">權限不足</p>';
        return;
    }

    $can_manage = current_user_can('bible_checkin_manage');

    // handle POST action（⚠️ handler 內仍是 manage only）
    if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
        $action = sanitize_text_field($_POST['bc_action'] ?? '');
        if ( $action === 'run_settlement' ) {
            bc_handle_run_settlement();
        }
    }

    $project_id = (int)$current_project_id;
    if ( $project_id <= 0 ) {
        echo '<p style="color:#a00;">找不到目前專案</p>';
        return;
    }

    echo '<h2>獎勵結算</h2>';

    if ($can_manage) {
        echo '<p style="color:#666;margin-top:-6px;">這裡是「計算結果 + 寫回資料庫」的入口。重跑會覆蓋舊的獎勵欄位。</p>';
    } else {
        echo '<p style="color:#666;margin-top:-6px;">此頁為唯讀檢視（viewer）：可查看結算結果與列表，但不可重新結算、不可匯出。</p>';
    }

    $last_run    = function_exists('bc_get_setting') ? bc_get_setting('settlement_last_run', '', $project_id) : '';
    $last_report = function_exists('bc_get_setting') ? bc_get_setting('settlement_last_report', null, $project_id) : null;

    echo '<div style="background:#fff;border:1px solid #e5e5e5;padding:12px;margin:12px 0;">';
    echo '<div style="margin-bottom:8px;"><strong>最近一次結算：</strong> ' . esc_html($last_run ? $last_run : '（尚未結算）') . '</div>';

    if ( is_array($last_report) ) {
        echo '<div style="color:#444;line-height:1.8;">';
        echo 'Active 人數：<strong>' . (int)($last_report['people_active'] ?? 0) . '</strong>　｜　';
        echo '1月咖啡券：<strong>' . (int)($last_report['coffee_01'] ?? 0) . '</strong>　｜　';
        echo '2月咖啡券：<strong>' . (int)($last_report['coffee_02'] ?? 0) . '</strong>　｜　';
        echo '3月咖啡券：<strong>' . (int)($last_report['coffee_03'] ?? 0) . '</strong>　｜　';
        echo '全勤餐券：<strong>' . (int)($last_report['meal_full_attendance'] ?? 0) . '</strong>　｜　';
        echo '4月補償券：<strong>' . (int)($last_report['april_recovery'] ?? 0) . '</strong>';
        echo '</div>';
    }

    echo '</div>';

    // 重新結算（只有 manage 看得到）
    if ($can_manage) {
        ?>
        <form method="post" style="margin:12px 0;">
            <?php wp_nonce_field('bc_admin_action', 'bc_admin_nonce'); ?>
            <input type="hidden" name="bc_action" value="run_settlement">
            <button class="button button-primary" onclick="return confirm('確定要重新結算並寫回資料庫？這會覆蓋舊的獎勵欄位。');">
                重新結算（寫回）
            </button>
            <label style="margin-left:10px;">
                <input type="checkbox" name="dry_run" value="1">
                Dry-run（只算不寫）
            </label>
        </form>
        <?php
    }

    echo '<hr>';

    $branches = function_exists('bc_get_branches') ? bc_get_branches($project_id, true) : array();

    $page     = max(1, (int)($_GET['paged'] ?? 1));
    $per_page = (int)($_GET['per_page'] ?? 50);
    if ($per_page <= 0) $per_page = 50;
    if ($per_page > 200) $per_page = 200;
    $offset = ($page - 1) * $per_page;

    $branch_id  = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
    $region     = isset($_GET['region']) ? sanitize_text_field($_GET['region']) : '';
    $sub_region = isset($_GET['sub_region']) ? sanitize_text_field($_GET['sub_region']) : '';
    $group_name = isset($_GET['group_name']) ? sanitize_text_field($_GET['group_name']) : '';

    $q            = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
    $only_rewarded = isset($_GET['only_rewarded']) && (string)$_GET['only_rewarded'] === '1';

    $reward_filter = isset($_GET['reward_filter']) ? sanitize_key($_GET['reward_filter']) : 'all';
    if ( ! in_array($reward_filter, bc_rewards_allowed_reward_filter_keys(), true) ) {
        $reward_filter = 'all';
    }

    $region_options    = bc_rewards_distinct_people_field_values($project_id, 'region', $branch_id);
    $subregion_options = bc_rewards_distinct_people_field_values($project_id, 'sub_region', $branch_id, ($region !== '' ? $region : ''));
    $group_options     = bc_rewards_distinct_people_field_values($project_id, 'group_name', $branch_id, ($region !== '' ? $region : ''), ($sub_region !== '' ? $sub_region : ''));

    global $wpdb;
    $t_people = function_exists('bc_table_people') ? bc_table_people() : ($wpdb->prefix . 'bc_people');
    $t_br     = function_exists('bc_table_branches') ? bc_table_branches() : ($wpdb->prefix . 'bc_branches');

    list($where_sql, $params) = bc_rewards_build_people_where_sql_and_params(
        $project_id,
        $branch_id,
        $region,
        $sub_region,
        $group_name,
        $q,
        $only_rewarded,
        $reward_filter
    );

    $sql_count = "SELECT COUNT(*) FROM {$t_people} p {$where_sql}";
    $total = (int)$wpdb->get_var($wpdb->prepare($sql_count, $params));
    $total_pages = max(1, (int)ceil($total / $per_page));

    $sql_rows = "
        SELECT
            p.*,
            b.branch_name
        FROM {$t_people} p
        LEFT JOIN {$t_br} b
               ON b.id = p.branch_id
              AND b.project_id = p.project_id
        {$where_sql}
        ORDER BY b.branch_name, p.region, p.sub_region, p.group_name, CAST(p.team_no AS UNSIGNED), p.team_no, p.name, p.id
        LIMIT %d OFFSET %d
    ";
    $rows_params = array_merge($params, array($per_page, $offset));
    $rows = $wpdb->get_results($wpdb->prepare($sql_rows, $rows_params), ARRAY_A);
    $rows = is_array($rows) ? $rows : array();

    $base_url = admin_url('admin.php?page=bible-checkin&tab=rewards');

    $query_base = $_GET;
    $query_base['page'] = 'bible-checkin';
    $query_base['tab']  = 'rewards';
    unset($query_base['paged']);

    $reward_buttons = array(
        'all'                 => '獎勵總覽',
        'coffee_01'           => '1月咖啡券',
        'coffee_02'           => '2月咖啡券',
        'coffee_03'           => '3月咖啡券',
        'april_recovery'      => '4月補償券',
        'meal_full_attendance'=> '全勤餐券',
    );

    ?>
    <h3>結算結果列表（Active）</h3>

    <form method="get" style="margin:10px 0;">
        <input type="hidden" name="page" value="bible-checkin">
        <input type="hidden" name="tab" value="rewards">
        <input type="hidden" name="reward_filter" value="<?php echo esc_attr($reward_filter); ?>">

        分堂：
        <select name="branch_id">
            <option value="0">全部</option>
            <?php foreach ($branches as $b): ?>
                <option value="<?php echo (int)$b['id']; ?>" <?php selected($branch_id, (int)$b['id']); ?>>
                    <?php echo esc_html($b['branch_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>

        牧區：
        <select name="region">
            <option value="">全部</option>
            <?php foreach ($region_options as $v): ?>
                <option value="<?php echo esc_attr($v); ?>" <?php selected($region, $v); ?>>
                    <?php echo esc_html($v); ?>
                </option>
            <?php endforeach; ?>
        </select>

        小區：
        <select name="sub_region">
            <option value="">全部</option>
            <?php foreach ($subregion_options as $v): ?>
                <option value="<?php echo esc_attr($v); ?>" <?php selected($sub_region, $v); ?>>
                    <?php echo esc_html($v); ?>
                </option>
            <?php endforeach; ?>
        </select>

        小組：
        <select name="group_name">
            <option value="">全部</option>
            <?php foreach ($group_options as $v): ?>
                <option value="<?php echo esc_attr($v); ?>" <?php selected($group_name, $v); ?>>
                    <?php echo esc_html($v); ?>
                </option>
            <?php endforeach; ?>
        </select>

        姓名：
        <input type="text" name="q" value="<?php echo esc_attr($q); ?>" style="width:120px;">

        <label style="margin-left:8px;">
            <input type="checkbox" name="only_rewarded" value="1" <?php checked($only_rewarded, true); ?>>
            只看「有獎勵」的人
        </label>

        每頁：
        <select name="per_page">
            <?php foreach ([20,50,100,200] as $n): ?>
                <option value="<?php echo $n; ?>" <?php selected($per_page, $n); ?>><?php echo $n; ?></option>
            <?php endforeach; ?>
        </select>

        <button class="button">套用</button>
    </form>

    <div style="margin:10px 0 12px 0;">
        <?php foreach ($reward_buttons as $k => $label):
            $q2 = $query_base;
            $q2['reward_filter'] = $k;
            $url = add_query_arg($q2, $base_url);

            $cls = 'button';
            if ($reward_filter === $k) $cls .= ' button-primary';
        ?>
            <a class="<?php echo esc_attr($cls); ?>" href="<?php echo esc_url($url); ?>" style="margin-right:6px;margin-bottom:6px;">
                <?php echo esc_html($label); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($can_manage): ?>
        <!-- ✅ 匯出：改走 admin-post endpoint，避免 headers already sent -->
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0 0 10px 0;">
            <input type="hidden" name="action" value="bc_export_rewards_csv">
            <?php wp_nonce_field('bc_export_rewards_csv', 'bc_export_nonce'); ?>

            <input type="hidden" name="branch_id" value="<?php echo (int)$branch_id; ?>">
            <input type="hidden" name="region" value="<?php echo esc_attr($region); ?>">
            <input type="hidden" name="sub_region" value="<?php echo esc_attr($sub_region); ?>">
            <input type="hidden" name="group_name" value="<?php echo esc_attr($group_name); ?>">
            <input type="hidden" name="q" value="<?php echo esc_attr($q); ?>">
            <input type="hidden" name="only_rewarded" value="<?php echo $only_rewarded ? '1' : '0'; ?>">
            <input type="hidden" name="reward_filter" value="<?php echo esc_attr($reward_filter); ?>">

            <button class="button">匯出目前結果 CSV</button>
            <span style="color:#666;margin-left:8px;">（會匯出所有符合條件的人，不受分頁影響）</span>
        </form>
    <?php else: ?>
        <div style="margin:0 0 10px 0;color:#666;">
            匯出 CSV：需要管理者權限（manage）。
        </div>
    <?php endif; ?>

    <p style="margin:6px 0;color:#444;">
        總筆數：<?php echo (int)$total; ?>　
        本頁：<?php echo (int)count($rows); ?>　
        頁數：<?php echo (int)$page; ?>/<?php echo (int)$total_pages; ?>
    </p>

    <?php if ($rows): ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th style="width:120px;">姓名</th>
                    <th style="width:140px;">分堂</th>
                    <th style="width:140px;">牧區</th>
                    <th style="width:140px;">小區</th>
                    <th>小組</th>
                    <th style="width:70px;">小隊</th>
                    <th style="width:40px;">1</th>
                    <th style="width:40px;">2</th>
                    <th style="width:40px;">3</th>
                    <th style="width:40px;">4</th>
                    <th style="width:220px;">獎勵</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo esc_html($r['name']); ?></td>
                    <td><?php echo esc_html(($r['branch_name'] ?? '') ?: '（未指定）'); ?></td>
                    <td><?php echo esc_html((string)($r['region'] ?? '') ?: ''); ?></td>
                    <td><?php echo esc_html((string)($r['sub_region'] ?? '') ?: ''); ?></td>
                    <td><?php echo esc_html((string)($r['group_name'] ?? '')); ?></td>
                    <td><?php echo esc_html($r['team_no'] ?: ''); ?></td>
                    <?php
                    $ms = [
                        (int)($r['month_01_status'] ?? -1),
                        (int)($r['month_02_status'] ?? -1),
                        (int)($r['month_03_status'] ?? -1),
                        (int)($r['month_04_status'] ?? -1),
                    ];
                    foreach ($ms as $v) {
                        if ($v === 1) echo '<td style="text-align:center;color:#0a0;font-weight:700;">✓</td>';
                        else if ($v === 0) echo '<td style="text-align:center;color:#a00;font-weight:700;">✗</td>';
                        else echo '<td style="text-align:center;color:#999;">-</td>';
                    }
                    ?>
                    <td><?php echo esc_html($r['reward_summary'] ?? ''); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
        <div style="margin-top:12px;">
            <?php
            $query = $_GET;
            unset($query['paged']);
            for ($i=1;$i<=$total_pages;$i++):
                $query['paged'] = $i;
                $url = add_query_arg($query, $base_url);
                if ($i === $page) {
                    echo '<strong style="margin-right:6px;">' . $i . '</strong>';
                } else {
                    echo '<a href="' . esc_url($url) . '" style="margin-right:6px;">' . $i . '</a>';
                }
            endfor;
            ?>
        </div>
        <?php endif; ?>

    <?php else: ?>
        <p>目前沒有資料（或篩選後為空）。</p>
    <?php endif; ?>
    <?php
}

/**
 * Handler：run settlement
 */
function bc_handle_run_settlement() {

    if ( ! current_user_can('bible_checkin_manage') ) {
        if (function_exists('bc_admin_error')) bc_admin_error('權限不足');
        return;
    }

    if ( empty($_POST['bc_admin_nonce']) || ! wp_verify_nonce($_POST['bc_admin_nonce'], 'bc_admin_action') ) {
        if (function_exists('bc_admin_error')) bc_admin_error('nonce 驗證失敗');
        return;
    }

    $project_id = function_exists('bc_get_current_project_id') ? (int)bc_get_current_project_id() : 0;
    if ( $project_id <= 0 ) {
        if (function_exists('bc_admin_error')) bc_admin_error('找不到目前專案');
        return;
    }

    $dry_run = isset($_POST['dry_run']) && (string)$_POST['dry_run'] === '1';

    if ( ! function_exists('bc_settlement_run') ) {
        if (function_exists('bc_admin_error')) bc_admin_error('settlement.php 未載入');
        return;
    }

    $res = bc_settlement_run($project_id, $dry_run);

    if ( empty($res['ok']) ) {
        $msg = $res['message'] ?? '結算失敗';
        if (function_exists('bc_admin_error')) bc_admin_error($msg);
        return;
    }

    $r = $res['report'] ?? array();
    $msg = $dry_run
        ? 'Dry-run 完成（未寫回）：'
        : '結算完成（已寫回）：';

    $msg .= ' 1月咖啡券 ' . (int)($r['coffee_01'] ?? 0)
        . '、2月 ' . (int)($r['coffee_02'] ?? 0)
        . '、3月 ' . (int)($r['coffee_03'] ?? 0)
        . '、全勤餐券 ' . (int)($r['meal_full_attendance'] ?? 0)
        . '、4月補償券 ' . (int)($r['april_recovery'] ?? 0);

    if ( ! $dry_run ) {
        $msg .= '（更新筆數 ' . (int)($r['rows_updated'] ?? 0) . '）';
    }

    if (function_exists('bc_admin_notice')) bc_admin_notice($msg);
}
