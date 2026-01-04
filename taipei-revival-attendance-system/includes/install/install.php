<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Install schema
 *
 * Rules (SSOT v1.5):
 * - Schema definitions belong here.
 * - Table names / version keys must come from includes/core/constants.php
 * - No business logic here.
 * - Must be idempotent (safe to re-run).
 *
 * IMPORTANT FIX (v1.0->v1.0a):
 * - UNIQUE keys that include nullable columns are NOT reliable in MySQL
 *   (multiple NULL rows are allowed).
 * - Therefore: columns used in UNIQUE must be NOT NULL with DEFAULT ''.
 */

function tr_as_install_schema(): void {

    if (!function_exists('dbDelta')) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }

    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    // -----------------------------
    // Sessions
    // -----------------------------
    $sessions = TR_AS_TABLE_SESSIONS;
    $sql_sessions = "CREATE TABLE {$sessions} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        meeting_type VARCHAR(50) NOT NULL,
        ymd CHAR(8) NOT NULL,
        service_slot VARCHAR(50) NOT NULL DEFAULT '',
        display_text VARCHAR(50) NOT NULL DEFAULT '',
        is_open TINYINT(1) NOT NULL DEFAULT 1,
        is_editable TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_session (meeting_type, ymd, service_slot),
        KEY idx_ymd (ymd),
        KEY idx_type_slot (meeting_type, service_slot)
    ) {$charset_collate};";

    // -----------------------------
    // People
    // -----------------------------
    $people = TR_AS_TABLE_PEOPLE;
    $sql_people = "CREATE TABLE {$people} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        branch_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        region VARCHAR(100) NOT NULL DEFAULT '',
        sub_region VARCHAR(100) NOT NULL DEFAULT '',
        group_name VARCHAR(100) NOT NULL DEFAULT '',
        team_no VARCHAR(20) NOT NULL DEFAULT '',
        name VARCHAR(100) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY  (id),
        KEY idx_scope (branch_id, region(50), sub_region(50), group_name(50)),
        KEY idx_group (group_name(50)),
        KEY idx_active (is_active),
        KEY idx_name (name(50))
    ) {$charset_collate};";

    // -----------------------------
    // Attendance (per-person record)
    // -----------------------------
    $attendance = TR_AS_TABLE_ATTENDANCE;
    $sql_attendance = "CREATE TABLE {$attendance} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id BIGINT(20) UNSIGNED NOT NULL,
        person_id BIGINT(20) UNSIGNED NOT NULL,
        attend_status VARCHAR(20) NOT NULL DEFAULT 'unmarked',
        attend_mode VARCHAR(20) NULL,
        marked_by_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        marked_at DATETIME NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uniq_att (session_id, person_id),
        KEY idx_session (session_id),
        KEY idx_person (person_id),
        KEY idx_marked_by (marked_by_user_id)
    ) {$charset_collate};";

    // -----------------------------
    // Newcomers (count-only, scoped by group)
    // -----------------------------
    $newcomers = TR_AS_TABLE_NEWCOMERS;
    $sql_newcomers = "CREATE TABLE {$newcomers} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id BIGINT(20) UNSIGNED NOT NULL,

        -- group scope snapshot (SSOT v1.5: scope = group + branch/region/sub_region)
        branch_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        region VARCHAR(100) NOT NULL DEFAULT '',
        sub_region VARCHAR(100) NOT NULL DEFAULT '',
        group_name VARCHAR(100) NOT NULL DEFAULT '',
        team_no VARCHAR(20) NOT NULL DEFAULT '',

        newcomers_count INT(11) NOT NULL DEFAULT 0,
        marked_by_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        marked_at DATETIME NULL,

        PRIMARY KEY (id),
        UNIQUE KEY uniq_newcomers (session_id, branch_id, region(50), sub_region(50), group_name(50), team_no),
        KEY idx_session (session_id),
        KEY idx_group (group_name(50)),
        KEY idx_marked_by (marked_by_user_id)
    ) {$charset_collate};";

    // -----------------------------
    // Headcount (aggregate entries)
    // -----------------------------
    $headcount = TR_AS_TABLE_HEADCOUNT;
    $sql_headcount = "CREATE TABLE {$headcount} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id BIGINT(20) UNSIGNED NOT NULL,
        venue VARCHAR(120) NOT NULL DEFAULT '',
        audience VARCHAR(120) NOT NULL DEFAULT '',
        mode VARCHAR(120) NOT NULL DEFAULT '',
        headcount INT(11) NOT NULL DEFAULT 0,
        note TEXT NULL,
        reported_by_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        reported_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_session (session_id),
        KEY idx_venue (venue(60)),
        KEY idx_reported_by (reported_by_user_id)
    ) {$charset_collate};";

    dbDelta($sql_sessions);
    dbDelta($sql_people);
    dbDelta($sql_attendance);
    dbDelta($sql_newcomers);
    dbDelta($sql_headcount);

    // record schema version
    update_option('tr_as_db_version', TR_AS_DB_VERSION, true);
}
