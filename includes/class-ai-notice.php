<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI content notice and optional VozCaster credit, rendered under bot posts.
 *
 * The notice is not stored in post_content: the bot flags each post it
 * creates, and this class appends the notice when the post is displayed.
 * Changing the text or turning it off therefore applies to every post at once,
 * and a single post can opt out from the editor.
 *
 * - AI notice: on by default, only on posts whose text was written by AI.
 * - Credit link ("Published with VozCaster"): opt-in, off by default, on any
 *   post published from the bot.
 */
class VPConn_AI_Notice {

	const META_AI_GENERATED = '_vpconn_ai_generated';
	const META_VIA_BOT      = '_vpconn_via_bot';
	const META_HIDE_NOTICE  = '_vpconn_hide_ai_notice';

	const OPTION_ENABLED  = 'vpconn_ai_notice_enabled';
	const OPTION_TEXT     = 'vpconn_ai_notice_text';
	const OPTION_POSITION = 'vpconn_ai_notice_position';
	const OPTION_CREDIT   = 'vpconn_credit_enabled';

	const NONCE_ACTION = 'vpconn_ai_notice_meta';

	public function register_hooks(): void {
		add_filter( 'the_content', [ $this, 'filter_content' ], 20 );
		add_action( 'add_meta_boxes_post', [ $this, 'add_meta_box' ] );
		add_action( 'save_post_post', [ $this, 'save_meta_box' ] );
	}

	// -------------------------------------------------------------------------
	// Settings
	// -------------------------------------------------------------------------

	public static function is_enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLED, 1 );
	}

	public static function is_credit_enabled(): bool {
		return (bool) get_option( self::OPTION_CREDIT, 0 );
	}

	/** @return string 'after' or 'before'. */
	public static function get_position(): string {
		return 'before' === get_option( self::OPTION_POSITION, 'after' ) ? 'before' : 'after';
	}

	/** Custom notice text set by the administrator ('' = use the default). */
	public static function get_custom_text(): string {
		return (string) get_option( self::OPTION_TEXT, '' );
	}

	public static function get_default_text(): string {
		return __( 'The content of this post was generated automatically with artificial intelligence from the audio transcript. It may contain errors or inaccuracies.', 'connector-for-vozcaster' );
	}

	public static function save_settings( bool $enabled, string $text, string $position, bool $credit ): void {
		update_option( self::OPTION_ENABLED, $enabled ? 1 : 0 );
		update_option( self::OPTION_TEXT, wp_kses_post( trim( $text ) ) );
		update_option( self::OPTION_POSITION, 'before' === $position ? 'before' : 'after' );
		update_option( self::OPTION_CREDIT, $credit ? 1 : 0 );
	}

	// -------------------------------------------------------------------------
	// Post flags (set by the REST endpoints that create posts)
	// -------------------------------------------------------------------------

	public static function mark_post( int $post_id, bool $ai_generated ): void {
		update_post_meta( $post_id, self::META_VIA_BOT, 1 );
		if ( $ai_generated ) {
			update_post_meta( $post_id, self::META_AI_GENERATED, 1 );
		}
	}

	// -------------------------------------------------------------------------
	// Front-end rendering
	// -------------------------------------------------------------------------

	public function filter_content( $content ) {
		if ( is_admin() || doing_filter( 'get_the_excerpt' ) ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post || 'post' !== $post->post_type || ! get_post_meta( $post->ID, self::META_VIA_BOT, true ) ) {
			return $content;
		}

		$parts = [];

		if (
			self::is_enabled()
			&& get_post_meta( $post->ID, self::META_AI_GENERATED, true )
			&& ! get_post_meta( $post->ID, self::META_HIDE_NOTICE, true )
		) {
			$text    = self::get_custom_text();
			$text    = '' !== $text ? $text : esc_html( self::get_default_text() );
			$parts[] = '<p class="ai-disclaimer" style="font-size:0.85em">🤖 <em>' . wp_kses_post( $text ) . '</em></p>';
		}

		if ( self::is_credit_enabled() ) {
			$parts[] = '<p class="vozcaster-credit" style="font-size:0.8em">🎙️ ' . self::credit_html() . '</p>';
		}

		if ( empty( $parts ) ) {
			return $content;
		}

		$separator = '<hr class="wp-block-separator has-alpha-channel-opacity is-style-wide vpconn-separator"/>';
		$block     = '<div class="vpconn-ai-notice">' . implode( '', $parts ) . '</div>';

		return 'before' === self::get_position()
			? $block . $separator . $content
			: $content . $separator . $block;
	}

	private static function credit_html(): string {
		$web = str_starts_with( get_locale(), 'es' ) ? 'https://vozcaster.com' : 'https://vozcaster.com/en/';
		$bot = 'https://t.me/' . ltrim( (string) get_option( 'vpconn_bot_username', 'VozCasterBot' ), '@' );

		return sprintf(
			/* translators: 1: link to the VozCaster website, 2: link to the Telegram bot. */
			esc_html__( 'Published with %1$s, the Telegram bot that turns your voice into a published podcast episode. %2$s.', 'connector-for-vozcaster' ),
			'<a href="' . esc_url( $web ) . '">VozCaster</a>',
			'<a href="' . esc_url( $bot ) . '">' . esc_html__( 'Try it free', 'connector-for-vozcaster' ) . '</a>'
		);
	}

	// -------------------------------------------------------------------------
	// Per-post opt-out in the editor
	// -------------------------------------------------------------------------

	public function add_meta_box( WP_Post $post ): void {
		if ( ! get_post_meta( $post->ID, self::META_AI_GENERATED, true ) ) {
			return;
		}
		add_meta_box(
			'vpconn-ai-notice',
			__( 'VozCaster', 'connector-for-vozcaster' ),
			[ $this, 'render_meta_box' ],
			'post',
			'side',
			'low'
		);
	}

	public function render_meta_box( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, 'vpconn_ai_notice_nonce' );
		$hidden = (bool) get_post_meta( $post->ID, self::META_HIDE_NOTICE, true );
		?>
		<p><?php esc_html_e( 'This post was written with AI from the audio of the episode.', 'connector-for-vozcaster' ); ?></p>
		<label>
			<input type="checkbox" name="vpconn_hide_ai_notice" value="1" <?php checked( $hidden ); ?>>
			<?php esc_html_e( 'Hide the AI notice on this post', 'connector-for-vozcaster' ); ?>
		</label>
		<?php if ( ! self::is_enabled() ) : ?>
			<p class="description"><?php esc_html_e( 'The AI notice is turned off for the whole site in VozCaster → Episodes.', 'connector-for-vozcaster' ); ?></p>
		<?php endif; ?>
		<?php
	}

	public function save_meta_box( int $post_id ): void {
		if ( ! isset( $_POST['vpconn_ai_notice_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['vpconn_ai_notice_nonce'] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! empty( $_POST['vpconn_hide_ai_notice'] ) ) {
			update_post_meta( $post_id, self::META_HIDE_NOTICE, 1 );
		} else {
			delete_post_meta( $post_id, self::META_HIDE_NOTICE );
		}
	}
}
