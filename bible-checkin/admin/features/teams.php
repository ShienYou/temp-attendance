<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Tab: teams
 * - 小隊總覽（可 filter + 階層式）
 * - 點進小隊：一次顯示「所有有 plan_days 的月份」→ 每月一塊矩陣（Excel 感）
 * - 整理小隊（寫入系統）：沿用你原本 Round 5.1 流程
 *
 * 依賴：
 * - db.php: bc_get_current_project_id(), bc_table_people(), bc_table_checkins(), bc_get_plan_months(), bc_get_plan_days_by_month_key()
 * - db.php: bc_get_people_hierarchy_for_filters()
 * - db.php: bc_get_team_candidates(), bc_create_team_container(), bc_assign_people_to_team(), bc_clear_team_bindings()
 * - admin.php: bc_admin_notice(), bc_admin_error()
 */

/* =========================================================
 * Entry renderer
 * ========================================================= */

function bc_admin_render_tab_teams($current_project_id) {

    if (
        ! current_user_can('bible_checkin_manage') &&
        ! current_user_can('bible_checkin_view')
    ) {
        echo '<p style="color:#a00;">權限不足</p>';
        return;
    }

    $project_id = (int)$current_project_id;

    // List vs Detail
    $view     = sanitize_text_field($_GET['bc_view'] ?? 'list'); // list|detail
    $team_key = sanitize_text_field($_GET['team_key'] ?? '');

    // handle POST action
    if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
        $action = sanitize_text_field($_POST['bc_action'] ?? '');
        if ( $action === 'rebuild_teams' ) {
            bc_handle_rebuild_teams();
        }
    }

    echo '<h2>小隊（teams）</h2>';
    echo '<p style="color:#666;margin-top:-6px;">此頁給同工檢查小隊與打卡狀況用；非三人小隊會標示警示，但不阻擋。</p>';

    if ( $view === 'detail' && $team_key !== '' ) {
        bc_admin_render_team_detail($project_id, $team_key);
        return;
    }

    bc_admin_render_team_list($project_id);
}

/* =========================================================
 * List view (with hierarchical filters)
 * ========================================================= */

function bc_admin_render_team_list($project_id) {
    global $wpdb;

    $project_id = (int)$project_id;
    if ($project_id <= 0) {
        echo '<p style="color:#a00;">找不到目前專案</p>';
        return;
    }

    // Filters (GET)
    $f_branch_id  = isset($_GET['f_branch_id']) ? (int)$_GET['f_branch_id'] : 0; // 0=all, -1=未指定(對應 branch_id=0)
    $f_region     = sanitize_text_field($_GET['f_region'] ?? '');
    $f_sub_region = sanitize_text_field($_GET['f_sub_region'] ?? '');
    $f_group_name = sanitize_text_field($_GET['f_group_name'] ?? '');
    $only_invalid = isset($_GET['only_invalid']) && (string)$_GET['only_invalid'] === '1';

    // Build hierarchy tree (active people only)
    $tree = bc_get_people_hierarchy_for_filters($project_id);

    // Branch options
    $branches = bc_get_branches($project_id, true);

    // 是否存在未指定分堂（branch_id=0 的人）
    $people_t = bc_table_people();
    $has_unspecified_branch = (int)$wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$people_t}
             WHERE project_id = %d AND is_active = 1 AND branch_id = 0",
            $project_id
        )
    ) > 0;

    // Teams overview (filtered)
    $teams = bc_admin_get_team_overview($project_id, [
        'branch_id'    => $f_branch_id,
        'region'       => $f_region,
        'sub_region'   => $f_sub_region,
        'group_name'   => $f_group_name,
        'only_invalid' => $only_invalid,
    ]);

    // Base URL helper
    $base_url = remove_query_arg(['bc_view','team_key']);

    // Filter UI
    ?>
    <div style="background:#fff;border:1px solid #e5e5e5;padding:12px;margin:12px 0;">
        <form method="get">
            <?php
            // 保留 page/tab 等必要參數（避免跳頁）
            foreach ( $_GET as $k => $v ) {
                if ( in_array($k, ['f_branch_id','f_region','f_sub_region','f_group_name','only_invalid','bc_view','team_key'], true) ) continue;
                if ( is_array($v) ) continue;
                echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
            }
            ?>

            <input type="hidden" name="bc_view" value="list">

            <table class="form-table" style="margin:0;">
                <tr>
                    <th style="width:120px;">分堂</th>
                    <td>
                        <select name="f_branch_id" id="bc_f_branch">
                            <option value="0"<?php selected($f_branch_id, 0); ?>>（全部）</option>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?php echo (int)$b['id']; ?>" <?php selected($f_branch_id, (int)$b['id']); ?>>
                                    <?php echo esc_html($b['branch_name']); ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if ($has_unspecified_branch): ?>
                                <option value="-1"<?php selected($f_branch_id, -1); ?>>（未指定分堂）</option>
                            <?php endif; ?>
                        </select>
                    </td>
                </tr>

                <tr>
                    <th>牧區</th>
                    <td>
                        <select name="f_region" id="bc_f_region">
                            <option value=""><?php echo esc_html('（全部）'); ?></option>
                        </select>
                    </td>
                </tr>

                <tr>
                    <th>小區</th>
                    <td>
                        <select name="f_sub_region" id="bc_f_sub_region">
                            <option value=""><?php echo esc_html('（全部）'); ?></option>
                        </select>
                    </td>
                </tr>

                <tr>
                    <th>小組</th>
                    <td>
                        <select name="f_group_name" id="bc_f_group_name">
                            <option value=""><?php echo esc_html('（全部）'); ?></option>
                        </select>
                    </td>
                </tr>

                <tr>
                    <th>顯示</th>
                    <td>
                        <label>
                            <input type="checkbox" name="only_invalid" value="1" <?php checked($only_invalid, true); ?>>
                            只看「非三人」小隊
                        </label>
                    </td>
                </tr>
            </table>

            <p style="margin:8px 0 0;">
                <button class="button button-primary">套用篩選</button>
                <a class="button" href="<?php echo esc_url( remove_query_arg(['f_branch_id','f_region','f_sub_region','f_group_name','only_invalid']) ); ?>">清除篩選</a>
            </p>
        </form>
    </div>

    <script>
    (function(){
        // PHP tree -> JS
        var tree = <?php echo wp_json_encode($tree); ?>;

        var selBranch = document.getElementById('bc_f_branch');
        var selRegion = document.getElementById('bc_f_region');
        var selSub    = document.getElementById('bc_f_sub_region');
        var selGroup  = document.getElementById('bc_f_group_name');

        var current = {
            branch_id: <?php echo (int)$f_branch_id; ?>,
            region: <?php echo wp_json_encode($f_region); ?>,
            sub_region: <?php echo wp_json_encode($f_sub_region); ?>,
            group_name: <?php echo wp_json_encode($f_group_name); ?>
        };

        function opt(el, value, label) {
            var o = document.createElement('option');
            o.value = value;
            o.textContent = label;
            el.appendChild(o);
        }

        function resetSelect(el, keepAllLabel) {
            while (el.firstChild) el.removeChild(el.firstChild);
            opt(el, '', keepAllLabel || '（全部）');
        }

        function getBranchNode(branchId) {
            // branchId: 0 all, -1 unspecified => bid=0
            if (branchId === 0) return null;

            var bid = branchId;
            if (bid === -1) bid = 0;

            // tree is object keyed by branch_id number as string
            var key = String(bid);
            return tree[key] || null;
        }

        function buildRegions() {
            resetSelect(selRegion, '（全部）');
            resetSelect(selSub, '（全部）');
            resetSelect(selGroup, '（全部）');

            var b = getBranchNode(parseInt(selBranch.value || '0', 10));
            if (!b) return;

            var regions = b.regions || {};
            Object.keys(regions).sort().forEach(function(r){
                opt(selRegion, r, r);
            });
        }

        function buildSubs() {
            resetSelect(selSub, '（全部）');
            resetSelect(selGroup, '（全部）');

            var b = getBranchNode(parseInt(selBranch.value || '0', 10));
            if (!b) return;

            var r = selRegion.value || '';
            if (!r) return;

            var nodeR = (b.regions || {})[r];
            if (!nodeR) return;

            var subs = nodeR.sub_regions || {};
            Object.keys(subs).sort().forEach(function(s){
                opt(selSub, s, s);
            });
        }

        function buildGroups() {
            resetSelect(selGroup, '（全部）');

            var b = getBranchNode(parseInt(selBranch.value || '0', 10));
            if (!b) return;

            var r = selRegion.value || '';
            var s = selSub.value || '';
            if (!r || !s) return;

            var nodeR = (b.regions || {})[r];
            if (!nodeR) return;

            var nodeS = (nodeR.sub_regions || {})[s];
            if (!nodeS) return;

            var groups = nodeS.groups || [];
            groups = groups.slice().sort();
            groups.forEach(function(g){
                opt(selGroup, g, g);
            });
        }

        // init build
        buildRegions();
        // restore selected region/sub/group
        if (current.region) selRegion.value = current.region;
        buildSubs();
        if (current.sub_region) selSub.value = current.sub_region;
        buildGroups();
        if (current.group_name) selGroup.value = current.group_name;

        // change handlers
        selBranch.addEventListener('change', function(){
            selRegion.value = '';
            selSub.value = '';
            selGroup.value = '';
            buildRegions();
        });

        selRegion.addEventListener('change', function(){
            selSub.value = '';
            selGroup.value = '';
            buildSubs();
        });

        selSub.addEventListener('change', function(){
            selGroup.value = '';
            buildGroups();
        });
    })();
    </script>
    <?php

    // Overview table
    ?>
    <h2>小隊總覽（檢查用）</h2>

    <?php if ($teams): ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th style="width:340px;">小隊</th>
                    <th>成員</th>
                    <th style="width:80px;">人數</th>
                    <th style="width:90px;">狀態</th>
                    <th style="width:120px;">操作</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($teams as $t): ?>
                <tr>
                    <td><?php echo esc_html( bc_admin_team_label($t) ); ?></td>
                    <td>
                        <?php
                        $names = array_column($t['members'], 'name');
                        echo esc_html(implode('、', $names));
                        ?>
                    </td>
                    <td><?php echo (int)$t['count']; ?></td>
                    <td>
                        <?php if ($t['is_valid']): ?>
                            <span style="color:#080;">✓ 三人</span>
                        <?php else: ?>
                            <span style="color:#a00;">⚠ 非三人</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $team_key = bc_admin_encode_team_key([
                            'branch_id'  => (int)$t['branch_id'],
                            'region'     => (string)$t['region'],
                            'sub_region' => (string)$t['sub_region'],
                            'group_name' => (string)$t['group_name'],
                            'team_no'    => (string)$t['team_no'],
                        ]);

                        $url = add_query_arg([
                            'bc_view'  => 'detail',
                            'team_key' => $team_key,
                        ], $base_url);

                        echo '<a class="button" href="' . esc_url($url) . '">查看打卡</a>';
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <form method="post" style="margin-top:12px;">
            <?php wp_nonce_field('bc_admin_action', 'bc_admin_nonce'); ?>
            <input type="hidden" name="bc_action" value="rebuild_teams">
            <button class="button button-primary">整理小隊（寫入系統）</button>
        </form>

    <?php else: ?>
        <p>目前沒有可用的小隊資料。</p>
    <?php endif; ?>

    <hr>
    <?php
}

/* =========================================================
 * Detail view (All months, Month blocks inside one scroll container)
 * ========================================================= */

function bc_admin_render_team_detail($project_id, $team_key) {
    global $wpdb;

    $project_id = (int)$project_id;
    if ($project_id <= 0) {
        echo '<p style="color:#a00;">Invalid project</p>';
        return;
    }

    $payload = bc_admin_decode_team_key($team_key);
    if ( ! $payload ) {
        echo '<p style="color:#a00;">小隊識別碼無效</p>';
        return;
    }

    $branch_id  = (int)($payload['branch_id'] ?? 0);
    $region     = (string)($payload['region'] ?? '');
    $sub_region = (string)($payload['sub_region'] ?? '');
    $group_name = (string)($payload['group_name'] ?? '');
    $team_no    = (string)($payload['team_no'] ?? '');

    // back url
    $back_url = remove_query_arg(['bc_view','team_key']);

    echo '<p><a class="button" href="' . esc_url($back_url) . '">← 回到小隊總覽</a></p>';

    echo '<h2>小隊打卡明細</h2>';
    echo '<p style="color:#666;margin-top:-6px;">' . esc_html(bc_admin_team_label([
        'branch_id' => $branch_id,
        'region' => $region,
        'sub_region' => $sub_region,
        'group_name' => $group_name,
        'team_no' => $team_no,
    ])) . '</p>';

    // Members
    $bid = ($branch_id === -1) ? 0 : $branch_id;
    $people_t = bc_table_people();

    $members = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, name
             FROM {$people_t}
             WHERE project_id = %d
               AND is_active = 1
               AND branch_id = %d
               AND region = %s
               AND sub_region = %s
               AND group_name = %s
               AND team_no = %s
             ORDER BY id ASC",
            $project_id,
            $bid,
            $region,
            $sub_region,
            $group_name,
            $team_no
        ),
        ARRAY_A
    );

    if ( ! $members ) {
        echo '<p style="color:#a00;">找不到此小隊成員（可能資料被改動）</p>';
        return;
    }

    // Months (only those with plan days)
    $months = bc_get_plan_months($project_id);
    if ( ! $months ) {
        echo '<p style="color:#a00;">目前沒有讀經月份資料（尚未匯入 plan_days）</p>';
        return;
    }

    $months_days = [];
    $all_ymds = [];

    foreach ($months as $m) {
        $mk = (string)($m['month_key'] ?? '');
        if ( ! preg_match('/^\d{4}\-\d{2}$/', $mk) ) continue;

        $days = bc_get_plan_days_by_month_key($project_id, $mk);
        if ( ! $days ) continue; // 只顯示有進度的月份（plan_days 有資料）

        // normalize ymd list
        $norm_days = [];
        foreach ($days as $d) {
            $ymd = preg_replace('/\D+/', '', (string)($d['ymd'] ?? ''));
            if (strlen($ymd) !== 8) continue;

            $d['ymd_norm'] = $ymd;
            $norm_days[] = $d;
            $all_ymds[] = $ymd;
        }

        if ($norm_days) {
            $months_days[$mk] = $norm_days;
        }
    }

    if ( ! $months_days ) {
        echo '<p style="color:#a00;">找不到任何月份的計畫日程（plan_days 可能是空的）</p>';
        return;
    }

    $all_ymds = array_values(array_unique($all_ymds));
    sort($all_ymds);

    // Build checkins set (query once for all months)
    $person_ids = array_map('intval', array_column($members, 'id'));
    $checked = []; // key: personid_ymd

    if ( $person_ids && $all_ymds ) {
        $t_checkins = bc_table_checkins();

        $min_ymd = $all_ymds[0];
        $max_ymd = $all_ymds[count($all_ymds) - 1];

        $pid_placeholders = implode(',', array_fill(0, count($person_ids), '%d'));

        $sql = "
            SELECT person_id, ymd
            FROM {$t_checkins}
            WHERE project_id = %d
              AND person_id IN ({$pid_placeholders})
              AND ymd BETWEEN %s AND %s
        ";

        $params = array_merge([$project_id], $person_ids, [$min_ymd, $max_ymd]);

        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, $params),
            ARRAY_A
        );

        if ( is_array($rows) ) {
            foreach ($rows as $r) {
                $k = (int)$r['person_id'] . '_' . preg_replace('/\D+/', '', (string)$r['ymd']);
                $checked[$k] = true;
            }
        }
    }

    // UI/UX: one scroll container, month blocks inside
    $left1 = 160; // 月份/姓名
    $left2 = 110; // 進度

    echo '<h3>打卡矩陣（所有月份）</h3>';
    echo '<p style="color:#666;margin-top:-6px;">一次顯示所有有進度的月份（plan_days 有資料）。表頭與左側兩欄已凍結；未打卡顯示紅叉。</p>';

    echo '<style>
        .bc-teams-all-wrap{
            border:1px solid #e5e5e5;
            background:#fff;
            max-height:620px;
            overflow:auto;
            padding:0;
        }
        table.bc-teams-month{
            border-collapse:separate;
            border-spacing:0;
            width:max-content;
            min-width:100%;
            margin:0;
        }
        table.bc-teams-month thead th{
            position:sticky;
            top:0;
            background:#fff;
            z-index:5;
            box-shadow:0 1px 0 rgba(0,0,0,.08);
        }
        table.bc-teams-month .bc-sticky-1{
            position:sticky; left:0;
            background:#fff; z-index:6;
            box-shadow:1px 0 0 rgba(0,0,0,.08);
        }
        table.bc-teams-month .bc-sticky-2{
            position:sticky; left:' . (int)$left1 . 'px;
            background:#fff; z-index:6;
            box-shadow:1px 0 0 rgba(0,0,0,.08);
        }
        table.bc-teams-month thead th.bc-sticky-1,
        table.bc-teams-month thead th.bc-sticky-2{
            z-index:7;
        }
        .bc-ok{ color:#0a0; font-weight:700; }
        .bc-no{ color:#a00; font-weight:700; }
        .bc-month-gap{
            height:12px;
            border-top:1px solid #f0f0f0;
            background:#fafafa;
        }
        .bc-month-title{
            font-weight:700;
        }
    </style>';

    echo '<div class="bc-teams-all-wrap">';

    $first = true;
    foreach ($months_days as $month_key => $days) {

        if (!$first) {
            echo '<div class="bc-month-gap"></div>';
        }
        $first = false;

        // month label like 1月 / 2月 ...
        $mm = (int)substr($month_key, 5, 2);
        $month_label = $mm . '月';

        // build headers: 01/01
        $day_headers = [];
        foreach ($days as $d) {
            $ymd = (string)$d['ymd_norm'];
            $mmdd = substr($ymd,4,2) . '/' . substr($ymd,6,2);
            $day_headers[] = $mmdd;
        }

        // for progress
        $day_count = count($days);
        $day_ymds  = array_column($days, 'ymd_norm');

        echo '<table class="widefat striped bc-teams-month">';
        echo '<thead><tr>';

        echo '<th class="bc-sticky-1 bc-month-title" style="min-width:' . (int)$left1 . 'px;width:' . (int)$left1 . 'px;">' . esc_html($month_label) . '</th>';
        echo '<th class="bc-sticky-2" style="min-width:' . (int)$left2 . 'px;width:' . (int)$left2 . 'px;">進度</th>';

        foreach ($day_headers as $h) {
            echo '<th style="min-width:70px;text-align:center;">' . esc_html($h) . '</th>';
        }

        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($members as $m) {
            $pid = (int)$m['id'];
            $name = (string)$m['name'];

            // progress
            $got = 0;
            foreach ($day_ymds as $ymd) {
                $k = $pid . '_' . $ymd;
                if (isset($checked[$k])) $got++;
            }
            $pct = ($day_count > 0) ? round(($got / $day_count) * 100) : 0;
            $progress_text = $got . '/' . $day_count . '（' . $pct . '%）';

            echo '<tr>';
            echo '<td class="bc-sticky-1">' . esc_html($name) . '</td>';
            echo '<td class="bc-sticky-2">' . esc_html($progress_text) . '</td>';

            foreach ($day_ymds as $ymd) {
                $k = $pid . '_' . $ymd;
                $ok = isset($checked[$k]);

                echo '<td style="text-align:center;">';
                echo $ok ? '<span class="bc-ok">✓</span>' : '<span class="bc-no">✗</span>';
                echo '</td>';
            }

            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    echo '</div>'; // wrap
}

/* =========================================================
 * Data: team overview (from people, NOT from bc_teams)
 * ========================================================= */

function bc_admin_get_team_overview($project_id, $filters = []) {
    global $wpdb;

    $project_id = (int)$project_id;
    if ($project_id <= 0) return [];

    $filters = is_array($filters) ? $filters : [];

    $branch_id  = isset($filters['branch_id']) ? (int)$filters['branch_id'] : 0;
    $region     = isset($filters['region']) ? (string)$filters['region'] : '';
    $sub_region = isset($filters['sub_region']) ? (string)$filters['sub_region'] : '';
    $group_name = isset($filters['group_name']) ? (string)$filters['group_name'] : '';
    $only_invalid = ! empty($filters['only_invalid']);

    $people_t = bc_table_people();

    $where = ["project_id = %d", "is_active = 1", "team_no <> ''"];
    $params = [$project_id];

    // branch filter
    if ($branch_id !== 0) {
        $bid = ($branch_id === -1) ? 0 : $branch_id;
        $where[] = "branch_id = %d";
        $params[] = (int)$bid;
    }

    if ($region !== '') {
        $where[] = "region = %s";
        $params[] = $region;
    }
    if ($sub_region !== '') {
        $where[] = "sub_region = %s";
        $params[] = $sub_region;
    }
    if ($group_name !== '') {
        $where[] = "group_name = %s";
        $params[] = $group_name;
    }

    $where_sql = 'WHERE ' . implode(' AND ', $where);

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id AS person_id, name, branch_id, region, sub_region, group_name, team_no
             FROM {$people_t}
             {$where_sql}
             ORDER BY branch_id, region, sub_region, group_name, CAST(team_no AS UNSIGNED), team_no, id ASC",
            $params
        ),
        ARRAY_A
    );

    $teams = [];

    foreach ($rows as $p) {
        $key = implode('|', [
            (int)$p['branch_id'],
            (string)$p['region'],
            (string)$p['sub_region'],
            (string)$p['group_name'],
            (string)$p['team_no'],
        ]);

        if (!isset($teams[$key])) {
            $teams[$key] = [
                'branch_id'  => (int)$p['branch_id'],
                'region'     => (string)$p['region'],
                'sub_region' => (string)$p['sub_region'],
                'group_name' => (string)$p['group_name'],
                'team_no'    => (string)$p['team_no'],
                'members'    => [],
                'count'      => 0,
            ];
        }

        $teams[$key]['members'][] = $p;
        $teams[$key]['count']++;
    }

    $out = [];
    foreach ($teams as $t) {
        $t['is_valid'] = ((int)$t['count'] === 3);
        if ($only_invalid && $t['is_valid']) continue;
        $out[] = $t;
    }

    return $out;
}

/* =========================================================
 * Rebuild teams (write containers + people.team_id)
 * ========================================================= */

function bc_handle_rebuild_teams() {

    if ( ! current_user_can('bible_checkin_manage') ) {
        if (function_exists('bc_admin_error')) bc_admin_error('權限不足');
        return;
    }

    if ( empty($_POST['bc_admin_nonce']) || ! wp_verify_nonce($_POST['bc_admin_nonce'], 'bc_admin_action') ) {
        if (function_exists('bc_admin_error')) bc_admin_error('nonce 驗證失敗');
        return;
    }

    $project_id = bc_get_current_project_id();
    if ($project_id <= 0) {
        if (function_exists('bc_admin_error')) bc_admin_error('找不到目前專案');
        return;
    }

    global $wpdb;

    $wpdb->delete(
        bc_table_teams(),
        [ 'project_id' => (int)$project_id ],
        [ '%d' ]
    );

    bc_clear_team_bindings($project_id);

    $teams = bc_get_team_candidates($project_id);

    $built = 0;
    foreach ($teams as $t) {
        $team_id = bc_create_team_container($t['team_no'], $project_id);
        if ($team_id <= 0) continue;

        $person_ids = array_column($t['members'], 'person_id');
        if ($person_ids) {
            bc_assign_people_to_team($team_id, $person_ids, $project_id);
        }

        $built++;
    }

    if (function_exists('bc_admin_notice')) {
        bc_admin_notice("小隊已整理完成（共 {$built} 隊）");
    }
}

/* =========================================================
 * Helpers
 * ========================================================= */

function bc_admin_team_label($t) {

    $branch_id = isset($t['branch_id']) ? (int)$t['branch_id'] : 0;
    $region = trim((string)($t['region'] ?? ''));
    $sub    = trim((string)($t['sub_region'] ?? ''));
    $group  = trim((string)($t['group_name'] ?? ''));
    $no     = trim((string)($t['team_no'] ?? ''));

    $branch_label = '（未指定分堂）';
	if ($branch_id > 0) {

		$pid = isset($project_id) ? (int)$project_id : (int) bc_get_current_project_id();

		$branches = bc_get_branches($pid, true);
		foreach ($branches as $b) {
			if ((int)$b['id'] === $branch_id) {
				$branch_label = $b['branch_name'];
				break;
			}
		}
	}

    $parts = [];
    $parts[] = $branch_label;
    if ($region !== '') $parts[] = $region;
    if ($sub !== '')    $parts[] = $sub;
    if ($group !== '')  $parts[] = $group;
    $parts[] = ($no === '') ? '（未指定小隊）' : ('第 ' . $no . ' 小隊');

    return implode(' / ', $parts);
}

function bc_admin_encode_team_key($payload) {
    $json = wp_json_encode($payload);
    $b64  = base64_encode($json);
    $b64 = rtrim(strtr($b64, '+/', '-_'), '=');
    return $b64;
}

function bc_admin_decode_team_key($key) {
    $key = (string)$key;
    if ($key === '') return null;

    $b64 = strtr($key, '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad) $b64 .= str_repeat('=', 4 - $pad);

    $json = base64_decode($b64, true);
    if ($json === false) return null;

    $data = json_decode($json, true);
    if (!is_array($data)) return null;

    return $data;
}
