<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin settings page: Settings → VozCaster.
 */
class VPConn_Settings {

	const OPTION_LOG              = 'vpconn_episode_log';
	const OPTION_FEED_PERMISSIONS = 'vpconn_feed_permissions';
	const LOG_MAX_ENTRIES         = 10;

	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
		add_action( 'admin_init', [ $this, 'handle_actions' ] );
		add_action( 'admin_notices', [ $this, 'show_token_notice' ] );
	}

	public function add_settings_page(): void {
		add_options_page(
			__( 'VozCaster', 'connector-for-vozcaster' ),
			__( 'VozCaster', 'connector-for-vozcaster' ),
			'manage_options',
			'connector-for-vozcaster',
			[ $this, 'render_page' ]
		);
	}

	// -------------------------------------------------------------------------
	// Form actions
	// -------------------------------------------------------------------------

	public function handle_actions(): void {
		if ( ! isset( $_POST['vpconn_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'vpconn_settings_action' );

		$action = sanitize_key( $_POST['vpconn_action'] );

		switch ( $action ) {
			case 'save_post_footer':
				$footer = isset( $_POST['post_footer'] ) ? wp_kses_post( wp_unslash( $_POST['post_footer'] ) ) : '';
				update_option( 'vpconn_post_footer', $footer );
				wp_safe_redirect( add_query_arg( [ 'page' => 'connector-for-vozcaster', 'vpconn_msg' => 'post_footer_saved' ], admin_url( 'options-general.php' ) ) );
				exit;

			case 'save_title_config':
				$prefix             = sanitize_text_field( wp_unslash( $_POST['title_prefix'] ?? '' ) );
				$include            = ! empty( $_POST['title_include_season'] ) ? 1 : 0;
				$numbering_mode_raw = sanitize_key( wp_unslash( $_POST['episode_numbering_mode'] ?? 'enclosure' ) );
				$numbering_mode     = in_array( $numbering_mode_raw, [ 'enclosure', 'title' ], true ) ? $numbering_mode_raw : 'enclosure';
				update_option( 'vpconn_title_prefix', $prefix );
				update_option( 'vpconn_title_include_season', $include );
				update_option( 'vpconn_episode_numbering_mode', $numbering_mode );
				wp_safe_redirect( add_query_arg( [ 'page' => 'connector-for-vozcaster', 'vpconn_msg' => 'title_config_saved' ], admin_url( 'options-general.php' ) ) );
				exit;

			case 'save_mix_config':
				$duck_start  = isset( $_POST['intro_duck_start'] )  ? (float) $_POST['intro_duck_start']  : 20;
				$duck_vol    = isset( $_POST['intro_duck_volume'] )  ? (float) $_POST['intro_duck_volume'] : 30;
				$fade_end_raw = isset( $_POST['intro_fade_end'] ) ? sanitize_text_field( wp_unslash( $_POST['intro_fade_end'] ) ) : '';
				$fade_end     = '' !== $fade_end_raw ? (float) $fade_end_raw : '';
				$knee_raw     = isset( $_POST['intro_knee_delay'] ) ? sanitize_text_field( wp_unslash( $_POST['intro_knee_delay'] ) ) : '';
				$knee_delay   = '' !== $knee_raw ? (float) $knee_raw : '';
				$outro_start = isset( $_POST['outro_fade_start'] )   ? (float) $_POST['outro_fade_start']  : 17;
				$outro_vol   = isset( $_POST['outro_duck_volume'] )   ? (float) $_POST['outro_duck_volume'] : 35;

				update_option( 'vpconn_intro_duck_start',  max( 0, $duck_start ) );
				update_option( 'vpconn_intro_duck_volume', min( 100, max( 1, $duck_vol ) ) );
				if ( '' === $fade_end ) {
					delete_option( 'vpconn_intro_fade_end' );
				} else {
					update_option( 'vpconn_intro_fade_end', max( $duck_start + 1, $fade_end ) );
				}
				if ( '' === $knee_delay || $knee_delay <= 0 ) {
					delete_option( 'vpconn_intro_knee_time' );
				} else {
					// Guardar como segundo absoluto: duck_start + knee_delay
					update_option( 'vpconn_intro_knee_time', $duck_start + $knee_delay );
				}
				update_option( 'vpconn_outro_fade_start',  max( 1, $outro_start ) );
				update_option( 'vpconn_outro_duck_volume', min( 100, max( 1, $outro_vol ) ) );

				wp_safe_redirect( add_query_arg( [ 'page' => 'connector-for-vozcaster', 'vpconn_msg' => 'mix_config_saved' ], admin_url( 'options-general.php' ) ) );
				exit;

			case 'save_feed_permissions':
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nested array; every key and value is sanitized in the loop below (int cast, sanitize_key, whitelist check).
				$raw  = isset( $_POST['feed_perm'] ) ? (array) wp_unslash( $_POST['feed_perm'] ) : [];
				$perms = [];
				foreach ( $raw as $uid_str => $feeds ) {
					$uid = (int) $uid_str;
					if ( ! $uid ) continue;
					$clean_feeds = [];
					foreach ( (array) $feeds as $slug => $level ) {
						$slug  = sanitize_key( $slug );
						if ( ! $slug ) continue;
						if ( in_array( $level, [ 'publish', 'draft' ], true ) ) {
							$clean_feeds[ $slug ] = $level;
						}
					}
					if ( ! empty( $clean_feeds ) ) {
						$perms[ $uid ] = $clean_feeds;
					}
				}
				self::set_feed_permissions( $perms );
				wp_safe_redirect( add_query_arg( [ 'page' => 'connector-for-vozcaster', 'vpconn_msg' => 'feed_permissions_saved' ], admin_url( 'options-general.php' ) ) );
				exit;

			case 'save_allowed_users':
				$ids = array_map( 'intval', (array) ( $_POST['allowed_users'] ?? [] ) );
				VPConn_Auth::set_allowed_user_ids( $ids );
				wp_safe_redirect( add_query_arg( [ 'page' => 'connector-for-vozcaster', 'vpconn_msg' => 'users_saved' ], admin_url( 'options-general.php' ) ) );
				exit;

			case 'revoke_token':
				$user_id = absint( wp_unslash( $_POST['user_id'] ?? 0 ) );
				if ( $user_id > 0 ) {
					VPConn_Auth::revoke_user_token( $user_id );
				}
				wp_safe_redirect( add_query_arg( [ 'page' => 'connector-for-vozcaster', 'vpconn_msg' => 'token_revoked' ], admin_url( 'options-general.php' ) ) );
				exit;

			case 'upload_intro':
			case 'upload_outro':
				$type    = ( 'upload_intro' === $action ) ? 'intro' : 'outro';
				$msg_ok  = ( 'intro' === $type ) ? 'intro_uploaded'   : 'outro_uploaded';
				$msg_err = ( 'intro' === $type ) ? 'intro_upload_err' : 'outro_upload_err';

				if ( empty( $_FILES['audio_file']['name'] ) ) {
					wp_safe_redirect( add_query_arg( [
						'page'       => 'connector-for-vozcaster',
						'vpconn_msg' => $msg_err,
						'vpconn_err' => rawurlencode( __( 'No file selected.', 'connector-for-vozcaster' ) ),
					], admin_url( 'options-general.php' ) ) );
					exit;
				}

				// Make sure wp_handle_upload and media helpers are available.
				if ( ! function_exists( 'wp_handle_upload' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}
				if ( ! function_exists( 'wp_read_audio_metadata' ) ) {
					require_once ABSPATH . 'wp-admin/includes/media.php';
					require_once ABSPATH . 'wp-admin/includes/image.php';
				}

				// Disable strict MIME type validation for audio files — different OGG/M4A
				// codecs can produce a detected MIME type that differs from the extension.
				add_filter( 'wp_check_filetype_and_ext', static function( $data, $file, $filename ) {
					$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
					$map = [
						'mp3' => 'audio/mpeg',
						'm4a' => 'audio/x-m4a',
						'ogg' => 'audio/ogg',
						'wav' => 'audio/wav',
					];
					if ( isset( $map[ $ext ] ) ) {
						$data['ext']  = $ext;
						$data['type'] = $map[ $ext ];
					}
					return $data;
				}, 10, 3 );

				$uploaded = wp_handle_upload( $_FILES['audio_file'], [
					'test_form' => false,
					'mimes'     => [
						'mp3|mpga' => 'audio/mpeg',
						'm4a'      => 'audio/x-m4a',
						'ogg|oga'  => 'audio/ogg',
						'wav'      => 'audio/wav',
					],
				] );

				if ( isset( $uploaded['error'] ) ) {
					wp_safe_redirect( add_query_arg( [
						'page'       => 'connector-for-vozcaster',
						'vpconn_msg' => $msg_err,
						'vpconn_err' => rawurlencode( $uploaded['error'] ),
					], admin_url( 'options-general.php' ) ) );
					exit;
				}

				// Create attachment in the media library.
				$attachment_id = wp_insert_attachment( [
					'post_mime_type' => $uploaded['type'],
					'post_title'     => sanitize_file_name( basename( $uploaded['file'] ) ),
					'post_status'    => 'inherit',
				], $uploaded['file'] );

				if ( is_wp_error( $attachment_id ) ) {
					wp_safe_redirect( add_query_arg( [
						'page'       => 'connector-for-vozcaster',
						'vpconn_msg' => $msg_err,
						'vpconn_err' => rawurlencode( $attachment_id->get_error_message() ),
					], admin_url( 'options-general.php' ) ) );
					exit;
				}

				wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $uploaded['file'] ) );
				VPConn_Media::set_intro_outro_id( $type, $attachment_id );

				wp_safe_redirect( add_query_arg( [ 'page' => 'connector-for-vozcaster', 'vpconn_msg' => $msg_ok ], admin_url( 'options-general.php' ) ) );
				exit;

			case 'delete_intro':
				VPConn_Media::delete_intro_outro( 'intro' );
				wp_safe_redirect( add_query_arg( [ 'page' => 'connector-for-vozcaster', 'vpconn_msg' => 'intro_deleted' ], admin_url( 'options-general.php' ) ) );
				exit;

			case 'delete_outro':
				VPConn_Media::delete_intro_outro( 'outro' );
				wp_safe_redirect( add_query_arg( [ 'page' => 'connector-for-vozcaster', 'vpconn_msg' => 'outro_deleted' ], admin_url( 'options-general.php' ) ) );
				exit;

			case 'select_intro_from_library':
			case 'select_outro_from_library':
				$type          = ( 'select_intro_from_library' === $action ) ? 'intro' : 'outro';
				$attachment_id = absint( wp_unslash( $_POST['attachment_id'] ?? 0 ) );
				$msg_ok        = ( 'intro' === $type ) ? 'intro_uploaded' : 'outro_uploaded';
				$msg_err       = ( 'intro' === $type ) ? 'intro_upload_err' : 'outro_upload_err';

				if ( $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) ) {
					VPConn_Media::set_intro_outro_id( $type, $attachment_id );
					wp_safe_redirect( add_query_arg( [ 'page' => 'connector-for-vozcaster', 'vpconn_msg' => $msg_ok ], admin_url( 'options-general.php' ) ) );
				} else {
					wp_safe_redirect( add_query_arg( [
						'page'       => 'connector-for-vozcaster',
						'vpconn_msg' => $msg_err,
						'vpconn_err' => rawurlencode( __( 'Invalid attachment.', 'connector-for-vozcaster' ) ),
					], admin_url( 'options-general.php' ) ) );
				}
				exit;

			case 'clear_log':
				update_option( self::OPTION_LOG, [] );
				wp_safe_redirect( add_query_arg( [ 'page' => 'connector-for-vozcaster', 'vpconn_msg' => 'log_cleared' ], admin_url( 'options-general.php' ) ) );
				exit;
		}
	}

	/**
	 * Displays admin notices at the top of the settings page.
	 */
	public function show_token_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'settings_page_connector-for-vozcaster' !== $screen->id ) {
			return;
		}

		// Read-only admin notice shown after a nonce-verified redirect; $msg is a
		// whitelisted key (sanitize_key + lookup in the $messages array below).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg = sanitize_key( wp_unslash( $_GET['vpconn_msg'] ?? '' ) );
		if ( ! $msg ) {
			return;
		}

		$messages = [
			'feed_permissions_saved' => __( 'Podcast permissions updated.', 'connector-for-vozcaster' ),
			'users_saved'            => __( 'User list updated.', 'connector-for-vozcaster' ),
			'token_revoked'          => __( 'User access revoked. They will need to reconnect using /connect.', 'connector-for-vozcaster' ),
			'intro_deleted'          => __( 'Intro file deleted.', 'connector-for-vozcaster' ),
			'outro_deleted'          => __( 'Outro file deleted.', 'connector-for-vozcaster' ),
			'intro_uploaded'         => __( 'Intro file uploaded successfully.', 'connector-for-vozcaster' ),
			'outro_uploaded'         => __( 'Outro file uploaded successfully.', 'connector-for-vozcaster' ),
			'intro_upload_err'       => __( 'Error uploading intro file. Please make sure it is a valid audio file.', 'connector-for-vozcaster' ),
			'outro_upload_err'       => __( 'Error uploading outro file. Please make sure it is a valid audio file.', 'connector-for-vozcaster' ),
			'log_cleared'            => __( 'Episode history cleared.', 'connector-for-vozcaster' ),
			'mix_config_saved'       => __( 'Mix settings saved.', 'connector-for-vozcaster' ),
			'title_config_saved'     => __( 'Title settings saved.', 'connector-for-vozcaster' ),
			'post_footer_saved'      => __( 'Post footer saved.', 'connector-for-vozcaster' ),
		];

		$errors = [ 'intro_upload_err', 'outro_upload_err' ];
		if ( in_array( $msg, $errors, true ) && isset( $messages[ $msg ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice detail after a nonce-verified redirect.
			$detail = ! empty( $_GET['vpconn_err'] ) ? ' — ' . esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['vpconn_err'] ) ) ) ) : '';
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s%s</p></div>',
				esc_html( $messages[ $msg ] ),
				$detail // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped with esc_html above
			);
			return;
		}

		if ( isset( $messages[ $msg ] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( $messages[ $msg ] )
			);
		}
	}

	// -------------------------------------------------------------------------
	// Page rendering
	// -------------------------------------------------------------------------

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$allowed_ids     = VPConn_Auth::get_allowed_user_ids();
		$all_users       = get_users( [ 'orderby' => 'display_name', 'order' => 'ASC' ] );
		$all_feed_perms  = self::get_all_feed_permissions();
		$pp_feeds        = self::get_powerpress_feeds();
		$intro           = VPConn_Media::get_intro_outro_info( 'intro' );
		$outro           = VPConn_Media::get_intro_outro_info( 'outro' );
		$log             = self::get_log();
		$pp_active       = is_plugin_active( 'powerpress/powerpress.php' );
		$nonce_field     = wp_nonce_field( 'vpconn_settings_action', '_wpnonce', true, false );
		$title_prefix           = (string) get_option( 'vpconn_title_prefix', '' );
		$title_include_season   = (bool)   get_option( 'vpconn_title_include_season', false );
		$episode_numbering_mode = (string) get_option( 'vpconn_episode_numbering_mode', 'enclosure' );
		$post_footer     = (string) get_option( 'vpconn_post_footer', '' );
		$duck_start      = (float) get_option( 'vpconn_intro_duck_start',  20 );
		$duck_vol        = (float) get_option( 'vpconn_intro_duck_volume', 30 );
		$fade_end_raw    = get_option( 'vpconn_intro_fade_end', '' );
		$fade_end        = '' !== $fade_end_raw ? (float) $fade_end_raw : '';
		$knee_time_raw   = get_option( 'vpconn_intro_knee_time', '' );
		// Mostrar como segundos relativos (desde duck_start) para que sea intuitivo
		$knee_delay      = '' !== $knee_time_raw ? max( 0, (float) $knee_time_raw - $duck_start ) : '';
		$outro_start     = (float) get_option( 'vpconn_outro_fade_start',  17 );
		$outro_vol       = (float) get_option( 'vpconn_outro_duck_volume', 35 );

		wp_enqueue_media();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'VozCaster', 'connector-for-vozcaster' ); ?></h1>

			<?php /* ----- PowerPress status ----- */ ?>
			<h2><?php esc_html_e( 'PowerPress', 'connector-for-vozcaster' ); ?></h2>
			<p>
				<?php if ( $pp_active ) : ?>
					<span style="color:#46b450;">&#10004;</span> <?php esc_html_e( 'PowerPress is installed and active.', 'connector-for-vozcaster' ); ?>
				<?php else : ?>
					<span style="color:#dc3232;">&#10008;</span> <?php esc_html_e( 'PowerPress is not installed or not active. Episodes will be created without podcast data.', 'connector-for-vozcaster' ); ?>
				<?php endif; ?>
			</p>

			<hr>

			<?php /* ----- Podcast access permissions ----- */ ?>
			<h2><?php esc_html_e( 'Podcast access permissions', 'connector-for-vozcaster' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Define which podcast each user can access from the bot and with what permission level. Users without specific permissions configured here will have access to all podcasts with publish permission (backward compatibility).', 'connector-for-vozcaster' ); ?>
			</p>

			<?php if ( count( $pp_feeds ) <= 1 ) : ?>
				<p><em><?php esc_html_e( 'Only one podcast is configured. Add more feeds in PowerPress to enable per-podcast permissions.', 'connector-for-vozcaster' ); ?></em></p>
			<?php else : ?>
			<form method="post" action="">
				<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<input type="hidden" name="vpconn_action" value="save_feed_permissions">
				<table class="widefat fixed striped" style="margin-top:12px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'User', 'connector-for-vozcaster' ); ?></th>
							<?php foreach ( $pp_feeds as $feed ) : ?>
								<th style="text-align:center;"><?php echo esc_html( $feed['name'] ); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $all_users as $wp_user ) : ?>
							<?php if ( ! in_array( $wp_user->ID, $allowed_ids, true ) ) continue; ?>
							<?php $user_perms = $all_feed_perms[ $wp_user->ID ] ?? []; ?>
							<tr>
								<td><strong><?php echo esc_html( $wp_user->display_name ); ?></strong> <small><?php echo esc_html( $wp_user->user_login ); ?></small></td>
								<?php foreach ( $pp_feeds as $feed ) : ?>
									<?php
									$slug  = $feed['slug'];
									$level = $user_perms[ $slug ] ?? 'none';
									$name  = "feed_perm[{$wp_user->ID}][{$slug}]";
									?>
									<td style="text-align:center;">
										<select name="<?php echo esc_attr( $name ); ?>">
											<option value="none"    <?php selected( $level, 'none' ); ?>><?php esc_html_e( 'No access', 'connector-for-vozcaster' ); ?></option>
											<option value="draft"   <?php selected( $level, 'draft' ); ?>><?php esc_html_e( 'Draft', 'connector-for-vozcaster' ); ?></option>
											<option value="publish" <?php selected( $level, 'publish' ); ?>><?php esc_html_e( 'Publish', 'connector-for-vozcaster' ); ?></option>
										</select>
									</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description" style="margin-top:8px;">
					<?php esc_html_e( 'No access: user will not see this podcast. Draft: can only submit for review. Publish: publishes directly.', 'connector-for-vozcaster' ); ?>
				</p>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save permissions', 'connector-for-vozcaster' ); ?></button></p>
			</form>
			<?php endif; ?>

			<hr>

			<?php /* ----- Users allowed to use the bot ----- */ ?>
			<h2><?php esc_html_e( 'Users allowed to use the bot', 'connector-for-vozcaster' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Check the WordPress users allowed to publish episodes from the Telegram bot. Checked users will be able to authenticate using their usual WordPress username and password.', 'connector-for-vozcaster' ); ?>
			</p>

			<form method="post" action="">
				<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<input type="hidden" name="vpconn_action" value="save_allowed_users">
				<table class="widefat fixed striped" style="margin-top:12px;">
					<thead>
						<tr>
							<th style="width:40px;"><?php esc_html_e( 'Allowed', 'connector-for-vozcaster' ); ?></th>
							<th><?php esc_html_e( 'Username', 'connector-for-vozcaster' ); ?></th>
							<th><?php esc_html_e( 'Name', 'connector-for-vozcaster' ); ?></th>
							<th><?php esc_html_e( 'Role', 'connector-for-vozcaster' ); ?></th>
							<th><?php esc_html_e( 'Bot status', 'connector-for-vozcaster' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $all_users as $wp_user ) : ?>
							<?php
							$is_allowed    = in_array( $wp_user->ID, $allowed_ids, true );
							$has_token     = VPConn_Auth::has_token( $wp_user->ID );
							$roles_display = implode( ', ', array_map( 'translate_user_role', $wp_user->roles ) );
							?>
							<tr>
								<td style="text-align:center;">
									<input
										type="checkbox"
										name="allowed_users[]"
										value="<?php echo esc_attr( $wp_user->ID ); ?>"
										<?php checked( $is_allowed ); ?>
									>
								</td>
								<td><strong><?php echo esc_html( $wp_user->user_login ); ?></strong></td>
								<td><?php echo esc_html( $wp_user->display_name ); ?></td>
								<td><?php echo esc_html( $roles_display ); ?></td>
								<td>
									<?php if ( $has_token ) : ?>
										<span style="color:#46b450;">&#10004; <?php esc_html_e( 'Connected', 'connector-for-vozcaster' ); ?></span>
										&nbsp;
										<button
											type="submit"
											formaction=""
											name="vpconn_action"
											value="revoke_token"
											class="button button-small button-link-delete"
											style="vertical-align:middle;"
											onclick="this.form.querySelector('[name=user_id]').value='<?php echo esc_attr( $wp_user->ID ); ?>';return confirm('<?php esc_attr_e( 'Revoke access? The user will need to reconnect using /connect.', 'connector-for-vozcaster' ); ?>')">
											<?php esc_html_e( 'Revoke', 'connector-for-vozcaster' ); ?>
										</button>
									<?php else : ?>
										<span style="color:#999;">&#8212; <?php esc_html_e( 'Not connected', 'connector-for-vozcaster' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<input type="hidden" name="user_id" value="">
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'connector-for-vozcaster' ); ?></button></p>
			</form>

			<hr>

			<?php /* ----- Episode titles ----- */ ?>
			<h2><?php esc_html_e( 'Episode titles', 'connector-for-vozcaster' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Define the prefix prepended to the number and title of each episode. Can also be changed from the Telegram bot with /settings.', 'connector-for-vozcaster' ); ?>
			</p>

			<form method="post" action="">
				<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<input type="hidden" name="vpconn_action" value="save_title_config">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="title_prefix"><?php esc_html_e( 'Title prefix', 'connector-for-vozcaster' ); ?></label>
						</th>
						<td>
							<input
								type="text" id="title_prefix" name="title_prefix"
								value="<?php echo esc_attr( $title_prefix ); ?>"
								placeholder="<?php esc_attr_e( 'E.g.: Chapter, Episode, Ep.', 'connector-for-vozcaster' ); ?>"
								style="width:200px;"
							>
							<p class="description">
								<?php esc_html_e( 'Empty = no prefix. With prefix and no season: «Chapter 15: Title». With season: «Chapter T2E15: Title».', 'connector-for-vozcaster' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Include season number', 'connector-for-vozcaster' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox" name="title_include_season" value="1"
									<?php checked( $title_include_season ); ?>
								>
								<?php esc_html_e( 'Include T2E15 in the title (if a prefix is defined)', 'connector-for-vozcaster' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label><?php esc_html_e( 'Chapter numbering in title', 'connector-for-vozcaster' ); ?></label>
						</th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="episode_numbering_mode" value="enclosure"
										<?php checked( $episode_numbering_mode, 'enclosure' ); ?>>
									<?php esc_html_e( 'Chapter within the season (resets at T1, T2…)', 'connector-for-vozcaster' ); ?>
									<br><span class="description"><?php esc_html_e( 'Example: «Chapter 5: Title» even if it is episode 37 overall.', 'connector-for-vozcaster' ); ?></span>
								</label>
								<br><br>
								<label>
									<input type="radio" name="episode_numbering_mode" value="title"
										<?php checked( $episode_numbering_mode, 'title' ); ?>>
									<?php esc_html_e( 'Global sequential number (inferred from the previous post title)', 'connector-for-vozcaster' ); ?>
									<br><span class="description"><?php esc_html_e( 'Example: «Chapter 38: Title». PowerPress still uses the chapter within the season internally.', 'connector-for-vozcaster' ); ?></span>
								</label>
							</fieldset>
						</td>
					</tr>
				</table>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save title settings', 'connector-for-vozcaster' ); ?></button></p>
			</form>

			<hr>

			<?php /* ----- Intro & Outro ----- */ ?>
			<h2><?php esc_html_e( 'Intro &amp; Outro', 'connector-for-vozcaster' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Upload your intro and outro audio files here. They can also be uploaded from the Telegram bot via the Intro/Outro menu.', 'connector-for-vozcaster' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Intro', 'connector-for-vozcaster' ); ?></th>
					<td>
						<?php if ( $intro['exists'] ) : ?>
							<p>
								<a href="<?php echo esc_url( $intro['url'] ); ?>" target="_blank">
									<?php echo esc_html( basename( wp_parse_url( $intro['url'], PHP_URL_PATH ) ) ); ?>
								</a>
								&nbsp;
								<form method="post" action="" style="display:inline;">
									<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<input type="hidden" name="vpconn_action" value="delete_intro">
									<button type="submit" class="button button-small button-link-delete"
										onclick="return confirm('<?php esc_attr_e( 'Delete the intro file?', 'connector-for-vozcaster' ); ?>')">
										<?php esc_html_e( 'Delete', 'connector-for-vozcaster' ); ?>
									</button>
								</form>
							</p>
							<p class="description"><?php esc_html_e( 'To replace the intro, upload a new file:', 'connector-for-vozcaster' ); ?></p>
						<?php else : ?>
							<p><em><?php esc_html_e( 'No intro loaded.', 'connector-for-vozcaster' ); ?></em></p>
						<?php endif; ?>
						<form method="post" action="" enctype="multipart/form-data">
							<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<input type="hidden" name="vpconn_action" value="upload_intro">
							<input type="file" name="audio_file" accept="audio/*" style="margin-right:8px;">
							<button type="submit" class="button button-secondary">
								<?php $intro['exists'] ? esc_html_e( 'Replace intro', 'connector-for-vozcaster' ) : esc_html_e( 'Upload intro', 'connector-for-vozcaster' ); ?>
							</button>
						</form>
						<form method="post" action="" id="vpconn_intro_select_form">
							<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<input type="hidden" name="vpconn_action" value="select_intro_from_library">
							<input type="hidden" name="attachment_id" id="vpconn_intro_media_id" value="">
						</form>
						<button type="button" class="button button-secondary" style="margin-top:6px;" onclick="vpconnOpenMediaPicker('intro')">
							<?php esc_html_e( 'Select from media library', 'connector-for-vozcaster' ); ?>
						</button>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Outro', 'connector-for-vozcaster' ); ?></th>
					<td>
						<?php if ( $outro['exists'] ) : ?>
							<p>
								<a href="<?php echo esc_url( $outro['url'] ); ?>" target="_blank">
									<?php echo esc_html( basename( wp_parse_url( $outro['url'], PHP_URL_PATH ) ) ); ?>
								</a>
								&nbsp;
								<form method="post" action="" style="display:inline;">
									<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<input type="hidden" name="vpconn_action" value="delete_outro">
									<button type="submit" class="button button-small button-link-delete"
										onclick="return confirm('<?php esc_attr_e( 'Delete the outro file?', 'connector-for-vozcaster' ); ?>')">
										<?php esc_html_e( 'Delete', 'connector-for-vozcaster' ); ?>
									</button>
								</form>
							</p>
							<p class="description"><?php esc_html_e( 'To replace the outro, upload a new file:', 'connector-for-vozcaster' ); ?></p>
						<?php else : ?>
							<p><em><?php esc_html_e( 'No outro loaded.', 'connector-for-vozcaster' ); ?></em></p>
						<?php endif; ?>
						<form method="post" action="" enctype="multipart/form-data">
							<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<input type="hidden" name="vpconn_action" value="upload_outro">
							<input type="file" name="audio_file" accept="audio/*" style="margin-right:8px;">
							<button type="submit" class="button button-secondary">
								<?php $outro['exists'] ? esc_html_e( 'Replace outro', 'connector-for-vozcaster' ) : esc_html_e( 'Upload outro', 'connector-for-vozcaster' ); ?>
							</button>
						</form>
						<form method="post" action="" id="vpconn_outro_select_form">
							<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<input type="hidden" name="vpconn_action" value="select_outro_from_library">
							<input type="hidden" name="attachment_id" id="vpconn_outro_media_id" value="">
						</form>
						<button type="button" class="button button-secondary" style="margin-top:6px;" onclick="vpconnOpenMediaPicker('outro')">
							<?php esc_html_e( 'Select from media library', 'connector-for-vozcaster' ); ?>
						</button>
					</td>
				</tr>
			</table>

			<hr>

			<?php /* ----- Audio mix settings ----- */ ?>
			<h2><?php esc_html_e( 'Audio mix settings (intro/outro)', 'connector-for-vozcaster' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Define how intro and outro music is mixed with the podcast audio. These are the default values and can be changed from the Telegram bot.', 'connector-for-vozcaster' ); ?>
			</p>

			<form method="post" action="">
				<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<input type="hidden" name="vpconn_action" value="save_mix_config">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="intro_duck_start"><?php esc_html_e( 'Intro duck-in second', 'connector-for-vozcaster' ); ?></label>
						</th>
						<td>
							<input
								type="number" id="intro_duck_start" name="intro_duck_start"
								value="<?php echo esc_attr( $duck_start ); ?>"
								min="0" step="any" style="width:80px;"
							> <?php esc_html_e( 'seconds', 'connector-for-vozcaster' ); ?>
							<p class="description"><?php esc_html_e( 'The intro plays at 100% until this second. From here it starts fading down and the podcast voice fades in.', 'connector-for-vozcaster' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="intro_duck_volume"><?php esc_html_e( 'Intro volume under podcast', 'connector-for-vozcaster' ); ?></label>
						</th>
						<td>
							<input
								type="number" id="intro_duck_volume" name="intro_duck_volume"
								value="<?php echo esc_attr( $duck_vol ); ?>"
								min="1" max="100" step="1" style="width:80px;"
							> %
							<p class="description"><?php esc_html_e( 'Volume to which the intro fades during duck-in, before reaching 0. Example: 30 = intro fades to 30% while the voice comes in.', 'connector-for-vozcaster' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="intro_fade_end"><?php esc_html_e( 'Second at which intro reaches 0', 'connector-for-vozcaster' ); ?></label>
						</th>
						<td>
							<input
								type="number" id="intro_fade_end" name="intro_fade_end"
								value="<?php echo esc_attr( $fade_end ); ?>"
								min="0" step="any" style="width:80px;"
								placeholder="—"
							> <?php esc_html_e( 'seconds (empty = until the end of the intro file)', 'connector-for-vozcaster' ); ?>
							<p class="description"><?php esc_html_e( 'Exact second at which the intro reaches silence. If left empty, the fade lasts until the end of the intro file.', 'connector-for-vozcaster' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="intro_knee_delay"><?php esc_html_e( 'Knee: seconds to reach duck volume', 'connector-for-vozcaster' ); ?></label>
						</th>
						<td>
							<input
								type="number" id="intro_knee_delay" name="intro_knee_delay"
								value="<?php echo esc_attr( $knee_delay ); ?>"
								min="0" step="any" style="width:80px;"
								placeholder="—"
							> <?php esc_html_e( 'seconds after duck start (empty = linear)', 'connector-for-vozcaster' ); ?>
							<p class="description">
								<?php esc_html_e( 'Seconds after the duck start at which the intro has already dropped to the duck volume %. A short value (e.g. 1) gives a sharp initial drop followed by a long gentle tail. The outro uses the exact mirror curve automatically. Leave empty for a linear fade.', 'connector-for-vozcaster' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="outro_fade_start"><?php esc_html_e( 'Seconds before end for outro fade-in', 'connector-for-vozcaster' ); ?></label>
						</th>
						<td>
							<input
								type="number" id="outro_fade_start" name="outro_fade_start"
								value="<?php echo esc_attr( $outro_start ); ?>"
								min="1" step="any" style="width:80px;"
							> <?php esc_html_e( 'seconds', 'connector-for-vozcaster' ); ?>
							<p class="description"><?php esc_html_e( 'The outro starts playing in the background this many seconds before the podcast voice ends. When the voice ends, it rises to 100%.', 'connector-for-vozcaster' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="outro_duck_volume"><?php esc_html_e( 'Outro volume under podcast', 'connector-for-vozcaster' ); ?></label>
						</th>
						<td>
							<input
								type="number" id="outro_duck_volume" name="outro_duck_volume"
								value="<?php echo esc_attr( $outro_vol ); ?>"
								min="1" max="100" step="1" style="width:80px;"
							> %
							<p class="description"><?php esc_html_e( 'Maximum volume of the outro while the podcast voice is still playing. When the voice ends, the outro rises to 100%.', 'connector-for-vozcaster' ); ?></p>
						</td>
					</tr>
				</table>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save mix settings', 'connector-for-vozcaster' ); ?></button></p>
			</form>

			<hr>

			<?php /* ----- Post footer ----- */ ?>
			<h2><?php esc_html_e( 'Post footer (signature)', 'connector-for-vozcaster' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'HTML or Gutenberg block markup appended automatically to every post published by the bot. You can also edit it from the Telegram bot with /firma.', 'connector-for-vozcaster' ); ?>
			</p>

			<form method="post" action="">
				<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<input type="hidden" name="vpconn_action" value="save_post_footer">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="post_footer"><?php esc_html_e( 'Footer content', 'connector-for-vozcaster' ); ?></label>
						</th>
						<td>
							<textarea
								id="post_footer"
								name="post_footer"
								rows="10"
								style="width:100%; font-family:monospace;"
							><?php echo esc_textarea( $post_footer ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Accepts raw HTML and Gutenberg block comments. Appended verbatim after the episode content. Leave empty to disable.', 'connector-for-vozcaster' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save footer', 'connector-for-vozcaster' ); ?></button></p>
			</form>

			<hr>

			<?php /* ----- Episode log ----- */ ?>
			<h2><?php esc_html_e( 'Recent published episodes', 'connector-for-vozcaster' ); ?></h2>
			<?php if ( empty( $log ) ) : ?>
				<p><?php esc_html_e( 'No episodes have been published with the bot yet.', 'connector-for-vozcaster' ); ?></p>
			<?php else : ?>
				<table class="widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'connector-for-vozcaster' ); ?></th>
							<th><?php esc_html_e( 'Title', 'connector-for-vozcaster' ); ?></th>
							<th><?php esc_html_e( 'Author', 'connector-for-vozcaster' ); ?></th>
							<th><?php esc_html_e( 'Status', 'connector-for-vozcaster' ); ?></th>
							<th><?php esc_html_e( 'Link', 'connector-for-vozcaster' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_reverse( $log ) as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( $entry['published'] ); ?></td>
								<td><?php echo esc_html( $entry['title'] ); ?></td>
								<td><?php echo esc_html( $entry['author'] ); ?></td>
								<td><?php echo esc_html( $entry['status'] ); ?></td>
								<td>
									<?php if ( ! empty( $entry['url'] ) ) : ?>
										<a href="<?php echo esc_url( $entry['url'] ); ?>" target="_blank"><?php esc_html_e( 'View', 'connector-for-vozcaster' ); ?></a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<form method="post" action="" style="margin-top:8px;">
					<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<input type="hidden" name="vpconn_action" value="clear_log">
					<button type="submit" class="button button-secondary">
						<?php esc_html_e( 'Clear history', 'connector-for-vozcaster' ); ?>
					</button>
				</form>
			<?php endif; ?>

		<script>
		function vpconnOpenMediaPicker( type ) {
			var titles = {
				intro: '<?php echo esc_js( __( 'Select intro audio', 'connector-for-vozcaster' ) ); ?>',
				outro: '<?php echo esc_js( __( 'Select outro audio', 'connector-for-vozcaster' ) ); ?>'
			};
			var frame = wp.media( {
				title:    titles[ type ] || titles.intro,
				button:   { text: '<?php echo esc_js( __( 'Select', 'connector-for-vozcaster' ) ); ?>' },
				library:  { type: 'audio' },
				multiple: false
			} );
			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				document.getElementById( 'vpconn_' + type + '_media_id' ).value = attachment.id;
				document.getElementById( 'vpconn_' + type + '_select_form' ).submit();
			} );
			frame.open();
		}
		</script>

		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Log management
	// -------------------------------------------------------------------------

	public static function create_log_option(): void {
		if ( false === get_option( self::OPTION_LOG ) ) {
			add_option( self::OPTION_LOG, [] );
		}
	}

	/**
	 * Appends an entry to the episode log (max 10 entries).
	 *
	 * @param array{post_id: int, title: string, status: string, author: string, published: string, url: string} $entry
	 */
	public static function add_to_log( array $entry ): void {
		$log   = self::get_log();
		$log[] = $entry;

		if ( count( $log ) > self::LOG_MAX_ENTRIES ) {
			$log = array_slice( $log, -self::LOG_MAX_ENTRIES );
		}

		update_option( self::OPTION_LOG, $log );
	}

	/**
	 * @return array<int, array{post_id: int, title: string, status: string, author: string, published: string, url: string}>
	 */
	public static function get_log(): array {
		return (array) get_option( self::OPTION_LOG, [] );
	}

	// -------------------------------------------------------------------------
	// Feed / podcast permissions
	// -------------------------------------------------------------------------

	/** @return array<int, array<string, string>> user_id → [feed_slug → 'publish'|'draft'] */
	public static function get_all_feed_permissions(): array {
		return (array) get_option( self::OPTION_FEED_PERMISSIONS, [] );
	}

	/** @return array<string, string>  feed_slug → 'publish'|'draft' (empty = no restrictions) */
	public static function get_user_feed_permissions( int $user_id ): array {
		$all = self::get_all_feed_permissions();
		return (array) ( $all[ $user_id ] ?? [] );
	}

	public static function set_feed_permissions( array $permissions ): void {
		update_option( self::OPTION_FEED_PERMISSIONS, $permissions );
	}

	// -------------------------------------------------------------------------
	// PowerPress feeds
	// -------------------------------------------------------------------------

	/**
	 * Returns the list of feeds configured in PowerPress.
	 *
	 * PowerPress stores custom channels in `powerpress_general.custom_feeds`
	 * as a `{ slug: name }` map. Per-channel configuration lives in
	 * `powerpress_feed_{slug}`. The default feed lives in `powerpress_feed`
	 * and is exposed under slug `podcast`.
	 *
	 * @return array<int, array{slug: string, name: string}>
	 */
	public static function get_powerpress_feeds(): array {
		$feeds         = [];
		$added_slugs   = [];
		$has_cat_feeds = false;

		// Default feed → slug 'podcast'.
		// category_slug is resolved after processing all feeds (see below).
		$default_opt  = get_option( 'powerpress_feed', [] );
		$default_name = is_array( $default_opt ) ? ( $default_opt['title'] ?? '' ) : '';
		if ( ! $default_name ) {
			$default_name = get_bloginfo( 'name' );
		}
		$podcast_cat      = get_term_by( 'slug', 'podcast', 'category' );
		$feeds[]          = [
			'slug'         => 'podcast',
			'name'         => $default_name,
			'category_slug' => $podcast_cat ? 'podcast' : null,
		];
		$added_slugs[] = 'podcast';

		// Category-podcasting feeds: PowerPress stores one option per category
		// that has its own podcast feed, named `powerpress_cat_feed_{term_id}`.
		// The category slug is the WordPress category slug — it is both the feed
		// identifier and the exact category to assign to published posts.
		global $wpdb;
		// Direct query: there is no WordPress API to look up options by a name
		// pattern. The LIKE pattern is a static literal with no user input, so it
		// needs no preparation; this runs only on the admin settings screen.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$cat_feed_opts = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options}
			  WHERE option_name LIKE 'powerpress_cat_feed_%'"
		);
		foreach ( $cat_feed_opts as $opt_name ) {
			$term_id = (int) substr( $opt_name, strlen( 'powerpress_cat_feed_' ) );
			if ( $term_id <= 0 ) {
				continue;
			}
			$term = get_term( $term_id, 'category' );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			$slug = sanitize_key( $term->slug );
			if ( '' === $slug || in_array( $slug, $added_slugs, true ) ) {
				continue;
			}
			$cat_feed = get_option( $opt_name, [] );
			$title    = is_array( $cat_feed ) ? ( $cat_feed['title'] ?? '' ) : '';
			if ( ! $title ) {
				$title = $term->name ?: ucwords( str_replace( [ '-', '_' ], ' ', $slug ) );
			}
			$feeds[]       = [
				'slug'          => $slug,
				'name'          => $title,
				'category_slug' => $slug, // category slug === feed slug for cat feeds
			];
			$added_slugs[] = $slug;
			$has_cat_feeds = true;
		}

		// Custom channels declared in powerpress_general.custom_feeds.
		// These are named podcast channels (e.g. 'presente', 'premiumpp') that
		// coexist alongside the main feed. category_slug is resolved by looking
		// for a WordPress category with the same slug.
		$general      = get_option( 'powerpress_general', [] );
		$has_named_feeds = false;
		if ( is_array( $general ) && ! empty( $general['custom_feeds'] ) && is_array( $general['custom_feeds'] ) ) {
			foreach ( $general['custom_feeds'] as $slug => $name ) {
				$slug = sanitize_key( (string) $slug );
				if ( '' === $slug || in_array( $slug, $added_slugs, true ) ) {
					continue;
				}
				$feed_opt = get_option( 'powerpress_feed_' . $slug, [] );
				$title    = is_array( $feed_opt ) ? ( $feed_opt['title'] ?? '' ) : '';
				if ( ! $title ) {
					$title = is_string( $name ) ? $name : ucwords( str_replace( [ '-', '_' ], ' ', $slug ) );
				}
				$cat      = get_term_by( 'slug', $slug, 'category' );
				$feeds[]  = [
					'slug'          => $slug,
					'name'          => $title,
					'category_slug' => $cat ? $cat->slug : null,
				];
				$added_slugs[]   = $slug;
				$has_named_feeds = true;
			}
		}

		// Backward compat: legacy `powerpress_feeds` option (plural) if present.
		$legacy = get_option( 'powerpress_feeds', null );
		if ( is_array( $legacy ) ) {
			foreach ( $legacy as $slug => $data ) {
				$slug = sanitize_key( (string) $slug );
				if ( '' === $slug || in_array( $slug, $added_slugs, true ) ) {
					continue;
				}
				$name = '';
				if ( is_array( $data ) ) {
					$name = $data['title'] ?? $data['name'] ?? $data['feed_title'] ?? '';
				} elseif ( is_string( $data ) ) {
					$name = $data;
				}
				if ( ! $name ) {
					$feed_opt = get_option( 'powerpress_feed_' . $slug, [] );
					$name     = is_array( $feed_opt ) ? ( $feed_opt['title'] ?? '' ) : '';
				}
				$cat     = get_term_by( 'slug', $slug, 'category' );
				$feeds[] = [
					'slug'          => $slug,
					'name'          => $name ?: ucwords( str_replace( [ '-', '_' ], ' ', $slug ) ),
					'category_slug' => $cat ? $cat->slug : null,
				];
				$added_slugs[] = $slug;
			}
		}

		// If the site uses only category-based feeds (no named custom feeds),
		// the generic 'podcast' entry is redundant — the category feeds already
		// represent the full podcast. Removing it avoids presenting a confusing
		// duplicate option in the bot's feed selector.
		// Sites with named custom feeds (Potencia Pro) keep the generic entry
		// because the main podcast and the named channels are separate things.
		if ( $has_cat_feeds && ! $has_named_feeds ) {
			$feeds = array_values(
				array_filter( $feeds, fn( $f ) => $f['slug'] !== 'podcast' )
			);
		}

		return $feeds;
	}
}
