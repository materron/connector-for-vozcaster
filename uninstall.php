<?php
defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Auth and session data — deleted on full uninstall
delete_option( 'vpconn_token' );
delete_option( 'vpconn_authorized_roles' );
delete_option( 'vpconn_allowed_wp_users' );
delete_option( 'vpconn_feed_permissions' );
delete_metadata( 'user', 0, 'vpconn_bot_token_hash', '', true );
delete_metadata( 'user', 0, 'vpconn_bot_token_clients', '', true );
delete_metadata( 'user', 0, 'vpconn_bot_token', '', true );
delete_metadata( 'user', 0, 'vpconn_connect_notice_dismissed', '', true );
delete_metadata( 'user', 0, 'vpconn_pending_token_hash', '', true );
delete_metadata( 'user', 0, 'vpconn_pending_token_info', '', true );

// Roles created from VozCaster → Access: only the list is deleted. The roles
// themselves are intentionally kept, otherwise their users would be left
// without any role.
delete_option( 'vpconn_created_roles' );

// AI notice settings and per-post flags.
delete_option( 'vpconn_ai_notice_enabled' );
delete_option( 'vpconn_ai_notice_text' );
delete_option( 'vpconn_ai_notice_position' );
delete_option( 'vpconn_credit_enabled' );
delete_option( 'vpconn_distribution' );
delete_option( 'vpconn_review_mode' );
delete_option( 'vpconn_separator_attachment_id' );
delete_option( 'vpconn_writing_style' );
delete_option( 'vpconn_title_template' );
delete_option( 'vpconn_style_reference_id' );
delete_option( 'vpconn_show_chapters' );
delete_option( 'vpconn_gs_podcast_saved' );
delete_option( 'vpconn_default_cover_id' );
delete_metadata( 'user', 0, 'vpconn_gs_step', '', true );
delete_metadata( 'user', 0, 'vpconn_gs_done', '', true );
delete_metadata( 'user', 0, 'vpconn_gs_skipped', '', true );
delete_metadata( 'user', 0, 'vpconn_gs_platforms_dismissed', '', true );
delete_transient( 'vpconn_gs_episodes' );
delete_post_meta_by_key( '_vpconn_ai_generated' );
delete_post_meta_by_key( '_vpconn_via_bot' );
delete_post_meta_by_key( '_vpconn_hide_ai_notice' );
delete_option( 'vpconn_episode_log' );
delete_option( 'vpconn_version' );
delete_transient( 'vpconn_token_plain' );

// NOTE: intro/outro attachment IDs and mix config are intentionally kept
// so that reinstalling the plugin does not require re-uploading the audio files.
