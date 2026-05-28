<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Auth and session data — deleted on full uninstall
delete_option( 'vpconn_token' );
delete_option( 'vpconn_authorized_users' );
delete_option( 'vpconn_episode_log' );
delete_transient( 'vpconn_token_plain' );

// NOTE: intro/outro attachment IDs and mix config are intentionally kept
// so that reinstalling the plugin does not require re-uploading the audio files.
