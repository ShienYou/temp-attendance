<?php
/**
 * Bible Check-in Plugin
 * CSV Helper
 *
 * 職責：
 * - 處理人員 CSV 匯出 / 匯入
 * - 不碰 UI
 * - 不直接做權限判斷（交給 AJAX）
 * - 不直接 echo / output
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* =====================================================
 * CSV Export
 * ===================================================== */

/**
 * 匯出人員 CSV（Excel 直接可開）
 *
 * @param int $project_id
 * @return string CSV 內容（UTF-8 with BOM）
 */
function bc_csv_export_people( $project_id ) {
    global $wpdb;

    $project_id = (int) $project_id;
    if ( $project_id <= 0 ) {
        return '';
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
               ON b.id = p.branch_id AND b.project_id = p.project_id
             WHERE p.project_id = %d
             ORDER BY p.group_name, p.team_no, p.name",
            $project_id
        ),
        ARRAY_A
    );

    $out = fopen( 'php://temp', 'r+' );

    // ✅ Excel 中文關鍵：UTF-8 BOM
    fwrite( $out, "\xEF\xBB\xBF" );

    fputcsv( $out, [
        'name',
        'branch',
        'region',
        'sub_region',
        'group_name',
        'team_no',
        'is_active',
    ] );

    foreach ( $rows as $r ) {
        fputcsv( $out, [
            $r['name'],
            $r['branch'],
            $r['region'],
            $r['sub_region'],
            $r['group_name'],
            $r['team_no'],
            (int) $r['is_active'],
        ] );
    }

    rewind( $out );
    return stream_get_contents( $out );
}

/* =====================================================
 * CSV Import
 * ===================================================== */

/**
 * 匯入人員 CSV（最終穩定版）
 *
 * 規則：
 * - 不依賴欄位順序（用 header map）
 * - 同名 + 同 group_name → duplicate（略過）
 * - CSV 中的新分堂 → 自動建立
 * - 匯入不中斷
 * - 只回傳數量摘要
 */
function bc_csv_import_people( $project_id, $csv_file_path ) {
    global $wpdb;

    $summary = [
        'inserted'         => 0,
        'duplicates'       => 0,
        'branches_created' => 0,
        'skipped'          => 0,
    ];

    $project_id = (int) $project_id;
    if ( $project_id <= 0 ) {
        return $summary;
    }

    if ( ! file_exists( $csv_file_path ) || ! is_readable( $csv_file_path ) ) {
        return $summary;
    }

    $fh = fopen( $csv_file_path, 'r' );
    if ( ! $fh ) {
        return $summary;
    }

    /* ================= Header ================= */

    $header = fgetcsv( $fh );
    if ( ! is_array( $header ) ) {
        fclose( $fh );
        return $summary;
    }

    // normalize header（trim + 去 BOM）
    $header = array_map( function ( $h ) {
        $h = trim( (string) $h );
        return preg_replace( '/^\xEF\xBB\xBF/', '', $h );
    }, $header);

    $required = [
        'name',
        'branch',
        'region',
        'sub_region',
        'group_name',
        'team_no',
        'is_active',
    ];

    // header → index map
    $header_map = array_flip( $header );

    // 檢查必要欄位是否存在
    foreach ( $required as $col ) {
        if ( ! isset( $header_map[ $col ] ) ) {
            fclose( $fh );
            return $summary; // 缺欄位直接整批不處理
        }
    }

    /* ================= Branch cache ================= */

    $branch_map = [];
    $branches   = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, branch_name
             FROM " . bc_table_branches() . "
             WHERE project_id = %d",
            $project_id
        ),
        ARRAY_A
    );

    foreach ( $branches as $b ) {
        $key = bc_csv_normalize_text( $b['branch_name'] );
        $branch_map[ $key ] = (int) $b['id'];
    }

    /* ================= Rows ================= */

    while ( ( $row = fgetcsv( $fh ) ) !== false ) {

        // 防呆：空行
        if ( ! is_array( $row ) || count( $row ) === 0 ) {
            continue;
        }

        // 用 header map 組 data（不靠順序）
        $data = [];
        foreach ( $required as $key ) {
            $idx = $header_map[ $key ];
            $data[ $key ] = isset( $row[ $idx ] )
                ? trim( (string) $row[ $idx ] )
                : '';
        }

        // 必填：name
        if ( $data['name'] === '' ) {
            $summary['skipped']++;
            continue;
        }

        /* ---------- branch resolve / auto create ---------- */

        $branch_id = 0;
        if ( $data['branch'] !== '' ) {
            $branch_key = bc_csv_normalize_text( $data['branch'] );

            if ( isset( $branch_map[ $branch_key ] ) ) {
                $branch_id = $branch_map[ $branch_key ];
            } else {
                $new_id = bc_insert_branch(
                    $data['branch'],
                    0,
                    $project_id
                );

                if ( $new_id ) {
                    $branch_id = (int) $new_id;
                    $branch_map[ $branch_key ] = $branch_id;
                    $summary['branches_created']++;
                }
            }
        }

        /* ---------- duplicate check ---------- */

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id
                 FROM " . bc_table_people() . "
                 WHERE project_id = %d
                   AND name = %s
                   AND group_name = %s
                 LIMIT 1",
                $project_id,
                $data['name'],
                $data['group_name']
            )
        );

        if ( $exists ) {
            $summary['duplicates']++;
            continue;
        }

        /* ---------- insert ---------- */

        $ok = $wpdb->insert(
            bc_table_people(),
            [
                'project_id' => $project_id,
                'branch_id'  => $branch_id,
                'region'     => $data['region'],
                'sub_region' => $data['sub_region'],
                'group_name' => $data['group_name'],
                'team_id'    => 0,
                'team_no'    => $data['team_no'],
                'name'       => $data['name'],
                'is_active'  => ! empty( $data['is_active'] ) ? 1 : 0,
                'created_at' => current_time( 'mysql' ),
            ],
            [
                '%d','%d','%s','%s','%s','%d','%s','%s','%d','%s'
            ]
        );

        if ( $ok !== false ) {
            $summary['inserted']++;
        } else {
            $summary['skipped']++;
        }
    }

    fclose( $fh );
    return $summary;
}

/* =====================================================
 * Helpers
 * ===================================================== */

/**
 * Normalize text for comparison (branch name etc.)
 *
 * @param string $text
 * @return string
 */
function bc_csv_normalize_text( $text ) {
    $text = trim( (string) $text );
    $text = preg_replace( '/\s+/u', ' ', $text );
    return mb_strtolower( $text, 'UTF-8' );
}
