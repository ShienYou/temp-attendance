<?php
/**
 * Bible Check-in Plugin
 * Install & Upgrade Handler
 *
 * 正式版 install.php
 * - 支援多專案（Project）
 * - 建立所有核心資料表
 * - DB version 由主檔啟用流程統一控管
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

/**
 * DB Schema Version
 * 1.1.1
 * - 強制 bc_plan_days 使用 ymd + month_key
 * - 正確 UNIQUE： (project_id, ymd)
 * - 新增 bc_branches（分堂清單）
 * - bc_people 新增 branch_id
 */
define( 'BC_DB_VERSION', '1.1.1' );

/**
 * Main install function
 * - 建立 / 升級所有資料表
 */
function bc_install_tables() {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $prefix          = $wpdb->prefix;

    /*
     * 1. Projects
     */
    $table_projects = $prefix . 'bc_projects';
    $sql_projects   = "
        CREATE TABLE {$table_projects} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_key VARCHAR(50) NOT NULL,
            project_name VARCHAR(200) NOT NULL,
            church_name VARCHAR(200) NOT NULL,
            year INT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY project_key (project_key),
            KEY status (status)
        ) {$charset_collate};
    ";

    /*
     * 1.5 Branches（分堂清單）
     */
    $table_branches = $prefix . 'bc_branches';
    $sql_branches   = "
        CREATE TABLE {$table_branches} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            branch_name VARCHAR(200) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY project_id (project_id),
            KEY is_active (is_active),
            KEY sort_order (sort_order)
        ) {$charset_collate};
    ";

    /*
     * 2. People（會眾 / 參與者）
     */
    $table_people = $prefix . 'bc_people';
    $sql_people   = "
        CREATE TABLE {$table_people} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,

            branch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,

            region VARCHAR(100) NOT NULL DEFAULT '',
            sub_region VARCHAR(100) NOT NULL DEFAULT '',
            group_name VARCHAR(100) NOT NULL DEFAULT '',
            team_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            team_no VARCHAR(50) NOT NULL DEFAULT '',
            name VARCHAR(100) NOT NULL DEFAULT '',
            is_active TINYINT(1) NOT NULL DEFAULT 1,

            month_01_status TINYINT NOT NULL DEFAULT -1,
            month_02_status TINYINT NOT NULL DEFAULT -1,
            month_03_status TINYINT NOT NULL DEFAULT -1,
            month_04_status TINYINT NOT NULL DEFAULT -1,

            team_month_01_status TINYINT NOT NULL DEFAULT -1,
            team_month_02_status TINYINT NOT NULL DEFAULT -1,
            team_month_03_status TINYINT NOT NULL DEFAULT -1,
            team_month_04_status TINYINT NOT NULL DEFAULT -1,

            reward_coffee_01 TINYINT(1) NOT NULL DEFAULT 0,
            reward_coffee_02 TINYINT(1) NOT NULL DEFAULT 0,
            reward_coffee_03 TINYINT(1) NOT NULL DEFAULT 0,
            reward_meal_full_attendance TINYINT(1) NOT NULL DEFAULT 0,
            reward_april_recovery TINYINT(1) NOT NULL DEFAULT 0,

            reward_summary TEXT NULL,
            reward_issued TINYINT(1) NOT NULL DEFAULT 0,
            reward_note TEXT NULL,

            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            KEY project_id (project_id),
            KEY branch_id (branch_id),
            KEY team_id (team_id),
            KEY is_active (is_active),
            KEY group_name (group_name),
            KEY name (name)
        ) {$charset_collate};
    ";

    /*
     * 3. Teams（系統整理用 + 結算容器）
     */
    $table_teams = $prefix . 'bc_teams';
    $sql_teams   = "
        CREATE TABLE {$table_teams} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            team_no VARCHAR(50) NOT NULL,
            member_count INT NOT NULL DEFAULT 0,

            team_month_01_status TINYINT NOT NULL DEFAULT -1,
            team_month_02_status TINYINT NOT NULL DEFAULT -1,
            team_month_03_status TINYINT NOT NULL DEFAULT -1,
            team_month_04_status TINYINT NOT NULL DEFAULT -1,

            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            KEY project_id (project_id),
            KEY team_no (team_no)
        ) {$charset_collate};
    ";

    /*
     * 4. Checkins（高頻打卡紀錄）
     */
    $table_checkins = $prefix . 'bc_checkins';
    $sql_checkins   = "
        CREATE TABLE {$table_checkins} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            person_id BIGINT UNSIGNED NOT NULL,
            ymd CHAR(8) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            UNIQUE KEY uniq_person_day (project_id, person_id, ymd),
            KEY project_id (project_id),
            KEY person_id (person_id),
            KEY ymd (ymd)
        ) {$charset_collate};
    ";

    /*
     * 5. Plan days（讀經計畫）
     * ✅ 正確 UNIQUE： (project_id, ymd)
     */
    $table_plan = $prefix . 'bc_plan_days';
    $sql_plan   = "
        CREATE TABLE {$table_plan} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            ymd CHAR(8) NOT NULL,
            display_text TEXT NOT NULL,
            month_key CHAR(7) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            UNIQUE KEY uniq_project_ymd (project_id, ymd),
            KEY project_id (project_id),
            KEY month_key (month_key),
            KEY ymd (ymd)
        ) {$charset_collate};
    ";

    /*
     * 6. Settings
     */
    $table_settings = $prefix . 'bc_settings';
    $sql_settings   = "
        CREATE TABLE {$table_settings} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id BIGINT UNSIGNED NOT NULL,
            setting_key VARCHAR(100) NOT NULL,
            setting_value LONGTEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            UNIQUE KEY uniq_project_key (project_id, setting_key),
            KEY project_id (project_id),
            KEY setting_key (setting_key)
        ) {$charset_collate};
    ";

    // 建表 / 升級
    dbDelta( $sql_projects );
    dbDelta( $sql_branches );
    dbDelta( $sql_people );
    dbDelta( $sql_teams );
    dbDelta( $sql_checkins );
    dbDelta( $sql_plan );
    dbDelta( $sql_settings );

    // 寫入 DB 版本
    update_option( 'bc_db_version', BC_DB_VERSION );

    // Seed example project（保留原本邏輯）
    bc_seed_default_project();
}

/**
 * Seed default project (example)
 * - 保留你原本的 seed：trc_2026
 */
function bc_seed_default_project() {
    global $wpdb;

    $table = $wpdb->prefix . 'bc_projects';

    $exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$table} WHERE project_key = %s",
            'trc_2026'
        )
    );

    if ( ! $exists ) {
        $wpdb->insert(
            $table,
            array(
                'project_key'  => 'trc_2026',
                'project_name' => '台北復興堂 2026 讀經計畫',
                'church_name'  => '台北復興堂',
                'year'         => 2026,
                'status'       => 'active',
                'created_at'   => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%d', '%s', '%s' )
        );
    }
}

/**
 * Version check helper
 * - 由主檔啟用流程主動呼叫
 */
function bc_maybe_upgrade() {
    $installed_version = get_option( 'bc_db_version' );

    if ( $installed_version !== BC_DB_VERSION ) {
        bc_install_tables();
    }
}
