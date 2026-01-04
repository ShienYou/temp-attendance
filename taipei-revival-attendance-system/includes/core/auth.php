<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ------------------------------------------------------------------
 * Auth & Scope (CORE, SINGLE SOURCE OF TRUTH)
 *
 * Responsibility (SSOT v1.5):
 * - Capability registration
 * - Capability assertion
 * - User scope retrieval (leader / usher)
 * - Write-scope assertion for session
 *
 * Boundary:
 * - NO business logic
 * - NO statistics
 * - NO UI handling
 * ------------------------------------------------------------------
 */

/**
 * A Throwable wrapper for WP_Error (because WP_Error is NOT throwable).
 */
if (!class_exists('TR_AS_WPError_Exception')) {
    class TR_AS_WPError_Exception extends Exception {
        /** @var WP_Error */
        public $wp_error;

        public function __construct(WP_Error $wp_error) {
            $this->wp_error = $wp_error;
            parent::__construct($wp_error->get_error_message());
        }
    }
}

function tr_as_throw_wp_error(string $code, string $message, $data = null): void {
    throw new TR_AS_WPError_Exception(new WP_Error($code, $message, $data));
}

/**
 * ------------------------------------------------------------------
 * Capability registration
 *
 * Purpose:
 * - Prevent admin lockout
 * - Ensure system caps always exist
 * ------------------------------------------------------------------
 */
function tr_as_register_caps(): void {

    $role = get_role('administrator');
    if (!$role) {
        return;
    }

    $caps = array(
        TR_AS_CAP_ADMIN,
        TR_AS_CAP_LEADER,
        TR_AS_CAP_USHER,
        TR_AS_CAP_VIEWER,
    );

    foreach ($caps as $cap) {
        if (!$role->has_cap($cap)) {
            $role->add_cap($cap);
        }
    }
}

/**
 * ------------------------------------------------------------------
 * Capability assertion (HARD GATE)
 *
 * NOTE:
 * - DO NOT throw WP_Error directly (it is not Throwable)
 * ------------------------------------------------------------------
 */
function tr_as_auth_assert_capability(int $user_id, string $capability): void {

    if (!user_can($user_id, $capability)) {
        tr_as_throw_wp_error('tr_as_forbidden', 'User does not have required capability.');
    }
}

/**
 * ------------------------------------------------------------------
 * Get user scope (leader / usher)
 *
 * IMPORTANT:
 * - Do NOT special-case admin to "return null scopes".
 *   Admin can still carry leader/usher caps and meta for testing.
 * ------------------------------------------------------------------
 */
function tr_as_auth_get_user_scope(int $user_id): array {

    $scope = array(
        'leader' => null,
        'usher'  => null,
    );

    /**
     * Leader scope (group-based)
     * Stored in user_meta (v1.x default)
     */
    if (user_can($user_id, TR_AS_CAP_LEADER)) {

        $scope['leader'] = array(
            'branch_id'  => get_user_meta($user_id, 'tr_as_branch_id', true),
            'region'     => get_user_meta($user_id, 'tr_as_region', true),
            'sub_region' => get_user_meta($user_id, 'tr_as_sub_region', true),
            'group_name' => get_user_meta($user_id, 'tr_as_group_name', true),
            'team_no'    => get_user_meta($user_id, 'tr_as_team_no', true),
        );
    }

    /**
     * Usher scope (session-based / venue-based)
     */
    if (user_can($user_id, TR_AS_CAP_USHER)) {

        $scope['usher'] = array(
            'venue_scope'        => (array) get_user_meta($user_id, 'tr_as_venue_scope', true),
            'meeting_type_scope' => (array) get_user_meta($user_id, 'tr_as_meeting_type_scope', true),
            'service_slot_scope' => (array) get_user_meta($user_id, 'tr_as_service_slot_scope', true),
        );
    }

    return $scope;
}

/**
 * ------------------------------------------------------------------
 * Assert leader can write to a session
 * ------------------------------------------------------------------
 */
function tr_as_auth_assert_leader_scope_for_session(int $user_id, int $session_id): void {

    tr_as_auth_assert_capability($user_id, TR_AS_CAP_LEADER);

    $scope = tr_as_auth_get_user_scope($user_id);

    if (empty($scope['leader']) || empty($scope['leader']['group_name'])) {
        tr_as_throw_wp_error('tr_as_no_leader_scope', 'leader scope missing');
    }

    // Session-specific validation will be handled by core/sessions.php
}

/**
 * ------------------------------------------------------------------
 * Assert usher can write to a session
 * ------------------------------------------------------------------
 */
function tr_as_auth_assert_usher_scope_for_session(int $user_id, int $session_id): void {

    tr_as_auth_assert_capability($user_id, TR_AS_CAP_USHER);

    $scope = tr_as_auth_get_user_scope($user_id);

    if (empty($scope['usher'])) {
        tr_as_throw_wp_error('tr_as_no_usher_scope', 'Usher scope not properly assigned.');
    }

    // Detailed scope validation will be handled by core/sessions.php
}

/**
 * ------------------------------------------------------------------
 * Admin-only assertion
 * ------------------------------------------------------------------
 */
function tr_as_auth_assert_admin_only(int $user_id): void {
    tr_as_auth_assert_capability($user_id, TR_AS_CAP_ADMIN);
}
