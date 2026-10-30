<?php
/**
 * "Connect with VozCaster" block: the same content as [vozcaster_connect].
 *
 * @var array $attributes Block attributes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

printf(
	'<div %1$s>%2$s</div>',
	get_block_wrapper_attributes( [ 'class' => 'vpconn-block' ] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by WordPress.
	( new VPConn_Pairing() )->shortcode( [ 'feed' => (string) ( $attributes['feed'] ?? '' ) ] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside.
);
