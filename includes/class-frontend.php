<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * VozCaster on the site's own pages: the blocks, and the small pieces shared
 * by every screen that works both in wp-admin and on the front end (form
 * redirects and the notice shown after saving).
 *
 * Each block shows what the logged-in user may do and nothing else, so a page
 * like "Send a podcast episode" can hold them all; visibility plugins can
 * hide them further.
 */
class VPConn_Frontend {

	const NOTICE_PARAM = 'vpconn_notice';
	const SCRIPT       = 'vpconn-blocks';
	const STYLE        = 'vpconn-blocks';

	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register_blocks' ] );
		// After VPConn_Pairing has created the VozCaster menu.
		add_action( 'admin_menu', [ $this, 'add_admin_pages' ], 20 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_style' ] );
	}

	// -------------------------------------------------------------------------
	// wp-admin pages: the same screens as the blocks
	// -------------------------------------------------------------------------

	public function add_admin_pages(): void {
		$user_id = get_current_user_id();
		if ( ! VPConn_Access::reviewable_feeds( $user_id ) ) {
			return;
		}
		$waiting = count( VPConn_Review::pending_for( $user_id ) );
		$badge   = $waiting ? ' <span class="awaiting-mod">' . esc_html( number_format_i18n( $waiting ) ) . '</span>' : '';
		add_submenu_page(
			VPConn_Pairing::PAGE_SLUG,
			__( 'VozCaster — Review', 'connector-for-vozcaster' ),
			__( 'Review', 'connector-for-vozcaster' ) . $badge,
			'read',
			VPConn_Review::PAGE_SLUG,
			static function (): void {
				self::admin_page( __( 'VozCaster — Review', 'connector-for-vozcaster' ), VPConn_Review::render( admin_url( 'admin.php?page=' . VPConn_Review::PAGE_SLUG ) ) );
			}
		);
		if ( VPConn_Classes::manageable_feeds( $user_id ) ) {
			add_submenu_page(
				VPConn_Pairing::PAGE_SLUG,
				__( 'VozCaster — Classes', 'connector-for-vozcaster' ),
				__( 'Classes', 'connector-for-vozcaster' ),
				'read',
				'vozcaster-classes',
				static function (): void {
					self::admin_page( __( 'VozCaster — Classes', 'connector-for-vozcaster' ), VPConn_Classes::render( admin_url( 'admin.php?page=vozcaster-classes' ) ) );
				}
			);
		}
		if ( VPConn_Access::is_manager( $user_id ) && ! current_user_can( 'manage_options' ) ) {
			// Administrators have it under Access, with the rest of the settings.
			add_submenu_page(
				VPConn_Pairing::PAGE_SLUG,
				__( 'VozCaster — Access', 'connector-for-vozcaster' ),
				__( 'Access', 'connector-for-vozcaster' ),
				'read',
				'vozcaster-manage-access',
				static function (): void {
					self::admin_page( __( 'VozCaster — Access', 'connector-for-vozcaster' ), VPConn_Access::render( admin_url( 'admin.php?page=vozcaster-manage-access' ) ) );
				}
			);
		}
	}

	private static function admin_page( string $title, string $html ): void {
		echo '<div class="wrap vpconn-admin"><h1>' . esc_html( $title ) . '</h1>' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the render functions.
	}

	public function enqueue_admin_style( string $hook ): void {
		if ( VPConn_Settings::is_plugin_screen( $hook ) ) {
			wp_enqueue_style( self::STYLE );
		}
	}

	// -------------------------------------------------------------------------
	// Blocks
	// -------------------------------------------------------------------------

	public function register_blocks(): void {
		$asset = require VPCONN_DIR . 'blocks/index.asset.php';
		wp_register_script( self::SCRIPT, VPCONN_URL . 'blocks/index.js', $asset['dependencies'], VPCONN_VERSION, true );
		wp_set_script_translations( self::SCRIPT, 'connector-for-vozcaster', VPCONN_DIR . 'languages' );
		wp_register_style( self::STYLE, VPCONN_URL . 'blocks/style.css', [], VPCONN_VERSION );

		$feeds = array_map(
			static fn( $f ) => [
				'slug' => $f['slug'],
				'name' => $f['name'],
			],
			VPConn_Settings::get_powerpress_feeds()
		);
		wp_add_inline_script( self::SCRIPT, 'window.vpconnBlocks = ' . wp_json_encode( [ 'feeds' => $feeds ] ) . ';', 'before' );

		foreach ( [ 'connect', 'classes', 'review', 'access' ] as $block ) {
			register_block_type( VPCONN_DIR . 'blocks/' . $block );
		}
	}

	/**
	 * Wraps a block's HTML, or tells a visitor to log in first.
	 *
	 * @param callable():string $render Called only for logged-in users.
	 */
	public static function block( string $class, callable $render ): string {
		wp_enqueue_style( self::STYLE );
		$titles = [
			'classes' => __( 'My classes', 'connector-for-vozcaster' ),
			'review'  => __( 'Waiting for review', 'connector-for-vozcaster' ),
			'access'  => __( 'Who publishes in each podcast', 'connector-for-vozcaster' ),
		];
		if ( ! is_user_logged_in() ) {
			$inner = sprintf(
				'<p>%1$s</p><p><a class="wp-element-button" href="%2$s">%3$s</a></p>',
				esc_html__( 'Log in with your account on this site to use VozCaster.', 'connector-for-vozcaster' ),
				esc_url( wp_login_url( self::current_url() ) ),
				esc_html__( 'Log in', 'connector-for-vozcaster' )
			);
		} else {
			$inner = $render();
		}
		// The heading is part of the block, so it disappears with it for
		// users who cannot use the block.
		$heading = isset( $titles[ $class ] ) ? '<h2 class="vpconn-block-title">' . esc_html( $titles[ $class ] ) . '</h2>' : '';
		return sprintf(
			'<div %1$s>%2$s%3$s</div>',
			get_block_wrapper_attributes( [ 'class' => 'vpconn-block vpconn-' . $class ] ),
			$heading,
			$inner
		);
	}

	/** URL of the page being rendered, for forms to come back to. */
	public static function current_url(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return remove_query_arg( self::NOTICE_PARAM, home_url( $uri ) );
	}

	// -------------------------------------------------------------------------
	// Form round trip
	// -------------------------------------------------------------------------

	/** Where a form goes back to: the page it was sent from, on this site. */
	public static function redirect_target(): string {
		// Only called from form handlers, after their nonce check.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$to = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '';
		return wp_validate_redirect( $to, admin_url() );
	}

	/** Goes back to the form's page with a notice and stops. */
	public static function redirect( string $url, string $notice ): void {
		wp_safe_redirect( add_query_arg( self::NOTICE_PARAM, $notice, $url ) );
		exit;
	}

	/** Notice for the last action, read from the URL. */
	public static function notice(): string {
		// Display only: the value just selects one of the fixed messages below.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key      = isset( $_GET[ self::NOTICE_PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE_PARAM ] ) ) : '';
		$messages = [
			'class_created'    => [ 'success', __( 'Class created.', 'connector-for-vozcaster' ) ],
			'class_saved'      => [ 'success', __( 'Class saved.', 'connector-for-vozcaster' ) ],
			'class_error'      => [ 'error', __( 'The class could not be created. Check that its name is not already used in this podcast.', 'connector-for-vozcaster' ) ],
			'approved'         => [ 'success', __( 'Episode published.', 'connector-for-vozcaster' ) ],
			'scheduled'        => [ 'success', __( 'Episode approved: it will be published on its scheduled date.', 'connector-for-vozcaster' ) ],
			'returned'         => [ 'success', __( 'Episode returned to its author.', 'connector-for-vozcaster' ) ],
			'access_saved'     => [ 'success', __( 'Access settings saved.', 'connector-for-vozcaster' ) ],
			'review_gone'      => [ 'warning', __( 'That episode is no longer waiting for review.', 'connector-for-vozcaster' ) ],
			'forbidden'        => [ 'error', __( 'You are not allowed to do that.', 'connector-for-vozcaster' ) ],
		];
		if ( ! isset( $messages[ $key ] ) ) {
			return '';
		}
		[ $type, $text ] = $messages[ $key ];
		return sprintf(
			'<div class="notice notice-%1$s inline vpconn-notice vpconn-notice-%1$s"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $text )
		);
	}
}
