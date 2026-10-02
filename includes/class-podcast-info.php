<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Podcast show data (title, description, artwork, Apple category, owner,
 * language, explicit) read from and written to PowerPress, per feed.
 *
 * PowerPress keeps one settings array per feed:
 * - default feed ("podcast")  → option `powerpress_feed`
 *   (or `powerpress_feed_podcast` when the site customised that channel)
 * - custom channels           → option `powerpress_feed_{slug}`
 * - category podcasting feeds → option `powerpress_cat_feed_{term_id}`
 * Channels and category feeds fall back to `powerpress_feed` for every empty
 * value, so reads return the effective value and say whether it is inherited,
 * and writes go to the feed's own option only.
 *
 * Endpoints:
 * - GET  /podcast?feed=slug — any authorized user.
 * - POST /podcast           — administrators only (site-wide data).
 */
class VPConn_Podcast_Info {

	/** API field => PowerPress settings key. */
	const FIELDS = [
		'title'       => 'title',
		'description' => 'description',
		'author'      => 'itunes_talent_name',
		'email'       => 'email',
		'language'    => 'rss_language',
		'category'    => 'apple_cat_1',
		'explicit'    => 'itunes_explicit',
		'artwork'     => 'itunes_image',
	];

	// Apple Podcasts artwork requirements.
	const ARTWORK_MIN = 1400;
	const ARTWORK_MAX = 3000;

	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$api = new VPConn_API();

		register_rest_route(
			VPConn_API::NAMESPACE,
			'/podcast',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_info' ],
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
					'callback'            => [ $this, 'update_info' ],
					'permission_callback' => [ $api, 'check_token_admin_permission' ],
					'args'                => [
						'feed'        => [
							'type'              => 'string',
							'default'           => 'podcast',
							'sanitize_callback' => 'sanitize_key',
						],
						'title'       => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
						'description' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
						'author'      => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
						// Validated in update_info(): sanitize_email() would silently turn a typo into ''.
						'email'       => [ 'type' => 'string' ],
						'language'    => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
						'category'    => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
						'explicit'    => [ 'type' => 'boolean' ],
						'artwork_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
					],
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Feed resolution
	// -------------------------------------------------------------------------

	/**
	 * Option that holds a feed's own settings, or null for an unknown feed.
	 * Mirrors the order of VPConn_Settings::get_powerpress_feeds().
	 */
	private static function option_name( string $slug ): ?string {
		if ( 'podcast' === $slug ) {
			return false !== get_option( 'powerpress_feed_podcast', false ) ? 'powerpress_feed_podcast' : 'powerpress_feed';
		}
		$general = get_option( 'powerpress_general', [] );
		$cat_ids = is_array( $general ) ? (array) ( $general['custom_cat_feeds'] ?? [] ) : [];
		$term    = get_term_by( 'slug', $slug, 'category' );
		if ( $term && ( in_array( (string) $term->term_id, array_map( 'strval', $cat_ids ), true ) || false !== get_option( 'powerpress_cat_feed_' . $term->term_id, false ) ) ) {
			return 'powerpress_cat_feed_' . $term->term_id;
		}
		$channels = is_array( $general ) ? (array) ( $general['custom_feeds'] ?? [] ) : [];
		if ( isset( $channels[ $slug ] ) || false !== get_option( 'powerpress_feed_' . $slug, false ) ) {
			return 'powerpress_feed_' . $slug;
		}
		return null;
	}

	/** Public URL of a feed. */
	private static function feed_url( string $slug, string $option ): string {
		if ( str_starts_with( $option, 'powerpress_cat_feed_' ) ) {
			return get_category_feed_link( (int) substr( $option, strlen( 'powerpress_cat_feed_' ) ) );
		}
		return get_feed_link( $slug );
	}

	// -------------------------------------------------------------------------
	// Lists for the bot (Apple categories, languages)
	// -------------------------------------------------------------------------

	/** @return array<string, string> Apple category code ('NN-MM') => English name. */
	public static function apple_categories(): array {
		return function_exists( 'powerpress_apple_categories' ) ? (array) powerpress_apple_categories() : [];
	}

	/** @return array<string, string> Language code => name, as PowerPress accepts them. */
	private static function languages(): array {
		return function_exists( 'powerpress_languages' ) ? (array) powerpress_languages() : [];
	}

	// -------------------------------------------------------------------------
	// Read
	// -------------------------------------------------------------------------

	/** Effective show data of a feed, plus readiness checks. */
	public static function describe( string $slug ): array|WP_Error {
		$option = self::option_name( $slug );
		if ( null === $option ) {
			return new WP_Error( 'vpconn_unknown_feed', 'Unknown feed.', [ 'status' => 404 ] );
		}
		$own   = (array) get_option( $option, [] );
		$base  = 'powerpress_feed' === $option ? $own : (array) get_option( 'powerpress_feed', [] );
		$data  = [];
		$inher = [];
		foreach ( self::FIELDS as $field => $key ) {
			$value = (string) ( $own[ $key ] ?? '' );
			if ( '' === $value && 'powerpress_feed' !== $option && '' !== (string) ( $base[ $key ] ?? '' ) ) {
				$value   = (string) $base[ $key ];
				$inher[] = $field;
			}
			$data[ $field ] = $value;
		}

		// Values as PowerPress renders them when empty.
		$data['explicit'] = '1' === $data['explicit'];
		if ( '' === $data['title'] ) {
			$data['title_fallback'] = get_bloginfo( 'name' );
		}
		if ( '' === $data['language'] ) {
			$data['language_fallback'] = get_bloginfo( 'language' );
		}

		$artwork = self::artwork_info( $data['artwork'] );
		$cats    = self::apple_categories();

		$result = [
			'feed'           => $slug,
			'feed_url'       => self::feed_url( $slug, $option ),
			'data'           => $data,
			'inherited'      => $inher,
			'artwork'        => $artwork,
			'category_name'  => $cats[ $data['category'] ] ?? '',
			'episodes'       => self::count_episodes( $slug, $option ),
			'powerpress'     => function_exists( 'powerpress_apple_categories' ),
		];
		$result['checks'] = self::readiness( $result );
		return $result;
	}

	/** Size of the artwork when it is in the media library. */
	private static function artwork_info( string $url ): array {
		if ( '' === $url ) {
			return [];
		}
		$info = [ 'url' => $url ];
		// attachment_url_to_postid() does not resolve an original whose copy
		// was "-scaled", so try that name too.
		$id = attachment_url_to_postid( $url );
		if ( ! $id ) {
			$id = attachment_url_to_postid( preg_replace( '/(\.[a-z0-9]+)$/i', '-scaled$1', $url ) );
		}
		if ( $id ) {
			$path = wp_get_original_image_path( $id );
			$size = $path && basename( $path ) === basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ? wp_getimagesize( $path ) : false;
			$meta = wp_get_attachment_metadata( $id );
			$info['id']     = $id;
			$info['width']  = $size ? (int) $size[0] : (int) ( $meta['width'] ?? 0 );
			$info['height'] = $size ? (int) $size[1] : (int) ( $meta['height'] ?? 0 );
		}
		return $info;
	}

	/** Published posts carrying an enclosure for this feed. */
	private static function count_episodes( string $slug, string $option ): int {
		$count = function ( array $extra ): int {
			$query = new WP_Query(
				array_merge(
					[
						'post_type'      => 'post',
						'post_status'    => 'publish',
						'posts_per_page' => 1,
						'fields'         => 'ids',
					],
					$extra
				)
			);
			return (int) $query->found_posts;
		};
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One count per request, on demand.
		if ( str_starts_with( $option, 'powerpress_cat_feed_' ) ) {
			return $count( [ 'meta_key' => 'enclosure', 'cat' => (int) substr( $option, strlen( 'powerpress_cat_feed_' ) ) ] );
		}
		if ( 'powerpress_feed' === $option || 'powerpress_feed_podcast' === $option ) {
			return $count( [ 'meta_key' => 'enclosure' ] );
		}
		// Custom channel: PowerPress's own key, or episodes the bot filed under
		// the channel's category (the bot stores the audio in "enclosure").
		$own  = $count( [ 'meta_key' => '_' . $slug . ':enclosure' ] );
		$term = get_term_by( 'slug', $slug, 'category' );
		$cat  = $term ? $count( [ 'meta_key' => 'enclosure', 'cat' => (int) $term->term_id ] ) : 0;
		// phpcs:enable
		return max( $own, $cat );
	}

	/**
	 * What Apple Podcasts and Spotify need before the feed is submitted.
	 *
	 * @return array<int, array{key: string, ok: bool}>
	 */
	private static function readiness( array $info ): array {
		$d       = $info['data'];
		$art     = $info['artwork'];
		$art_ok  = ! empty( $art['url'] ) && ( empty( $art['width'] ) || ( $art['width'] === $art['height'] && $art['width'] >= self::ARTWORK_MIN && $art['width'] <= self::ARTWORK_MAX ) );
		return [
			[ 'key' => 'title', 'ok' => '' !== $d['title'] || ! empty( $d['title_fallback'] ) ],
			[ 'key' => 'description', 'ok' => '' !== trim( $d['description'] ) ],
			[ 'key' => 'artwork', 'ok' => $art_ok ],
			[ 'key' => 'category', 'ok' => '' !== $d['category'] ],
			[ 'key' => 'author', 'ok' => '' !== $d['author'] ],
			[ 'key' => 'email', 'ok' => '' !== $d['email'] ],
			[ 'key' => 'episodes', 'ok' => $info['episodes'] > 0 ],
		];
	}

	public function get_info( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$info = self::describe( (string) $request->get_param( 'feed' ) );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		$info['categories'] = self::apple_categories();
		// Lets the bot show the edit buttons only to those who can save.
		$info['can_edit']   = current_user_can( 'manage_options' );
		$response           = new WP_REST_Response( $info );
		$response->header( 'Cache-Control', 'no-store, no-cache' );
		return $response;
	}

	// -------------------------------------------------------------------------
	// Write
	// -------------------------------------------------------------------------

	public function update_info( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! function_exists( 'powerpress_apple_categories' ) ) {
			return new WP_Error( 'vpconn_no_powerpress', 'PowerPress is not active.', [ 'status' => 409 ] );
		}
		$slug   = (string) $request->get_param( 'feed' );
		$option = self::option_name( $slug );
		if ( null === $option ) {
			return new WP_Error( 'vpconn_unknown_feed', 'Unknown feed.', [ 'status' => 404 ] );
		}

		$params  = $request->get_params();
		$changes = [];

		foreach ( [ 'title', 'description', 'author' ] as $field ) {
			if ( array_key_exists( $field, $params ) ) {
				$changes[ self::FIELDS[ $field ] ] = trim( (string) $request->get_param( $field ) );
			}
		}

		if ( array_key_exists( 'email', $params ) ) {
			$email = trim( (string) $request->get_param( 'email' ) );
			if ( '' !== $email && ! is_email( $email ) ) {
				return new WP_Error( 'vpconn_invalid_email', 'Invalid email address.', [ 'status' => 400 ] );
			}
			$changes['email'] = sanitize_email( $email );
			// PowerPress only prints <itunes:owner> when this is on.
			$changes['pp_enable_email'] = '1';
		}

		if ( array_key_exists( 'language', $params ) ) {
			$lang = (string) $request->get_param( 'language' );
			if ( '' !== $lang && ! array_key_exists( $lang, self::languages() ) ) {
				return new WP_Error( 'vpconn_invalid_language', 'Unknown language code.', [ 'status' => 400 ] );
			}
			$changes['rss_language'] = $lang;
		}

		if ( array_key_exists( 'category', $params ) ) {
			$cat = (string) $request->get_param( 'category' );
			if ( '' !== $cat && ! array_key_exists( $cat, self::apple_categories() ) ) {
				return new WP_Error( 'vpconn_invalid_category', 'Unknown Apple Podcasts category.', [ 'status' => 400 ] );
			}
			$changes['apple_cat_1'] = $cat;
		}

		if ( array_key_exists( 'explicit', $params ) ) {
			// PowerPress: 1 = explicit, 2 = clean.
			$changes['itunes_explicit'] = $request->get_param( 'explicit' ) ? '1' : '2';
		}

		if ( array_key_exists( 'artwork_id', $params ) ) {
			$id   = (int) $request->get_param( 'artwork_id' );
			$meta = wp_get_attachment_metadata( $id );
			if ( ! wp_attachment_is_image( $id ) || empty( $meta['width'] ) ) {
				return new WP_Error( 'vpconn_invalid_artwork', 'Not an image in the media library.', [ 'status' => 400 ] );
			}
			// WordPress scales images above 2560 px and serves the "-scaled" copy;
			// Apple recommends 3000 px, so use the original when there is one.
			$url = (string) wp_get_attachment_url( $id );
			$w   = (int) $meta['width'];
			$h   = (int) $meta['height'];
			if ( ! empty( $meta['original_image'] ) ) {
				$size = wp_getimagesize( (string) wp_get_original_image_path( $id ) );
				if ( $size ) {
					$url = (string) wp_get_original_image_url( $id );
					$w   = (int) $size[0];
					$h   = (int) $size[1];
				}
			}
			if ( $w !== $h || $w < self::ARTWORK_MIN || $w > self::ARTWORK_MAX ) {
				return new WP_Error(
					'vpconn_invalid_artwork',
					sprintf( 'Artwork must be square, between %1$d and %2$d px (got %3$dx%4$d).', self::ARTWORK_MIN, self::ARTWORK_MAX, $w, $h ),
					[ 'status' => 400 ]
				);
			}
			$changes['itunes_image'] = $url;
		}

		if ( $changes ) {
			$settings = (array) get_option( $option, [] );
			update_option( $option, array_merge( $settings, $changes ) );
		}

		return $this->get_info( $request );
	}
}
