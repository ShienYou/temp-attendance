<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ------------------------------------------------------------------
 * Frontend: Leader Page
 *
 * Responsibility:
 * - Provide shortcode container
 * - Enqueue leader JS only when shortcode exists
 * - Provide ajax_url + nonce via wp_localize_script
 * - NO business logic
 * ------------------------------------------------------------------
 */

function tr_as_page_has_leader_shortcode(): bool {
    if (is_admin()) return false;
    if (!is_singular()) return false;

    $post = get_post();
    if (!$post) return false;

    return (is_string($post->post_content) && has_shortcode($post->post_content, 'tr_attendance_leader'));
}

/**
 * No-cache on leader page (attendance UI should not be cached)
 */
add_action('template_redirect', function () {
    if (!tr_as_page_has_leader_shortcode()) return;

    if (!defined('DONOTCACHEPAGE'))   define('DONOTCACHEPAGE', true);
    if (!defined('DONOTCACHEOBJECT')) define('DONOTCACHEOBJECT', true);
    if (!defined('DONOTCACHEDB'))     define('DONOTCACHEDB', true);

    nocache_headers();

    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-TRAS-NOCACHE: 1');
    }
}, 0);

add_filter('body_class', function (array $classes) {
    if (tr_as_page_has_leader_shortcode()) {
        $classes[] = 'tr-as-leader-page';
    }
    return $classes;
});

/**
 * Enqueue Leader assets only when needed
 */
add_action('wp_enqueue_scripts', function () {
    if (!tr_as_page_has_leader_shortcode()) return;

    $js_rel  = 'includes/frontend/assets/leader.js';
    $js_path = TR_AS_PATH . $js_rel;
    $js_url  = TR_AS_URL  . $js_rel;

    $ver = file_exists($js_path) ? (string) filemtime($js_path) : '0.1.0';

    wp_enqueue_script(
        'tr-as-leader',
        $js_url,
        [],
        $ver,
        true
    );

    wp_localize_script('tr-as-leader', 'TRAS_LEADER', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('tr_as_ajax'),
        'build'    => $ver,

        'endpoints' => [
            'get_sessions' => 'tr_as_get_sessions',
            'get_matrix'   => 'tr_as_get_attendance_matrix',
            'get_newcomers'=> 'tr_as_get_newcomers',
            'submit_bulk'  => 'tr_as_submit_leader_bulk',
        ],

        'i18n' => [
            'loading' => '載入中…',
            'error'   => '發生錯誤，請稍後再試。',
            'save'    => '儲存',
            'saved'   => '已儲存',
        ],
    ]);
}, 20);

/**
 * Shortcode: [tr_attendance_leader]
 */
add_shortcode('tr_attendance_leader', function () {
    return '<div class="tr-as-leader-app" data-build="' . esc_attr('1') . '"></div>';
});
