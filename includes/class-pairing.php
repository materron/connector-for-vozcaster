<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connects the Telegram bot from wp-admin, without typing the site address or
 * logging in again.
 *
 * Flow:
 * 1. An authorized user clicks "Connect in Telegram" for a podcast feed on the
 *    Users → VozCaster page (Profile → VozCaster for non-admins).
 * 2. The plugin stores a single-use code (user + feed, 15 minutes) and sends
 *    the browser to t.me/<bot>?start=<code><host>. The host travels in the
 *    link because the bot needs to know which site to call back.
 * 3. The bot asks for confirmation (GET /pair/info) and then redeems the code
 *    (POST /pair/claim), which returns the user's bot token.
 *
 * When the site address does not fit in Telegram's start parameter (64 chars
 * of [A-Za-z0-9_-]: long host, http, a port or WordPress in a subfolder), the
 * page falls back to the /conectar instructions.
 */
class VPConn_Pairing {

	const PAGE_SLUG       = 'vpconn-connect';
	const CODE_PATTERN    = '/^[a-f0-9]{16}$/';
	const CODE_TTL        = 15 * MINUTE_IN_SECONDS;
	const TRANSIENT       = 'vpconn_pair_';
	const META_DISMISSED  = 'vpconn_connect_notice_dismissed';
	const START_MAX_CHARS = 64;

	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
		add_action( 'admin_post_vpconn_pair', [ $this, 'handle_pair' ] );
		add_action( 'admin_init', [ $this, 'handle_dismiss' ] );
		add_action( 'admin_notices', [ $this, 'show_connect_notice' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	public static function get_bot_username(): string {
		return ltrim( (string) get_option( 'vpconn_bot_username', 'VozCasterBot' ), '@' );
	}

	public static function get_page_url(): string {
		return admin_url( ( current_user_can( 'list_users' ) ? 'users.php' : 'profile.php' ) . '?page=' . self::PAGE_SLUG );
	}

	/**
	 * Site host encoded for Telegram's start parameter ('.' → '_'), or null when
	 * the address cannot be rebuilt by the bot as https://<host>.
	 */
	public static function get_encoded_host(): ?string {
		$parts = wp_parse_url( home_url() );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || isset( $parts['port'] ) ) {
			return null;
		}
		if ( '' !== trim( (string) ( $parts['path'] ?? '' ), '/' ) ) {
			return null;
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( ! preg_match( '/^[a-z0-9.-]+$/', $host ) ) {
			return null;
		}
		$encoded = str_replace( '.', '_', $host );
		if ( 16 + strlen( $encoded ) > self::START_MAX_CHARS ) {
			return null;
		}
		return $encoded;
	}

	/** @return array{slug: string, name: string, category_slug: ?string}|null */
	private static function find_feed( string $slug ): ?array {
		foreach ( VPConn_Settings::get_powerpress_feeds() as $feed ) {
			if ( $feed['slug'] === $slug ) {
				return $feed;
			}
		}
		return null;
	}

	// -------------------------------------------------------------------------
	// Admin page
	// -------------------------------------------------------------------------

	public function add_page(): void {
		if ( ! VPConn_Auth::is_user_allowed( get_current_user_id() ) ) {
			return;
		}
		add_users_page(
			__( 'Connect with VozCaster', 'connector-for-vozcaster' ),
			__( 'VozCaster', 'connector-for-vozcaster' ),
			'read',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function render_page(): void {
		$user_id = get_current_user_id();
		if ( ! VPConn_Auth::is_user_allowed( $user_id ) ) {
			return;
		}

		$host       = self::get_encoded_host();
		$feeds      = VPConn_Settings::get_powerpress_feeds();
		$connected  = VPConn_Auth::has_token( $user_id );
		$bot_url    = 'https://t.me/' . self::get_bot_username();
		$pp_active  = defined( 'POWERPRESS_VERSION' ) || function_exists( 'powerpress_get_settings' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Connect with VozCaster', 'connector-for-vozcaster' ); ?></h1>
			<p style="max-width:640px;">
				<?php esc_html_e( 'VozCaster is a Telegram bot that turns your voice notes into published podcast episodes on this site. Connect your account once and publish from Telegram.', 'connector-for-vozcaster' ); ?>
			</p>

			<?php if ( ! $pp_active ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'PowerPress is not active. Install and activate it before connecting, so episodes reach your podcast feed.', 'connector-for-vozcaster' ); ?>
				</p></div>
			<?php endif; ?>

			<?php if ( $connected ) : ?>
				<div class="notice notice-success inline"><p>
					<?php esc_html_e( 'Your account is already connected to the bot. Connecting again replaces the previous connection, and with several podcasts it switches the one you publish to.', 'connector-for-vozcaster' ); ?>
				</p></div>
			<?php endif; ?>

			<?php if ( null !== $host ) : ?>
				<table class="widefat striped" style="max-width:640px;margin-top:16px;">
					<tbody>
						<?php foreach ( $feeds as $feed ) : ?>
							<tr>
								<td style="vertical-align:middle;"><strong><?php echo esc_html( $feed['name'] ); ?></strong></td>
								<td style="text-align:right;">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" target="_blank" style="margin:0;">
										<?php wp_nonce_field( 'vpconn_pair' ); ?>
										<input type="hidden" name="action" value="vpconn_pair">
										<input type="hidden" name="feed" value="<?php echo esc_attr( $feed['slug'] ); ?>">
										<button type="submit" class="button button-primary">
											<?php esc_html_e( 'Connect in Telegram', 'connector-for-vozcaster' ); ?>
										</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description" style="max-width:640px;">
					<?php esc_html_e( 'Telegram opens with the bot. Press Start and confirm the connection there. The link works once and expires in 15 minutes.', 'connector-for-vozcaster' ); ?>
				</p>
			<?php else : ?>
				<p style="max-width:640px;">
					<?php esc_html_e( 'The address of this site cannot be passed to the bot automatically. Open the bot, send /conectar and paste this address:', 'connector-for-vozcaster' ); ?>
				</p>
				<p><code><?php echo esc_html( home_url() ); ?></code></p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( $bot_url ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'Open the bot in Telegram', 'connector-for-vozcaster' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * admin-post.php?action=vpconn_pair — creates the code and opens Telegram.
	 */
	public function handle_pair(): void {
		check_admin_referer( 'vpconn_pair' );

		$user_id = get_current_user_id();
		if ( ! VPConn_Auth::is_user_allowed( $user_id ) ) {
			wp_die( esc_html__( 'Your role is not authorized to publish from VozCaster.', 'connector-for-vozcaster' ), '', [ 'response' => 403 ] );
		}

		$feed = self::find_feed( sanitize_key( wp_unslash( $_POST['feed'] ?? '' ) ) );
		$host = self::get_encoded_host();
		if ( ! $feed || null === $host ) {
			wp_safe_redirect( self::get_page_url() );
			exit;
		}

		$code = bin2hex( random_bytes( 8 ) );
		set_transient(
			self::TRANSIENT . $code,
			[
				'user_id' => $user_id,
				'feed'    => $feed['slug'],
			],
			self::CODE_TTL
		);

		add_filter(
			'allowed_redirect_hosts',
			static function ( array $hosts ): array {
				$hosts[] = 't.me';
				return $hosts;
			}
		);
		wp_safe_redirect( 'https://t.me/' . self::get_bot_username() . '?start=' . $code . $host );
		exit;
	}

	// -------------------------------------------------------------------------
	// "Connect" notice after activation
	// -------------------------------------------------------------------------

	public function show_connect_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, [ 'dashboard', 'plugins', 'settings_page_connector-for-vozcaster' ], true ) ) {
			return;
		}
		$user_id = get_current_user_id();
		if (
			! VPConn_Auth::is_user_allowed( $user_id )
			|| VPConn_Auth::has_token( $user_id )
			|| get_user_meta( $user_id, self::META_DISMISSED, true )
		) {
			return;
		}
		$dismiss_url = wp_nonce_url( add_query_arg( 'vpconn_dismiss_connect', 1 ), 'vpconn_dismiss_connect' );
		?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'Publish your podcast from Telegram.', 'connector-for-vozcaster' ); ?></strong>
				<?php esc_html_e( 'Connect your account with the VozCaster bot in one click.', 'connector-for-vozcaster' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( self::get_page_url() ); ?>"><?php esc_html_e( 'Connect with VozCaster', 'connector-for-vozcaster' ); ?></a>
				&nbsp;<a href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Dismiss', 'connector-for-vozcaster' ); ?></a>
			</p>
		</div>
		<?php
	}

	public function handle_dismiss(): void {
		if ( empty( $_GET['vpconn_dismiss_connect'] ) ) {
			return;
		}
		check_admin_referer( 'vpconn_dismiss_connect' );
		update_user_meta( get_current_user_id(), self::META_DISMISSED, 1 );
		wp_safe_redirect( remove_query_arg( [ 'vpconn_dismiss_connect', '_wpnonce' ] ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// REST: the bot redeems the code
	// -------------------------------------------------------------------------

	public function register_routes(): void {
		$code_arg = [
			'required'          => true,
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		];

		// GET /pair/info — what the code would connect, so the bot can ask first.
		// Public: the single-use, short-lived random code is the credential.
		register_rest_route(
			VPConn_API::NAMESPACE,
			'/pair/info',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'pair_info' ],
				'permission_callback' => '__return_true',
				'args'                => [ 'code' => $code_arg ],
			]
		);

		// POST /pair/claim — redeems the code and returns the user's bot token.
		register_rest_route(
			VPConn_API::NAMESPACE,
			'/pair/claim',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'pair_claim' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'code'        => $code_arg,
					'telegram_id' => [
						'required' => false,
						'type'     => 'integer',
						'default'  => 0,
					],
				],
			]
		);
	}

	/**
	 * Resolves a pairing code to its user and feed, or a WP_Error.
	 *
	 * @return array{user: WP_User, feed: array}|WP_Error
	 */
	private static function resolve_code( string $code ): array|WP_Error {
		$invalid = new WP_Error( 'vpconn_pair_invalid', 'Invalid or expired code.', [ 'status' => 404 ] );
		if ( ! preg_match( self::CODE_PATTERN, $code ) ) {
			return $invalid;
		}
		$data = get_transient( self::TRANSIENT . $code );
		if ( ! is_array( $data ) ) {
			return $invalid;
		}
		$user = get_userdata( (int) ( $data['user_id'] ?? 0 ) );
		if ( ! $user || ! VPConn_Auth::is_user_allowed( $user->ID ) ) {
			return new WP_Error( 'vpconn_pair_not_allowed', 'User not authorized.', [ 'status' => 403 ] );
		}
		$feed = self::find_feed( (string) ( $data['feed'] ?? '' ) ) ?? VPConn_Settings::get_powerpress_feeds()[0];
		return [
			'user' => $user,
			'feed' => $feed,
		];
	}

	private static function describe( WP_User $user, array $feed ): array {
		return [
			'site_name'     => get_bloginfo( 'name' ),
			'site_url'      => home_url(),
			'wp_username'   => $user->user_login,
			'display_name'  => $user->display_name,
			'feed_slug'     => $feed['slug'],
			'feed_name'     => $feed['name'],
			'category_slug' => $feed['category_slug'] ?? null,
		];
	}

	public function pair_info( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$resolved = self::resolve_code( (string) $request->get_param( 'code' ) );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$response = new WP_REST_Response( self::describe( $resolved['user'], $resolved['feed'] ) );
		$response->header( 'Cache-Control', 'no-store, no-cache' );
		return $response;
	}

	public function pair_claim( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$code     = (string) $request->get_param( 'code' );
		$resolved = self::resolve_code( $code );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		// Single use: drop the code before handing out the token.
		delete_transient( self::TRANSIENT . $code );

		$data          = self::describe( $resolved['user'], $resolved['feed'] );
		$data['token'] = VPConn_Auth::generate_user_token( $resolved['user']->ID );

		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store, no-cache' );
		return $response;
	}
}
