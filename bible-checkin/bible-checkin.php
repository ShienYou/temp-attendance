<?php
/**
 * Plugin Name: Bible Check-in System
 * Description: 線上讀經打卡系統（前台無登入可代打、後台管理、結算與獎勵計算）
 * Version: 1.0.0
 * Author: Shien’s Insight Lab
 * Company: 沃酷有限公司（World Cool Co., Ltd.）
 * License: GPLv2 or later
 * Text Domain: bible-checkin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * =========================
 * Constants
 * =========================
 */
define( 'BC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * =========================
 * Church Staff Accounts (hard-coded per Shien request)
 * =========================
 * - church-admin  : full access
 * - church-viewer : read-only access
 */
define( 'BC_CHURCH_ADMIN_LOGIN',  'church-admin' );
define( 'BC_CHURCH_VIEWER_LOGIN', 'church-viewer' );

/**
 * =========================
 * Capabilities
 * =========================
 * bible_checkin_access : can enter Bible Check-in admin
 * bible_checkin_manage : full admin inside plugin
 * bible_checkin_view   : read-only inside plugin
 */
function bc_register_capabilities() {
    $role = get_role( 'administrator' );
    if ( $role ) {
        if ( ! $role->has_cap( 'bible_checkin_access' ) ) $role->add_cap( 'bible_checkin_access' );
        if ( ! $role->has_cap( 'bible_checkin_manage' ) ) $role->add_cap( 'bible_checkin_manage' );
        // admins don't strictly need view, but harmless
        if ( ! $role->has_cap( 'bible_checkin_view' ) )   $role->add_cap( 'bible_checkin_view' );
    }
}

function bc_assign_caps_to_user_login( $login, array $caps ) {
    $user = get_user_by( 'login', (string)$login );
    if ( ! $user || ! ( $user instanceof WP_User ) ) return;

    foreach ( $caps as $cap ) {
        if ( ! $user->has_cap( $cap ) ) {
            $user->add_cap( $cap );
        }
    }
}

/**
 * Ensure the two church accounts always have the right caps.
 * - Runs on activation
 * - Also runs on admin_init so you don't need to deactivate/reactivate after creating users
 */
function bc_sync_church_accounts_caps() {
    // Full access account
    bc_assign_caps_to_user_login( BC_CHURCH_ADMIN_LOGIN, [
        'bible_checkin_access',
        'bible_checkin_manage',
    ] );

    // Read-only account
    bc_assign_caps_to_user_login( BC_CHURCH_VIEWER_LOGIN, [
        'bible_checkin_access',
        'bible_checkin_view',
    ] );
}

function bc_is_church_staff_account() : bool {
    if ( ! is_user_logged_in() ) return false;
    if ( current_user_can( 'manage_options' ) ) return false; // site admins unaffected
    return ( current_user_can( 'bible_checkin_access' ) || current_user_can( 'bible_checkin_manage' ) || current_user_can( 'bible_checkin_view' ) );
}

function bc_church_staff_role() : string {
    if ( current_user_can( 'manage_options' ) ) return 'site_admin';
    if ( current_user_can( 'bible_checkin_manage' ) ) return 'checkin_admin';
    if ( current_user_can( 'bible_checkin_view' ) ) return 'checkin_viewer';
    if ( current_user_can( 'bible_checkin_access' ) ) return 'checkin_access';
    return 'none';
}

/**
 * =========================
 * Install / Upgrade
 * =========================
 */
require_once BC_PLUGIN_DIR . 'includes/install.php';

/**
 * =========================
 * Activation Hook
 * =========================
 */
function bc_on_activation() {

    bc_register_capabilities();

    if ( function_exists( 'bc_maybe_upgrade' ) ) {
        bc_maybe_upgrade();
    }

    // Assign caps to the two church accounts (if they exist already)
    bc_sync_church_accounts_caps();
}
register_activation_hook( __FILE__, 'bc_on_activation' );

/**
 * Keep caps synced without requiring reactivation.
 * Only runs in admin to avoid front-end overhead.
 */
add_action( 'admin_init', function () {
    bc_register_capabilities();
    bc_sync_church_accounts_caps();
}, 5 );

/**
 * =========================
 * Core DB Layer
 * =========================
 */
require_once BC_PLUGIN_DIR . 'includes/db.php';

/**
 * =========================
 * CSV Helper（必須全域載入）
 * =========================
 */
require_once BC_PLUGIN_DIR . 'includes/csv.php';

/**
 * =========================
 * AJAX Handlers（必須全域載入）
 * =========================
 */
require_once BC_PLUGIN_DIR . 'includes/ajax.php';
require_once BC_PLUGIN_DIR . 'frontend/ajax.php';

/**
 * =========================
 * Admin / Frontend bootstrap
 * =========================
 */
add_action( 'plugins_loaded', function () {

    if ( is_admin() ) {
        require_once BC_PLUGIN_DIR . 'admin/admin.php';
    } else {
        require_once BC_PLUGIN_DIR . 'frontend/frontend.php';
    }

} );

/**
 * =========================
 * ✅ Admin Lockdown for church staff accounts
 * =========================
 * Goal:
 * - Church staff see ONLY "讀經打卡" menu (plus WP top bar)
 * - Direct URL access to other wp-admin pages redirects back
 * - Administrators (manage_options) are NOT affected
 */

/**
 * Hide other wp-admin menus for church staff accounts.
 * Note: We do NOT remove the "讀經打卡" menu.
 */
add_action( 'admin_menu', function () {

    if ( ! bc_is_church_staff_account() ) return;

    // Keep profile hidden from menu; they can still access via direct URL if needed.
    // Remove everything else.
    $remove = [
        'index.php',                 // Dashboard
        'edit.php',                  // Posts
        'upload.php',                // Media
        'edit.php?post_type=page',   // Pages
        'edit-comments.php',         // Comments
        'themes.php',                // Appearance
        'plugins.php',               // Plugins
        'users.php',                 // Users
        'tools.php',                 // Tools
        'options-general.php',       // Settings
        'profile.php',               // Profile (hide)
    ];

    foreach ( $remove as $slug ) {
        remove_menu_page( $slug );
    }

    // Common plugin menus (best-effort)
    remove_menu_page( 'woocommerce' );
    remove_menu_page( 'edit.php?post_type=product' );
    remove_menu_page( 'wc-admin' );

}, 999 );

/**
 * Redirect church staff away from other wp-admin pages to our plugin page.
 * Allowlist:
 * - Our plugin page (admin.php?page=bible-checkin)
 * - admin-ajax.php / admin-post.php
 * - async-upload.php (media upload endpoint; generally safe)
 * - profile.php (optional; still accessible even if menu hidden)
 */
add_action( 'admin_init', function () {

    if ( ! bc_is_church_staff_account() ) return;

    // Always allow Ajax / admin-post
    $pagenow = isset($GLOBALS['pagenow']) ? (string)$GLOBALS['pagenow'] : '';
    if ( $pagenow === 'admin-ajax.php' || $pagenow === 'admin-post.php' || $pagenow === 'async-upload.php' ) {
        return;
    }

    // Allow profile page (even though menu hidden)
    if ( $pagenow === 'profile.php' ) {
        return;
    }

    // Allow our plugin page
    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    if ( $pagenow === 'admin.php' && $page === 'bible-checkin' ) {
        return;
    }

    // Anything else -> redirect
    wp_safe_redirect( admin_url( 'admin.php?page=bible-checkin' ) );
    exit;

}, 20 );

/**
 * After login, send church staff directly to Bible Check-in admin.
 */
add_filter( 'login_redirect', function ( $redirect_to, $requested, $user ) {

    if ( ! $user || ! ( $user instanceof WP_User ) ) return $redirect_to;

    if ( $user->has_cap( 'manage_options' ) ) return $redirect_to;

    if ( $user->has_cap( 'bible_checkin_access' ) || $user->has_cap( 'bible_checkin_manage' ) || $user->has_cap( 'bible_checkin_view' ) ) {
        return admin_url( 'admin.php?page=bible-checkin' );
    }

    return $redirect_to;

}, 10, 3 );

/**
 * =========================
 * 判斷是否為打卡頁（含 [bible_checkin] shortcode）
 * =========================
 */
function bc_page_has_bible_checkin_shortcode() {
    if ( is_admin() ) return false;
    if ( ! is_singular() ) return false;

    $post = get_post();
    if ( ! $post ) return false;

    return ( is_string( $post->post_content ) && has_shortcode( $post->post_content, 'bible_checkin' ) );
}

/**
 * =========================
 * Disable cache on pages that contain [bible_checkin]
 * =========================
 */
function bc_send_strict_nocache_headers() {
    nocache_headers();

    if ( ! headers_sent() ) {
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        header( 'Cache-Control: post-check=0, pre-check=0', false );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
        header( 'X-BC-NOCACHE: 1' );
    }
}

add_action( 'template_redirect', function () {

    if ( bc_page_has_bible_checkin_shortcode() ) {

        if ( ! defined( 'DONOTCACHEPAGE' ) )   define( 'DONOTCACHEPAGE', true );
        if ( ! defined( 'DONOTCACHEOBJECT' ) ) define( 'DONOTCACHEOBJECT', true );
        if ( ! defined( 'DONOTCACHEDB' ) )     define( 'DONOTCACHEDB', true );

        bc_send_strict_nocache_headers();
    }

}, 0 );

/**
 * =========================
 * ✅ Round 6.5：穩定補上 body_class
 * =========================
 */
add_filter( 'body_class', function ( $classes ) {

    if ( bc_page_has_bible_checkin_shortcode() ) {
        $classes[] = 'bc-checkin-page';
    }

    return $classes;

} );

/**
 * =========================
 * Frontend Assets（Cache-Busted）
 * =========================
 */
add_action( 'wp_enqueue_scripts', function () {

    if ( is_admin() ) {
        return;
    }

    if ( ! bc_page_has_bible_checkin_shortcode() ) {
        return;
    }

    $frontend_js_path = BC_PLUGIN_DIR . 'frontend/frontend.js';
    $frontend_js_ver  = file_exists( $frontend_js_path )
        ? filemtime( $frontend_js_path )
        : time();

    wp_enqueue_script(
        'bc-frontend',
        BC_PLUGIN_URL . 'frontend/frontend.js',
        [],
        $frontend_js_ver,
        true
    );

    wp_localize_script( 'bc-frontend', 'bc_ajax', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'build'    => $frontend_js_ver,
    ] );

    $frontend_css_path = BC_PLUGIN_DIR . 'frontend/frontend.css';
    if ( file_exists( $frontend_css_path ) ) {
        $frontend_css_ver = filemtime( $frontend_css_path );

        wp_enqueue_style(
            'bc-frontend',
            BC_PLUGIN_URL . 'frontend/frontend.css',
            [],
            $frontend_css_ver
        );
    }

}, 20 );
