<?php
/**
 * "VozCaster review" block: the same screen as VozCaster → Review in wp-admin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Shows nothing to logged-in users who cannot use it, so one page can hold
// every VozCaster block and each person sees only their part.
if ( is_user_logged_in() && ! VPConn_Access::reviewable_feeds( get_current_user_id() ) ) {
	return;
}

$vpconn_html = VPConn_Frontend::block(
	'review',
	static fn(): string => VPConn_Review::render( VPConn_Frontend::current_url() )
);
echo $vpconn_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside block() and render().
