<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ------------------------------------------------------------------
 * People Core Service
 *
 * Responsibility (SSOT v1.5):
 * - People listing & retrieval
 * - Leader-scoped people listing
 * - Admin write operations (create/update/activate/move)
 * - CSV import / export (summary only)
 *
 * Boundary:
 * - NO UI rendering
 * - NO attendance logic
 * - NO statistics
 * - NO direct $wpdb usage (must go through includes/utils/db.php)
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../utils/db.php';
require_once __DIR__ . '/auth.php';

/**
 * ------------------------------------------------------------------
 * Internal: build WHERE clause from filters
 * ------------------------------------------------------------------
 */
function tr_as_people_build_where(array $filters, array &$args): string {

    $where = [];

    if (isset($filters['person_id'])) {
        $where[] = 'id = %d';
        $args[]  = (int) $filters['person_id'];
    }

    if (!empty($filters['person_ids']) && is_array($filters['person_ids'])) {
        $ids = array_values(array_filter(array_map('intval', $filters['person_ids']), function ($v) {
            return $v > 0;
        }));

        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $where[] = "id IN ({$placeholders})";
            foreach ($ids as $id) {
                $args[] = $id;
            }
        }
    }

    if (isset($filters['branch_id']) && $filters['branch_id'] !== '' && $filters['branch_id'] !== null) {
        $where[] = 'branch_id = %d';
        $args[]  = (int) $filters['branch_id'];
    }

    if (array_key_exists('region', $filters) && $filters['region'] !== '' && $filters['region'] !== null) {
        $where[] = 'region = %s';
        $args[]  = (string) $filters['region'];
    }

    if (array_key_exists('sub_region', $filters) && $filters['sub_region'] !== '' && $filters['sub_region'] !== null) {
        $where[] = 'sub_region = %s';
        $args[]  = (string) $filters['sub_region'];
    }

    if (array_key_exists('group_name', $filters) && $filters['group_name'] !== '' && $filters['group_name'] !== null) {
        $where[] = 'group_name = %s';
        $args[]  = (string) $filters['group_name'];
    }

    if (array_key_exists('team_no', $filters) && $filters['team_no'] !== '' && $filters['team_no'] !== null) {
        $where[] = 'team_no = %s';
        $args[]  = (string) $filters['team_no'];
    }

    if (isset($filters['is_active'])) {
        $where[] = 'is_active = %d';
        $args[]  = $filters['is_active'] ? 1 : 0;
    }

    if (!empty($filters['q'])) {
        $q = trim((string) $filters['q']);
        if ($q !== '') {
            $where[] = 'name LIKE %s';
            $args[]  = '%' . $q . '%';
        }
    }

    if (!$where) {
        return '';
    }

    return ' WHERE ' . implode(' AND ', $where);
}

/**
 * ------------------------------------------------------------------
 * List people (admin / viewer)
 * ------------------------------------------------------------------
 */
function tr_as_people_list(array $args = []): array {

    $table = TR_AS_TABLE_PEOPLE;

    $filters = isset($args['filters']) && is_array($args['filters']) ? $args['filters'] : [];

    $orderby = isset($args['orderby']) ? (string) $args['orderby'] : 'group_name';
    $order   = isset($args['order']) ? strtoupper((string) $args['order']) : 'ASC';

    $allowed_orderby = [
        'id'         => 'id',
        'name'       => 'name',
        'branch_id'  => 'branch_id',
        'region'     => 'region',
        'sub_region' => 'sub_region',
        'group_name' => 'group_name',
        'team_no'    => 'team_no',
        'is_active'  => 'is_active',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
    ];

    $orderby_sql = $allowed_orderby[$orderby] ?? 'group_name';
    $order_sql   = in_array($order, ['ASC', 'DESC'], true) ? $order : 'ASC';

    $limit  = isset($args['limit']) ? (int) $args['limit'] : 0;
    $offset = isset($args['offset']) ? (int) $args['offset'] : 0;

    if ($limit < 0) $limit = 0;
    if ($offset < 0) $offset = 0;

    $sql_args = [];
    $where_sql = tr_as_people_build_where($filters, $sql_args);

    $sql = "SELECT
                id AS person_id,
                branch_id,
                region,
                sub_region,
                group_name,
                team_no,
                name,
                is_active,
                created_at,
                updated_at
            FROM {$table}
            {$where_sql}
            ORDER BY {$orderby_sql} {$order_sql}, id ASC";

    if ($limit > 0) {
        // LIMIT / OFFSET must be integers; embed safely after casting.
        $sql .= ' LIMIT ' . (int) $limit;
        if ($offset > 0) {
            $sql .= ' OFFSET ' . (int) $offset;
        }
    }

    $sql = tr_as_db_prepare($sql, $sql_args);
    return tr_as_db_get_results($sql, ARRAY_A);
}

/**
 * ------------------------------------------------------------------
 * Count people (for pagination)
 * ------------------------------------------------------------------
 */
function tr_as_people_count(array $args = []): int {

    $table = TR_AS_TABLE_PEOPLE;
    $filters = isset($args['filters']) && is_array($args['filters']) ? $args['filters'] : [];

    $sql_args = [];
    $where_sql = tr_as_people_build_where($filters, $sql_args);

    $sql = "SELECT COUNT(1) FROM {$table}{$where_sql}";
    $sql = tr_as_db_prepare($sql, $sql_args);

    return (int) tr_as_db_get_var($sql);
}

/**
 * ------------------------------------------------------------------
 * Get single person
 * ------------------------------------------------------------------
 */
function tr_as_people_get(int $person_id): array {

    $table = TR_AS_TABLE_PEOPLE;

    $sql = tr_as_db_prepare(
        "SELECT
            id AS person_id,
            branch_id,
            region,
            sub_region,
            group_name,
            team_no,
            name,
            is_active,
            created_at,
            updated_at
         FROM {$table}
         WHERE id = %d",
        [$person_id]
    );

    $row = tr_as_db_get_row($sql, ARRAY_A);

    if (!$row) {
        throw new WP_Error('tr_as_person_not_found', 'Person not found.');
    }

    return $row;
}

/**
 * ------------------------------------------------------------------
 * Leader: list people in leader scope
 *
 * NOTE:
 * - v1.x leader scope is group-based and read from user_meta (auth.php).
 * - This function ONLY applies the scope snapshot to People table.
 * ------------------------------------------------------------------
 */
function tr_as_people_list_for_leader(int $user_id, array $args = []): array {

    tr_as_auth_assert_capability($user_id, TR_AS_CAP_LEADER);

    $scope = tr_as_auth_get_user_scope($user_id);

    if (empty($scope['leader']) || empty($scope['leader']['group_name'])) {
        throw new WP_Error('tr_as_no_leader_scope', 'Leader scope not properly assigned.');
    }

    $filters = isset($args['filters']) && is_array($args['filters']) ? $args['filters'] : [];
    $filters = array_merge($filters, [
        'branch_id'  => (int) ($scope['leader']['branch_id'] ?? 0),
        'region'     => (string) ($scope['leader']['region'] ?? ''),
        'sub_region' => (string) ($scope['leader']['sub_region'] ?? ''),
        'group_name' => (string) ($scope['leader']['group_name'] ?? ''),
    ]);

    // team_no is optional in scope
    if (!empty($scope['leader']['team_no'])) {
        $filters['team_no'] = (string) $scope['leader']['team_no'];
    }

    // Default: only active people for leader
    if (!isset($filters['is_active'])) {
        $filters['is_active'] = true;
    }

    $args['filters'] = $filters;

    // Default ordering for leader
    if (empty($args['orderby'])) $args['orderby'] = 'name';
    if (empty($args['order']))   $args['order']   = 'ASC';

    return tr_as_people_list($args);
}

/**
 * ------------------------------------------------------------------
 * Admin: create person
 * ------------------------------------------------------------------
 */
function tr_as_people_create(int $user_id, array $data): int {

    tr_as_auth_assert_admin_only($user_id);

    $table = TR_AS_TABLE_PEOPLE;

    $name = isset($data['name']) ? trim((string) $data['name']) : '';
    if ($name === '') {
        throw new WP_Error('tr_as_invalid_person', 'Name is required.');
    }

    $branch_id  = isset($data['branch_id']) ? (int) $data['branch_id'] : 0;
    $region     = isset($data['region']) ? (string) $data['region'] : '';
    $sub_region = isset($data['sub_region']) ? (string) $data['sub_region'] : '';
    $group_name = isset($data['group_name']) ? (string) $data['group_name'] : '';
    $team_no    = isset($data['team_no']) ? (string) $data['team_no'] : '';
    $is_active  = isset($data['is_active']) ? (int) !!$data['is_active'] : 1;

    // Duplicate rule (v1.x safety): same name + same full scope -> duplicate
    $dup_sql = tr_as_db_prepare(
        "SELECT id
         FROM {$table}
         WHERE name = %s
           AND branch_id = %d
           AND region = %s
           AND sub_region = %s
           AND group_name = %s
           AND IFNULL(team_no, '') = %s
         LIMIT 1",
        [$name, $branch_id, $region, $sub_region, $group_name, $team_no]
    );

    $exists_id = (int) tr_as_db_get_var($dup_sql);
    if ($exists_id > 0) {
        throw new WP_Error('tr_as_duplicate_person', 'Duplicate person (same name + same scope).');
    }

    $sql = tr_as_db_prepare(
        "INSERT INTO {$table}
         (branch_id, region, sub_region, group_name, team_no, name, is_active, created_at)
         VALUES (%d, %s, %s, %s, %s, %s, %d, %s)",
        [
            $branch_id,
            $region,
            $sub_region,
            $group_name,
            $team_no,
            $name,
            $is_active,
            current_time('mysql'),
        ]
    );

    $ok = tr_as_db_execute($sql);
    if ($ok === false) {
        throw new WP_Error('tr_as_db_error', 'Failed to create person.');
    }

    // Get inserted ID
    $id_sql = tr_as_db_prepare("SELECT LAST_INSERT_ID()");
    return (int) tr_as_db_get_var($id_sql);
}

/**
 * ------------------------------------------------------------------
 * Admin: update person fields (patch)
 *
 * Allowed fields:
 * - branch_id, region, sub_region, group_name, team_no, name, is_active
 * ------------------------------------------------------------------
 */
function tr_as_people_update(int $user_id, int $person_id, array $patch): void {

    tr_as_auth_assert_admin_only($user_id);

    $table = TR_AS_TABLE_PEOPLE;

    $allowed = [
        'branch_id'  => '%d',
        'region'     => '%s',
        'sub_region' => '%s',
        'group_name' => '%s',
        'team_no'    => '%s',
        'name'       => '%s',
        'is_active'  => '%d',
    ];

    $sets = [];
    $args = [];

    foreach ($allowed as $key => $fmt) {
        if (!array_key_exists($key, $patch)) {
            continue;
        }

        if ($key === 'branch_id' || $key === 'is_active') {
            $val = (int) $patch[$key];
            if ($key === 'is_active') $val = $val ? 1 : 0;
        } else {
            $val = trim((string) $patch[$key]);
        }

        if ($key === 'name' && $val === '') {
            throw new WP_Error('tr_as_invalid_person', 'Name is required.');
        }

        $sets[] = "{$key} = {$fmt}";
        $args[] = $val;
    }

    if (!$sets) {
        return;
    }

    // Always update updated_at when patch applies
    $sets[] = "updated_at = %s";
    $args[] = current_time('mysql');

    $args[] = $person_id;

    $sql = tr_as_db_prepare(
        "UPDATE {$table}
         SET " . implode(', ', $sets) . "
         WHERE id = %d",
        $args
    );

    tr_as_db_execute($sql);
}

/**
 * ------------------------------------------------------------------
 * Admin: toggle active flag
 * ------------------------------------------------------------------
 */
function tr_as_people_set_active(int $user_id, int $person_id, bool $is_active): void {

    tr_as_people_update($user_id, $person_id, [
        'is_active' => $is_active ? 1 : 0,
    ]);
}

/**
 * ------------------------------------------------------------------
 * Admin: move people to a new scope (bulk)
 *
 * Scope keys:
 * - branch_id, region, sub_region, group_name, team_no
 * ------------------------------------------------------------------
 */
function tr_as_people_move_scope(int $user_id, array $person_ids, array $new_scope): array {

    tr_as_auth_assert_admin_only($user_id);

    $ids = array_values(array_filter(array_map('intval', $person_ids), function ($v) {
        return $v > 0;
    }));

    if (!$ids) {
        return ['moved' => 0, 'skipped' => 0];
    }

    $patch = [];

    foreach (['branch_id', 'region', 'sub_region', 'group_name', 'team_no'] as $k) {
        if (array_key_exists($k, $new_scope)) {
            $patch[$k] = $new_scope[$k];
        }
    }

    if (!$patch) {
        return ['moved' => 0, 'skipped' => count($ids)];
    }

    $moved = 0;

    tr_as_db_transaction(function () use ($user_id, $ids, $patch, &$moved) {

        foreach ($ids as $id) {
            try {
                tr_as_people_update($user_id, (int) $id, $patch);
                $moved++;
            } catch (Throwable $e) {
                // Keep moving others; do not fail whole transaction for a single bad row.
                // (This is a deliberate v1.x tradeoff for admin bulk operations.)
            }
        }

        return true;
    });

    return ['moved' => $moved, 'skipped' => max(0, count($ids) - $moved)];
}

/**
 * ------------------------------------------------------------------
 * CSV: export people (UTF-8 BOM)
 *
 * Export columns (stable):
 * - name, branch_id, region, sub_region, group_name, team_no, is_active
 * ------------------------------------------------------------------
 */
function tr_as_people_csv_export(int $user_id, array $filters = []): string {

    // Admin + viewer can export. (Admins already pass via manage_options.)
    tr_as_auth_assert_capability($user_id, TR_AS_CAP_VIEWER);

    $rows = tr_as_people_list([
        'filters' => $filters,
        'orderby' => 'group_name',
        'order'   => 'ASC',
        'limit'   => 0,
    ]);

    $out = fopen('php://temp', 'r+');

    // Excel-friendly UTF-8 BOM
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, [
        'name',
        'branch_id',
        'region',
        'sub_region',
        'group_name',
        'team_no',
        'is_active',
    ]);

    foreach ($rows as $r) {
        fputcsv($out, [
            (string) ($r['name'] ?? ''),
            (int) ($r['branch_id'] ?? 0),
            (string) ($r['region'] ?? ''),
            (string) ($r['sub_region'] ?? ''),
            (string) ($r['group_name'] ?? ''),
            (string) ($r['team_no'] ?? ''),
            (int) ($r['is_active'] ?? 0),
        ]);
    }

    rewind($out);
    return (string) stream_get_contents($out);
}

/**
 * ------------------------------------------------------------------
 * CSV: import people (summary only)
 *
 * Rules (v1.x safe default):
 * - Header map (not column order)
 * - Required columns must exist, else whole import is skipped
 * - Duplicate rule: same name + same full scope -> duplicate (skip)
 * - Import does NOT delete
 * ------------------------------------------------------------------
 */
function tr_as_people_csv_import(int $user_id, string $csv_file_path): array {

    tr_as_auth_assert_admin_only($user_id);

    $summary = [
        'inserted'   => 0,
        'duplicates' => 0,
        'skipped'    => 0,
    ];

    if (!file_exists($csv_file_path) || !is_readable($csv_file_path)) {
        return $summary;
    }

    $fh = fopen($csv_file_path, 'r');
    if (!$fh) {
        return $summary;
    }

    $header = fgetcsv($fh);
    if (!is_array($header)) {
        fclose($fh);
        return $summary;
    }

    // normalize header (trim + remove BOM)
    $header = array_map(function ($h) {
        $h = trim((string) $h);
        return preg_replace('/^\xEF\xBB\xBF/', '', $h);
    }, $header);

    $required = [
        'name',
        'branch_id',
        'region',
        'sub_region',
        'group_name',
        'team_no',
        'is_active',
    ];

    $header_map = array_flip($header);

    foreach ($required as $col) {
        if (!isset($header_map[$col])) {
            fclose($fh);
            return $summary; // missing required -> skip whole import
        }
    }

    $table = TR_AS_TABLE_PEOPLE;

    while (($row = fgetcsv($fh)) !== false) {

        if (!is_array($row) || count($row) === 0) {
            continue;
        }

        $data = [];
        foreach ($required as $key) {
            $idx = $header_map[$key];
            $data[$key] = isset($row[$idx]) ? trim((string) $row[$idx]) : '';
        }

        // Required: name
        if ($data['name'] === '') {
            $summary['skipped']++;
            continue;
        }

        $name       = $data['name'];
        $branch_id  = (int) $data['branch_id'];
        $region     = (string) $data['region'];
        $sub_region = (string) $data['sub_region'];
        $group_name = (string) $data['group_name'];
        $team_no    = (string) $data['team_no'];
        $is_active  = !empty($data['is_active']) ? 1 : 0;

        // Duplicate check
        $exists_sql = tr_as_db_prepare(
            "SELECT id
             FROM {$table}
             WHERE name = %s
               AND branch_id = %d
               AND region = %s
               AND sub_region = %s
               AND group_name = %s
               AND IFNULL(team_no, '') = %s
             LIMIT 1",
            [$name, $branch_id, $region, $sub_region, $group_name, $team_no]
        );

        $exists_id = (int) tr_as_db_get_var($exists_sql);

        if ($exists_id > 0) {
            $summary['duplicates']++;
            continue;
        }

        // Insert
        $insert_sql = tr_as_db_prepare(
            "INSERT INTO {$table}
             (branch_id, region, sub_region, group_name, team_no, name, is_active, created_at)
             VALUES (%d, %s, %s, %s, %s, %s, %d, %s)",
            [$branch_id, $region, $sub_region, $group_name, $team_no, $name, $is_active, current_time('mysql')]
        );

        $ok = tr_as_db_execute($insert_sql);

        if ($ok === false) {
            $summary['skipped']++;
        } else {
            $summary['inserted']++;
        }
    }

    fclose($fh);
    return $summary;
}
