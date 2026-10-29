<?php
/**
 * "VozCaster access" block: the same screen as VozCaster → Access in wp-admin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Shows nothing to logged-in users who cannot use it, so one page can hold
// every VozCaster block and each person sees only their part.
if ( is_user_logged_in() && ! VPConn_Access::is_manager( get_current_user_id() ) ) {
	return;
}

$vpconn_html = VPConn_Frontend::block(
	'access',
	static fn(): string => VPConn_Access::render( VPConn_Frontend::current_url() )
);
echo $vpconn_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside block() and render().
