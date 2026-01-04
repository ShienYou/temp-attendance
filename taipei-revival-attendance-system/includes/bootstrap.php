<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ------------------------------------------------------------------
 * Load constants first (single source of truth)
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/core/constants.php';

/**
 * ------------------------------------------------------------------
 * Core modules (safe includes)
 *
 * SSOT v1.5 rules:
 * - core/time.php defines ALL business time keys (ISO week, cross-year).
 * - core/stats.php MAY depend on core/time.php.
 * - Therefore: time.php MUST be loaded BEFORE stats.php.
 * ------------------------------------------------------------------
 */
$core_files = array(
    'auth.php',
    'sessions.php',
    'people.php',
    'attendance.php',
    'newcomers.php',
    'headcount.php',

    // ⚠️ time MUST come before stats
    'time.php',
    'stats.php',

    'csv.php',
    'nocache.php',
);

foreach ($core_files as $file) {
    $path = __DIR__ . '/core/' . $file;
    if (file_exists($path)) {
        require_once $path;
    }
}

/**
 * ------------------------------------------------------------------
 * Admin / Frontend loaders
 * ------------------------------------------------------------------
 */
if (is_admin()) {
    require_once __DIR__ . '/admin/admin.php';
} else {
    require_once __DIR__ . '/frontend/leader.php';
    require_once __DIR__ . '/frontend/usher.php';
}

/**
 * ------------------------------------------------------------------
 * AJAX (shared entry)
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/api/ajax.php';
