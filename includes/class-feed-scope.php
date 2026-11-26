<?php
/**
 * Per-podcast settings.
 *
 * A site can have several podcasts (PowerPress channels, category podcasts or
 * post type podcasts). Each one can have its own music, mix, titles, season,
 * styles and cover characters. A podcast with no settings of its own uses the
 * site's ones (the main podcast's), exactly as before 1.23.0.
 *
 * It works underneath the options API: while a podcast is "in scope" (a REST
 * request with `feed`, or the settings pages with `vpconn_feed`), reading and
 * writing any of the per-podcast options goes to that podcast's own copy. The
 * rest of the plugin keeps using get_option()/update_option() as always.
 *
 * - Reading a podcast without settings of its own gives the main ones.
 * - Saving settings for it (from the settings pages, or VozCaster's settings
 *   and music endpoints) first copies the main ones, so from then on it has
 *   its own full set and only that podcast changes.
 * - The current season is always per podcast: each podcast runs its own
 *   seasons, whether or not it has other settings of its own.
 *
 * @package Connector_For_VozCaster
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps each podcast's settings and points the options API at them.
 */
class VPConn_Feed_Scope {

	/** Settings of each podcast that has its own: [ feed slug => [ option name => value ] ]. */
	const OPTION = 'vpconn_feed_settings';

	/** Current season of each podcast other than the main one: [ feed slug => int ]. */
	const OPTION_SEASONS = 'vpconn_feed_seasons';

	/** The main podcast: its settings are the site's options themselves. */
	const MAIN = 'podcast';

	/** The season option, always per podcast. */
	const SEASON = 'vpconn_current_season';

	/** Options each podcast can have of its own. */
	const KEYS = [
		'vpconn_title_prefix',
		'vpconn_title_include_season',
		'vpconn_title_template',
		'vpconn_episode_numbering_mode',
		'vpconn_post_footer',
		'vpconn_image_style',
		'vpconn_writing_style',
		'vpconn_style_reference_id',
		'vpconn_characters',
		'vpconn_intro_attachment_id',
		'vpconn_outro_attachment_id',
		'vpconn_separator_attachment_id',
		'vpconn_intro_duck_start',
		'vpconn_intro_duck_volume',
		'vpconn_intro_fade_end',
		'vpconn_intro_knee_time',
		'vpconn_outro_fade_start',
		'vpconn_outro_duck_volume',
	];

	/** Option names holding audio attachments (deleting one must not break another podcast). */
	const ATTACHMENT_KEYS = [
		'vpconn_intro_attachment_id',
		'vpconn_outro_attachment_id',
		'vpconn_separator_attachment_id',
	];

	/** REST routes whose writes give the podcast settings of its own. */
	const SETTINGS_ROUTES = [
		'/settings',
		'/settings/intro-outro',
		'/media/intro',
		'/media/outro',
		'/media/separator',
		'/media/style-reference',
	];

	/**
	 * Scopes in force, innermost last: each is [ feed slug|null, split ].
	 * `split` says whether a write may give the podcast settings of its own.
	 *
	 * @var array<int, array{0: ?string, 1: bool}>
	 */
	private static array $stack = [];

	/** @var array<string, bool>|null Known feed slugs, cached per request. */
	private static ?array $known = null;

	public static function register_hooks(): void {
		foreach ( array_merge( self::KEYS, [ self::SEASON ] ) as $option ) {
			add_filter( 'pre_option_' . $option, [ __CLASS__, 'filter_get' ], 10, 3 );
			add_filter( 'pre_update_option_' . $option, [ __CLASS__, 'filter_update' ], 10, 3 );
		}
		add_filter( 'rest_request_before_callbacks', [ __CLASS__, 'rest_before' ], 10, 3 );
		add_filter( 'rest_request_after_callbacks', [ __CLASS__, 'rest_after' ], 10, 3 );
		// Before VPConn_Settings::handle_actions() (priority 10) saves anything.
		add_action( 'admin_init', [ __CLASS__, 'admin_scope' ], 1 );
	}

	// -------------------------------------------------------------------------
	// Scope
	// -------------------------------------------------------------------------

	/** Podcast in scope, or null for the main one / the site. */
	public static function current(): ?string {
		$top = end( self::$stack );
		return $top ? $top[0] : null;
	}

	private static function split(): bool {
		$top = end( self::$stack );
		return $top ? $top[1] : false;
	}

	/**
	 * Puts a podcast in scope until pop(). The main podcast, '' and unknown
	 * slugs mean the site's own settings.
	 */
	public static function push( ?string $feed, bool $split = false ): void {
		$feed           = self::normalize( $feed );
		self::$stack[] = [ $feed, $split ];
	}

	public static function pop(): void {
		array_pop( self::$stack );
	}

	/**
	 * Runs $fn with a podcast in scope.
	 *
	 * @param callable $fn Callback.
	 * @return mixed What $fn returns.
	 */
	public static function run( ?string $feed, callable $fn, bool $split = false ) {
		self::push( $feed, $split );
		try {
			return $fn();
		} finally {
			self::pop();
		}
	}

	/** A podcast slug that has settings apart from the site's, or null. */
	private static function normalize( ?string $feed ): ?string {
		$feed = sanitize_key( (string) $feed );
		if ( '' === $feed || self::MAIN === $feed ) {
			return null;
		}
		return self::is_known( $feed ) ? $feed : null;
	}

	/** Whether $feed is one of this site's podcasts. */
	public static function is_known( string $feed ): bool {
		if ( null === self::$known ) {
			self::$known = [];
			foreach ( VPConn_Settings::get_powerpress_feeds() as $row ) {
				self::$known[ (string) $row['slug'] ] = true;
			}
		}
		return isset( self::$known[ $feed ] );
	}

	/** Forgets the cached list of podcasts (after PowerPress changes them). */
	public static function flush_known(): void {
		self::$known = null;
	}

	// -------------------------------------------------------------------------
	// REST and settings pages
	// -------------------------------------------------------------------------

	/**
	 * Puts the request's podcast (`feed`, or `feed_slug` for /episode) in
	 * scope for this plugin's routes.
	 *
	 * @param mixed           $response Response so far (passed through).
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function rest_before( $response, $handler, $request ) {
		$route  = (string) $request->get_route();
		$prefix = '/' . VPConn_API::NAMESPACE;
		if ( ! str_starts_with( $route, $prefix . '/' ) ) {
			self::push( null );
			return $response;
		}
		$feed  = $request->get_param( 'feed' );
		$feed  = is_string( $feed ) && '' !== $feed ? $feed : $request->get_param( 'feed_slug' );
		$split = 'GET' !== $request->get_method() && in_array( substr( $route, strlen( $prefix ) ), self::SETTINGS_ROUTES, true );
		self::push( is_string( $feed ) ? $feed : null, $split );
		return $response;
	}

	/**
	 * Takes the request's podcast out of scope.
	 *
	 * @param mixed $response Response (passed through).
	 * @return mixed
	 */
	public static function rest_after( $response ) {
		self::pop();
		return $response;
	}

	/**
	 * The Episodes and Audio pages show and save the podcast chosen with
	 * `vpconn_feed` (forms post back to the same URL, so it comes with them).
	 */
	public static function admin_scope(): void {
		// Only reads which podcast the page is about; saving is checked with
		// its nonce and capability in VPConn_Settings::handle_actions().
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) );
		if ( ! in_array( $page, [ 'vozcaster-episodes', 'vozcaster-audio' ], true ) || empty( $_GET['vpconn_feed'] ) ) {
			return;
		}
		$feed = sanitize_key( wp_unslash( $_GET['vpconn_feed'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
		self::push( $feed, $post );
	}

	// -------------------------------------------------------------------------
	// Options API
	// -------------------------------------------------------------------------

	/** @return array<string, array<string, mixed>> */
	private static function store(): array {
		$store = get_option( self::OPTION, [] );
		return is_array( $store ) ? $store : [];
	}

	/** @param array<string, array<string, mixed>> $store */
	private static function save_store( array $store ): void {
		update_option( self::OPTION, $store, false );
	}

	/** Whether $feed has settings of its own. */
	public static function has_own( string $feed ): bool {
		return isset( self::store()[ $feed ] );
	}

	/** Slugs of the podcasts with settings of their own. */
	public static function own_feeds(): array {
		return array_keys( self::store() );
	}

	/**
	 * pre_option_{option}: the podcast's own value.
	 *
	 * @param mixed  $pre     False to read the site's option.
	 * @param string $option  Option name.
	 * @param mixed  $default Default given to get_option().
	 * @return mixed
	 */
	public static function filter_get( $pre, $option, $default = false ) {
		$feed = self::current();
		if ( null === $feed ) {
			return $pre;
		}
		if ( self::SEASON === $option ) {
			$seasons = get_option( self::OPTION_SEASONS, [] );
			return is_array( $seasons ) ? (int) ( $seasons[ $feed ] ?? 0 ) : 0;
		}
		$store = self::store();
		if ( ! isset( $store[ $feed ] ) ) {
			return $pre; // Same settings as the main podcast.
		}
		if ( array_key_exists( $option, $store[ $feed ] ) ) {
			return $store[ $feed ][ $option ];
		}
		// Not set for this podcast. A false default cannot be returned from
		// here (it would read the site's option), so callers of these options
		// never use false as their default.
		return $default;
	}

	/**
	 * pre_update_option_{option}: saves into the podcast and leaves the site's
	 * option alone (returning the old value makes update_option() stop).
	 *
	 * @param mixed  $value  New value.
	 * @param mixed  $old    Value read before (already the podcast's).
	 * @param string $option Option name.
	 * @return mixed
	 */
	public static function filter_update( $value, $old, $option ) {
		$feed = self::current();
		if ( null === $feed ) {
			return $value;
		}
		if ( self::SEASON === $option ) {
			$seasons          = get_option( self::OPTION_SEASONS, [] );
			$seasons          = is_array( $seasons ) ? $seasons : [];
			$seasons[ $feed ] = (int) $value;
			update_option( self::OPTION_SEASONS, $seasons, false );
			return $old;
		}
		$store = self::store();
		if ( ! isset( $store[ $feed ] ) ) {
			if ( ! self::split() ) {
				return $value; // A podcast without settings of its own changes the main ones.
			}
			$store[ $feed ] = self::main_values();
		}
		// false would read back as "not set" (see filter_get()).
		$store[ $feed ][ $option ] = false === $value ? 0 : $value;
		self::save_store( $store );
		return $old;
	}

	/**
	 * delete_option() for the per-podcast options: there is no filter to stop
	 * a deletion, so the plugin calls this instead.
	 */
	public static function delete( string $option ): void {
		$feed = self::current();
		if ( null === $feed || ! in_array( $option, self::KEYS, true ) ) {
			delete_option( $option );
			return;
		}
		$store = self::store();
		if ( ! isset( $store[ $feed ] ) ) {
			if ( ! self::split() ) {
				delete_option( $option );
				return;
			}
			$store[ $feed ] = self::main_values();
		}
		unset( $store[ $feed ][ $option ] );
		self::save_store( $store );
	}

	/** The site's (main podcast's) per-podcast options that are set. */
	private static function main_values(): array {
		return self::run(
			null,
			static function (): array {
				$unset  = new stdClass();
				$values = [];
				foreach ( self::KEYS as $option ) {
					$value = get_option( $option, $unset );
					if ( $value !== $unset ) {
						$values[ $option ] = $value;
					}
				}
				return $values;
			}
		);
	}

	/**
	 * Gives $feed back the main podcast's settings (its season stays: each
	 * podcast keeps its own). Audio files are left in the media library.
	 */
	public static function reset( string $feed ): void {
		$store = self::store();
		unset( $store[ $feed ] );
		self::save_store( $store );
	}

	/**
	 * Whether attachment $id is the intro/outro/separator of the main podcast
	 * or of a podcast other than the one in scope.
	 */
	public static function attachment_used_elsewhere( int $id ): bool {
		$feed  = self::current();
		$store = self::store();
		$main  = self::run(
			null,
			static function (): array {
				$ids = [];
				foreach ( self::ATTACHMENT_KEYS as $option ) {
					$ids[] = (int) get_option( $option, 0 );
				}
				return $ids;
			}
		);
		if ( null !== $feed && in_array( $id, $main, true ) ) {
			return true;
		}
		foreach ( $store as $slug => $values ) {
			if ( $slug === $feed ) {
				continue;
			}
			foreach ( self::ATTACHMENT_KEYS as $option ) {
				if ( (int) ( $values[ $option ] ?? 0 ) === $id ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Attachment IDs of the intro, outro and separator of every podcast.
	 *
	 * @return int[]
	 */
	public static function all_audio_attachments(): array {
		$ids = self::run(
			null,
			static function (): array {
				$ids = [];
				foreach ( self::ATTACHMENT_KEYS as $option ) {
					$ids[] = (int) get_option( $option, 0 );
				}
				return $ids;
			}
		);
		foreach ( self::store() as $values ) {
			foreach ( self::ATTACHMENT_KEYS as $option ) {
				$ids[] = (int) ( $values[ $option ] ?? 0 );
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}
}
