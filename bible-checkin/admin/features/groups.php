<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Tab: groups
 * - 小組總覽（唯讀）
 * - 第一層：只顯示 小組清單 + 人數 + 入口（不顯示人名/每日狀態）
 * - 第二層：必須先選月份 → 顯示該小組單月「人 × 日」打卡表（唯讀）
 *
 * 依賴（你現有已存在）：
 * - db.php:
 *   - bc_table_people(), bc_table_checkins()
 *   - bc_get_branches($project_id, $include_all)
 *   - bc_get_people_hierarchy_for_filters($project_id)
 *   - bc_get_plan_months($project_id)
 *   - bc_get_plan_days_by_month_key($project_id, $month_key)
 */

/* =========================================================
 * Entry renderer
 * ========================================================= */

function bc_admin_render_tab_groups($current_project_id) {

    if (
        ! current_user_can('bible_checkin_manage') &&
        ! current_user_can('bible_checkin_view')
    ) {
        echo '<p style="color:#a00;">權限不足</p>';
        return;
    }

    $project_id = (int)$current_project_id;
    if ($project_id <= 0) {
        echo '<p style="color:#a00;">找不到目前專案</p>';
        return;
    }

    // list|detail
    $view      = sanitize_text_field($_GET['bc_view'] ?? 'list');
    $group_key = sanitize_text_field($_GET['group_key'] ?? '');
    $month_key = sanitize_text_field($_GET['month_key'] ?? '');

    echo '<h2>小組總覽（唯讀）</h2>';
    echo '<p style="color:#666;margin-top:-6px;">此頁只供同工「查看」小組打卡狀況：第一層只看人數與入口；第二層需先選月份才顯示打卡大表格（不可修改）。</p>';

    if ( $view === 'detail' && $group_key !== '' ) {
        bc_admin_groups_render_detail($project_id, $group_key, $month_key);
        return;
    }

    bc_admin_groups_render_list($project_id);
}

/* =========================================================
 * List view (filters + overview table)
 * ========================================================= */

function bc_admin_groups_render_list($project_id) {
    global $wpdb;

    $project_id = (int)$project_id;

    // Filters (GET): 只做同工友善的「分堂 → 牧區 → 小區」
    $f_branch_id  = isset($_GET['f_branch_id']) ? (int)$_GET['f_branch_id'] : 0; // 0=all, -1=未指定(=>branch_id=0)
    $f_region     = sanitize_text_field($_GET['f_region'] ?? '');
    $f_sub_region = sanitize_text_field($_GET['f_sub_region'] ?? '');

    // build hierarchy tree (active people only)
    $tree = bc_get_people_hierarchy_for_filters($project_id);

    // branches
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

    // overview data
    $groups = bc_admin_groups_get_overview($project_id, [
        'branch_id'  => $f_branch_id,
        'region'     => $f_region,
        'sub_region' => $f_sub_region,
    ]);

    // base url helper
    $base_url = remove_query_arg(['bc_view','group_key','month_key']);

    // Filter UI (跟 teams.php 同款式)
    ?>
    <div style="background:#fff;border:1px solid #e5e5e5;padding:12px;margin:12px 0;">
        <form method="get">
            <?php
            // 保留 page/tab 等必要參數（避免跳頁）
            foreach ( $_GET as $k => $v ) {
                if ( in_array($k, ['f_branch_id','f_region','f_sub_region','bc_view','group_key','month_key'], true) ) continue;
                if ( is_array($v) ) continue;
                echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
            }
            ?>

            <input type="hidden" name="bc_view" value="list">

            <table class="form-table" style="margin:0;">
                <tr>
                    <th style="width:120px;">分堂</th>
                    <td>
                        <select name="f_branch_id" id="bc_g_f_branch">
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
                        <select name="f_region" id="bc_g_f_region">
                            <option value=""><?php echo esc_html('（全部）'); ?></option>
                        </select>
                    </td>
                </tr>

                <tr>
                    <th>小區</th>
                    <td>
                        <select name="f_sub_region" id="bc_g_f_sub_region">
                            <option value=""><?php echo esc_html('（全部）'); ?></option>
                        </select>
                    </td>
                </tr>
            </table>

            <p style="margin:8px 0 0;">
                <button class="button button-primary">套用篩選</button>
                <a class="button" href="<?php echo esc_url( remove_query_arg(['f_branch_id','f_region','f_sub_region']) ); ?>">清除篩選</a>
            </p>
        </form>
    </div>

    <script>
    (function(){
        var tree = <?php echo wp_json_encode($tree); ?>;

        var selBranch = document.getElementById('bc_g_f_branch');
        var selRegion = document.getElementById('bc_g_f_region');
        var selSub    = document.getElementById('bc_g_f_sub_region');

        var current = {
            branch_id: <?php echo (int)$f_branch_id; ?>,
            region: <?php echo wp_json_encode($f_region); ?>,
            sub_region: <?php echo wp_json_encode($f_sub_region); ?>
        };

        function opt(el, value, label) {
            var o = document.createElement('option');
            o.value = value;
            o.textContent = label;
            el.appendChild(o);
        }

        function resetSelect(el, allLabel) {
            while (el.firstChild) el.removeChild(el.firstChild);
            opt(el, '', allLabel || '（全部）');
        }

        function getBranchNode(branchId) {
            if (branchId === 0) return null;
            var bid = branchId;
            if (bid === -1) bid = 0;
            var key = String(bid);
            return tree[key] || null;
        }

        function buildRegions() {
            resetSelect(selRegion, '（全部）');
            resetSelect(selSub, '（全部）');

            var b = getBranchNode(parseInt(selBranch.value || '0', 10));
            if (!b) return;

            var regions = b.regions || {};
            Object.keys(regions).sort().forEach(function(r){
                opt(selRegion, r, r);
            });
        }

        function buildSubs() {
            resetSelect(selSub, '（全部）');

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

        // init
        buildRegions();
        if (current.region) selRegion.value = current.region;
        buildSubs();
        if (current.sub_region) selSub.value = current.sub_region;

        selBranch.addEventListener('change', function(){
            selRegion.value = '';
            selSub.value = '';
            buildRegions();
        });

        selRegion.addEventListener('change', function(){
            selSub.value = '';
            buildSubs();
        });
    })();
    </script>
    <?php

    // Overview table (第一層：只看人數 + 入口)
    echo '<h2>小組清單（只顯示人數）</h2>';

    if ( ! $groups ) {
        echo '<p>目前沒有可用的小組資料。</p>';
        return;
    }

    ?>
    <table class="widefat striped">
        <thead>
            <tr>
                <th style="width:420px;">小組</th>
                <th style="width:90px;">人數</th>
                <th style="width:140px;">操作</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($groups as $g): ?>
            <tr>
                <td><?php echo esc_html( bc_admin_groups_label($project_id, $g) ); ?></td>
                <td><?php echo (int)$g['count']; ?></td>
                <td>
                    <?php
                    $group_key = bc_admin_groups_encode_key([
                        'branch_id'  => (int)$g['branch_id'],
                        'region'     => (string)$g['region'],
                        'sub_region' => (string)$g['sub_region'],
                        'group_name' => (string)$g['group_name'],
                    ]);

                    $url = add_query_arg([
                        'bc_view'   => 'detail',
                        'group_key' => $group_key,
                    ], $base_url);

                    echo '<a class="button" href="' . esc_url($url) . '">查看打卡</a>';
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

/**
 * Overview: group by (branch_id, region, sub_region, group_name), count people.
 */
function bc_admin_groups_get_overview($project_id, $filters = []) {
    global $wpdb;

    $project_id = (int)$project_id;
    $filters = is_array($filters) ? $filters : [];

    $branch_id  = isset($filters['branch_id']) ? (int)$filters['branch_id'] : 0;
    $region     = isset($filters['region']) ? (string)$filters['region'] : '';
    $sub_region = isset($filters['sub_region']) ? (string)$filters['sub_region'] : '';

    $people_t = bc_table_people();

    $where  = ["project_id = %d", "is_active = 1", "group_name <> ''"];
    $params = [$project_id];

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

    $where_sql = 'WHERE ' . implode(' AND ', $where);

    $sql = "
        SELECT branch_id, region, sub_region, group_name, COUNT(*) AS cnt
        FROM {$people_t}
        {$where_sql}
        GROUP BY branch_id, region, sub_region, group_name
        ORDER BY branch_id, region, sub_region, group_name
    ";

    $rows = $wpdb->get_results(
        $wpdb->prepare($sql, $params),
        ARRAY_A
    );

    $out = [];
    foreach ((array)$rows as $r) {
        $out[] = [
            'branch_id'  => (int)$r['branch_id'],
            'region'     => (string)$r['region'],
            'sub_region' => (string)$r['sub_region'],
            'group_name' => (string)$r['group_name'],
            'count'      => (int)$r['cnt'],
        ];
    }
    return $out;
}

/* =========================================================
 * Detail view (Month -> big matrix)
 * ========================================================= */

function bc_admin_groups_render_detail($project_id, $group_key, $month_key) {
    global $wpdb;

    $project_id = (int)$project_id;

    $payload = bc_admin_groups_decode_key($group_key);
    if ( ! $payload ) {
        echo '<p style="color:#a00;">小組識別碼無效</p>';
        return;
    }

    $branch_id  = (int)($payload['branch_id'] ?? 0);
    $region     = (string)($payload['region'] ?? '');
    $sub_region = (string)($payload['sub_region'] ?? '');
    $group_name = (string)($payload['group_name'] ?? '');

    $back_url = remove_query_arg(['bc_view','group_key','month_key']);

    echo '<p><a class="button" href="' . esc_url($back_url) . '">← 回到小組總覽</a></p>';

    echo '<h2>小組打卡明細（唯讀）</h2>';
    echo '<p style="color:#666;margin-top:-6px;">' . esc_html( bc_admin_groups_label($project_id, [
        'branch_id' => $branch_id,
        'region' => $region,
        'sub_region' => $sub_region,
        'group_name' => $group_name,
    ]) ) . '</p>';

    // Months dropdown
    $months = bc_get_plan_months($project_id);
    if ( ! $months ) {
        echo '<p style="color:#a00;">目前沒有讀經月份資料（尚未匯入 plan_days）</p>';
        return;
    }

    if ( ! preg_match('/^\d{4}\-\d{2}$/', (string)$month_key) ) {
        $month_key = (string)($months[0]['month_key'] ?? '');
    }

    // month select form (GET)
    ?>
    <form method="get" style="background:#fff;border:1px solid #e5e5e5;padding:12px;margin:12px 0;">
        <?php
        foreach ( $_GET as $k => $v ) {
            if ( $k === 'month_key' ) continue;
            if ( is_array($v) ) continue;
            echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
        }
        ?>
        <label>月份：</label>
        <select name="month_key">
            <?php foreach ($months as $m):
                $mk = (string)($m['month_key'] ?? '');
                if ( ! preg_match('/^\d{4}\-\d{2}$/', $mk) ) continue;
            ?>
                <option value="<?php echo esc_attr($mk); ?>" <?php selected($month_key, $mk); ?>>
                    <?php echo esc_html($mk . '（' . (int)($m['day_count'] ?? 0) . ' 天）'); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="button button-primary">切換</button>
    </form>
    <?php

    // Days in month
    $days = bc_get_plan_days_by_month_key($project_id, $month_key);
    if ( ! $days ) {
        echo '<p style="color:#a00;">本月沒有計畫日程（plan_days 為空）</p>';
        return;
    }

    // People list (this group only)
    $bid = ($branch_id === -1) ? 0 : $branch_id;
    $people_t = bc_table_people();

    $members = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, name, team_no
             FROM {$people_t}
             WHERE project_id = %d
               AND is_active = 1
               AND branch_id = %d
               AND region = %s
               AND sub_region = %s
               AND group_name = %s
             ORDER BY CAST(team_no AS UNSIGNED), team_no, id ASC",
            $project_id,
            $bid,
            $region,
            $sub_region,
            $group_name
        ),
        ARRAY_A
    );

    if ( ! $members ) {
        echo '<p style="color:#a00;">找不到此小組成員（可能資料被改動或尚未分組）</p>';
        return;
    }

    // SSOT 性能硬規則：單頁最大 500 人
    if ( count($members) > 500 ) {
        echo '<div class="notice notice-error"><p>此小組人數超過 500（目前：' . (int)count($members) . '）。依 SSOT 規則，單頁不允許載入超過 500×31 的矩陣，請先分拆小組或縮小範圍。</p></div>';
        return;
    }

    // Build ymds
    $person_ids = array_map('intval', array_column($members, 'id'));
    $ymds = [];
    foreach ($days as $d) {
        $ymd = preg_replace('/\D+/', '', (string)($d['ymd'] ?? ''));
        if ( strlen($ymd) === 8 ) $ymds[] = $ymd;
    }
    $ymds = array_values(array_unique($ymds));
    if ( ! $ymds ) {
        echo '<p style="color:#a00;">本月日期資料異常（ymd 無效）</p>';
        return;
    }

    // Query checkins once (IN + BETWEEN)
    $checked = []; // key: personid_ymd => true
    if ( $person_ids ) {
        $t_checkins = bc_table_checkins();

        $min_ymd = min($ymds);
        $max_ymd = max($ymds);

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

    // Render big matrix (人 × 日)
    echo '<h3>打卡矩陣（' . esc_html($month_key) . '）</h3>';
    echo '<p style="color:#666;margin-top:-6px;">此表為唯讀：不可點擊、不可修改；切換月份會重新載入。表頭與左側兩欄已凍結，捲動時仍可看見。</p>';

    // ===== UI/UX v2: 外層固定高度 + 凍結表頭 + 凍結左兩欄 =====
    // 欄寬：左1(小隊) 80px, 左2(人員) 160px
    $left1 = 80;
    $left2 = 160;

    echo '<style>
        #bc-groups-matrix-wrap{
            border:1px solid #e5e5e5;
            background:#fff;
            max-height:560px;  /* 約 15 筆高度 + 表頭 */
            overflow:auto;     /* 上下左右捲軸都在這一層 */
        }
        #bc-groups-matrix{
            border-collapse:separate;
            border-spacing:0;
            width:max-content; /* 讓欄數多時自然變寬，靠外層 scroll */
            min-width:100%;
        }
        #bc-groups-matrix thead th{
            position:sticky;
            top:0;
            background:#fff;
            z-index:5;
            box-shadow:0 1px 0 rgba(0,0,0,.08);
        }
        /* 凍結左兩欄（th + td 都要） */
        #bc-groups-matrix .bc-sticky-1{
            position:sticky;
            left:0;
            background:#fff;
            z-index:6;
            box-shadow:1px 0 0 rgba(0,0,0,.08);
        }
        #bc-groups-matrix .bc-sticky-2{
            position:sticky;
            left:' . (int)$left1 . 'px;
            background:#fff;
            z-index:6;
            box-shadow:1px 0 0 rgba(0,0,0,.08);
        }
        /* 左上角交疊：同時 sticky top + left 的 th 要更高 */
        #bc-groups-matrix thead th.bc-sticky-1,
        #bc-groups-matrix thead th.bc-sticky-2{
            z-index:7;
        }
        /* 打卡符號 */
        #bc-groups-matrix .bc-ok{ color:#0a0; font-weight:700; }
        #bc-groups-matrix .bc-no{ color:#a00; font-weight:700; }
    </style>';

    echo '<div id="bc-groups-matrix-wrap">';

    echo '<table id="bc-groups-matrix" class="widefat striped">';
    echo '<thead><tr>';
    echo '<th class="bc-sticky-1" style="min-width:' . (int)$left1 . 'px;width:' . (int)$left1 . 'px;text-align:center;">小隊</th>';
    echo '<th class="bc-sticky-2" style="min-width:' . (int)$left2 . 'px;width:' . (int)$left2 . 'px;">人員</th>';

    foreach ($ymds as $ymd) {
        $mmdd = substr($ymd,4,2) . '/' . substr($ymd,6,2);
        echo '<th style="min-width:54px;width:54px;text-align:center;">' . esc_html($mmdd) . '</th>';
    }
    echo '</tr></thead>';

    echo '<tbody>';
    foreach ($members as $m) {
        $pid = (int)$m['id'];
        $name = (string)$m['name'];
        $team_no = trim((string)($m['team_no'] ?? ''));

        echo '<tr>';
        echo '<td class="bc-sticky-1" style="text-align:center;">' . esc_html($team_no === '' ? '—' : $team_no) . '</td>';
        echo '<td class="bc-sticky-2">' . esc_html($name) . '</td>';

        foreach ($ymds as $ymd) {
            $k = $pid . '_' . $ymd;
            $ok = isset($checked[$k]);

            echo '<td style="text-align:center;">';
            if ($ok) {
                echo '<span class="bc-ok">✓</span>';
            } else {
                // ✅ UX v2：沒打卡用紅叉叉
                echo '<span class="bc-no">✗</span>';
            }
            echo '</td>';
        }

        echo '</tr>';
    }
    echo '</tbody></table>';

    echo '</div>'; // wrap
}

/* =========================================================
 * Helpers
 * ========================================================= */

function bc_admin_groups_label($project_id, $g) {
    $project_id = (int)$project_id;

    $branch_id  = isset($g['branch_id']) ? (int)$g['branch_id'] : 0;
    $region     = trim((string)($g['region'] ?? ''));
    $sub        = trim((string)($g['sub_region'] ?? ''));
    $group      = trim((string)($g['group_name'] ?? ''));

    $branch_label = '（未指定分堂）';
    if ($branch_id > 0) {
        $branches = bc_get_branches($project_id, true);
        foreach ((array)$branches as $b) {
            if ((int)$b['id'] === $branch_id) {
                $branch_label = (string)$b['branch_name'];
                break;
            }
        }
    }

    $parts = [];
    $parts[] = $branch_label;
    if ($region !== '') $parts[] = $region;
    if ($sub !== '')    $parts[] = $sub;
    $parts[] = ($group !== '') ? $group : '（未指定小組）';

    return implode(' / ', $parts);
}

function bc_admin_groups_encode_key($payload) {
    $json = wp_json_encode($payload);
    $b64  = base64_encode($json);
    $b64  = rtrim(strtr($b64, '+/', '-_'), '=');
    return $b64;
}

function bc_admin_groups_decode_key($key) {
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
