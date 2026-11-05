<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * VozCaster → Assistants: the "Getting started" wizard.
 *
 * Steps (administrators see all four, other authorized users only 3 and 4):
 * 1. Welcome — makes sure PowerPress is installed and active.
 * 2. Your podcast — the minimum show data the platforms ask for, saved with the
 *    same code as the REST endpoint (VPConn_Podcast_Info::save_fields()).
 * 3. Connect — Telegram or the web app, through the existing pairing flow; the
 *    page polls until the account is connected.
 * 4. Start recording — and, once the feed has an episode, send it to the
 *    platforms (VozCaster → Distribute).
 *
 * Only the main PowerPress feed is handled. Nothing here uses AI credits.
 */
class VPConn_Assistant {

	const PAGE_SLUG       = 'vozcaster-assistants';
	const ASSISTANT       = 'getting-started';
	const FEED            = 'podcast';
	const COVER_OPTION    = 'vpconn_default_cover_id';
	const OPT_SAVED       = 'vpconn_gs_podcast_saved';
	const REDIRECT_FLAG   = 'vpconn_gs_redirect';
	const NOTICE_TRANS    = 'vpconn_gs_notice_';
	const META_STEP       = 'vpconn_gs_step';
	const META_DONE       = 'vpconn_gs_done';
	const META_SKIPPED    = 'vpconn_gs_skipped';
	const META_PLATFORMS  = 'vpconn_gs_platforms_dismissed';
	const POWERPRESS_FILE = 'powerpress/powerpress.php';

	public function register_hooks(): void {
		// After the other VozCaster submenu pages, so Assistants comes last.
		add_action( 'admin_menu', [ $this, 'add_page' ], 11 );
		add_action( 'admin_init', [ $this, 'maybe_redirect_after_activation' ] );
		add_action( 'admin_init', [ $this, 'handle_links' ] );
		add_action( 'admin_post_vpconn_gs_powerpress', [ $this, 'handle_powerpress' ] );
		add_action( 'admin_post_vpconn_gs_podcast', [ $this, 'handle_podcast' ] );
		add_action( 'wp_ajax_vpconn_gs_status', [ $this, 'ajax_status' ] );
		add_action( 'admin_notices', [ $this, 'show_platforms_notice' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	// -------------------------------------------------------------------------
	// URLs and state
	// -------------------------------------------------------------------------

	public static function get_page_url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	public static function get_step_url( int $step ): string {
		return add_query_arg(
			[
				'page'      => self::PAGE_SLUG,
				'assistant' => self::ASSISTANT,
				'step'      => $step,
			],
			admin_url( 'admin.php' )
		);
	}

	/** @return int[] Steps this user can go through. */
	private static function steps_for_user(): array {
		return current_user_can( 'manage_options' ) ? [ 1, 2, 3, 4 ] : [ 3, 4 ];
	}

	public static function is_done( int $user_id ): bool {
		return (bool) get_user_meta( $user_id, self::META_DONE, true );
	}

	private static function powerpress_active(): bool {
		return function_exists( 'powerpress_apple_categories' );
	}

	/** Queues a one-shot message for the next render of the wizard. */
	private static function flash( string $type, string $message, array $values = [] ): void {
		set_transient(
			self::NOTICE_TRANS . get_current_user_id(),
			[
				'type'    => $type,
				'message' => $message,
				'values'  => $values,
			],
			MINUTE_IN_SECONDS
		);
	}

	private static function take_flash(): array {
		$key  = self::NOTICE_TRANS . get_current_user_id();
		$data = get_transient( $key );
		if ( false === $data ) {
			return [];
		}
		delete_transient( $key );
		return (array) $data;
	}

	// -------------------------------------------------------------------------
	// Menu
	// -------------------------------------------------------------------------

	public function add_page(): void {
		if ( ! VPConn_Auth::is_user_allowed( get_current_user_id() ) ) {
			return;
		}
		add_submenu_page(
			VPConn_Pairing::PAGE_SLUG,
			__( 'VozCaster — Assistants', 'connector-for-vozcaster' ),
			__( 'Assistants', 'connector-for-vozcaster' ),
			'read',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! str_ends_with( $hook, 'page_' . self::PAGE_SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'vpconn-assistant', VPCONN_URL . 'assets/css/assistant.css', [], VPCONN_VERSION );

		if ( ! self::is_wizard_request() ) {
			return;
		}
		$step = self::current_step();
		if ( 2 === $step ) {
			wp_enqueue_media();
		}
		if ( 2 !== $step && 3 !== $step ) {
			return;
		}
		wp_enqueue_script( 'vpconn-assistant', VPCONN_URL . 'assets/js/assistant.js', [], VPCONN_VERSION, true );
		wp_localize_script(
			'vpconn-assistant',
			'vpconnAssistant',
			[
				'step'          => $step,
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'vpconn_gs_status' ),
				'pollMs'        => 4000,
				'defaults'      => 2 === $step ? self::default_values() : [],
				'defaultCover'  => VPCONN_URL . 'assets/default-cover.jpg',
				'i18n'          => [
					'pickCover'   => __( 'Choose the cover', 'connector-for-vozcaster' ),
					'select'      => __( 'Use this image', 'connector-for-vozcaster' ),
					'filled'      => __( 'Empty fields are filled in. Review them, change what you like and save.', 'connector-for-vozcaster' ),
					'nothing'     => __( 'Nothing to fill in: every field already has a value.', 'connector-for-vozcaster' ),
					'defaultCover' => __( 'VozCaster default cover — you can change it any time.', 'connector-for-vozcaster' ),
					'ownCover'    => __( 'Cover selected from your media library.', 'connector-for-vozcaster' ),
					'continue'    => __( 'Continue', 'connector-for-vozcaster' ),
				],
			]
		);
	}

	/** Requested step, limited to the ones this user may see. */
	private static function current_step(): int {
		// Read-only navigation on an admin screen.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$step  = isset( $_GET['step'] ) ? absint( wp_unslash( $_GET['step'] ) ) : 0;
		$steps = self::steps_for_user();
		if ( ! in_array( $step, $steps, true ) ) {
			$step = $steps[0];
		}
		// Everything after the first step needs PowerPress.
		if ( 1 !== $step && current_user_can( 'manage_options' ) && ! self::powerpress_active() ) {
			$step = 1;
		}
		return $step;
	}

	private static function is_wizard_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['assistant'] ) && self::ASSISTANT === sanitize_key( wp_unslash( $_GET['assistant'] ) );
	}

	// -------------------------------------------------------------------------
	// Opening once after activation
	// -------------------------------------------------------------------------

	/** Called from the activation hook. */
	public static function flag_activation(): void {
		if ( is_network_admin() ) {
			return;
		}
		set_transient( self::REDIRECT_FLAG, 1, 5 * MINUTE_IN_SECONDS );
	}

	public function maybe_redirect_after_activation(): void {
		if ( ! get_transient( self::REDIRECT_FLAG ) ) {
			return;
		}
		delete_transient( self::REDIRECT_FLAG );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['activate-multi'] ) || is_network_admin() || wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( ! VPConn_Auth::is_user_allowed( get_current_user_id() ) ) {
			return;
		}
		wp_safe_redirect( self::get_step_url( self::steps_for_user()[0] ) );
		exit;
	}

	/** "Skip" and the dismissal of the platforms notice. */
	public function handle_links(): void {
		if ( ! empty( $_GET['vpconn_gs_skip'] ) ) {
			check_admin_referer( 'vpconn_gs_skip' );
			update_user_meta( get_current_user_id(), self::META_SKIPPED, 1 );
			wp_safe_redirect( self::get_page_url() );
			exit;
		}
		if ( ! empty( $_GET['vpconn_gs_dismiss_platforms'] ) ) {
			check_admin_referer( 'vpconn_gs_dismiss_platforms' );
			update_user_meta( get_current_user_id(), self::META_PLATFORMS, 1 );
			wp_safe_redirect( remove_query_arg( [ 'vpconn_gs_dismiss_platforms', '_wpnonce' ] ) );
			exit;
		}
	}

	// -------------------------------------------------------------------------
	// Default values for "Fill in with default data"
	// -------------------------------------------------------------------------

	/** Values deduced from the site, for the empty fields of the podcast form. */
	private static function default_values(): array {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$tag  = trim( wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES ) );
		$user = wp_get_current_user();

		$language = '';
		$locale   = strtolower( str_replace( '_', '-', get_locale() ) );
		$codes    = array_keys( VPConn_Podcast_Info::languages() );
		foreach ( $codes as $code ) {
			if ( strtolower( (string) $code ) === $locale ) {
				$language = (string) $code;
				break;
			}
		}
		if ( '' === $language ) {
			$base = strtok( $locale, '-' );
			foreach ( $codes as $code ) {
				if ( strtolower( (string) $code ) === $base ) {
					$language = (string) $code;
					break;
				}
			}
		}

		$category = '';
		foreach ( VPConn_Podcast_Info::apple_categories() as $code => $name ) {
			if ( 'Society & Culture' === $name ) {
				$category = (string) $code;
				break;
			}
		}

		return [
			'title'       => $site,
			'description' => '' !== $tag
				? $tag
				/* translators: %s: site name. */
				: sprintf( __( 'The %s podcast.', 'connector-for-vozcaster' ), $site ),
			'author'      => $user->display_name,
			'email'       => (string) get_option( 'admin_email' ),
			'language'    => $language,
			'category'    => $category,
			'explicit'    => '0',
		];
	}

	/**
	 * The attachment holding the VozCaster default cover, imported to the media
	 * library the first time it is needed and reused afterwards.
	 */
	private static function default_cover_id(): int|WP_Error {
		$id = (int) get_option( self::COVER_OPTION );
		if ( $id && wp_attachment_is_image( $id ) ) {
			return $id;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$path = VPCONN_DIR . 'assets/default-cover.jpg';
		// Local file shipped with the plugin, not a remote URL.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$bytes = (string) file_get_contents( $path );
		$bits  = wp_upload_bits( 'vozcaster-default-cover.jpg', null, $bytes );
		if ( ! empty( $bits['error'] ) ) {
			return new WP_Error( 'vpconn_cover_import', (string) $bits['error'] );
		}
		$id = wp_insert_attachment(
			[
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'VozCaster default cover',
				'post_status'    => 'inherit',
			],
			$bits['file']
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return new WP_Error( 'vpconn_cover_import', 'Could not add the cover to the media library.' );
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $bits['file'] ) );
		update_option( self::COVER_OPTION, (int) $id, false );
		return (int) $id;
	}

	// -------------------------------------------------------------------------
	// Form handlers
	// -------------------------------------------------------------------------

	/** Installs (when needed) and activates PowerPress. */
	public function handle_powerpress(): void {
		check_admin_referer( 'vpconn_gs_powerpress' );
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'connector-for-vozcaster' ), '', [ 'response' => 403 ] );
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if ( ! isset( get_plugins()[ self::POWERPRESS_FILE ] ) ) {
			if ( ! current_user_can( 'install_plugins' ) ) {
				wp_die( esc_html__( 'You are not allowed to do this.', 'connector-for-vozcaster' ), '', [ 'response' => 403 ] );
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

			$api = plugins_api( 'plugin_information', [ 'slug' => 'powerpress', 'fields' => [ 'sections' => false ] ] );
			if ( is_wp_error( $api ) ) {
				self::flash( 'error', __( 'PowerPress could not be downloaded from WordPress.org. Try again, or install it from Plugins → Add New.', 'connector-for-vozcaster' ) );
				wp_safe_redirect( self::get_step_url( 1 ) );
				exit;
			}
			$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
			$result   = $upgrader->install( $api->download_link );
			wp_clean_plugins_cache();
			if ( true !== $result || ! isset( get_plugins()[ self::POWERPRESS_FILE ] ) ) {
				self::flash( 'error', __( 'PowerPress could not be installed from here. Install it from Plugins → Add New and come back.', 'connector-for-vozcaster' ) );
				wp_safe_redirect( self::get_step_url( 1 ) );
				exit;
			}
		}

		$activated = activate_plugin( self::POWERPRESS_FILE );
		if ( is_wp_error( $activated ) ) {
			self::flash( 'error', __( 'PowerPress is installed but could not be activated. Activate it from the Plugins page.', 'connector-for-vozcaster' ) );
			wp_safe_redirect( self::get_step_url( 1 ) );
			exit;
		}
		wp_safe_redirect( self::get_step_url( 2 ) );
		exit;
	}

	/** Saves the podcast form. Empty fields never overwrite what is already there. */
	public function handle_podcast(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'connector-for-vozcaster' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'vpconn_gs_podcast' );

		$posted = [
			'title'       => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
			'description' => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
			'author'      => sanitize_text_field( wp_unslash( $_POST['author'] ?? '' ) ),
			'email'       => trim( sanitize_text_field( wp_unslash( $_POST['email'] ?? '' ) ) ),
			'language'    => sanitize_text_field( wp_unslash( $_POST['language'] ?? '' ) ),
			'category'    => sanitize_text_field( wp_unslash( $_POST['category'] ?? '' ) ),
			'explicit'    => sanitize_text_field( wp_unslash( $_POST['explicit'] ?? '' ) ),
		];
		$cover_id   = absint( wp_unslash( $_POST['artwork_id'] ?? 0 ) );
		$cover_orig = absint( wp_unslash( $_POST['artwork_orig'] ?? 0 ) );
		$use_def    = '1' === sanitize_text_field( wp_unslash( $_POST['use_default_cover'] ?? '' ) );

		$back = static function ( string $message ) use ( $posted, $cover_id, $use_def ): void {
			self::flash( 'error', $message, $posted + [ 'artwork_id' => $cover_id, 'use_default' => $use_def ] );
			wp_safe_redirect( self::get_step_url( 2 ) );
			exit;
		};

		$values = [];
		foreach ( [ 'title', 'description', 'author', 'email', 'language', 'category' ] as $field ) {
			if ( '' !== $posted[ $field ] ) {
				$values[ $field ] = $posted[ $field ];
			}
		}
		if ( in_array( $posted['explicit'], [ '0', '1' ], true ) ) {
			$values['explicit'] = '1' === $posted['explicit'];
		}
		if ( $use_def && ! $cover_id ) {
			$default = self::default_cover_id();
			if ( is_wp_error( $default ) ) {
				$back( __( 'The VozCaster cover could not be added to your media library. Choose your own cover instead.', 'connector-for-vozcaster' ) );
			}
			$values['artwork_id'] = $default;
		} elseif ( $cover_id && $cover_id !== $cover_orig ) {
			$values['artwork_id'] = $cover_id;
		}

		$saved = VPConn_Podcast_Info::save_fields( self::FEED, $values );
		if ( is_wp_error( $saved ) ) {
			$messages = [
				'vpconn_invalid_email'    => __( 'That email address does not look right. Check it and try again.', 'connector-for-vozcaster' ),
				'vpconn_invalid_language' => __( 'Choose the language from the list.', 'connector-for-vozcaster' ),
				'vpconn_invalid_category' => __( 'Choose the category from the list.', 'connector-for-vozcaster' ),
				'vpconn_invalid_artwork'  => sprintf(
					/* translators: 1: minimum size in pixels, 2: maximum size in pixels. */
					__( 'The cover must be square, between %1$d and %2$d px. Choose another image.', 'connector-for-vozcaster' ),
					VPConn_Podcast_Info::ARTWORK_MIN,
					VPConn_Podcast_Info::ARTWORK_MAX
				),
			];
			$back( $messages[ $saved->get_error_code() ] ?? __( 'Your podcast data could not be saved. Please try again.', 'connector-for-vozcaster' ) );
		}

		update_option( self::OPT_SAVED, 1, false );
		wp_safe_redirect( self::get_step_url( 3 ) );
		exit;
	}

	public function ajax_status(): void {
		check_ajax_referer( 'vpconn_gs_status', 'nonce' );
		$user_id = get_current_user_id();
		if ( ! VPConn_Auth::is_user_allowed( $user_id ) ) {
			wp_send_json_error( null, 403 );
		}
		wp_send_json_success( [ 'connected' => VPConn_Auth::has_token( $user_id ) ] );
	}

	// -------------------------------------------------------------------------
	// Rendering
	// -------------------------------------------------------------------------

	public function render_page(): void {
		$user_id = get_current_user_id();
		if ( ! VPConn_Auth::is_user_allowed( $user_id ) ) {
			return;
		}
		echo '<div class="wrap vpconn-gs">';
		if ( self::is_wizard_request() ) {
			$this->render_wizard( $user_id );
		} else {
			$this->render_cards( $user_id );
		}
		echo '</div>';
	}

	private function render_cards( int $user_id ): void {
		$done = self::is_done( $user_id );
		$step = (int) get_user_meta( $user_id, self::META_STEP, true );
		if ( ! in_array( $step, self::steps_for_user(), true ) ) {
			$step = self::steps_for_user()[0];
		}
		?>
		<h1><?php esc_html_e( 'VozCaster — Assistants', 'connector-for-vozcaster' ); ?></h1>
		<p class="vpconn-gs-lead"><?php esc_html_e( 'Short guides that take you by the hand. Pick one.', 'connector-for-vozcaster' ); ?></p>
		<div class="vpconn-gs-cards">
			<div class="vpconn-gs-card">
				<h2><?php esc_html_e( 'Getting started', 'connector-for-vozcaster' ); ?></h2>
				<p><?php esc_html_e( 'The basics in a few minutes: your podcast data for Apple Podcasts and Spotify, the connection with VozCaster, and your first recording.', 'connector-for-vozcaster' ); ?></p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( self::get_step_url( $done ? self::steps_for_user()[0] : $step ) ); ?>">
						<?php echo esc_html( $done ? __( 'Open', 'connector-for-vozcaster' ) : __( 'Continue setup', 'connector-for-vozcaster' ) ); ?>
					</a>
				</p>
			</div>
			<div class="vpconn-gs-card vpconn-gs-card--off" aria-disabled="true">
				<h2><?php esc_html_e( 'Guided setup', 'connector-for-vozcaster' ); ?> <span class="vpconn-gs-badge"><?php esc_html_e( 'Coming soon', 'connector-for-vozcaster' ); ?></span></h2>
				<p><?php esc_html_e( 'The advanced settings, explained one by one: titles, style, intro and outro, and who can publish.', 'connector-for-vozcaster' ); ?></p>
				<p><button type="button" class="button" disabled><?php esc_html_e( 'Coming soon', 'connector-for-vozcaster' ); ?></button></p>
			</div>
		</div>
		<?php
	}

	private function render_wizard( int $user_id ): void {
		$step  = self::current_step();
		$steps = self::steps_for_user();
		update_user_meta( $user_id, self::META_STEP, $step );
		$pos   = (int) array_search( $step, $steps, true ) + 1;
		$total = count( $steps );
		$flash = self::take_flash();
		$skip  = wp_nonce_url( add_query_arg( 'vpconn_gs_skip', 1, self::get_page_url() ), 'vpconn_gs_skip' );
		?>
		<h1><?php esc_html_e( 'Getting started', 'connector-for-vozcaster' ); ?></h1>
		<div class="vpconn-gs-progress">
			<span>
				<?php
				/* translators: 1: current step number, 2: total steps. */
				echo esc_html( sprintf( __( 'Step %1$d of %2$d', 'connector-for-vozcaster' ), $pos, $total ) );
				?>
			</span>
			<span class="vpconn-gs-bar" aria-hidden="true"><i style="width:<?php echo esc_attr( (string) round( 100 * $pos / $total ) ); ?>%"></i></span>
			<a class="vpconn-gs-skip" href="<?php echo esc_url( $skip ); ?>"><?php esc_html_e( 'Skip', 'connector-for-vozcaster' ); ?></a>
		</div>
		<?php
		if ( ! empty( $flash['message'] ) ) {
			printf(
				'<div class="notice notice-%1$s inline"><p>%2$s</p></div>',
				esc_attr( 'error' === ( $flash['type'] ?? '' ) ? 'error' : 'success' ),
				esc_html( (string) $flash['message'] )
			);
		}
		echo '<div class="vpconn-gs-panel">';
		match ( $step ) {
			1       => $this->render_step_welcome(),
			2       => $this->render_step_podcast( (array) ( $flash['values'] ?? [] ) ),
			3       => $this->render_step_connect( $user_id ),
			default => $this->render_step_record( $user_id ),
		};
		echo '</div>';
	}

	private function render_step_welcome(): void {
		$active    = self::powerpress_active();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$installed = isset( get_plugins()[ self::POWERPRESS_FILE ] );
		?>
		<h2><?php esc_html_e( 'Welcome to VozCaster', 'connector-for-vozcaster' ); ?></h2>
		<p><?php esc_html_e( 'You talk, VozCaster does the rest: send a voice note and it turns it into a podcast episode published on this site. No rush and nothing to break: we will set up the essentials together.', 'connector-for-vozcaster' ); ?></p>

		<?php if ( $active ) : ?>
			<p class="vpconn-gs-ok">✓ <?php esc_html_e( 'PowerPress is active: it is the plugin that builds your podcast feed.', 'connector-for-vozcaster' ); ?></p>
			<p><a class="button button-primary" href="<?php echo esc_url( self::get_step_url( 2 ) ); ?>"><?php esc_html_e( 'Next', 'connector-for-vozcaster' ); ?></a></p>
		<?php else : ?>
			<p><?php esc_html_e( 'First we need PowerPress, the free plugin that builds your podcast feed.', 'connector-for-vozcaster' ); ?></p>
			<?php if ( current_user_can( $installed ? 'activate_plugins' : 'install_plugins' ) && ( $installed || current_user_can( 'activate_plugins' ) ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'vpconn_gs_powerpress' ); ?>
					<input type="hidden" name="action" value="vpconn_gs_powerpress">
					<button type="submit" class="button button-primary">
						<?php echo esc_html( $installed ? __( 'Activate PowerPress', 'connector-for-vozcaster' ) : __( 'Install and activate PowerPress', 'connector-for-vozcaster' ) ); ?>
					</button>
				</form>
			<?php else : ?>
				<p><?php esc_html_e( 'Your account cannot install plugins. Ask the site administrator to install and activate PowerPress, then come back.', 'connector-for-vozcaster' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	private function render_step_podcast( array $flash_values ): void {
		$info = VPConn_Podcast_Info::describe( self::FEED );
		if ( is_wp_error( $info ) ) {
			echo '<p>' . esc_html__( 'The podcast data could not be read.', 'connector-for-vozcaster' ) . '</p>';
			return;
		}
		$d     = $info['data'];
		$val   = static fn( string $k ) => (string) ( $flash_values[ $k ] ?? $d[ $k ] ?? '' );
		$cats  = VPConn_Podcast_Info::apple_categories();
		$langs = VPConn_Podcast_Info::languages();

		$art_id   = (int) ( $info['artwork']['id'] ?? 0 );
		$art_url  = (string) ( $info['artwork']['url'] ?? '' );
		$art_post = isset( $flash_values['artwork_id'] ) ? (int) $flash_values['artwork_id'] : $art_id;
		if ( $art_post && $art_post !== $art_id ) {
			$art_url = (string) wp_get_attachment_url( $art_post );
		}
		$use_default = ! empty( $flash_values['use_default'] ) && ! $art_post;
		if ( $use_default ) {
			$art_url = VPCONN_URL . 'assets/default-cover.jpg';
		}
		// Only "Yes" is pre-selected: an unset value stays unset until the user picks.
		$explicit = (string) ( $flash_values['explicit'] ?? ( $d['explicit'] ? '1' : '' ) );
		$next     = self::get_step_url( 3 );
		?>
		<h2><?php esc_html_e( 'Your podcast', 'connector-for-vozcaster' ); ?></h2>
		<p><?php esc_html_e( 'This is what Apple Podcasts, Spotify and the other apps show about your podcast. You can change all of it later.', 'connector-for-vozcaster' ); ?></p>

		<p>
			<button type="button" class="button" id="vpconn-gs-fill"><?php esc_html_e( 'Fill in with default data', 'connector-for-vozcaster' ); ?></button>
			<span class="description" id="vpconn-gs-fill-msg" role="status"></span>
		</p>
		<p class="description"><?php esc_html_e( 'Fills in only the empty fields, so you can see them and change them. Nothing is saved until you press Save and continue.', 'connector-for-vozcaster' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="vpconn-gs-form">
			<?php wp_nonce_field( 'vpconn_gs_podcast' ); ?>
			<input type="hidden" name="action" value="vpconn_gs_podcast">
			<input type="hidden" name="artwork_id" id="vpconn-gs-artwork-id" value="<?php echo esc_attr( (string) $art_post ); ?>">
			<input type="hidden" name="artwork_orig" value="<?php echo esc_attr( (string) $art_id ); ?>">
			<input type="hidden" name="use_default_cover" id="vpconn-gs-use-default" value="<?php echo $use_default ? '1' : '0'; ?>">

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="vpconn-gs-title"><?php esc_html_e( 'Title', 'connector-for-vozcaster' ); ?></label></th>
					<td><input type="text" class="regular-text" id="vpconn-gs-title" name="title" data-default="title" value="<?php echo esc_attr( $val( 'title' ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="vpconn-gs-description"><?php esc_html_e( 'Description', 'connector-for-vozcaster' ); ?></label></th>
					<td><textarea class="large-text" rows="3" id="vpconn-gs-description" name="description" data-default="description"><?php echo esc_textarea( $val( 'description' ) ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="vpconn-gs-author"><?php esc_html_e( 'Author', 'connector-for-vozcaster' ); ?></label></th>
					<td><input type="text" class="regular-text" id="vpconn-gs-author" name="author" data-default="author" value="<?php echo esc_attr( $val( 'author' ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="vpconn-gs-email"><?php esc_html_e( 'Email', 'connector-for-vozcaster' ); ?></label></th>
					<td>
						<input type="email" class="regular-text" id="vpconn-gs-email" name="email" data-default="email" value="<?php echo esc_attr( $val( 'email' ) ); ?>">
						<p class="description"><?php esc_html_e( 'It appears in your public feed, and Apple uses it to check that the podcast is yours.', 'connector-for-vozcaster' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="vpconn-gs-language"><?php esc_html_e( 'Language', 'connector-for-vozcaster' ); ?></label></th>
					<td>
						<select id="vpconn-gs-language" name="language" data-default="language">
							<option value=""><?php esc_html_e( '— Choose —', 'connector-for-vozcaster' ); ?></option>
							<?php foreach ( $langs as $code => $name ) : ?>
								<option value="<?php echo esc_attr( (string) $code ); ?>" <?php selected( $val( 'language' ), (string) $code ); ?>><?php echo esc_html( (string) $name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="vpconn-gs-category"><?php esc_html_e( 'Apple Podcasts category', 'connector-for-vozcaster' ); ?></label></th>
					<td>
						<select id="vpconn-gs-category" name="category" data-default="category">
							<option value=""><?php esc_html_e( '— Choose —', 'connector-for-vozcaster' ); ?></option>
							<?php foreach ( $cats as $code => $name ) : ?>
								<option value="<?php echo esc_attr( (string) $code ); ?>" <?php selected( $val( 'category' ), (string) $code ); ?>><?php echo esc_html( (string) $name ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description vpconn-gs-hint" id="vpconn-gs-category-note" hidden><?php esc_html_e( 'We picked a general category: review it and choose the one that fits your podcast best.', 'connector-for-vozcaster' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Explicit content', 'connector-for-vozcaster' ); ?></th>
					<td>
						<label><input type="radio" name="explicit" value="0" <?php checked( '0', $explicit ); ?>> <?php esc_html_e( 'No', 'connector-for-vozcaster' ); ?></label>
						&nbsp;
						<label><input type="radio" name="explicit" value="1" <?php checked( '1', $explicit ); ?>> <?php esc_html_e( 'Yes', 'connector-for-vozcaster' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Cover', 'connector-for-vozcaster' ); ?></th>
					<td>
						<div class="vpconn-gs-cover">
							<img id="vpconn-gs-cover-img" src="<?php echo esc_url( $art_url ); ?>" alt="" width="120" height="120" <?php echo '' === $art_url ? 'hidden' : ''; ?>>
							<div>
								<p class="description" id="vpconn-gs-cover-label">
									<?php
									if ( $use_default ) {
										esc_html_e( 'VozCaster default cover — you can change it any time.', 'connector-for-vozcaster' );
									} elseif ( '' === $art_url ) {
										esc_html_e( 'No cover yet. Square, between 1400 and 3000 px.', 'connector-for-vozcaster' );
									} else {
										esc_html_e( 'Your current cover.', 'connector-for-vozcaster' );
									}
									?>
								</p>
								<button type="button" class="button" id="vpconn-gs-cover-pick"><?php esc_html_e( 'Choose from the media library', 'connector-for-vozcaster' ); ?></button>
							</div>
						</div>
					</td>
				</tr>
			</table>

			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save and continue', 'connector-for-vozcaster' ); ?></button>
				&nbsp;<a href="<?php echo esc_url( $next ); ?>"><?php esc_html_e( 'Skip this step', 'connector-for-vozcaster' ); ?></a>
				&nbsp;<a href="<?php echo esc_url( self::get_step_url( 1 ) ); ?>"><?php esc_html_e( 'Back', 'connector-for-vozcaster' ); ?></a>
			</p>
		</form>
		<?php
	}

	private function render_step_connect( int $user_id ): void {
		$connected = VPConn_Auth::has_token( $user_id );
		$feeds     = VPConn_Access::feeds_for_user( $user_id );
		$feed      = $feeds[0] ?? null;
		foreach ( $feeds as $f ) {
			if ( self::FEED === $f['slug'] ) {
				$feed = $f;
			}
		}
		$can_edit = current_user_can( 'manage_options' );
		?>
		<h2><?php esc_html_e( 'Connect', 'connector-for-vozcaster' ); ?></h2>
		<p><?php esc_html_e( 'Link this site with VozCaster so you can send voice notes from your phone. Pick where you prefer to record. It opens in a new tab; come back here when you are done.', 'connector-for-vozcaster' ); ?></p>

		<div id="vpconn-gs-connected" class="vpconn-gs-ok" role="status" <?php echo $connected ? '' : 'hidden'; ?>>
			✓ <?php esc_html_e( 'Connected ✓ Your account is linked with VozCaster.', 'connector-for-vozcaster' ); ?>
		</div>

		<?php if ( ! $feed ) : ?>
			<p><?php esc_html_e( 'Your account cannot send episodes to a podcast of this site yet. Ask the site administrator for access.', 'connector-for-vozcaster' ); ?></p>
		<?php elseif ( null === VPConn_Pairing::get_encoded_host() ) : ?>
			<p><?php esc_html_e( 'The address of this site cannot be passed to the bot automatically. Open the bot, send /conectar and paste this address:', 'connector-for-vozcaster' ); ?></p>
			<p><code><?php echo esc_html( home_url() ); ?></code></p>
			<p><a class="button button-primary" href="<?php echo esc_url( 'https://t.me/' . VPConn_Pairing::get_bot_username() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the bot in Telegram', 'connector-for-vozcaster' ); ?></a></p>
		<?php else : ?>
			<div class="vpconn-gs-cards vpconn-gs-cards--two" id="vpconn-gs-connect-cards" <?php echo $connected ? 'hidden' : ''; ?>>
				<div class="vpconn-gs-card">
					<h3><?php esc_html_e( 'Telegram', 'connector-for-vozcaster' ); ?></h3>
					<p><?php esc_html_e( 'Send voice notes to the VozCaster bot. Press Start and confirm there.', 'connector-for-vozcaster' ); ?></p>
					<?php VPConn_Pairing::connect_form( $feed['slug'], 'telegram', __( 'Connect in Telegram', 'connector-for-vozcaster' ), true ); ?>
				</div>
				<div class="vpconn-gs-card">
					<h3><?php esc_html_e( 'Web app', 'connector-for-vozcaster' ); ?></h3>
					<p><?php esc_html_e( 'Record in the browser of your phone or computer, no Telegram needed.', 'connector-for-vozcaster' ); ?></p>
					<?php VPConn_Pairing::connect_form( $feed['slug'], 'app', __( 'Connect in the app', 'connector-for-vozcaster' ), true ); ?>
				</div>
			</div>
			<p class="description" id="vpconn-gs-waiting" <?php echo $connected ? 'hidden' : ''; ?>>
				<?php esc_html_e( 'Waiting for the connection… this page updates by itself. Each link works once and expires in 15 minutes.', 'connector-for-vozcaster' ); ?>
			</p>
		<?php endif; ?>

		<p>
			<a class="button <?php echo $connected ? 'button-primary' : ''; ?>" id="vpconn-gs-next" href="<?php echo esc_url( self::get_step_url( 4 ) ); ?>">
				<?php echo esc_html( $connected ? __( 'Continue', 'connector-for-vozcaster' ) : __( 'Skip this step', 'connector-for-vozcaster' ) ); ?>
			</a>
			<?php if ( $can_edit ) : ?>
				&nbsp;<a href="<?php echo esc_url( self::get_step_url( 2 ) ); ?>"><?php esc_html_e( 'Back', 'connector-for-vozcaster' ); ?></a>
			<?php endif; ?>
		</p>
		<?php
	}

	private function render_step_record( int $user_id ): void {
		update_user_meta( $user_id, self::META_DONE, 1 );
		$is_admin = current_user_can( 'manage_options' );
		?>
		<h2><?php esc_html_e( 'Start recording', 'connector-for-vozcaster' ); ?></h2>
		<p><strong><?php esc_html_e( 'Congratulations, you are all set!', 'connector-for-vozcaster' ); ?></strong>
			<?php esc_html_e( 'Send your first voice note to the bot, or record it in the app. VozCaster will do the rest.', 'connector-for-vozcaster' ); ?></p>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( 'https://t.me/' . VPConn_Pairing::get_bot_username() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the bot in Telegram', 'connector-for-vozcaster' ); ?></a>
			<a class="button" href="<?php echo esc_url( VPConn_Pairing::get_app_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the app', 'connector-for-vozcaster' ); ?></a>
		</p>

		<?php if ( $is_admin ) : ?>
			<?php
			$info     = VPConn_Podcast_Info::describe( self::FEED );
			$episodes = is_wp_error( $info ) ? 0 : (int) $info['episodes'];
			$default  = (int) get_option( self::COVER_OPTION );
			$uses_default = ! is_wp_error( $info ) && ! empty( $info['artwork']['id'] ) && (int) $info['artwork']['id'] === $default && $default;
			?>
			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'To send your podcast to Apple Podcasts, Spotify and the other platforms, you need at least one published episode. Record the first one and come back.', 'connector-for-vozcaster' ); ?></p>
			</div>
			<hr>
			<h3><?php esc_html_e( 'Send to platforms', 'connector-for-vozcaster' ); ?></h3>
			<?php if ( $episodes < 1 ) : ?>
				<p class="vpconn-gs-locked">🔒 <?php esc_html_e( 'Locked until your podcast has its first published episode.', 'connector-for-vozcaster' ); ?></p>
				<p>
					<button type="button" class="button" disabled><?php esc_html_e( 'Send to platforms', 'connector-for-vozcaster' ); ?></button>
					&nbsp;<a href="<?php echo esc_url( self::get_step_url( 4 ) ); ?>"><?php esc_html_e( 'Check again', 'connector-for-vozcaster' ); ?></a>
				</p>
			<?php else : ?>
				<?php if ( $uses_default ) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'Your podcast still uses the VozCaster default cover. It is better to set your own before sending it to the platforms (VozCaster → Assistants → Getting started, step 2).', 'connector-for-vozcaster' ); ?></p></div>
				<?php endif; ?>
				<p><?php esc_html_e( 'Your first episode is out. Send your feed to each platform once; after that they pick up every new episode by themselves.', 'connector-for-vozcaster' ); ?></p>
				<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=vozcaster-distribute' ) ); ?>"><?php esc_html_e( 'Send to platforms', 'connector-for-vozcaster' ); ?></a></p>
			<?php endif; ?>
		<?php endif; ?>
		<p><a href="<?php echo esc_url( self::get_page_url() ); ?>"><?php esc_html_e( 'Back to Assistants', 'connector-for-vozcaster' ); ?></a></p>
		<?php
	}

	// -------------------------------------------------------------------------
	// Notice: first episode is out
	// -------------------------------------------------------------------------

	public function show_platforms_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! VPConn_Auth::is_user_allowed( get_current_user_id() ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, [ 'dashboard', 'plugins' ], true ) && ! VPConn_Settings::is_plugin_screen( $screen->id ) ) {
			return;
		}
		if ( str_ends_with( $screen->id, 'page_vozcaster-distribute' ) || get_user_meta( get_current_user_id(), self::META_PLATFORMS, true ) ) {
			return;
		}
		// Distribute already started: nothing to nudge.
		$started = (array) get_option( VPConn_Distribution::OPTION, [] );
		if ( ! empty( $started[ self::FEED ] ) ) {
			return;
		}
		$count = get_transient( 'vpconn_gs_episodes' );
		if ( false === $count ) {
			$info  = VPConn_Podcast_Info::describe( self::FEED );
			$count = is_wp_error( $info ) ? 0 : (int) $info['episodes'];
			set_transient( 'vpconn_gs_episodes', $count, 5 * MINUTE_IN_SECONDS );
		}
		if ( (int) $count < 1 ) {
			return;
		}
		$dismiss = wp_nonce_url( add_query_arg( 'vpconn_gs_dismiss_platforms', 1 ), 'vpconn_gs_dismiss_platforms' );
		?>
		<div class="notice notice-info">
			<p><strong><?php esc_html_e( 'Your first episode is out: time to send your podcast to the platforms.', 'connector-for-vozcaster' ); ?></strong></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=vozcaster-distribute' ) ); ?>"><?php esc_html_e( 'Send to platforms', 'connector-for-vozcaster' ); ?></a>
				&nbsp;<a href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( 'Dismiss', 'connector-for-vozcaster' ); ?></a>
			</p>
		</div>
		<?php
	}
}
