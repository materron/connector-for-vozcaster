<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sites whose hosting does not let VozCaster in.
 *
 * Some hostings answer any request that does not come from a browser with a
 * "checking your browser" page. VozCaster then cannot reach this plugin, but
 * this site can still reach VozCaster. For those sites only:
 *
 * 1. Connecting: when a user presses "Connect", the plugin registers the
 *    single-use code with VozCaster, together with a new token for that user
 *    (kept here as pending until it is first used) and a snapshot of the
 *    site's settings. VozCaster uses it only if it cannot reach the site when
 *    the code is redeemed; otherwise the connection works as always and the
 *    registered copy is dropped.
 * 2. Publishing and changing settings: VozCaster keeps what it would have
 *    sent in a mailbox and gives the user a single-use link to this site
 *    (?vozcaster_pickup=<code>). Opened in the user's browser, the page asks
 *    for confirmation and then collects the mailbox one call at a time
 *    (REST /relay/step), running each call through this plugin's own REST
 *    endpoints with the user's token, exactly as if VozCaster had sent it.
 *    It ends by sending VozCaster a fresh snapshot of the site.
 */
class VPConn_Relay {

	const QUERY_VAR         = 'vozcaster_pickup';
	const CODE_PATTERN      = '/^[a-f0-9]{32}$/';
	const CHUNK_BYTES       = 8 * 1024 * 1024;
	const MAX_FILE_BYTES    = 30 * 1024 * 1024;
	const META_PENDING      = 'vpconn_pending_token_hash';
	const META_PENDING_INFO = 'vpconn_pending_token_info';
	const PENDING_TTL       = 30 * DAY_IN_SECONDS;

	public function register_hooks(): void {
		add_action( 'template_redirect', [ $this, 'maybe_render_pickup' ], 0 );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	// -------------------------------------------------------------------------
	// Talking to VozCaster
	// -------------------------------------------------------------------------

	private static function mater_url( string $path ): string {
		return VPConn_Pairing::get_app_url() . $path;
	}

	/**
	 * @return array|WP_Error Decoded JSON answer.
	 */
	private static function mater_request( string $method, string $path, $body = null, array $args = [] ) {
		$args = array_merge(
			[
				'method'  => $method,
				'timeout' => 30,
				'headers' => [ 'Accept' => 'application/json' ],
			],
			$args
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		$response = wp_remote_request( self::mater_url( $path ), $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 || ! is_array( $data ) ) {
			return new WP_Error( 'vpconn_relay_http', is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : 'HTTP ' . $code, [ 'status' => $code ] );
		}
		return $data;
	}

	// -------------------------------------------------------------------------
	// Pending tokens (connection registered while VozCaster could not reach us)
	// -------------------------------------------------------------------------

	private static function hash_token( string $token ): string {
		return hash( 'sha256', $token );
	}

	private static function add_pending_token( int $user_id, string $token, string $code ): void {
		$hash = self::hash_token( $token );
		$info = get_user_meta( $user_id, self::META_PENDING_INFO, true );
		$info = is_array( $info ) ? $info : [];
		// Forget expired ones while we are here.
		foreach ( $info as $h => $entry ) {
			if ( (int) ( $entry['expires'] ?? 0 ) < time() ) {
				unset( $info[ $h ] );
				delete_user_meta( $user_id, self::META_PENDING, $h );
			}
		}
		$info[ $hash ] = [
			'code'    => $code,
			'expires' => time() + self::PENDING_TTL,
		];
		add_user_meta( $user_id, self::META_PENDING, $hash );
		update_user_meta( $user_id, self::META_PENDING_INFO, $info );
	}

	/**
	 * The code was redeemed directly (VozCaster reached the site): the token
	 * registered with it must never become valid.
	 */
	public static function forget_pending_code( int $user_id, string $code ): void {
		$info = get_user_meta( $user_id, self::META_PENDING_INFO, true );
		if ( ! is_array( $info ) ) {
			return;
		}
		foreach ( $info as $hash => $entry ) {
			if ( ( $entry['code'] ?? '' ) === $code ) {
				unset( $info[ $hash ] );
				delete_user_meta( $user_id, self::META_PENDING, $hash );
			}
		}
		update_user_meta( $user_id, self::META_PENDING_INFO, $info );
	}

	/**
	 * A pending token used for the first time becomes the user's token (the
	 * connection made through VozCaster's copy is now real). Returns the user
	 * or false.
	 */
	public static function promote_pending_token( string $token ): int|false {
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return false;
		}
		$hash  = self::hash_token( $token );
		$users = get_users(
			[
				// Single lookup by a unique hash, only for tokens not found among the active ones.
				'meta_key'    => self::META_PENDING, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => $hash, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'      => 1,
				'fields'      => 'ID',
				'count_total' => false,
			]
		);
		if ( empty( $users ) ) {
			return false;
		}
		$user_id = (int) $users[0];
		$info    = get_user_meta( $user_id, self::META_PENDING_INFO, true );
		$entry   = is_array( $info ) ? ( $info[ $hash ] ?? null ) : null;
		if ( ! $entry || (int) ( $entry['expires'] ?? 0 ) < time() ) {
			return false;
		}
		update_user_meta( $user_id, VPConn_Auth::USER_META_TOKEN_HASH, $hash );
		delete_user_meta( $user_id, self::META_PENDING );
		delete_user_meta( $user_id, self::META_PENDING_INFO );
		return $user_id;
	}

	// -------------------------------------------------------------------------
	// Snapshot: the answers VozCaster needs while it cannot ask
	// -------------------------------------------------------------------------

	/**
	 * Runs one of this plugin's REST calls internally, as $user_id.
	 *
	 * @return array{status: int, data: mixed}
	 */
	private static function dispatch( int $user_id, string $method, string $route, array $args = [] ): array {
		$previous = get_current_user_id();
		wp_set_current_user( $user_id );
		try {
			$request = new WP_REST_Request( $method, '/' . VPConn_API::NAMESPACE . $route );
			foreach ( (array) ( $args['headers'] ?? [] ) as $name => $value ) {
				$request->set_header( $name, (string) $value );
			}
			if ( isset( $args['query'] ) ) {
				$request->set_query_params( (array) $args['query'] );
			}
			if ( isset( $args['json'] ) ) {
				$request->set_header( 'Content-Type', 'application/json' );
				$request->set_body( (string) wp_json_encode( $args['json'] ) );
				$request->set_body_params( [] );
			}
			if ( isset( $args['body'] ) ) {
				$request->set_body( (string) $args['body'] );
			}
			$response = rest_do_request( $request );
			$server   = rest_get_server();
			$data     = $server->response_to_data( $response, false );
			return [
				'status' => (int) $response->get_status(),
				'data'   => $data,
			];
		} finally {
			wp_set_current_user( $previous );
		}
	}

	/**
	 * GET answers keyed as VozCaster stores them ('/podcast?feed=podcast').
	 */
	public static function build_snapshot( int $user_id ): array {
		$routes = [];
		$calls  = [
			[ '/ping', [] ],
			[ '/settings', [] ],
			[ '/media/intro-outro', [] ],
			[ '/feeds', [] ],
		];
		foreach ( VPConn_Settings::get_powerpress_feeds() as $feed ) {
			foreach ( [ '/episode/next-number', '/podcast', '/distribution' ] as $route ) {
				$calls[] = [ $route, [ 'feed' => $feed['slug'] ] ];
			}
		}
		foreach ( $calls as [ $route, $query ] ) {
			$result = self::dispatch( $user_id, 'GET', $route, [ 'query' => $query ] );
			if ( 200 !== $result['status'] ) {
				continue;
			}
			$key            = $route . ( $query ? '?' . http_build_query( $query ) : '' );
			$routes[ $key ] = $result['data'];
		}
		return [ 'routes' => $routes ];
	}

	/** Local file of a media URL of this site (intro, outro, separator). */
	private static function media_file( string $url ): ?string {
		foreach ( [ 'intro', 'outro', 'separator' ] as $type ) {
			$info = VPConn_Media::get_intro_outro_info( $type );
			if ( ! empty( $info['exists'] ) && $info['url'] === $url ) {
				$path = get_attached_file( (int) $info['id'] );
				return $path && is_readable( $path ) ? $path : null;
			}
		}
		return null;
	}

	private static function send_media( string $path, array $urls, array $headers ): void {
		foreach ( $urls as $url ) {
			$file = self::media_file( (string) $url );
			if ( ! $file || filesize( $file ) > self::MAX_FILE_BYTES ) {
				continue;
			}
			wp_remote_post(
				self::mater_url( $path ),
				[
					'timeout' => 60,
					'headers' => array_merge(
						$headers,
						[
							'Content-Type'    => 'application/octet-stream',
							'X-VozCaster-Url' => (string) $url,
						]
					),
					// Reading a local media file of this site to hand it to VozCaster.
					'body'    => (string) file_get_contents( $file ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				]
			);
		}
	}

	// -------------------------------------------------------------------------
	// 1. Registering a pairing code
	// -------------------------------------------------------------------------

	/**
	 * Called when a user presses "Connect" (before leaving for Telegram or the
	 * app). Never blocks the connection: any failure is ignored and the usual
	 * direct pairing goes on.
	 *
	 * @param array $info What /pair/info answers for this code.
	 */
	public static function register_pairing( string $code, int $user_id, array $info ): void {
		$token = bin2hex( random_bytes( 32 ) );
		// Pending before VozCaster stores it: if the answer gets lost on the
		// way back, a connection VozCaster completes must still work here.
		self::add_pending_token( $user_id, $token, $code );
		$response = self::mater_request(
			'POST',
			'/api/relay/pair',
			[
				'site_url' => home_url(),
				'code'     => $code,
				'token'    => $token,
				'info'     => $info,
				'snapshot' => self::build_snapshot( $user_id ),
			],
			[ 'timeout' => 20 ]
		);
		if ( is_wp_error( $response ) || empty( $response['ok'] ) ) {
			return;
		}
		if ( ! empty( $response['blocked'] ) && ! empty( $response['want'] ) ) {
			self::send_media(
				'/api/relay/pair/media',
				(array) $response['want'],
				[
					'X-VozCaster-Site' => home_url(),
					'X-VozCaster-Code' => $code,
				]
			);
		}
	}

	// -------------------------------------------------------------------------
	// 2. The pickup page
	// -------------------------------------------------------------------------

	private static function pickup_code(): string {
		// A public page reached from a link VozCaster gave the user; the random
		// code is the credential and no state changes until the user confirms.
		$code = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return preg_match( self::CODE_PATTERN, $code ) ? $code : '';
	}

	public function maybe_render_pickup(): void {
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$code = self::pickup_code();
		$info = '' !== $code ? self::mater_request( 'GET', '/api/relay/pickup/' . $code ) : new WP_Error( 'vpconn_relay_bad_code', '' );

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// Read by page cache plugins (WP Super Cache, W3TC, FlyingPress…): never keep this page.
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		}

		wp_register_style( 'vpconn-pickup', VPCONN_URL . 'assets/css/pickup.css', [], VPCONN_VERSION );
		wp_register_script( 'vpconn-pickup', VPCONN_URL . 'assets/js/pickup.js', [], VPCONN_VERSION, true );

		$items     = is_wp_error( $info ) ? [] : (array) ( $info['items'] ?? [] );
		$titles    = [];
		$publish   = false; // An episode or post is waiting.
		$goes_live = false; // ...and it will be published (now or scheduled), not kept as a draft.
		foreach ( $items as $item ) {
			if ( in_array( $item['kind'] ?? '', [ 'episode', 'post' ], true ) ) {
				$publish  = true;
				$titles[] = (string) ( $item['summary'] ?? '' );
				if ( in_array( $item['status'] ?? '', [ 'publish', 'future' ], true ) ) {
					$goes_live = true;
				}
			}
		}
		wp_localize_script(
			'vpconn-pickup',
			'vpconnPickup',
			[
				'stepUrl' => esc_url_raw( rest_url( VPConn_API::NAMESPACE . '/relay/step' ) ),
				'code'    => $code,
				'i18n'    => [
					'working'   => __( 'Collecting from VozCaster…', 'connector-for-vozcaster' ),
					'uploading' => __( 'Bringing the audio…', 'connector-for-vozcaster' ),
					'syncing'   => __( 'Updating VozCaster with your site settings…', 'connector-for-vozcaster' ),
					'done'      => __( 'Done. Everything VozCaster had waiting is now on your site.', 'connector-for-vozcaster' ),
					'published' => __( 'Published:', 'connector-for-vozcaster' ),
					'draft'     => __( 'Saved as a draft:', 'connector-for-vozcaster' ),
					'future'    => __( 'Scheduled:', 'connector-for-vozcaster' ),
					'pending'   => __( 'Sent for review.', 'connector-for-vozcaster' ),
					'failed'    => __( 'Something failed:', 'connector-for-vozcaster' ),
					'retry'     => __( 'Try again', 'connector-for-vozcaster' ),
				],
			]
		);

		status_header( 200 );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( sprintf( /* translators: %s: site name. */ __( 'VozCaster — %s', 'connector-for-vozcaster' ), get_bloginfo( 'name' ) ) ); ?></title>
		<?php wp_print_styles( [ 'vpconn-pickup' ] ); ?>
</head>
<body class="vpconn-pickup">
	<main class="vpconn-card">
		<p class="vpconn-brand">🎙️ VozCaster · <?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
		<?php if ( is_wp_error( $info ) ) : ?>
			<h1><?php esc_html_e( 'This link is no longer valid', 'connector-for-vozcaster' ); ?></h1>
			<p><?php esc_html_e( 'It was already used, or it expired. If something is still waiting, VozCaster will send you a new link.', 'connector-for-vozcaster' ); ?></p>
		<?php elseif ( ! $items ) : ?>
			<h1><?php esc_html_e( 'Nothing is waiting', 'connector-for-vozcaster' ); ?></h1>
			<p><?php esc_html_e( 'Your site already has everything VozCaster had for it.', 'connector-for-vozcaster' ); ?></p>
		<?php else : ?>
			<?php if ( $publish ) : ?>
				<h1>
					<?php
					echo esc_html(
						1 === count( $titles )
							/* translators: %s: episode or post title. */
							? sprintf( __( '«%s» is ready for your site', 'connector-for-vozcaster' ), $titles[0] )
							/* translators: %d: number of episodes or posts. */
							: sprintf( __( '%d episodes are ready for your site', 'connector-for-vozcaster' ), count( $titles ) )
					);
					?>
				</h1>
			<?php else : ?>
				<h1><?php esc_html_e( 'Your changes in VozCaster are ready to be applied', 'connector-for-vozcaster' ); ?></h1>
			<?php endif; ?>
			<p class="vpconn-why"><?php esc_html_e( 'We collect it from here because your hosting blocks direct connections from VozCaster: your browser can reach your site, VozCaster cannot.', 'connector-for-vozcaster' ); ?></p>
			<p>
				<button type="button" class="vpconn-go" id="vpconn-go">
					<?php
					if ( $goes_live ) {
						esc_html_e( 'Publish now', 'connector-for-vozcaster' );
					} elseif ( $publish ) {
						esc_html_e( 'Save it on my site', 'connector-for-vozcaster' );
					} else {
						esc_html_e( 'Apply now', 'connector-for-vozcaster' );
					}
					?>
				</button>
			</p>
			<div id="vpconn-progress" class="vpconn-progress" hidden>
				<div class="vpconn-bar"><span id="vpconn-bar"></span></div>
				<p id="vpconn-status" aria-live="polite"></p>
			</div>
			<ul id="vpconn-results" class="vpconn-results"></ul>
			<p class="vpconn-hint"><?php esc_html_e( 'To skip this step next time, ask your hosting to let VozCaster reach /wp-json/vozpress/ on your site.', 'connector-for-vozcaster' ); ?></p>
		<?php endif; ?>
	</main>
		<?php wp_print_scripts( [ 'vpconn-pickup' ] ); ?>
</body>
</html>
		<?php
		exit;
	}

	// -------------------------------------------------------------------------
	// 3. Collecting the mailbox, one step per request
	// -------------------------------------------------------------------------

	public function register_routes(): void {
		// POST /relay/step — public: the pickup code (random, single connection,
		// sent only to its user by VozCaster) is the credential, and every call
		// it runs is authorized again with that user's own token.
		register_rest_route(
			VPConn_API::NAMESPACE,
			'/relay/step',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'step' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'code' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					],
				],
			]
		);
	}

	private static function state_key( string $code ): string {
		return 'vpconn_relay_' . substr( $code, 0, 24 );
	}

	public function step( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$code = (string) $request->get_param( 'code' );
		if ( ! preg_match( self::CODE_PATTERN, $code ) ) {
			return new WP_Error( 'vpconn_relay_bad_code', 'Invalid code.', [ 'status' => 400 ] );
		}
		// One step at a time per code (a second tab or a double click waits).
		$lock = self::state_key( $code ) . '_lock';
		if ( get_transient( $lock ) ) {
			return new WP_REST_Response( [ 'state' => 'busy' ] );
		}
		set_transient( $lock, 1, 120 );
		try {
			return new WP_REST_Response( $this->run_step( $code ) );
		} finally {
			delete_transient( $lock );
		}
	}

	private function run_step( string $code ): array {
		$key   = self::state_key( $code );
		$state = get_transient( $key );
		$state = is_array( $state ) ? $state : [
			'current' => null,
			'results' => [],
		];

		if ( empty( $state['current'] ) ) {
			$next = self::mater_request( 'POST', '/api/relay/pickup/' . $code . '/next', [] );
			if ( is_wp_error( $next ) ) {
				return [
					'state'   => 'error',
					'message' => $next->get_error_message(),
				];
			}
			if ( ! empty( $next['done'] ) ) {
				$this->sync_snapshot( $code );
				delete_transient( $key );
				return [
					'state'   => 'done',
					'results' => $state['results'],
				];
			}
			$state['current'] = [
				'item'   => $next['item'],
				'token'  => (string) $next['token'],
				'offset' => 0,
				'upload' => wp_generate_uuid4(),
			];
		}

		$current = $state['current'];
		$item    = $current['item'];
		$user_id = VPConn_Auth::find_user_id_by_token( $current['token'] );
		if ( ! $user_id || ! VPConn_Auth::is_user_allowed( (int) $user_id ) ) {
			$this->report( $code, $item, false, null, 'The connection is not valid on this site any more: connect it again.' );
			$state['current'] = null;
			set_transient( self::state_key( $code ), $state, HOUR_IN_SECONDS );
			return [
				'state'   => 'working',
				'label'   => (string) ( $item['summary'] ?? '' ),
				'percent' => 0,
			];
		}

		$op     = (array) $item['op'];
		$file   = $item['file'] ?? null;
		$result = null;
		$error  = '';
		$more   = false;

		if ( $file && ! empty( $op['chunked'] ) ) {
			$size  = (int) $file['size'];
			$total = max( 1, (int) ceil( $size / self::CHUNK_BYTES ) );
			$index = (int) floor( $current['offset'] / self::CHUNK_BYTES );
			$bytes = $this->fetch_file( (string) $file['url'], (int) $current['offset'], self::CHUNK_BYTES );
			if ( is_wp_error( $bytes ) ) {
				$error = $bytes->get_error_message();
			} else {
				$out = self::dispatch(
					(int) $user_id,
					'POST',
					(string) $op['route'],
					[
						'headers' => [
							'Content-Type'          => 'application/octet-stream',
							'X-VozPress-Upload-ID'  => sanitize_key( $current['upload'] ),
							'X-VozPress-Chunk-Index' => $index,
							'X-VozPress-Chunk-Total' => $total,
							'X-VozPress-Filename'   => (string) ( $op['filename'] ?? 'audio.mp3' ),
						],
						'body'    => $bytes,
					]
				);
				if ( 202 === $out['status'] ) {
					$state['current']['offset'] = $current['offset'] + strlen( $bytes );
					$more                       = true;
				} elseif ( $out['status'] < 300 ) {
					$result = $out['data'];
				} else {
					$error = self::error_text( $out );
				}
			}
		} elseif ( $file ) {
			$bytes = (int) $file['size'] <= self::MAX_FILE_BYTES
				? $this->fetch_file( (string) $file['url'], 0, (int) $file['size'] )
				: new WP_Error( 'vpconn_relay_too_big', 'File too big.' );
			if ( is_wp_error( $bytes ) ) {
				$error = $bytes->get_error_message();
			} else {
				$out = self::dispatch(
					(int) $user_id,
					'POST',
					(string) $op['route'],
					[
						'headers' => [
							'Content-Type'        => (string) ( $op['mime'] ?? 'application/octet-stream' ),
							'Content-Disposition' => 'attachment; filename=' . sanitize_file_name( (string) ( $op['filename'] ?? 'file' ) ),
						],
						'body'    => $bytes,
					]
				);
				if ( $out['status'] < 300 ) {
					$result = $out['data'];
				} else {
					$error = self::error_text( $out );
				}
			}
		} else {
			$args = isset( $op['json'] ) ? [ 'json' => (array) $op['json'] ] : [];
			$out  = self::dispatch( (int) $user_id, (string) ( $op['method'] ?? 'POST' ), (string) $op['route'], $args );
			if ( $out['status'] < 300 ) {
				$result = $out['data'];
			} else {
				$error = self::error_text( $out );
			}
		}

		$percent = 0;
		if ( $file && ! empty( $op['chunked'] ) && (int) $file['size'] > 0 ) {
			$percent = (int) min( 100, round( 100 * $state['current']['offset'] / (int) $file['size'] ) );
		}

		if ( ! $more ) {
			$this->report( $code, $item, '' === $error, $result, $error );
			if ( in_array( $item['kind'], [ 'episode', 'post' ], true ) ) {
				$state['results'][] = [
					'kind'   => $item['kind'],
					'title'  => (string) ( $item['summary'] ?? '' ),
					'ok'     => '' === $error,
					'url'    => is_array( $result ) ? (string) ( $result['url'] ?? ( $result['link'] ?? '' ) ) : '',
					'status' => is_array( $result ) ? (string) ( $result['status'] ?? '' ) : '',
					'error'  => $error,
				];
			} elseif ( '' !== $error ) {
				$state['results'][] = [
					'kind'  => $item['kind'],
					'title' => (string) ( $item['summary'] ?: $item['kind'] ),
					'ok'    => false,
					'error' => $error,
				];
			}
			$state['current'] = null;
		}
		set_transient( $key, $state, HOUR_IN_SECONDS );

		return [
			'state'   => 'working',
			'kind'    => $item['kind'],
			'label'   => (string) ( $item['summary'] ?? '' ),
			'percent' => $percent,
		];
	}

	private static function error_text( array $out ): string {
		$data = $out['data'];
		if ( is_array( $data ) && isset( $data['message'] ) ) {
			return (string) $data['message'];
		}
		return 'HTTP ' . $out['status'];
	}

	/** @return string|WP_Error */
	private function fetch_file( string $path, int $offset, int $length ) {
		$response = wp_remote_get(
			self::mater_url( $path ),
			[
				'timeout' => 60,
				'headers' => [ 'Range' => 'bytes=' . $offset . '-' . ( $offset + $length - 1 ) ],
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code && 206 !== $code ) {
			return new WP_Error( 'vpconn_relay_file', 'HTTP ' . $code );
		}
		$body = (string) wp_remote_retrieve_body( $response );
		if ( 200 === $code && $offset > 0 ) {
			// The server ignored the range: keep only the part asked for.
			$body = substr( $body, $offset, $length );
		}
		return $body;
	}

	private function report( string $code, array $item, bool $ok, $result, string $error ): void {
		self::mater_request(
			'POST',
			'/api/relay/pickup/' . $code . '/result',
			[
				'item_id' => (int) $item['id'],
				'ok'      => $ok,
				'data'    => is_array( $result ) ? $result : null,
				'error'   => $error,
			]
		);
	}

	private function sync_snapshot( string $code ): void {
		$info = self::mater_request( 'GET', '/api/relay/pickup/' . $code );
		if ( is_wp_error( $info ) ) {
			return;
		}
		$user = get_user_by( 'login', (string) ( $info['wp_username'] ?? '' ) );
		if ( ! $user || ! VPConn_Auth::is_user_allowed( $user->ID ) ) {
			return;
		}
		$answer = self::mater_request( 'POST', '/api/relay/pickup/' . $code . '/snapshot', self::build_snapshot( $user->ID ) );
		if ( ! is_wp_error( $answer ) && ! empty( $answer['want'] ) ) {
			self::send_media( '/api/relay/pickup/' . $code . '/media', (array) $answer['want'], [] );
		}
	}
}
