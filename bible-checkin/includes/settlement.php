<?php
/**
 * Bible Check-in Plugin
 * Settlement / Rewards Engine
 *
 * 職責：
 * - 依 plan_days + checkins 計算每人每月是否「完成」
 * - 依「三人小隊」(branch + region + sub_region + group_name + team_no) 計算 team 月完成
 * - 依獎勵規則寫回 bc_people 的狀態與 reward 欄位
 *
 * 規則（以 SSOT 目前口徑）：
 * A) 1~3 月：同一小隊三人當月「都完成」→ 每人當月一張咖啡券（reward_coffee_01~03）
 * B) 1~4 月：個人四個月都完成 → 全勤餐券（reward_meal_full_attendance）
 * C) 4 月補償：前三月「沒有全完成」的人，但 4 月小隊三人都完成 → 個人一張補償券（reward_april_recovery）
 *
 * 狀態欄位：
 * - month_0x_status：1=完成, 0=未完成, -1=該月無 plan_days/無法計算
 * - team_month_0x_status：1=小隊完成, 0=小隊未完成, -1=非三人小隊/無 team_no / 無法計算
 */

if ( ! defined('ABSPATH') ) exit;

function bc_settlement_get_target_month_keys( $project_id ) {
    $project_id = (int) $project_id;
    if ( $project_id <= 0 ) return array();

    $p = function_exists('bc_get_project') ? bc_get_project($project_id) : null;
    $year = isset($p['year']) ? (int)$p['year'] : 0;

    // fallback：如果 project.year 拿不到，就從 plan_months 的第一筆推年份
    if ( $year <= 0 && function_exists('bc_get_plan_months') ) {
        $months = bc_get_plan_months($project_id);
        if ( $months && isset($months[0]['month_key']) && preg_match('/^(\d{4})\-\d{2}$/', (string)$months[0]['month_key'], $m) ) {
            $year = (int)$m[1];
        }
    }

    if ( $year <= 0 ) return array();

    // 固定抓 1~4 月
    $out = array();
    for ( $i=1; $i<=4; $i++ ) {
        $out[$i] = sprintf('%04d-%02d', $year, $i);
    }
    return $out;
}

function bc_settlement_get_month_day_ymds( $project_id, $month_key ) {
    $project_id = (int) $project_id;
    $month_key  = (string) $month_key;

    if ( $project_id <= 0 || ! preg_match('/^\d{4}\-\d{2}$/', $month_key) ) return array();
    if ( ! function_exists('bc_get_plan_days_by_month_key') ) return array();

    $days = bc_get_plan_days_by_month_key($project_id, $month_key);
    if ( ! $days ) return array();

    $ymds = array();
    foreach ( $days as $d ) {
        $ymd = preg_replace('/\D+/', '', (string)($d['ymd'] ?? ''));
        if ( strlen($ymd) === 8 ) $ymds[] = $ymd;
    }
    $ymds = array_values(array_unique($ymds));
    sort($ymds);
    return $ymds;
}

function bc_settlement_fetch_active_people_min( $project_id ) {
    global $wpdb;

    $project_id = (int) $project_id;
    if ( $project_id <= 0 ) return array();

    $t = function_exists('bc_table_people') ? bc_table_people() : ($wpdb->prefix . 'bc_people');

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT
                id,
                name,
                branch_id,
                region,
                sub_region,
                group_name,
                team_no,
                is_active
             FROM {$t}
             WHERE project_id = %d
               AND is_active = 1
             ORDER BY branch_id, region, sub_region, group_name, CAST(team_no AS UNSIGNED), team_no, id ASC",
            $project_id
        ),
        ARRAY_A
    );

    return is_array($rows) ? $rows : array();
}

function bc_settlement_fetch_checkin_counts_for_ymds( $project_id, $ymds ) {
    global $wpdb;

    $project_id = (int) $project_id;
    if ( $project_id <= 0 || empty($ymds) ) return array();

    $ymds = array_values(array_filter(array_map(function($s){
        $s = preg_replace('/\D+/', '', (string)$s);
        return (strlen($s) === 8) ? $s : null;
    }, $ymds)));

    if ( empty($ymds) ) return array();

    $t = function_exists('bc_table_checkins') ? bc_table_checkins() : ($wpdb->prefix . 'bc_checkins');

    $ph = implode(',', array_fill(0, count($ymds), '%s'));
    $sql = "
        SELECT person_id, COUNT(*) AS cnt
        FROM {$t}
        WHERE project_id = %d
          AND ymd IN ({$ph})
        GROUP BY person_id
    ";

    $params = array_merge(array($project_id), $ymds);

    $rows = $wpdb->get_results(
        $wpdb->prepare($sql, $params),
        ARRAY_A
    );

    $map = array();
    if ( is_array($rows) ) {
        foreach ( $rows as $r ) {
            $map[(int)$r['person_id']] = (int)$r['cnt'];
        }
    }
    return $map;
}

function bc_settlement_make_team_key( $p ) {
    $branch_id  = (int)($p['branch_id'] ?? 0);
    $region     = (string)($p['region'] ?? '');
    $sub_region = (string)($p['sub_region'] ?? '');
    $group_name = (string)($p['group_name'] ?? '');
    $team_no    = trim((string)($p['team_no'] ?? ''));

    if ( $team_no === '' ) return '';

    return implode('|', array(
        $branch_id,
        $region,
        $sub_region,
        $group_name,
        $team_no
    ));
}

/**
 * 主入口：跑一次結算（可 dry-run）
 *
 * @return array report
 */
function bc_settlement_run( $project_id = 0, $dry_run = false ) {
    global $wpdb;

    $project_id = $project_id ? (int)$project_id : (function_exists('bc_get_current_project_id') ? (int)bc_get_current_project_id() : 0);
    if ( $project_id <= 0 ) {
        return array('ok' => false, 'message' => 'invalid project_id');
    }

    $month_keys = bc_settlement_get_target_month_keys($project_id);
    if ( empty($month_keys) ) {
        return array('ok' => false, 'message' => 'cannot resolve project year/month_keys');
    }

    // 取每月 plan day ymds
    $month_ymds = array();
    $month_day_count = array();
    for ( $m=1; $m<=4; $m++ ) {
        $mk = (string)($month_keys[$m] ?? '');
        $ymds = $mk ? bc_settlement_get_month_day_ymds($project_id, $mk) : array();
        $month_ymds[$m] = $ymds;
        $month_day_count[$m] = count($ymds);
    }

    // People
    $people = bc_settlement_fetch_active_people_min($project_id);
    if ( empty($people) ) {
        return array('ok' => true, 'message' => 'no active people', 'report' => array(
            'project_id' => $project_id,
            'people' => 0
        ));
    }

    $person_ids = array_map('intval', array_column($people, 'id'));

    // 每月 checkin count map
    $month_counts = array();
    for ( $m=1; $m<=4; $m++ ) {
        $ymds = $month_ymds[$m];
        $month_counts[$m] = !empty($ymds) ? bc_settlement_fetch_checkin_counts_for_ymds($project_id, $ymds) : array();
    }

    // person month complete
    $person_month_status = array(); // [pid][m] => 1/0/-1
    foreach ( $person_ids as $pid ) {
        $person_month_status[$pid] = array();
        for ( $m=1; $m<=4; $m++ ) {
            $day_count = (int)$month_day_count[$m];
            if ( $day_count <= 0 ) {
                $person_month_status[$pid][$m] = -1;
                continue;
            }
            $cnt = isset($month_counts[$m][$pid]) ? (int)$month_counts[$m][$pid] : 0;
            $person_month_status[$pid][$m] = ($cnt >= $day_count) ? 1 : 0;
        }
    }

    // team grouping
    $teams = array(); // team_key => ['members'=>[pid...], 'meta'=>...]
    foreach ( $people as $p ) {
        $pid = (int)$p['id'];
        $key = bc_settlement_make_team_key($p);
        if ( $key === '' ) continue;

        if ( ! isset($teams[$key]) ) {
            $teams[$key] = array(
                'members' => array(),
                'branch_id' => (int)$p['branch_id'],
                'region' => (string)$p['region'],
                'sub_region' => (string)$p['sub_region'],
                'group_name' => (string)$p['group_name'],
                'team_no' => (string)$p['team_no'],
            );
        }
        $teams[$key]['members'][] = $pid;
    }

    // team month status
    $team_month_status = array(); // team_key => [m=> 1/0/-1] ; -1 means not eligible (not 3 members or missing plan)
    foreach ( $teams as $k => $t ) {
        $members = array_values(array_unique(array_map('intval', $t['members'])));
        $teams[$k]['members'] = $members;

        $team_month_status[$k] = array();

        // 只認「剛好三人」的小隊做獎勵判斷
        $is_three = (count($members) === 3);

        for ( $m=1; $m<=4; $m++ ) {
            if ( (int)$month_day_count[$m] <= 0 ) {
                $team_month_status[$k][$m] = -1;
                continue;
            }
            if ( ! $is_three ) {
                $team_month_status[$k][$m] = -1;
                continue;
            }

            $ok = true;
            foreach ( $members as $pid ) {
                if ( ! isset($person_month_status[$pid][$m]) || (int)$person_month_status[$pid][$m] !== 1 ) {
                    $ok = false; break;
                }
            }
            $team_month_status[$k][$m] = $ok ? 1 : 0;
        }
    }

    // Build per-person updates
    $updates = array(); // pid => data
    $report = array(
        'project_id' => $project_id,
        'people_active' => count($people),
        'month_day_count' => $month_day_count,
        'coffee_01' => 0,
        'coffee_02' => 0,
        'coffee_03' => 0,
        'meal_full_attendance' => 0,
        'april_recovery' => 0,
    );

    // map pid -> team_key (一人只會落在一個 team_key；若資料怪，取第一個)
    $pid_team = array();
    foreach ( $teams as $k => $t ) {
        foreach ( $t['members'] as $pid ) {
            if ( ! isset($pid_team[$pid]) ) $pid_team[$pid] = $k;
        }
    }

    foreach ( $people as $p ) {
        $pid = (int)$p['id'];
        $tkey = isset($pid_team[$pid]) ? $pid_team[$pid] : '';

        // month statuses
        $m1 = (int)$person_month_status[$pid][1];
        $m2 = (int)$person_month_status[$pid][2];
        $m3 = (int)$person_month_status[$pid][3];
        $m4 = (int)$person_month_status[$pid][4];

        // team statuses (default -1)
        $tm1 = -1; $tm2 = -1; $tm3 = -1; $tm4 = -1;
        if ( $tkey && isset($team_month_status[$tkey]) ) {
            $tm1 = (int)$team_month_status[$tkey][1];
            $tm2 = (int)$team_month_status[$tkey][2];
            $tm3 = (int)$team_month_status[$tkey][3];
            $tm4 = (int)$team_month_status[$tkey][4];
        }

        // Rewards default reset
        $coffee01 = 0;
        $coffee02 = 0;
        $coffee03 = 0;
        $meal = 0;
        $recovery = 0;

        // A) coffee券：只算 1~3 月，且 team 月完成==1
        if ( $tm1 === 1 ) $coffee01 = 1;
        if ( $tm2 === 1 ) $coffee02 = 1;
        if ( $tm3 === 1 ) $coffee03 = 1;

        // B) 全勤餐券：1~4 月個人都完成（都==1）
        if ( $m1 === 1 && $m2 === 1 && $m3 === 1 && $m4 === 1 ) {
            $meal = 1;
        }

        // C) 4 月補償：前三月「不是全完成」的人，但 4 月 team 完成
        $first3_all_done = ( $m1 === 1 && $m2 === 1 && $m3 === 1 );
        if ( $meal !== 1 && ( ! $first3_all_done ) && $tm4 === 1 ) {
            $recovery = 1;
        }

        // Summary text
        $summary = array();
        if ( $coffee01 ) $summary[] = '1月咖啡券';
        if ( $coffee02 ) $summary[] = '2月咖啡券';
        if ( $coffee03 ) $summary[] = '3月咖啡券';
        if ( $meal )     $summary[] = '全勤餐券';
        if ( $recovery ) $summary[] = '4月補償券';

        $reward_summary = $summary ? implode('、', $summary) : '';

        $updates[$pid] = array(
            'month_01_status' => $m1,
            'month_02_status' => $m2,
            'month_03_status' => $m3,
            'month_04_status' => $m4,

            'team_month_01_status' => $tm1,
            'team_month_02_status' => $tm2,
            'team_month_03_status' => $tm3,
            'team_month_04_status' => $tm4,

            'reward_coffee_01' => $coffee01,
            'reward_coffee_02' => $coffee02,
            'reward_coffee_03' => $coffee03,
            'reward_meal_full_attendance' => $meal,
            'reward_april_recovery' => $recovery,

            'reward_summary' => $reward_summary,
        );

        // report counters（以「人次」統計）
        if ( $coffee01 ) $report['coffee_01']++;
        if ( $coffee02 ) $report['coffee_02']++;
        if ( $coffee03 ) $report['coffee_03']++;
        if ( $meal )     $report['meal_full_attendance']++;
        if ( $recovery ) $report['april_recovery']++;
    }

    if ( $dry_run ) {
        return array('ok' => true, 'dry_run' => true, 'report' => $report);
    }

    // Write to DB (per-person update)
    $t_people = function_exists('bc_table_people') ? bc_table_people() : ($wpdb->prefix . 'bc_people');

    $updated = 0;
    foreach ( $updates as $pid => $data ) {
        $formats = array(
            '%d','%d','%d','%d',
            '%d','%d','%d','%d',
            '%d','%d','%d','%d','%d',
            '%s'
        );

        $ok = $wpdb->update(
            $t_people,
            $data,
            array(
                'id' => (int)$pid,
                'project_id' => (int)$project_id,
            ),
            $formats,
            array('%d','%d')
        );

        if ( $ok !== false ) $updated++;
    }

    // record last run
    if ( function_exists('bc_set_setting') ) {
        bc_set_setting('settlement_last_run', current_time('mysql'), $project_id);
        bc_set_setting('settlement_last_report', $report, $project_id);
    }

    $report['rows_updated'] = $updated;

    return array('ok' => true, 'dry_run' => false, 'report' => $report);
}
