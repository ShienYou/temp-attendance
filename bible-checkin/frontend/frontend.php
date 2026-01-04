<?php
/**
 * Bible Check-in System
 * Frontend UI — SSOT Flow (Branch → Region → Sub-region → Group → Team(with members) → Month → Matrix)
 *
 * 核心原則（不可踩雷）：
 * - 前台不使用 bc_teams（container，不是語意資料）
 * - 小隊一律從 bc_people 即時計算（team_no + group_name）
 * - 打卡核心不改：checkbox class/data + JS AJAX（bc_toggle_checkin）
 *
 * ✅ 多專案前台（定案）：
 * - shortcode 參數 project：
 *   [bible_checkin project="X"]
 * - 僅做前台局部 override，不污染 bc_current_project_id
 * - 將 project_id 放進 #bc-app 的 data-project-id（給 JS/AJAX 用）
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

add_shortcode('bible_checkin', function ( $atts = [] ) {

    global $wpdb;

    // ─────────────────────────────────
    // 0. 專案（SSOT + Shortcode Override）
    // ─────────────────────────────────
    $atts = shortcode_atts([
        'project' => 0,
    ], $atts);

    $project_id = (int)$atts['project'] > 0 ? (int)$atts['project'] : bc_get_current_project_id();
    $project    = bc_get_project($project_id);

    if ( ! $project ) {
        return '<div class="bc-app"><p class="bc-empty">目前尚未設定任何讀經專案。</p></div>';
    }

    // ─────────────────────────────────
    // 1. URL State（按 SSOT 流程）
    // ─────────────────────────────────
    $branch_id  = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;   // -1 表示（未指定）
    $region     = sanitize_text_field($_GET['region'] ?? '');
    $sub_region = sanitize_text_field($_GET['sub_region'] ?? '');
    $group_name = sanitize_text_field($_GET['group_name'] ?? '');
    $team_no    = sanitize_text_field($_GET['team_no'] ?? '');
    $month_key  = sanitize_text_field($_GET['month_key'] ?? '');

    if ( ! preg_match('/^\d{4}\-\d{2}$/', $month_key) ) {
        $month_key = '';
    }

	// ─────────────────────────────────
	// 2. URL helpers（老人友善：回上一步一定要準）
	// ─────────────────────────────────

	// 重新開始：清掉全部狀態
	$url_reset_all = function () {
		return remove_query_arg(['branch_id','region','sub_region','group_name','team_no','month_key']);
	};

	// set + unset：先 remove 再 add（⚠️ 不用 null，WP 不會幫你刪）
	$url_with = function (array $set = [], array $unset = []) {
		$url = remove_query_arg($unset);
		return add_query_arg($set, $url);
	};

	// 回到某個「步驟」的固定狀態（保留前面、清掉後面）
	$url_step = function (string $step) use ($url_with, $branch_id, $region, $sub_region, $group_name, $team_no) {

		switch ($step) {
			case 'branch': // 回到分堂選單
				return remove_query_arg(['branch_id','region','sub_region','group_name','team_no','month_key']);

			case 'region': // 回到牧區選單（保留分堂）
				return $url_with(
					['branch_id' => $branch_id],
					['region','sub_region','group_name','team_no','month_key']
				);

			case 'sub': // 回到小區選單（保留分堂+牧區）
				return $url_with(
					['branch_id' => $branch_id, 'region' => $region],
					['sub_region','group_name','team_no','month_key']
				);

			case 'group': // 回到小組選單（保留分堂+牧區+小區）
				return $url_with(
					['branch_id' => $branch_id, 'region' => $region, 'sub_region' => $sub_region],
					['group_name','team_no','month_key']
				);

			case 'team': // 回到小隊選單（保留分堂+牧區+小區+小組）
				return $url_with(
					['branch_id' => $branch_id, 'region' => $region, 'sub_region' => $sub_region, 'group_name' => $group_name],
					['team_no','month_key']
				);

			case 'month': // 回到月份選單（保留分堂+牧區+小區+小組+小隊）
				return $url_with(
					['branch_id' => $branch_id, 'region' => $region, 'sub_region' => $sub_region, 'group_name' => $group_name, 'team_no' => $team_no],
					['month_key']
				);
		}

		// fallback
		return $url_reset_all();
	};

    // ─────────────────────────────────
    // 3. 麵包屑顯示文字（老人友善：看得懂自己選到哪）
    // ─────────────────────────────────
    $branch_label = '';
    if ($branch_id !== 0) {
        if ($branch_id === -1) {
            $branch_label = '（未指定分堂）';
        } else {
            $branches = bc_get_branches($project_id, true);
            foreach ($branches as $b) {
                if ((int)$b['id'] === $branch_id) {
                    $branch_label = (string)$b['branch_name'];
                    break;
                }
            }
        }
    }

    $nonce = wp_create_nonce('bc_checkin_nonce');

    ob_start();
    ?>
    <div id="bc-app"
         class="bc-app"
         data-nonce="<?php echo esc_attr($nonce); ?>"
         data-project-id="<?php echo (int)$project_id; ?>">

        <div class="bc-header">
            <h2 class="bc-title">讀經打卡</h2>

            <div class="bc-subtitle">
                <?php echo esc_html($project['church_name'] . '｜' . $project['project_name'] . '（' . $project['year'] . '）'); ?>
            </div>

            <div class="bc-toolbar">
                <a class="bc-btn bc-btn-ghost" href="<?php echo esc_url($url_reset_all()); ?>">重新開始</a>
                <span class="bc-hint">（點錯沒關係，重新開始就好）</span>
            </div>

            <div class="bc-breadcrumb">
                <span class="bc-crumb <?php echo $branch_id ? 'is-done' : 'is-now'; ?>">1 分堂</span>
                <span class="bc-sep">›</span>
                <span class="bc-crumb <?php echo ($branch_id && $region) ? 'is-done' : (($branch_id && !$region) ? 'is-now' : ''); ?>">2 牧區</span>
                <span class="bc-sep">›</span>
                <span class="bc-crumb <?php echo ($region && $sub_region) ? 'is-done' : (($region && !$sub_region) ? 'is-now' : ''); ?>">3 小區</span>
                <span class="bc-sep">›</span>
                <span class="bc-crumb <?php echo ($sub_region && $group_name) ? 'is-done' : (($sub_region && !$group_name) ? 'is-now' : ''); ?>">4 小組</span>
                <span class="bc-sep">›</span>
                <span class="bc-crumb <?php echo ($group_name && $team_no) ? 'is-done' : (($group_name && !$team_no) ? 'is-now' : ''); ?>">5 小隊</span>
                <span class="bc-sep">›</span>
                <span class="bc-crumb <?php echo ($team_no && $month_key) ? 'is-done' : (($team_no && !$month_key) ? 'is-now' : ''); ?>">6 月份</span>
                <span class="bc-sep">›</span>
                <span class="bc-crumb <?php echo $month_key ? 'is-now' : ''; ?>">7 打卡</span>
            </div>

            <div class="bc-chosen">
                <div class="bc-chosen-line">
                    <span class="bc-chosen-k">分堂：</span>
                    <span class="bc-chosen-v"><?php echo $branch_id ? esc_html($branch_label ?: '（找不到分堂）') : '（尚未選）'; ?></span>
                </div>
                <div class="bc-chosen-line">
                    <span class="bc-chosen-k">牧區：</span>
                    <span class="bc-chosen-v"><?php echo $region ? esc_html($region) : '（尚未選）'; ?></span>
                </div>
                <div class="bc-chosen-line">
                    <span class="bc-chosen-k">小區：</span>
                    <span class="bc-chosen-v"><?php echo $sub_region ? esc_html($sub_region) : '（尚未選）'; ?></span>
                </div>
                <div class="bc-chosen-line">
                    <span class="bc-chosen-k">小組：</span>
                    <span class="bc-chosen-v"><?php echo $group_name ? esc_html($group_name) : '（尚未選）'; ?></span>
                </div>
                <div class="bc-chosen-line">
                    <span class="bc-chosen-k">小隊：</span>
                    <span class="bc-chosen-v"><?php echo $team_no ? esc_html('第 ' . $team_no . ' 小隊') : '（尚未選）'; ?></span>
                </div>
                <div class="bc-chosen-line">
                    <span class="bc-chosen-k">月份：</span>
                    <span class="bc-chosen-v"><?php echo $month_key ? esc_html(substr($month_key,5,2) . ' 月') : '（尚未選）'; ?></span>
                </div>
            </div>
        </div>

        <?php
        // ─────────────────────────────────
        // Step 1：選分堂（branch）
        // ─────────────────────────────────
        if ( ! $branch_id ) {

            $branches = bc_get_branches($project_id, true);

            // 額外：若存在 branch_id = 0 的人員，提供「未指定分堂」選項（用 -1）
            $people_t = bc_table_people();
            $has_unspecified_branch = (int)$wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$people_t}
                     WHERE project_id = %d AND is_active = 1 AND branch_id = 0",
                    $project_id
                )
            ) > 0;

            echo '<div class="bc-step">';
            echo '<h3 class="bc-step-title">請先選擇母堂或分堂</h3>';
            echo '<div class="bc-list">';

            if ( $branches ) {
                foreach ( $branches as $b ) {
                    $url = $url_with([
                        'branch_id' => (int)$b['id'],
                    ], ['region','sub_region','group_name','team_no','month_key']);

                    echo '<a class="bc-list-item bc-branch-item" href="' . esc_url($url) . '">';
                    echo '<div class="bc-item-main"><div class="bc-item-title">' . esc_html($b['branch_name']) . '</div></div>';
                    echo '<div class="bc-item-right">點我進入</div>';
                    echo '</a>';
                }
            }

            if ( $has_unspecified_branch ) {
                $url = $url_with([
                    'branch_id' => -1,
                ], ['region','sub_region','group_name','team_no','month_key']);

                echo '<a class="bc-list-item bc-branch-item is-muted" href="' . esc_url($url) . '">';
                echo '<div class="bc-item-main"><div class="bc-item-title">（未指定分堂）</div><div class="bc-item-sub">名單裡分堂沒填的人</div></div>';
                echo '<div class="bc-item-right">點我進入</div>';
                echo '</a>';
            }

            if ( ! $branches && ! $has_unspecified_branch ) {
                echo '<p class="bc-empty">目前尚未建立任何分堂或人員資料。</p>';
            }

            echo '</div></div>';

        // ─────────────────────────────────
        // Step 2：選牧區（region）
        // ─────────────────────────────────
        } elseif ( ! $region ) {

            $bid = ($branch_id === -1) ? 0 : $branch_id;

            $regions = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DISTINCT region
                     FROM " . bc_table_people() . "
                     WHERE project_id = %d
                       AND is_active = 1
                       AND branch_id = %d
                       AND region <> ''
                     ORDER BY region ASC",
                    $project_id,
                    $bid
                ),
                ARRAY_A
            );

            echo '<div class="bc-step">';
            echo '<div class="bc-step-top">';
            echo '<a class="bc-btn bc-btn-ghost" href="' . esc_url($url_step('branch')) . '">← 回到分堂</a>';
            echo '</div>';
            echo '<h3 class="bc-step-title">選擇「牧區」</h3>';

            if ( ! $regions ) {
                echo '<p class="bc-empty">這個分堂目前沒有可用的牧區資料。</p>';
            } else {
                echo '<div class="bc-list">';
                foreach ( $regions as $r ) {
                    $rname = (string)($r['region'] ?? '');
                    if ($rname === '') continue;

                    $url = $url_with([
                        'branch_id'  => $branch_id,
                        'region'     => $rname,
                    ], ['sub_region','group_name','team_no','month_key']);

                    echo '<a class="bc-list-item" href="' . esc_url($url) . '">';
                    echo '<div class="bc-item-main"><div class="bc-item-title">' . esc_html($rname) . '</div></div>';
                    echo '<div class="bc-item-right">點我進入</div>';
                    echo '</a>';
                }
                echo '</div>';
            }

            echo '</div>';

        // ─────────────────────────────────
        // Step 3：選小區（sub_region）
        // ─────────────────────────────────
        } elseif ( ! $sub_region ) {

            $bid = ($branch_id === -1) ? 0 : $branch_id;

            $subs = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DISTINCT sub_region
                     FROM " . bc_table_people() . "
                     WHERE project_id = %d
                       AND is_active = 1
                       AND branch_id = %d
                       AND region = %s
                       AND sub_region <> ''
                     ORDER BY sub_region ASC",
                    $project_id,
                    $bid,
                    $region
                ),
                ARRAY_A
            );

            echo '<div class="bc-step">';
            echo '<div class="bc-step-top">';
            echo '<a class="bc-btn bc-btn-ghost" href="' . esc_url($url_step('region')) . '">← 回到牧區</a>';
            echo '</div>';
            echo '<h3 class="bc-step-title">選擇「小區」</h3>';

            if ( ! $subs ) {
                echo '<p class="bc-empty">這個牧區目前沒有可用的小區資料。</p>';
            } else {
                echo '<div class="bc-list">';
                foreach ( $subs as $s ) {
                    $sname = (string)($s['sub_region'] ?? '');
                    if ($sname === '') continue;

                    $url = $url_with([
                        'branch_id'  => $branch_id,
                        'region'     => $region,
                        'sub_region' => $sname,
                    ], ['group_name','team_no','month_key']);

                    echo '<a class="bc-list-item" href="' . esc_url($url) . '">';
                    echo '<div class="bc-item-main"><div class="bc-item-title">' . esc_html($sname) . '</div></div>';
                    echo '<div class="bc-item-right">點我進入</div>';
                    echo '</a>';
                }
                echo '</div>';
            }

            echo '</div>';

        // ─────────────────────────────────
        // Step 4：選小組（group_name）
        // ─────────────────────────────────
        } elseif ( ! $group_name ) {

            $bid = ($branch_id === -1) ? 0 : $branch_id;

            $groups = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DISTINCT group_name
                     FROM " . bc_table_people() . "
                     WHERE project_id = %d
                       AND is_active = 1
                       AND branch_id = %d
                       AND region = %s
                       AND sub_region = %s
                       AND group_name <> ''
                     ORDER BY group_name ASC",
                    $project_id,
                    $bid,
                    $region,
                    $sub_region
                ),
                ARRAY_A
            );

            echo '<div class="bc-step">';
            echo '<div class="bc-step-top">';
            echo '<a class="bc-btn bc-btn-ghost" href="' . esc_url($url_step('sub')) . '">← 回到小區</a>';
            echo '</div>';
            echo '<h3 class="bc-step-title">選擇「小組」</h3>';

            if ( ! $groups ) {
                echo '<p class="bc-empty">這個小區目前沒有可用的小組資料。</p>';
            } else {
                echo '<div class="bc-list">';
                foreach ( $groups as $g ) {
                    $gname = (string)($g['group_name'] ?? '');
                    if ($gname === '') continue;

                    $url = $url_with([
                        'branch_id'  => $branch_id,
                        'region'     => $region,
                        'sub_region' => $sub_region,
                        'group_name' => $gname,
                    ], ['team_no','month_key']);

                    echo '<a class="bc-list-item bc-group-item" href="' . esc_url($url) . '">';
                    echo '<div class="bc-item-main"><div class="bc-item-title">' . esc_html($gname) . '</div></div>';
                    echo '<div class="bc-item-right">點我進入</div>';
                    echo '</a>';
                }
                echo '</div>';
            }

            echo '</div>';

        // ─────────────────────────────────
        // Step 5：選小隊（team_no）＋顯示隊員名字（老人友善）
        // ─────────────────────────────────
        } elseif ( ! $team_no ) {

            $bid = ($branch_id === -1) ? 0 : $branch_id;
            $people_t = bc_table_people();

            // 先抓此 group 下的小隊
            $teams = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT team_no, COUNT(*) AS cnt
                     FROM {$people_t}
                     WHERE project_id = %d
                       AND is_active = 1
                       AND branch_id = %d
                       AND region = %s
                       AND sub_region = %s
                       AND group_name = %s
                       AND team_no <> ''
                     GROUP BY team_no
                     ORDER BY CAST(team_no AS UNSIGNED) ASC, team_no ASC",
                    $project_id,
                    $bid,
                    $region,
                    $sub_region,
                    $group_name
                ),
                ARRAY_A
            );

            echo '<div class="bc-step">';
            echo '<div class="bc-step-top">';
            echo '<a class="bc-btn bc-btn-ghost" href="' . esc_url($url_step('group')) . '">← 回到小組</a>';
            echo '</div>';
            echo '<h3 class="bc-step-title">選擇「小隊」</h3>';
            echo '<div class="bc-note">提示：小隊下面會直接顯示隊員名字，避免選錯。</div>';

            if ( ! $teams ) {
                echo '<p class="bc-empty">這個小組目前沒有可用的小隊資料。</p>';
            } else {

                echo '<div class="bc-list">';

                foreach ( $teams as $t ) {
                    $tno = (string)($t['team_no'] ?? '');
                    if ($tno === '') continue;

                    // 抓隊員名字（顯示用）
                    $members = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT name
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
                            $tno
                        ),
                        ARRAY_A
                    );

                    $names = [];
                    foreach ($members as $m) {
                        $nm = trim((string)($m['name'] ?? ''));
                        if ($nm !== '') $names[] = $nm;
                    }

                    $cnt = (int)($t['cnt'] ?? 0);
                    $is_warning = ($cnt !== 3);

                    $url = $url_with([
                        'branch_id'  => $branch_id,
                        'region'     => $region,
                        'sub_region' => $sub_region,
                        'group_name' => $group_name,
                        'team_no'    => $tno,
                    ], ['month_key']);

                    echo '<a class="bc-list-item bc-team-item ' . ($is_warning ? 'is-warning' : '') . '" href="' . esc_url($url) . '">';
                    echo '<div class="bc-item-main">';
                    echo '<div class="bc-item-title">第 ' . esc_html($tno) . ' 小隊</div>';
                    echo '<div class="bc-item-sub">隊員：' . esc_html(implode('、', $names)) . '</div>';
                    echo '<div class="bc-item-sub bc-team-meta">人數：' . (int)$cnt . ' 人</div>';
                    echo '</div>';

                    echo '<div class="bc-item-right">';
                    if ($is_warning) {
                        echo '<span class="bc-badge bc-badge-warn">非三人</span>';
                    }
                    echo '<span class="bc-go">點我進入</span>';
                    echo '</div>';

                    echo '</a>';
                }

                echo '</div>';
            }

            echo '</div>';

        // ─────────────────────────────────
        // Step 6：選月份（month_key）
        // ─────────────────────────────────
        } elseif ( ! $month_key ) {

            $months = bc_get_plan_months($project_id);

            echo '<div class="bc-step">';
            echo '<div class="bc-step-top">';
            echo '<a class="bc-btn bc-btn-ghost" href="' . esc_url($url_step('team')) . '">← 回到小隊</a>';
            echo '</div>';
            echo '<h3 class="bc-step-title">選擇「月份」</h3>';

            if ( ! $months ) {
                echo '<p class="bc-empty">目前尚未匯入任何讀經計畫月份。</p>';
            } else {
                echo '<div class="bc-list">';
                foreach ( $months as $m ) {
                    $mk = (string)($m['month_key'] ?? '');
                    if ( ! preg_match('/^\d{4}\-\d{2}$/', $mk) ) continue;

                    // open=0 不顯示
                    if ( (int) bc_get_setting('month_open_' . $mk, 1, $project_id) !== 1 ) continue;

                    $editable = (int) bc_get_setting('month_editable_' . $mk, 1, $project_id) === 1;

                    $url = $url_with([
                        'branch_id'  => $branch_id,
                        'region'     => $region,
                        'sub_region' => $sub_region,
                        'group_name' => $group_name,
                        'team_no'    => $team_no,
                        'month_key'  => $mk,
                    ]);

                    echo '<a class="bc-list-item bc-month-item ' . ($editable ? '' : 'is-locked') . '" href="' . esc_url($url) . '">';
                    echo '<div class="bc-item-main">';
                    echo '<div class="bc-item-title">' . esc_html((int)substr($mk, 5, 2) . ' 月') . '</div>';
                    echo '<div class="bc-item-sub">' . ($editable ? '可打卡' : '已鎖定（可看不可勾）') . '</div>';
                    echo '</div>';
                    echo '<div class="bc-item-right"><span class="bc-go">點我進入</span></div>';
                    echo '</a>';
                }
                echo '</div>';
            }

            echo '</div>';

        // ─────────────────────────────────
        // Step 7：打卡矩陣（Matrix）
        // ─────────────────────────────────
        } else {

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

            $days = bc_get_plan_days_by_month_key($project_id, $month_key);
            $editable = (int) bc_get_setting('month_editable_' . $month_key, 1, $project_id) === 1;

            echo '<div class="bc-step">';
            echo '<div class="bc-step-top">';
            echo '<a class="bc-btn bc-btn-ghost" href="' . esc_url($url_step('month')) . '">← 回到月份</a>';
            echo '</div>';

            echo '<h3 class="bc-step-title">開始打卡（' . esc_html((int)substr($month_key,5,2)) . ' 月）</h3>';
            echo '<div class="bc-note">' . ($editable ? '點一下就打卡；再點一次取消。' : '本月已鎖定：可看不可勾。') . '</div>';

            if ( ! $members ) {
                echo '<p class="bc-empty">找不到此小隊的隊員資料。</p>';
                echo '</div>';
                return ob_get_clean();
            }

            if ( ! $days ) {
                echo '<p class="bc-empty">本月沒有讀經計畫日程（可能尚未匯入）。</p>';
                echo '</div>';
                return ob_get_clean();
            }

            // ── Matrix（使用 div/grid，手機可橫滑）
            echo '<div class="bc-matrix">';
            echo '<div class="bc-matrix-header">';
            echo '<div class="bc-cell bc-cell-date">日期</div>';
            foreach ( $members as $m ) {
                echo '<div class="bc-cell bc-cell-member">' . esc_html($m['name']) . '</div>';
            }
            echo '</div>';

            foreach ( $days as $d ) {
                $ymd = (string)($d['ymd'] ?? '');
                if ( ! preg_match('/^\d{8}$/', $ymd) ) continue;

                echo '<div class="bc-matrix-row">';
                echo '<div class="bc-cell bc-cell-date">' . esc_html(substr($ymd,4,2) . '/' . substr($ymd,6,2)) . '</div>';

                foreach ( $members as $m ) {
                    $checked = bc_has_checkin($project_id, (int)$m['id'], $ymd);

                    echo '<div class="bc-cell bc-cell-checkin">';
                    echo '<label class="bc-checkin-wrap">';
                    echo '<input type="checkbox"
                                class="bc-checkin"
                                data-person-id="' . (int)$m['id'] . '"
                                data-ymd="' . esc_attr($ymd) . '"
                                ' . ($checked ? 'checked' : '') . '
                                ' . ( $editable ? '' : 'disabled' ) . '>';
                    echo '<span class="bc-checkin-box" aria-hidden="true"></span>';
                    echo '</label>';
                    echo '</div>';
                }

                echo '</div>';
            }

            echo '</div>'; // bc-matrix

            echo '</div>'; // bc-step
        }
        ?>

    </div>
    <?php

    return ob_get_clean();
});
