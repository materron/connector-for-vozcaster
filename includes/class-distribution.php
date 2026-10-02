<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where to submit the podcast feed, and how far each platform has got.
 *
 * The plugin only stores links and a per-feed status that people set (or the
 * bot sets after checking a platform's public catalogue); it never contacts
 * those platforms itself. The submit and search links open in the user's
 * browser.
 *
 * Status per platform: 'none' (not submitted), 'submitted', 'live'.
 *
 * Endpoints:
 * - GET  /distribution?feed=slug — any authorized user.
 * - POST /distribution           — administrators only.
 */
class VPConn_Distribution {

	const OPTION   = 'vpconn_distribution';
	const STATUSES = [ 'none', 'submitted', 'live' ];

	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'admin_menu', [ $this, 'add_page' ], 11 );
		add_action( 'admin_post_vpconn_distribution', [ $this, 'handle_form' ] );
	}

	/**
	 * Platforms, most important first (reach for a Spanish-speaking show).
	 * `{q}` in a search URL is replaced with the podcast title.
	 *
	 * @return array<string, array{name: string, submit: string, search: string, how: string}>
	 */
	public static function platforms(): array {
		return [
			'spotify'      => [
				'name'   => 'Spotify',
				'submit' => 'https://creators.spotify.com/',
				'search' => 'https://open.spotify.com/search/{q}/podcasts',
				'how'    => __( 'Spotify for Creators → add an existing podcast with its RSS feed. A code is sent to the owner email of the feed.', 'connector-for-vozcaster' ),
			],
			'apple'        => [
				'name'   => 'Apple Podcasts',
				'submit' => 'https://podcastsconnect.apple.com/',
				'search' => 'https://podcasts.apple.com/search?term={q}',
				'how'    => __( 'Podcasts Connect with your Apple Account → add a show with an RSS feed. Apple reviews it, usually within a few days. Many other apps take their catalogue from Apple.', 'connector-for-vozcaster' ),
			],
			'youtube'      => [
				'name'   => 'YouTube Music',
				'submit' => 'https://studio.youtube.com/',
				'search' => 'https://music.youtube.com/search?q={q}',
				'how'    => __( 'YouTube Studio → Create → Submit RSS feed. A code is sent to the owner email of the feed; each episode becomes a video with the podcast artwork.', 'connector-for-vozcaster' ),
			],
			'ivoox'        => [
				'name'   => 'iVoox',
				'submit' => 'https://podcasters.ivoox.com/',
				'search' => 'https://www.google.com/search?q=site%3Aivoox.com+%22{q}%22',
				'how'    => __( 'iVoox Podcasters → create a new show → "Do you already have a show?" → paste the feed.', 'connector-for-vozcaster' ),
			],
			'amazon'       => [
				'name'   => 'Amazon Music',
				'submit' => 'https://podcasters.amazon.com/',
				'search' => 'https://music.amazon.com/search/{q}',
				'how'    => __( 'Amazon Music for Podcasters → add your podcast with its RSS feed. Also reaches Audible.', 'connector-for-vozcaster' ),
			],
			'podcastindex' => [
				'name'   => 'Podcast Index',
				'submit' => 'https://podcastindex.org/add',
				'search' => 'https://podcastindex.org/search?q={q}',
				'how'    => __( 'Paste the feed, no account needed. It feeds many independent podcast apps.', 'connector-for-vozcaster' ),
			],
			'pocketcasts'  => [
				'name'   => 'Pocket Casts',
				'submit' => 'https://pocketcasts.com/submit/',
				'search' => 'https://pocketcasts.com/search?q={q}',
				'how'    => __( 'Paste the feed, no account needed.', 'connector-for-vozcaster' ),
			],
			'castbox'      => [
				'name'   => 'Castbox',
				'submit' => 'https://castbox.fm/creator/',
				'search' => 'https://castbox.fm/search/{q}',
				'how'    => __( 'Creator Studio → Claim ownership → paste the feed. A link is sent to the owner email of the feed.', 'connector-for-vozcaster' ),
			],
			'deezer'       => [
				'name'   => 'Deezer',
				'submit' => 'https://podcasters.deezer.com/',
				'search' => 'https://www.deezer.com/search/{q}/show',
				'how'    => __( 'Deezer for Podcasters → add your podcast with its RSS feed.', 'connector-for-vozcaster' ),
			],
		];
	}

	// -------------------------------------------------------------------------
	// Data
	// -------------------------------------------------------------------------

	/** @return array<string, array{status: string, url: string, updated: int}> */
	private static function get_status( string $feed ): array {
		$all = (array) get_option( self::OPTION, [] );
		return (array) ( $all[ $feed ] ?? [] );
	}

	public static function set_status( string $feed, string $platform, string $status, string $url = '' ): void {
		$all = (array) get_option( self::OPTION, [] );
		if ( 'none' === $status ) {
			unset( $all[ $feed ][ $platform ] );
		} else {
			$all[ $feed ][ $platform ] = [
				'status'  => $status,
				'url'     => $url,
				'updated' => time(),
			];
		}
		update_option( self::OPTION, $all, false );
	}

	/** Everything the bot and the admin page show for a feed. */
	public static function describe( string $feed ): array|WP_Error {
		$info = VPConn_Podcast_Info::describe( $feed );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		$title  = $info['data']['title'] ?: ( $info['data']['title_fallback'] ?? '' );
		$status = self::get_status( $feed );
		$list   = [];
		foreach ( self::platforms() as $key => $p ) {
			$s      = $status[ $key ] ?? [];
			$list[] = [
				'key'        => $key,
				'name'       => $p['name'],
				'how'        => $p['how'],
				'submit_url' => $p['submit'],
				'search_url' => str_replace( '{q}', rawurlencode( $title ), $p['search'] ),
				'status'     => in_array( $s['status'] ?? '', self::STATUSES, true ) ? $s['status'] : 'none',
				'url'        => (string) ( $s['url'] ?? '' ),
				'updated'    => (int) ( $s['updated'] ?? 0 ),
			];
		}
		return [
			'feed'      => $feed,
			'feed_url'  => $info['feed_url'],
			'title'     => $title,
			'checks'    => $info['checks'],
			'platforms' => $list,
		];
	}

	// -------------------------------------------------------------------------
	// REST
	// -------------------------------------------------------------------------

	public function register_routes(): void {
		$api = new VPConn_API();
		register_rest_route(
			VPConn_API::NAMESPACE,
			'/distribution',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'rest_get' ],
					'permission_callback' => [ $api, 'check_token_permission' ],
					'args'                => [
						'feed' => [
							'type'              => 'string',
							'default'           => 'podcast',
							'sanitize_callback' => 'sanitize_key',
						],
					],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'rest_update' ],
					'permission_callback' => [ $api, 'check_token_admin_permission' ],
					'args'                => [
						'feed'     => [
							'type'              => 'string',
							'default'           => 'podcast',
							'sanitize_callback' => 'sanitize_key',
						],
						'platform' => [
							'type'     => 'string',
							'required' => true,
							'enum'     => array_keys( self::platforms() ),
						],
						'status'   => [
							'type'     => 'string',
							'required' => true,
							'enum'     => self::STATUSES,
						],
						'url'      => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'esc_url_raw',
						],
					],
				],
			]
		);
	}

	public function rest_get( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$data = self::describe( (string) $request->get_param( 'feed' ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$data['can_edit'] = current_user_can( 'manage_options' );
		$response         = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store, no-cache' );
		return $response;
	}

	public function rest_update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$feed = (string) $request->get_param( 'feed' );
		if ( is_wp_error( VPConn_Podcast_Info::describe( $feed ) ) ) {
			return new WP_Error( 'vpconn_unknown_feed', 'Unknown feed.', [ 'status' => 404 ] );
		}
		self::set_status(
			$feed,
			(string) $request->get_param( 'platform' ),
			(string) $request->get_param( 'status' ),
			(string) $request->get_param( 'url' )
		);
		return $this->rest_get( $request );
	}

	// -------------------------------------------------------------------------
	// Admin page: VozCaster → Distribute
	// -------------------------------------------------------------------------

	public function add_page(): void {
		add_submenu_page(
			VPConn_Pairing::PAGE_SLUG,
			__( 'VozCaster — Distribute', 'connector-for-vozcaster' ),
			__( 'Distribute', 'connector-for-vozcaster' ),
			'manage_options',
			'vozcaster-distribute',
			[ $this, 'render_page' ]
		);
	}

	public function handle_form(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'connector-for-vozcaster' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'vpconn_distribution' );
		$feed     = sanitize_key( wp_unslash( $_POST['feed'] ?? 'podcast' ) );
		$platform = sanitize_key( wp_unslash( $_POST['platform'] ?? '' ) );
		$status   = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );
		$url      = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
		if ( isset( self::platforms()[ $platform ] ) && in_array( $status, self::STATUSES, true ) ) {
			self::set_status( $feed, $platform, $status, $url );
		}
		wp_safe_redirect( add_query_arg( [ 'page' => 'vozcaster-distribute', 'feed' => $feed, 'vpconn_msg' => 'distribution_saved' ], admin_url( 'admin.php' ) ) . '#vpconn-' . $platform );
		exit;
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$feeds = VPConn_Settings::get_powerpress_feeds();
		// Read-only feed selector on an admin screen.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$feed = sanitize_key( wp_unslash( $_GET['feed'] ?? ( $feeds[0]['slug'] ?? 'podcast' ) ) );
		$data = self::describe( $feed );

		$labels = [
			'none'      => __( 'Not submitted', 'connector-for-vozcaster' ),
			'submitted' => __( 'Submitted, pending', 'connector-for-vozcaster' ),
			'live'      => __( 'Live', 'connector-for-vozcaster' ),
		];
		$check_labels = [
			'title'       => __( 'Title', 'connector-for-vozcaster' ),
			'description' => __( 'Description', 'connector-for-vozcaster' ),
			'artwork'     => __( 'Square artwork of 1400–3000 px', 'connector-for-vozcaster' ),
			'category'    => __( 'Apple Podcasts category', 'connector-for-vozcaster' ),
			'author'      => __( 'Author', 'connector-for-vozcaster' ),
			'email'       => __( 'Owner email', 'connector-for-vozcaster' ),
			'episodes'    => __( 'At least one published episode', 'connector-for-vozcaster' ),
		];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'VozCaster — Distribute', 'connector-for-vozcaster' ); ?></h1>
			<p style="max-width:760px;">
				<?php esc_html_e( 'Submit your podcast feed to each platform once; after that they pick up every new episode by themselves. They are ordered by importance. Mark each one as you go, so you can see at a glance where your podcast is still missing.', 'connector-for-vozcaster' ); ?>
			</p>

			<?php if ( count( $feeds ) > 1 ) : ?>
				<form method="get" style="margin:12px 0;">
					<input type="hidden" name="page" value="vozcaster-distribute">
					<label for="vpconn-feed"><strong><?php esc_html_e( 'Podcast:', 'connector-for-vozcaster' ); ?></strong></label>
					<select id="vpconn-feed" name="feed" onchange="this.form.submit()">
						<?php foreach ( $feeds as $f ) : ?>
							<option value="<?php echo esc_attr( $f['slug'] ); ?>" <?php selected( $feed, $f['slug'] ); ?>><?php echo esc_html( $f['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</form>
			<?php endif; ?>

			<?php if ( is_wp_error( $data ) ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'This podcast feed was not found.', 'connector-for-vozcaster' ); ?></p></div>
			<?php else : ?>
				<h2><?php esc_html_e( 'Feed address', 'connector-for-vozcaster' ); ?></h2>
				<p>
					<input type="text" readonly class="large-text code" style="max-width:640px;" value="<?php echo esc_attr( $data['feed_url'] ); ?>" onclick="this.select()">
					<button type="button" class="button" onclick="navigator.clipboard.writeText(<?php echo esc_attr( wp_json_encode( $data['feed_url'] ) ); ?>);this.textContent=<?php echo esc_attr( wp_json_encode( __( 'Copied', 'connector-for-vozcaster' ) ) ); ?>;"><?php esc_html_e( 'Copy', 'connector-for-vozcaster' ); ?></button>
				</p>

				<h2><?php esc_html_e( 'Ready to submit?', 'connector-for-vozcaster' ); ?></h2>
				<ul style="margin-left:0;">
					<?php foreach ( $data['checks'] as $check ) : ?>
						<li><?php echo $check['ok'] ? '✅' : '❌'; ?> <?php echo esc_html( $check_labels[ $check['key'] ] ?? $check['key'] ); ?></li>
					<?php endforeach; ?>
				</ul>
				<p class="description" style="max-width:760px;">
					<?php esc_html_e( 'Platforms reject feeds with missing data. Fill them in from the bot with /podcast, or in PowerPress → Settings.', 'connector-for-vozcaster' ); ?>
				</p>

				<h2><?php esc_html_e( 'Platforms', 'connector-for-vozcaster' ); ?></h2>
				<table class="widefat striped" style="max-width:1000px;">
					<tbody>
						<?php foreach ( $data['platforms'] as $p ) : ?>
							<tr id="vpconn-<?php echo esc_attr( $p['key'] ); ?>">
								<td style="width:28%;">
									<strong><?php echo esc_html( $p['name'] ); ?></strong><br>
									<span class="description"><?php echo esc_html( $p['how'] ); ?></span>
								</td>
								<td style="width:22%;">
									<a class="button button-primary" href="<?php echo esc_url( $p['submit_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Submit', 'connector-for-vozcaster' ); ?></a>
									<a class="button" href="<?php echo esc_url( $p['search_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Find my podcast', 'connector-for-vozcaster' ); ?></a>
								</td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<?php wp_nonce_field( 'vpconn_distribution' ); ?>
										<input type="hidden" name="action" value="vpconn_distribution">
										<input type="hidden" name="feed" value="<?php echo esc_attr( $feed ); ?>">
										<input type="hidden" name="platform" value="<?php echo esc_attr( $p['key'] ); ?>">
										<select name="status" aria-label="<?php esc_attr_e( 'Status', 'connector-for-vozcaster' ); ?>">
											<?php foreach ( $labels as $value => $label ) : ?>
												<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $p['status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
										<input type="url" name="url" value="<?php echo esc_attr( $p['url'] ); ?>" placeholder="<?php esc_attr_e( 'Link to the podcast page (optional)', 'connector-for-vozcaster' ); ?>" style="width:260px;">
										<button type="submit" class="button"><?php esc_html_e( 'Save', 'connector-for-vozcaster' ); ?></button>
										<?php if ( 'live' === $p['status'] && $p['url'] ) : ?>
											<a href="<?php echo esc_url( $p['url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open', 'connector-for-vozcaster' ); ?></a>
										<?php endif; ?>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description" style="max-width:760px;margin-top:12px;">
					<?php esc_html_e( 'The VozCaster bot checks Apple Podcasts by itself and marks it as live when your feed appears there. Use /difundir in the bot to see this list on your phone.', 'connector-for-vozcaster' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
