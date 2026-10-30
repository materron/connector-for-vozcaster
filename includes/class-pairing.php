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
 *    VozCaster → Connect page.
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

	const PAGE_SLUG       = 'vozcaster';
	const CODE_PATTERN    = '/^[a-f0-9]{16}$/';
	const CODE_TTL        = 15 * MINUTE_IN_SECONDS;
	const TRANSIENT       = 'vpconn_pair_';
	const META_DISMISSED  = 'vpconn_connect_notice_dismissed';
	const START_MAX_CHARS = 64;

	public function register_hooks(): void {
		// Before VPConn_Settings (priority 10), which hangs its pages from this menu.
		add_action( 'admin_menu', [ $this, 'add_page' ], 9 );
		add_action( 'admin_post_vpconn_pair', [ $this, 'handle_pair' ] );
		add_action( 'admin_init', [ $this, 'handle_dismiss' ] );
		add_action( 'admin_notices', [ $this, 'show_connect_notice' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_shortcode( 'vozcaster_connect', [ $this, 'shortcode' ] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	public static function get_bot_username(): string {
		return ltrim( (string) get_option( 'vpconn_bot_username', 'VozCasterBot' ), '@' );
	}

	/**
	 * Address of the VozCaster web app ("Connect in the app"). Filterable and
	 * overridable with the vpconn_app_url option (test installs).
	 */
	public static function get_app_url(): string {
		$url = (string) get_option( 'vpconn_app_url', 'https://app.vozcaster.com' );
		return untrailingslashit( (string) apply_filters( 'vpconn_app_url', esc_url_raw( $url ) ) );
	}

	public static function get_page_url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
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
		// Top-level menu, visible to every authorized user. Its first item is
		// this page; administrators also get the settings pages under it.
		add_menu_page(
			__( 'Connect with VozCaster', 'connector-for-vozcaster' ),
			__( 'VozCaster', 'connector-for-vozcaster' ),
			'read',
			self::PAGE_SLUG,
			[ $this, 'render_page' ],
			'dashicons-microphone',
			58
		);
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Connect with VozCaster', 'connector-for-vozcaster' ),
			__( 'Connect', 'connector-for-vozcaster' ),
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
		$feeds      = VPConn_Access::feeds_for_user( $user_id );
		$connected  = VPConn_Auth::has_token( $user_id );
		$bot_url    = 'https://t.me/' . self::get_bot_username();
		$pp_active  = defined( 'POWERPRESS_VERSION' ) || function_exists( 'powerpress_get_settings' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Connect with VozCaster', 'connector-for-vozcaster' ); ?></h1>
			<p style="max-width:640px;">
				<?php esc_html_e( 'You talk, VozCaster does the rest: it cleans your audio, writes the post, makes the cover and publishes the episode on this site. Connect your account once, then record in the VozCaster app or in Telegram.', 'connector-for-vozcaster' ); ?>
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
									<?php foreach ( [ 'app', 'telegram' ] as $target ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" target="_blank" style="margin:0 0 0 6px;display:inline-block;">
											<?php wp_nonce_field( 'vpconn_pair' ); ?>
											<input type="hidden" name="action" value="vpconn_pair">
											<input type="hidden" name="target" value="<?php echo esc_attr( $target ); ?>">
											<input type="hidden" name="feed" value="<?php echo esc_attr( $feed['slug'] ); ?>">
											<button type="submit" class="button <?php echo 'app' === $target ? 'button-primary' : ''; ?>">
												<?php
												'app' === $target
													? esc_html_e( 'Connect in the app', 'connector-for-vozcaster' )
													: esc_html_e( 'Connect in Telegram', 'connector-for-vozcaster' );
												?>
											</button>
										</form>
									<?php endforeach; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description" style="max-width:640px;">
					<?php esc_html_e( 'The app opens in a new tab, ready to record from your phone or computer; you can add it to your home screen. Telegram opens the bot instead: press Start and confirm there. Each link works once and expires in 15 minutes.', 'connector-for-vozcaster' ); ?>
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
	 * [vozcaster_connect] — the "Connect in Telegram" buttons on any page of the
	 * site, so authorized users (e.g. teachers with a custom role) can connect
	 * without ever opening wp-admin.
	 *
	 * Attributes: feed — show only that podcast feed (slug).
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts( [ 'feed' => '' ], (array) $atts, 'vozcaster_connect' );

		ob_start();
		echo '<div class="vpconn-connect">';

		if ( ! is_user_logged_in() ) {
			printf(
				'<p>%1$s</p><p><a class="wp-element-button button" href="%2$s">%3$s</a></p>',
				esc_html__( 'Log in with your account on this site to connect it with the VozCaster bot and publish podcast episodes from Telegram.', 'connector-for-vozcaster' ),
				esc_url( wp_login_url( (string) get_permalink() ) ),
				esc_html__( 'Log in', 'connector-for-vozcaster' )
			);
			echo '</div>';
			return (string) ob_get_clean();
		}

		$user_id = get_current_user_id();
		if ( ! VPConn_Auth::is_user_allowed( $user_id ) ) {
			echo '<p>' . esc_html__( 'Your account is not authorized to publish from VozCaster yet. Ask the site administrator for access.', 'connector-for-vozcaster' ) . '</p></div>';
			return (string) ob_get_clean();
		}

		$feeds = VPConn_Access::feeds_for_user( $user_id );
		if ( '' !== $atts['feed'] ) {
			$only  = sanitize_key( $atts['feed'] );
			$feeds = array_values( array_filter( $feeds, static fn( $f ) => $f['slug'] === $only ) ) ?: $feeds;
		}
		if ( ! $feeds ) {
			echo '<p>' . esc_html__( 'Your account cannot send episodes to any podcast of this site yet. Ask the site administrator for access.', 'connector-for-vozcaster' ) . '</p></div>';
			return (string) ob_get_clean();
		}

		if ( VPConn_Auth::has_token( $user_id ) ) {
			echo '<p><strong>' . esc_html__( 'Your account is already connected to the bot.', 'connector-for-vozcaster' ) . '</strong> ' . esc_html__( 'Connecting again replaces the previous connection.', 'connector-for-vozcaster' ) . '</p>';
		}

		if ( null === self::get_encoded_host() ) {
			printf(
				'<p>%1$s</p><p><code>%2$s</code></p><p><a class="wp-element-button button" href="%3$s" target="_blank" rel="noopener">%4$s</a></p>',
				esc_html__( 'Open the bot, send /conectar and paste this address:', 'connector-for-vozcaster' ),
				esc_html( home_url() ),
				esc_url( 'https://t.me/' . self::get_bot_username() ),
				esc_html__( 'Open the bot in Telegram', 'connector-for-vozcaster' )
			);
			echo '</div>';
			return (string) ob_get_clean();
		}

		foreach ( $feeds as $feed ) {
			foreach ( [ 'app', 'telegram' ] as $target ) {
				if ( 'app' === $target ) {
					$label = count( $feeds ) > 1
						/* translators: %s: podcast name. */
						? sprintf( __( 'Connect «%s» in the app', 'connector-for-vozcaster' ), $feed['name'] )
						: __( 'Connect in the app', 'connector-for-vozcaster' );
				} else {
					$label = count( $feeds ) > 1
						/* translators: %s: podcast name. */
						? sprintf( __( 'Connect «%s» in Telegram', 'connector-for-vozcaster' ), $feed['name'] )
						: __( 'Connect in Telegram', 'connector-for-vozcaster' );
				}
				?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" target="_blank" style="margin:0 8px 12px 0;display:inline-block;">
					<?php wp_nonce_field( 'vpconn_pair' ); ?>
					<input type="hidden" name="action" value="vpconn_pair">
					<input type="hidden" name="target" value="<?php echo esc_attr( $target ); ?>">
					<input type="hidden" name="feed" value="<?php echo esc_attr( $feed['slug'] ); ?>">
					<button type="submit" class="wp-element-button button"><?php echo esc_html( $label ); ?></button>
				</form>
				<?php
			}
		}
		echo '<p><small>' . esc_html__( 'The app opens in a new tab, ready to record from your phone or computer. Telegram opens the bot instead: press Start and confirm there. Each link works once and expires in 15 minutes.', 'connector-for-vozcaster' ) . '</small></p>';
		echo '</div>';
		return (string) ob_get_clean();
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
		if ( ! $feed || null === $host || '' === VPConn_Access::user_level( $user_id, $feed['slug'] ) ) {
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
		// So the connection also works when this site's hosting blocks VozCaster.
		VPConn_Relay::register_pairing( $code, $user_id, self::describe( wp_get_current_user(), $feed ) );

		$target = sanitize_key( wp_unslash( $_POST['target'] ?? 'telegram' ) );
		if ( 'app' === $target ) {
			// The app hands the same code to VozCaster, which redeems it like the bot does.
			$app_url  = self::get_app_url();
			$app_host = (string) wp_parse_url( $app_url, PHP_URL_HOST );
			add_filter(
				'allowed_redirect_hosts',
				static function ( array $hosts ) use ( $app_host ): array {
					$hosts[] = $app_host;
					return $hosts;
				}
			);
			wp_safe_redirect( $app_url . '/#pair=' . $code . $host );
			exit;
		}

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
		if ( ! $screen || 'toplevel_page_' . self::PAGE_SLUG === $screen->id ) {
			return;
		}
		if ( ! in_array( $screen->id, [ 'dashboard', 'plugins' ], true ) && ! VPConn_Settings::is_plugin_screen( $screen->id ) ) {
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
		$allowed = VPConn_Access::feeds_for_user( $user->ID );
		$feed    = self::find_feed( (string) ( $data['feed'] ?? '' ) );
		if ( ! $feed || '' === VPConn_Access::user_level( $user->ID, $feed['slug'] ) ) {
			$feed = $allowed[0] ?? null;
		}
		if ( ! $feed ) {
			return new WP_Error( 'vpconn_pair_not_allowed', 'User not authorized.', [ 'status' => 403 ] );
		}
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
		// Redeemed directly: the copy registered with VozCaster is never used.
		VPConn_Relay::forget_pending_code( $resolved['user']->ID, $code );

		$data          = self::describe( $resolved['user'], $resolved['feed'] );
		$data['token'] = VPConn_Auth::generate_user_token( $resolved['user']->ID );

		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store, no-cache' );
		return $response;
	}
}
