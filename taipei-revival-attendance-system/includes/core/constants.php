<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ------------------------------------------------------------------
 * System versions
 * ------------------------------------------------------------------
 */
define('TR_AS_DB_VERSION', '0.1.0');

/**
 * ------------------------------------------------------------------
 * Table naming
 * ------------------------------------------------------------------
 */
define('TR_AS_TABLE_PREFIX', 'tr_as_');

define('TR_AS_TABLE_SESSIONS',    TR_AS_TABLE_PREFIX . 'sessions');
define('TR_AS_TABLE_PEOPLE',      TR_AS_TABLE_PREFIX . 'people');
define('TR_AS_TABLE_ATTENDANCE',  TR_AS_TABLE_PREFIX . 'attendance');
define('TR_AS_TABLE_NEWCOMERS',   TR_AS_TABLE_PREFIX . 'newcomers');
define('TR_AS_TABLE_HEADCOUNT',   TR_AS_TABLE_PREFIX . 'headcount');

/**
 * ------------------------------------------------------------------
 * Capability naming (SSOT v1.5)
 *
 * IMPORTANT — READ CAREFULLY:
 *
 * 1) Capability *strings* are SYSTEM CONTRACTS.
 *    - They are written into WordPress roles/user_meta.
 *    - Changing them later REQUIRES a migration.
 *
 * 2) All capability strings MUST be namespaced (tr_as_*).
 *    - WordPress capabilities live in a GLOBAL namespace.
 *    - Non-namespaced caps WILL collide with other plugins sooner or later.
 *
 * 3) UI / core / auth code MUST reference ONLY these constants.
 *    - NEVER hardcode capability strings elsewhere.
 *
 * ❌ DO NOT rename these strings casually.
 * ❌ DO NOT "clean them up" for readability.
 * ❌ DO NOT add aliases or fallbacks.
 *
 * If a change is truly required, it must go through:
 * - explicit migration logic
 * - SSOT version update
 * ------------------------------------------------------------------
 */
define('TR_AS_CAP_ADMIN',   'tr_as_attendance_admin');
define('TR_AS_CAP_LEADER',  'tr_as_attendance_leader');
define('TR_AS_CAP_USHER',   'tr_as_attendance_usher');
define('TR_AS_CAP_VIEWER',  'tr_as_attendance_viewer');
