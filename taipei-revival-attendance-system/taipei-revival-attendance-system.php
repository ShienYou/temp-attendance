<?php
/**
 * Plugin Name: Taipei Revival — Attendance System
 * Description: Attendance system core skeleton (safe activation).
 * Version: 0.1.0
 * Author: Taipei Revival
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ------------------------------------------------------------------
 * Basic constants
 * ------------------------------------------------------------------
 */
define('TR_AS_PATH', plugin_dir_path(__FILE__));
define('TR_AS_URL', plugin_dir_url(__FILE__));

/**
 * ------------------------------------------------------------------
 * Bootstrap
 *
 * SSOT v1.5:
 * - All core / caps / constants MUST be loaded before activation logic.
 * ------------------------------------------------------------------
 */
require_once TR_AS_PATH . 'includes/bootstrap.php';

/**
 * ------------------------------------------------------------------
 * Install loader (schema only)
 *
 * NOTE:
 * - install.php contains schema definitions ONLY.
 * - No business logic allowed there.
 * ------------------------------------------------------------------
 */
require_once TR_AS_PATH . 'includes/install/install.php';

/**
 * ------------------------------------------------------------------
 * Activation hook (FINAL, v1.x)
 *
 * SSOT v1.5 (FINAL DECISION):
 * - Activation hook is the SINGLE stable entry point.
 * - v1.x MUST create / upgrade schema here (idempotent).
 * - Caps MUST be registered here to avoid admin lockout.
 * ------------------------------------------------------------------
 */
register_activation_hook(__FILE__, 'tr_as_activate');

function tr_as_activate() {

    // 1) Ensure capabilities exist (safe to re-run)
    if (function_exists('tr_as_register_caps')) {
        tr_as_register_caps();
    }

    // 2) Ensure database schema exists / upgraded (idempotent)
    if (function_exists('tr_as_install_schema')) {
        tr_as_install_schema();
    }
}

/**
 * ------------------------------------------------------------------
 * Safety net:
 * Ensure caps stay in sync (e.g. after role changes / new admins)
 *
 * - This does NOT create tables.
 * - This only ensures capability consistency.
 * ------------------------------------------------------------------
 */
add_action('admin_init', function () {
    if (function_exists('tr_as_register_caps')) {
        tr_as_register_caps();
    }
});
