<?php
/**
 * Bible Check-in Plugin
 * Database Helper Layer
 *
 * db.php 的唯一責任：
 * - 統一資料表名稱
 * - 統一 current project 的取得方式（SSOT）
 * - 提供常用查詢 helper
 *
 * ❌ 不寫商業邏輯
 * ❌ 不做 UI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * =========================
 * Table name helpers
 * =========================
 */

function bc_table_projects() {
    global $wpdb;
    return $wpdb->prefix . 'bc_projects';
}

function bc_table_people() {
    global $wpdb;
    return $wpdb->prefix . 'bc_people';
}

function bc_table_teams() {
    global $wpdb;
    return $wpdb->prefix . 'bc_teams';
}

function bc_table_checkins() {
    global $wpdb;
    return $wpdb->prefix . 'bc_checkins';
}

function bc_table_plan_days() {
    global $wpdb;
    return $wpdb->prefix . 'bc_plan_days';
}

function bc_table_settings() {
    global $wpdb;
    return $wpdb->prefix . 'bc_settings';
}

/**
 * ✅ Branches（分堂）
 */
function bc_table_branches() {
    global $wpdb;
    return $wpdb->prefix . 'bc_branches';
}

/**
 * =========================
 * SSOT: Current project id
 * =========================
 */

function bc_get_current_project_id() {
    global $wpdb;

    $pid = (int) get_option( 'bc_current_project_id', 0 );
    if ( $pid > 0 ) {
        return $pid;
    }

    $table = bc_table_projects();
    $first = (int) $wpdb->get_var( "SELECT id FROM {$table} ORDER BY id ASC LIMIT 1" );

    if ( $first > 0 ) {
        update_option( 'bc_current_project_id', $first );
        return $first;
    }

    return 0;
}

function bc_set_current_project_id( $project_id ) {
    update_option( 'bc_current_project_id', (int) $project_id );
}

/**
 * =========================
 * Project helpers
 * =========================
 */

function bc_get_project( $project_id = 0 ) {
    global $wpdb;

    $project_id = $project_id ? (int) $project_id : bc_get_current_project_id();
    if ( $project_id <= 0 ) {
        return null;
    }

    $table = bc_table_projects();

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d LIMIT 1",
            $project_id
        ),
        ARRAY_A
    );

    return is_array( $row ) ? $row : null;
}

function bc_get_all_projects() {
    global $wpdb;

    $table = bc_table_projects();

    $rows = $wpdb->get_results(
        "SELECT *
         FROM {$table}
         ORDER BY status DESC, year DESC, id DESC",
        ARRAY_A
    );

    return is_array( $rows ) ? $rows : array();
}

/**
 * =========================
 * Plan helpers
 * =========================
 */

function bc_get_plan_months( $project_id = 0 ) {
    global $wpdb;

    $project_id = $project_id ? (int) $project_id : bc_get_current_project_id();
    if ( $project_id <= 0 ) {
        return array();
    }

    $table = bc_table_plan_days();

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT month_key, COUNT(*) AS day_count
             FROM {$table}
             WHERE project_id = %d
             GROUP BY month_key
             ORDER BY month_key ASC",
            $project_id
        ),
        ARRAY_A
    );

    return is_array( $rows ) ? $rows : array();
}

/**
 * 取得某 month_key 的每日進度（給前台用）
 * @return array [{ymd, display_text}, ...]
 */
function bc_get_plan_days_by_month_key( $project_id, $month_key ) {
    global $wpdb;

    $project_id = (int) $project_id;
    $month_key  = (string) $month_key;

    if ( $project_id <= 0 || ! preg_match('/^\d{4}\-\d{2}$/', $month_key) ) {
        return array();
    }

    $t = bc_table_plan_days();

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT ymd, display_text
             FROM {$t}
             WHERE project_id = %d AND month_key = %s
             ORDER BY ymd ASC",
            $project_id,
            $month_key
        ),
        ARRAY_A
    );

    return is_array( $rows ) ? $rows : array();
}

/**
 * 檢查某人某日是否已打卡（ymd）
 */
function bc_has_checkin( $project_id, $person_id, $ymd ) {
    global $wpdb;

    $project_id = (int) $project_id;
    $person_id  = (int) $person_id;
    $ymd        = preg_replace('/\D+/', '', (string) $ymd);

    if ( $project_id <= 0 || $person_id <= 0 || strlen($ymd) !== 8 ) {
        return false;
    }

    $t = bc_table_checkins();

    $id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id
             FROM {$t}
             WHERE project_id = %d AND person_id = %d AND ymd = %s
             LIMIT 1",
            $project_id,
            $person_id,
            $ymd
        )
    );

    return ! empty( $id );
}

/**
 * =========================
 * Settings helpers
 * =========================
 */

function bc_get_setting( $key, $default = null, $project_id = 0 ) {
    global $wpdb;

    $project_id = $project_id ? (int) $project_id : bc_get_current_project_id();
    if ( $project_id <= 0 ) {
        return $default;
    }

    $table = bc_table_settings();

    $val = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT setting_value
             FROM {$table}
             WHERE project_id = %d AND setting_key = %s
             LIMIT 1",
            $project_id,
            $key
        )
    );

    if ( null === $val ) {
        return $default;
    }

    return maybe_unserialize( $val );
}

function bc_set_setting( $key, $value, $project_id = 0 ) {
    global $wpdb;

    $project_id = $project_id ? (int) $project_id : bc_get_current_project_id();
    if ( $project_id <= 0 ) {
        return false;
    }

    $table = bc_table_settings();

    $exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id
             FROM {$table}
             WHERE project_id = %d AND setting_key = %s
             LIMIT 1",
            $project_id,
            $key
        )
    );

    $data = array(
        'project_id'    => $project_id,
        'setting_key'   => $key,
        'setting_value' => maybe_serialize( $value ),
        'updated_at'    => current_time( 'mysql' ),
    );

    if ( $exists ) {
        return ( false !== $wpdb->update(
            $table,
            array(
                'setting_value' => $data['setting_value'],
                'updated_at'    => $data['updated_at'],
            ),
            array( 'id' => (int) $exists ),
            array( '%s', '%s' ),
            array( '%d' )
        ) );
    }

    return ( false !== $wpdb->insert(
        $table,
        $data,
        array( '%d', '%s', '%s', '%s' )
    ) );
}

/**
 * =========================
 * Branch helpers（分堂）
 * =========================
 */

function bc_get_branches( $project_id = 0, $active_only = true ) {
    global $wpdb;

    $project_id = $project_id ? (int) $project_id : bc_get_current_project_id();
    if ( $project_id <= 0 ) {
        return array();
    }

    $table = bc_table_branches();

    $where = "WHERE project_id = %d";
    if ( $active_only ) {
        $where .= " AND is_active = 1";
    }

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, branch_name, sort_order, is_active
             FROM {$table}
             {$where}
             ORDER BY sort_order ASC, id ASC",
            $project_id
        ),
        ARRAY_A
    );

    return is_array( $rows ) ? $rows : array();
}

function bc_insert_branch( $branch_name, $sort_order = 0, $project_id = 0 ) {
    global $wpdb;

    $project_id = $project_id ? (int) $project_id : bc_get_current_project_id();
    if ( $project_id <= 0 ) {
        return false;
    }

    $branch_name = trim( (string) $branch_name );
    if ( $branch_name === '' ) {
        return false;
    }

    $table = bc_table_branches();

    $ok = $wpdb->insert(
        $table,
        array(
            'project_id'  => $project_id,
            'branch_name' => $branch_name,
            'sort_order'  => (int) $sort_order,
            'is_active'   => 1,
            'created_at'  => current_time( 'mysql' ),
        ),
        array( '%d', '%s', '%d', '%d', '%s' )
    );

    if ( false === $ok ) {
        return false;
    }

    return (int) $wpdb->insert_id;
}

function bc_update_branch( $branch_id, $fields = array(), $project_id = 0 ) {
    global $wpdb;

    $project_id = $project_id ? (int) $project_id : bc_get_current_project_id();
    $branch_id  = (int) $branch_id;

    if ( $project_id <= 0 || $branch_id <= 0 ) {
        return false;
    }

    $table = bc_table_branches();

    $data = array();
    $fmt  = array();

    if ( isset( $fields['branch_name'] ) ) {
        $name = trim( (string) $fields['branch_name'] );
        if ( $name !== '' ) {
            $data['branch_name'] = $name;
            $fmt[] = '%s';
        }
    }

    if ( isset( $fields['sort_order'] ) ) {
        $data['sort_order'] = (int) $fields['sort_order'];
        $fmt[] = '%d';
    }

    if ( isset( $fields['is_active'] ) ) {
        $data['is_active'] = (int) ( $fields['is_active'] ? 1 : 0 );
        $fmt[] = '%d';
    }

    if ( empty( $data ) ) {
        return false;
    }

    return ( false !== $wpdb->update(
        $table,
        $data,
        array(
            'id'         => $branch_id,
            'project_id' => $project_id,
        ),
        $fmt,
        array( '%d', '%d' )
    ) );
}

/**
 * =====================================================
 * Round 5.1 — Team helpers（方案 B：寫入 bc_teams）
 * =====================================================
 */

/**
 * 取得目前專案的人員，依「隊伍顯示欄位」分組
 * ⚠️ 不寫 team_id，只是聚合用
 */
function bc_get_team_candidates( $project_id = 0 ) {
    global $wpdb;

    $project_id = $project_id ? (int) $project_id : bc_get_current_project_id();
    if ( $project_id <= 0 ) {
        return array();
    }

    $people = bc_table_people();

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT
                id AS person_id,
                name,
                region,
                sub_region,
                group_name,
                team_no,
                is_active
             FROM {$people}
             WHERE project_id = %d AND is_active = 1
             ORDER BY region, sub_region, group_name, team_no, id ASC",
            $project_id
        ),
        ARRAY_A
    );

    $teams = array();

    foreach ( $rows as $p ) {
        // ✅ 正確的小隊唯一鍵
        $key_parts = array(
            trim((string)$p['region']),
            trim((string)$p['sub_region']),
            trim((string)$p['group_name']),
            trim((string)$p['team_no']),
        );

        // 沒填 team_no 的人，仍然丟到未指定
        if ($key_parts[3] === '') {
            $key = '_no_team_';
        } else {
            $key = implode('|', $key_parts);
        }

        if ( ! isset( $teams[ $key ] ) ) {
            $teams[ $key ] = array(
                'region'     => $p['region'],
                'sub_region' => $p['sub_region'],
                'group_name' => $p['group_name'],
                'team_no'    => $p['team_no'],
                'members'    => array(),
                'count'      => 0,
            );
        }

        $teams[ $key ]['members'][] = $p;
        $teams[ $key ]['count']++;
    }

    return $teams;
}

/**
 * 建立一筆 team container（bc_teams）
 * 回傳 team_id
 */
function bc_create_team_container( $team_no, $project_id = 0 ) {
    global $wpdb;

    $project_id = $project_id ? (int) $project_id : bc_get_current_project_id();
    if ( $project_id <= 0 ) {
        return 0;
    }

    $table = bc_table_teams();

    $ok = $wpdb->insert(
        $table,
        array(
            'project_id' => $project_id,
            'team_no'    => (string) $team_no,
            'created_at'=> current_time( 'mysql' ),
        ),
        array( '%d', '%s', '%s' )
    );

    if ( false === $ok ) {
        return 0;
    }

    return (int) $wpdb->insert_id;
}

/**
 * 將人員綁定到 team_id（寫入 people.team_id）
 * ⚠️ 僅在「整理小隊」時呼叫
 */
function bc_assign_people_to_team( $team_id, $person_ids = array(), $project_id = 0 ) {
    global $wpdb;

    $project_id = $project_id ? (int) $project_id : bc_get_current_project_id();
    $team_id    = (int) $team_id;

    if ( $project_id <= 0 || $team_id <= 0 || empty( $person_ids ) ) {
        return false;
    }

    $people = bc_table_people();
    $ids    = array_map( 'intval', $person_ids );
    $in     = implode( ',', $ids );

    $sql = "
        UPDATE {$people}
        SET team_id = %d
        WHERE project_id = %d
          AND id IN ({$in})
    ";

    return ( false !== $wpdb->query(
        $wpdb->prepare( $sql, $team_id, $project_id )
    ) );
}

/**
 * 清空目前專案所有 team 綁定（重整前使用）
 */
function bc_clear_team_bindings( $project_id = 0 ) {
    global $wpdb;

    $project_id = $project_id ? (int) $project_id : bc_get_current_project_id();
    if ( $project_id <= 0 ) {
        return false;
    }

    $people = bc_table_people();

    return ( false !== $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$people}
             SET team_id = 0
             WHERE project_id = %d",
            $project_id
        )
    ) );
}

/**
 * 取得人員清單（給 CSV 匯出用）
 * ⚠️ 僅回傳人類可編輯欄位，不含任何 system id
 */
function bc_db_get_people_for_csv_export( $project_id = 0 ) {
    global $wpdb;

    $project_id = $project_id ? (int) $project_id : bc_get_current_project_id();
    if ( $project_id <= 0 ) {
        return array();
    }

    $people   = bc_table_people();
    $branches = bc_table_branches();

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT
                p.name,
                b.branch_name AS branch,
                p.region,
                p.sub_region,
                p.group_name,
                p.team_no,
                p.is_active
             FROM {$people} p
             LEFT JOIN {$branches} b
               ON p.branch_id = b.id
              AND b.project_id = %d
             WHERE p.project_id = %d
             ORDER BY p.region, p.sub_region, p.group_name, p.team_no, p.id ASC",
            $project_id,
            $project_id
        ),
        ARRAY_A
    );

    return is_array( $rows ) ? $rows : array();
}

/**
 * 由 CSV 資料新增或更新一位人員（穩定版）
 *
 * 規則：
 * - 判定重複：project_id + name + group_name
 * - CSV 中不存在的分堂 → 自動建立
 * - INSERT / UPDATE 欄位順序固定（避免 formats 對不齊）
 *
 * @return array ['action' => insert|duplicate|skip, 'id' => person_id|0, 'branch_created' => bool]
 */
function bc_db_upsert_person_from_csv( $project_id, $person_data ) {
    global $wpdb;

    $project_id = (int) $project_id;
    if ( $project_id <= 0 || ! is_array( $person_data ) ) {
        return array( 'action' => 'skip', 'id' => 0, 'branch_created' => false );
    }

    /* ========= normalize ========= */

    $name        = trim( (string) ( $person_data['name'] ?? '' ) );
    $branch_name = trim( (string) ( $person_data['branch'] ?? '' ) );
    $region      = trim( (string) ( $person_data['region'] ?? '' ) );
    $sub_region  = trim( (string) ( $person_data['sub_region'] ?? '' ) );
    $group_name  = trim( (string) ( $person_data['group_name'] ?? '' ) );
    $team_no     = trim( (string) ( $person_data['team_no'] ?? '' ) );
    $is_active   = ! empty( $person_data['is_active'] ) ? 1 : 0;

    if ( $name === '' ) {
        return array( 'action' => 'skip', 'id' => 0, 'branch_created' => false );
    }

    /* ========= branch: auto create ========= */

    $branch_id       = 0;
    $branch_created  = false;

    if ( $branch_name !== '' ) {
        $branches = bc_table_branches();

        $branch_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$branches}
                 WHERE project_id = %d AND branch_name = %s
                 LIMIT 1",
                $project_id,
                $branch_name
            )
        );

        if ( $branch_id <= 0 ) {
            $ok = $wpdb->insert(
                $branches,
                array(
                    'project_id'  => $project_id,
                    'branch_name' => $branch_name,
                    'sort_order'  => 0,
                    'is_active'   => 1,
                    'created_at'  => current_time( 'mysql' ),
                ),
                array( '%d', '%s', '%d', '%d', '%s' )
            );

            if ( $ok !== false ) {
                $branch_id      = (int) $wpdb->insert_id;
                $branch_created = true;
            }
        }
    }

    $people = bc_table_people();

    /* ========= duplicate check ========= */

    $existing_id = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$people}
             WHERE project_id = %d
               AND name = %s
               AND group_name = %s
             LIMIT 1",
            $project_id,
            $name,
            $group_name
        )
    );

    if ( $existing_id > 0 ) {
        // 同名＋同小組 → 視為重複，不更新、不覆蓋
        return array(
            'action'         => 'duplicate',
            'id'             => $existing_id,
            'branch_created' => $branch_created,
        );
    }

    /* ========= INSERT（欄位順序固定） ========= */

    $ok = $wpdb->insert(
        $people,
        array(
            'project_id' => $project_id,
            'branch_id'  => $branch_id,
            'region'     => $region,
            'sub_region' => $sub_region,
            'group_name' => $group_name,
            'team_id'    => 0,
            'team_no'    => $team_no,
            'name'       => $name,
            'is_active'  => $is_active,
            'created_at' => current_time( 'mysql' ),
        ),
        array(
            '%d', // project_id
            '%d', // branch_id
            '%s', // region
            '%s', // sub_region
            '%s', // group_name
            '%d', // team_id
            '%s', // team_no
            '%s', // name
            '%d', // is_active
            '%s', // created_at
        )
    );

    if ( false === $ok ) {
        return array( 'action' => 'skip', 'id' => 0, 'branch_created' => $branch_created );
    }

    return array(
        'action'         => 'insert',
        'id'             => (int) $wpdb->insert_id,
        'branch_created' => $branch_created,
    );
}

/**
 * =========================
 * People list helpers (Admin UX: paging/filter/sort)
 * =========================
 */

/**
 * 允許的 orderby 欄位白名單（避免 SQL injection）
 */
function bc_people_allowed_orderby_fields() {
    return array(
        'name'       => 'p.name',
        'branch'     => 'b.branch_name',
        'branch_id'  => 'p.branch_id',
        'region'     => 'p.region',
        'sub_region' => 'p.sub_region',
        'group_name' => 'p.group_name',
        'team_no'    => 'p.team_no',
        'is_active'  => 'p.is_active',
        'id'         => 'p.id',
    );
}

/**
 * 取得人員清單（給 admin 人員管理頁：分頁/排序/filter）
 *
 * @param int   $project_id
 * @param array $args
 *   - page (int) 預設 1
 *   - per_page (int) 預設 50，建議 20/50/100
 *   - orderby (string) 預設 'id'
 *   - order (string) 'ASC'|'DESC' 預設 'ASC'
 *   - filters (array)
 *       - branch_id (int)
 *       - is_active (int 0/1)
 *       - region (string)
 *       - sub_region (string)
 *       - group_name (string)
 *       - team_no (string)
 *       - q (string) 搜尋 name（LIKE）
 *
 * @return array rows (ARRAY_A) 每列包含：people 欄位 + branch_name
 */
function bc_get_people_list_with_branch( $project_id, $args = array() ) {
    global $wpdb;

    $project_id = (int) $project_id;
    if ( $project_id <= 0 ) {
        return array();
    }

    $defaults = array(
        'page'     => 1,
        'per_page' => 50,
        'orderby'  => 'id',
        'order'    => 'ASC',
        'filters'  => array(),
    );
    $args = array_merge( $defaults, is_array( $args ) ? $args : array() );

    $page     = max( 1, (int) $args['page'] );
    $per_page = (int) $args['per_page'];
    if ( $per_page <= 0 ) $per_page = 50;
    if ( $per_page > 200 ) $per_page = 200; // 防止一次撈太多

    $offset = ( $page - 1 ) * $per_page;

    $allowed = bc_people_allowed_orderby_fields();
    $orderby_key = (string) $args['orderby'];
    $orderby_sql = isset( $allowed[ $orderby_key ] ) ? $allowed[ $orderby_key ] : $allowed['id'];

    $order = strtoupper( (string) $args['order'] );
    $order = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'ASC';

    $people   = bc_table_people();
    $branches = bc_table_branches();

    $where   = array( 'p.project_id = %d' );
    $params  = array( $project_id );

    $filters = is_array( $args['filters'] ) ? $args['filters'] : array();

    // branch_id
    if ( isset( $filters['branch_id'] ) && (int) $filters['branch_id'] > 0 ) {
        $where[]  = 'p.branch_id = %d';
        $params[] = (int) $filters['branch_id'];
    }

    // is_active 0/1
    if ( isset( $filters['is_active'] ) && ( $filters['is_active'] === 0 || $filters['is_active'] === '0' || $filters['is_active'] === 1 || $filters['is_active'] === '1' ) ) {
        $where[]  = 'p.is_active = %d';
        $params[] = (int) $filters['is_active'];
    }

    // region / sub_region / group_name / team_no (精準比對；你要 LIKE 也可以之後再加)
    foreach ( array( 'region', 'sub_region', 'group_name', 'team_no' ) as $k ) {
        if ( isset( $filters[ $k ] ) ) {
            $v = trim( (string) $filters[ $k ] );
            if ( $v !== '' ) {
                $where[]  = "p.{$k} = %s";
                $params[] = $v;
            }
        }
    }

    // q：搜尋 name（LIKE）
    if ( isset( $filters['q'] ) ) {
        $q = trim( (string) $filters['q'] );
        if ( $q !== '' ) {
            $where[]  = "p.name LIKE %s";
            $params[] = '%' . $wpdb->esc_like( $q ) . '%';
        }
    }

    $where_sql = 'WHERE ' . implode( ' AND ', $where );

    // 注意：LEFT JOIN 需要限定 project_id，避免跨 project 同 id 混到
    $sql = "
        SELECT
            p.*,
            b.branch_name
        FROM {$people} p
        LEFT JOIN {$branches} b
               ON p.branch_id = b.id
              AND b.project_id = %d
        {$where_sql}
        ORDER BY {$orderby_sql} {$order}, p.id ASC
        LIMIT %d OFFSET %d
    ";

    // params：join project_id + where params + limit/offset
    $all_params = array_merge( array( $project_id ), $params, array( $per_page, $offset ) );

    $rows = $wpdb->get_results(
        $wpdb->prepare( $sql, $all_params ),
        ARRAY_A
    );

    return is_array( $rows ) ? $rows : array();
}

/**
 * 計算符合 filter 的總筆數（給 admin 分頁用）
 *
 * @return int total rows
 */
function bc_get_people_count( $project_id, $args = array() ) {
    global $wpdb;

    $project_id = (int) $project_id;
    if ( $project_id <= 0 ) {
        return 0;
    }

    $args = is_array( $args ) ? $args : array();
    $filters = isset( $args['filters'] ) && is_array( $args['filters'] ) ? $args['filters'] : array();

    $people = bc_table_people();

    $where   = array( 'project_id = %d' );
    $params  = array( $project_id );

    if ( isset( $filters['branch_id'] ) && (int) $filters['branch_id'] > 0 ) {
        $where[]  = 'branch_id = %d';
        $params[] = (int) $filters['branch_id'];
    }

    if ( isset( $filters['is_active'] ) && ( $filters['is_active'] === 0 || $filters['is_active'] === '0' || $filters['is_active'] === 1 || $filters['is_active'] === '1' ) ) {
        $where[]  = 'is_active = %d';
        $params[] = (int) $filters['is_active'];
    }

    foreach ( array( 'region', 'sub_region', 'group_name', 'team_no' ) as $k ) {
        if ( isset( $filters[ $k ] ) ) {
            $v = trim( (string) $filters[ $k ] );
            if ( $v !== '' ) {
                $where[]  = "{$k} = %s";
                $params[] = $v;
            }
        }
    }

    if ( isset( $filters['q'] ) ) {
        $q = trim( (string) $filters['q'] );
        if ( $q !== '' ) {
            $where[]  = "name LIKE %s";
            $params[] = '%' . $wpdb->esc_like( $q ) . '%';
        }
    }

    $where_sql = 'WHERE ' . implode( ' AND ', $where );

    $sql = "SELECT COUNT(*) FROM {$people} {$where_sql}";

    $total = (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
    return $total;
}

function bc_get_people_hierarchy_for_filters( $project_id ) {
    global $wpdb;

    $project_id = (int)$project_id;
    if ($project_id <= 0) return [];

    $p = bc_table_people();
    $b = bc_table_branches();

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT
                p.branch_id,
                b.branch_name,
                p.region,
                p.sub_region,
                p.group_name
             FROM {$p} p
             LEFT JOIN {$b} b
               ON b.id = p.branch_id AND b.project_id = p.project_id
             WHERE p.project_id = %d
               AND p.is_active = 1
             GROUP BY p.branch_id, p.region, p.sub_region, p.group_name
             ORDER BY b.branch_name, p.region, p.sub_region, p.group_name",
            $project_id
        ),
        ARRAY_A
    );

    $tree = [];

    foreach ($rows as $r) {
        $bid = (int)$r['branch_id'];

        if (!isset($tree[$bid])) {
            $tree[$bid] = [
                'branch_id'   => $bid,
                'branch_name' => $r['branch_name'] ?: '（未指定）',
                'regions'     => [],
            ];
        }

        if ($r['region'] !== '') {
            $tree[$bid]['regions'][$r['region']] ??= [
                'sub_regions' => [],
            ];

            if ($r['sub_region'] !== '') {
                $tree[$bid]['regions'][$r['region']]['sub_regions'][$r['sub_region']] ??= [
                    'groups' => [],
                ];

                if ($r['group_name'] !== '') {
                    $tree[$bid]['regions'][$r['region']]['sub_regions'][$r['sub_region']]['groups'][] =
                        $r['group_name'];
                }
            }
        }
    }

    return $tree;
}

