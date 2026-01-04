<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ------------------------------------------------------------------
 * Admin Menu
 *
 * SSOT v1.5 rules:
 * - Admin entry MUST use TR_AS_CAP_ADMIN.
 * - Capability naming MUST align with TR_AS_* prefix.
 * - admin.php MUST ONLY register menu / router.
 * - NO business logic, NO queries, NO capability fallback here.
 * ------------------------------------------------------------------
 */

add_action('admin_menu', function () {

    /**
     * SYSTEM GUARANTEE:
     *
     * If TR_AS_CAP_ADMIN is missing, this is a SYSTEM ERROR,
     * NOT a UI problem.
     *
     * ❌ DO NOT add fallback capabilities here
     * ❌ DO NOT fallback to manage_options
     * ❌ DO NOT "fix" this by widening access
     *
     * The correct fix is to repair capability registration
     * (see core/auth.php), NOT to weaken the gate.
     */
    if (!defined('TR_AS_CAP_ADMIN')) {
        return;
    }

    add_menu_page(
        'TR Attendance',
        'TR Attendance',
        TR_AS_CAP_ADMIN,
        'tr-attendance',
        function () {
            echo '<div class="wrap">';
            echo '<h1>TR Attendance</h1>';
            echo '<p>Skeleton active.</p>';
            echo '</div>';
        }
    );
});
