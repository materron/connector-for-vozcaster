<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * VozCaster → Assistants → "Guided setup": every setting, one step at a time.
 *
 * It owns no form of its own. Each step prints the same section as the regular
 * settings page (VPConn_Settings::section_*, VPConn_Access::render,
 * VPConn_Classes::render, VPConn_Assistant::render_podcast_form…) and those
 * forms are saved by the same handlers; the only difference is where they go
 * back to afterwards (see return_url()). Every step explains in plain words
 * what it is for and what happens if it is left alone.
 */
class VPConn_Guided_Setup {

	const ASSISTANT    = 'guided-setup';
	const META_STEP    = 'vpconn_gs_guided_step';
	const META_VISITED = 'vpconn_gs_guided_visited';

	// -------------------------------------------------------------------------
	// Steps
	// -------------------------------------------------------------------------

	/**
	 * Steps in order, keyed by id. 'footer' says what the shell prints under the
	 * step: self (the step has its own buttons), none (only Back), continue, keep or finish.
	 *
	 * @return array<string, array{chapter: string, title: string, intro: string, footer: string}>
	 */
	private static function all_steps(): array {
		return [
			'podcast'     => [
				'chapter' => __( 'Your podcast', 'connector-for-vozcaster' ),
				'title'   => __( 'Your podcast', 'connector-for-vozcaster' ),
				'intro'   => __( 'This is the identity of your podcast: the name, description, cover and category that Apple Podcasts, Spotify and the other apps show. Without them your feed is incomplete and the platforms may reject it. The fields at the bottom (copyright, type and website) are optional.', 'connector-for-vozcaster' ),
				'footer'  => 'self',
			],
			'titles'      => [
				'chapter' => __( 'Episodes', 'connector-for-vozcaster' ),
				'title'   => __( 'Episode titles', 'connector-for-vozcaster' ),
				'intro'   => __( 'Here you choose how the title of each episode is built: a prefix such as «Episode», the season and how episodes are counted. If you leave it as it is, episodes get the title the AI writes, without a prefix or a number.', 'connector-for-vozcaster' ),
				'footer'  => 'none',
			],
			'writing'     => [
				'chapter' => __( 'Episodes', 'connector-for-vozcaster' ),
				'title'   => __( 'How your podcast writes', 'connector-for-vozcaster' ),
				'intro'   => __( 'VozCaster can write the title, excerpt, post and chapters of each episode with AI. A style guide tells it how your podcast sounds: serious, funny, formal, relaxed. If you leave it empty, the AI copies the tone it hears in your recording.', 'connector-for-vozcaster' ),
				'footer'  => 'none',
			],
			'footer'      => [
				'chapter' => __( 'Episodes', 'connector-for-vozcaster' ),
				'title'   => __( 'Post footer (signature)', 'connector-for-vozcaster' ),
				'intro'   => __( 'A text that VozCaster adds at the end of every post it publishes: who you are, how to contact you, where to subscribe. If you leave it empty, nothing is added.', 'connector-for-vozcaster' ),
				'footer'  => 'none',
			],
			'image'       => [
				'chapter' => __( 'Episodes', 'connector-for-vozcaster' ),
				'title'   => __( 'Cover images', 'connector-for-vozcaster' ),
				'intro'   => __( 'Episode covers can be drawn with AI. You can ask for a recurring element in all of them (an orange cat with headphones, say) and give a reference image whose style the covers copy. By default each cover is inspired only by the text of the episode.', 'connector-for-vozcaster' ),
				'footer'  => 'none',
			],
			'ai-notice'   => [
				'chapter' => __( 'Episodes', 'connector-for-vozcaster' ),
				'title'   => __( 'AI content notice', 'connector-for-vozcaster' ),
				'intro'   => __( 'When a post is written with AI, a short notice says so, which your readers will appreciate. It is on by default. You can change its wording and position, and turn on a small «Published with VozCaster» credit.', 'connector-for-vozcaster' ),
				'footer'  => 'none',
			],
			'audio-files' => [
				'chapter' => __( 'Audio', 'connector-for-vozcaster' ),
				'title'   => __( 'Intro, outro and separator', 'connector-for-vozcaster' ),
				'intro'   => __( 'The intro and the outro are short pieces of music that open and close every episode. The separator is a short sound used between the parts of an episode recorded in several audios. Without them, your episodes have just your voice and several audios are joined with a one-second pause.', 'connector-for-vozcaster' ),
				'footer'  => 'continue',
			],
			'mix'         => [
				'chapter' => __( 'Audio', 'connector-for-vozcaster' ),
				'title'   => __( 'Music mix', 'connector-for-vozcaster' ),
				'intro'   => __( 'How the music blends with your voice: when the intro starts to fade, how loud it stays under your voice and when the outro comes in. The defaults suit most music and you can change them later. It only matters if you uploaded an intro or an outro.', 'connector-for-vozcaster' ),
				'footer'  => 'none',
			],
			'access'      => [
				'chapter' => __( 'Who can publish', 'connector-for-vozcaster' ),
				'title'   => __( 'Who can publish', 'connector-for-vozcaster' ),
				'intro'   => __( 'Choose who can publish in each podcast of this site, and who only sends episodes for review before they go out. By default every role that can publish posts (administrators, editors, authors) publishes directly. If you work alone, you can keep it as it is.', 'connector-for-vozcaster' ),
				'footer'  => 'keep',
			],
			'groups'      => [
				'chapter' => __( 'Groups and reviewers', 'connector-for-vozcaster' ),
				'title'   => __( 'Groups and reviewers', 'connector-for-vozcaster' ),
				'intro'   => __( 'A podcast can have groups, each one with its own reviewers: the sections of a team or the members of an association, for example. What a member sends for review reaches the reviewers of their group, and each group gets its own page and feed. If you do not need groups, skip this step; you can add them later.', 'connector-for-vozcaster' ),
				'footer'  => 'continue',
			],
			'connect'     => [
				'chapter' => __( 'Connect', 'connector-for-vozcaster' ),
				'title'   => __( 'Connect', 'connector-for-vozcaster' ),
				'intro'   => __( 'Link your account with VozCaster so you can send voice notes from Telegram or from the web app. It takes one click and you can connect again whenever you want.', 'connector-for-vozcaster' ),
				'footer'  => 'self',
			],
			'platforms'   => [
				'chapter' => __( 'Send to platforms', 'connector-for-vozcaster' ),
				'title'   => __( 'Send to platforms', 'connector-for-vozcaster' ),
				'intro'   => __( 'When your podcast has at least one published episode, you can send its feed to Apple Podcasts, Spotify and the rest. You do it once per platform; after that they pick up every new episode by themselves.', 'connector-for-vozcaster' ),
				'footer'  => 'finish',
			],
		];
	}

	/** Steps this user may see: administrators all of them, anyone else only the connection. */
	private static function steps(): array {
		$all = self::all_steps();
		return current_user_can( 'manage_options' ) ? $all : array_intersect_key( $all, [ 'connect' => true ] );
	}

	public static function step_url( string $id ): string {
		return add_query_arg(
			[
				'page'      => VPConn_Assistant::PAGE_SLUG,
				'assistant' => self::ASSISTANT,
				'step'      => $id,
			],
			admin_url( 'admin.php' )
		);
	}

	private static function current_step_id(): string {
		$steps = self::steps();
		// Read-only navigation on an admin screen.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';
		if ( ! isset( $steps[ $id ] ) ) {
			$id = (string) array_key_first( $steps );
		}
		return $id;
	}

	/**
	 * Where a form of this assistant goes back to after saving: the next step
	 * when it asked for it (the "Save and continue" forms), otherwise the same
	 * step (uploads, deletions, class changes).
	 */
	public static function return_url(): string {
		$here = self::step_url( self::current_step_id() );
		// Called after the nonce check of the form being handled.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$next = isset( $_POST['vpconn_next'] ) ? esc_url_raw( wp_unslash( $_POST['vpconn_next'] ) ) : '';
		return '' !== $next ? wp_validate_redirect( $next, $here ) : $here;
	}

	public static function has_started( int $user_id ): bool {
		return '' !== (string) get_user_meta( $user_id, self::META_STEP, true );
	}

	public static function resume_url( int $user_id ): string {
		$id    = (string) get_user_meta( $user_id, self::META_STEP, true );
		$steps = self::steps();
		return self::step_url( isset( $steps[ $id ] ) ? $id : (string) array_key_first( $steps ) );
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	public static function enqueue(): void {
		$id = self::current_step_id();
		if ( 'podcast' === $id ) {
			wp_enqueue_media();
			VPConn_Assistant::enqueue_script( 2 );
		} elseif ( 'connect' === $id ) {
			VPConn_Assistant::enqueue_script( 3 );
		} elseif ( in_array( $id, [ 'image', 'audio-files' ], true ) ) {
			VPConn_Settings::enqueue_picker();
		}
	}

	// -------------------------------------------------------------------------
	// Rendering
	// -------------------------------------------------------------------------

	public static function render( int $user_id ): void {
		$steps   = self::steps();
		$ids     = array_keys( $steps );
		$id      = self::current_step_id();
		$pos     = (int) array_search( $id, $ids, true );
		$step    = $steps[ $id ];
		$next    = isset( $ids[ $pos + 1 ] ) ? self::step_url( $ids[ $pos + 1 ] ) : VPConn_Assistant::get_page_url();
		$prev    = $pos > 0 ? self::step_url( $ids[ $pos - 1 ] ) : '';
		$here    = self::step_url( $id );
		$ctx     = [ 'next' => $next ];

		$visited = array_values( array_unique( array_merge( (array) get_user_meta( $user_id, self::META_VISITED, true ), [ $id ] ) ) );
		update_user_meta( $user_id, self::META_STEP, $id );
		update_user_meta( $user_id, self::META_VISITED, $visited );

		$flash = VPConn_Assistant::take_flash();
		?>
		<h1><?php esc_html_e( 'Guided setup', 'connector-for-vozcaster' ); ?></h1>
		<div class="vpconn-gs-progress">
			<span>
				<?php
				/* translators: 1: current step number, 2: total steps. */
				echo esc_html( sprintf( __( 'Step %1$d of %2$d', 'connector-for-vozcaster' ), $pos + 1, count( $ids ) ) );
				?>
			</span>
			<span class="vpconn-gs-bar" aria-hidden="true"><i style="width:<?php echo esc_attr( (string) round( 100 * ( $pos + 1 ) / count( $ids ) ) ); ?>%"></i></span>
			<a class="vpconn-gs-skip" href="<?php echo esc_url( VPConn_Assistant::get_page_url() ); ?>"><?php esc_html_e( 'Back to Assistants', 'connector-for-vozcaster' ); ?></a>
		</div>

		<?php self::render_index( $steps, $id, $visited ); ?>

		<?php
		if ( ! empty( $flash['message'] ) ) {
			printf(
				'<div class="notice notice-%1$s inline"><p>%2$s</p></div>',
				esc_attr( 'error' === ( $flash['type'] ?? '' ) ? 'error' : 'success' ),
				esc_html( (string) $flash['message'] )
			);
		}
		?>
		<div class="vpconn-gs-panel">
			<h2><?php echo esc_html( $step['title'] ); ?></h2>
			<p class="vpconn-gs-intro"><?php echo esc_html( $step['intro'] ); ?></p>
			<?php
			match ( $id ) {
				'podcast'     => VPConn_Assistant::render_podcast_form(
					(array) ( $flash['values'] ?? [] ),
					[
						'next'   => $next,
						'back'   => $prev,
						'return' => $here,
						'full'   => true,
					]
				),
				'titles'      => VPConn_Settings::section_titles( $ctx ),
				'writing'     => VPConn_Settings::section_writing( $ctx ),
				'footer'      => VPConn_Settings::section_footer( $ctx ),
				'image'       => VPConn_Settings::section_image_style( $ctx ),
				'ai-notice'   => VPConn_Settings::section_ai_notice( $ctx ),
				'audio-files' => VPConn_Settings::section_audio_files( $ctx ),
				'mix'         => VPConn_Settings::section_mix( $ctx ),
				'access'      => VPConn_Settings::section_access( $ctx ),
				'groups'      => self::render_groups( $here ),
				'connect'     => VPConn_Assistant::render_step_connect( $user_id, $next, $prev ),
				default       => VPConn_Assistant::render_platforms_block( $here ),
			};
			self::render_footer( $step['footer'], $next, $prev );
			?>
		</div>
		<?php
	}

	/** Index of every step, grouped by chapter, with the visited ones ticked. */
	private static function render_index( array $steps, string $current, array $visited ): void {
		$chapters = [];
		foreach ( $steps as $id => $step ) {
			$chapters[ $step['chapter'] ][ $id ] = $step['title'];
		}
		?>
		<details class="vpconn-gs-index" <?php echo count( $steps ) > 1 ? 'open' : 'hidden'; ?>>
			<summary><?php esc_html_e( 'All steps', 'connector-for-vozcaster' ); ?></summary>
			<ol>
				<?php foreach ( $chapters as $chapter => $items ) : ?>
					<li>
						<?php if ( 1 < count( $items ) ) : ?>
							<span class="vpconn-gs-chapter"><?php echo esc_html( (string) $chapter ); ?></span>
						<?php endif; ?>
						<ul>
							<?php foreach ( $items as $id => $title ) : ?>
								<li class="<?php echo $id === $current ? 'is-current' : ''; ?>">
									<a href="<?php echo esc_url( self::step_url( (string) $id ) ); ?>" <?php echo $id === $current ? 'aria-current="step"' : ''; ?>>
										<?php echo in_array( $id, $visited, true ) ? '<span aria-hidden="true">✓</span> ' : ''; ?>
										<?php echo esc_html( $title ); ?>
										<?php if ( in_array( $id, $visited, true ) ) : ?>
											<span class="screen-reader-text"><?php esc_html_e( '(visited)', 'connector-for-vozcaster' ); ?></span>
										<?php endif; ?>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					</li>
				<?php endforeach; ?>
			</ol>
		</details>
		<?php
	}

	private static function render_groups( string $here ): void {
		echo VPConn_Classes::render( $here ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside.
	}

	private static function render_footer( string $kind, string $next, string $prev ): void {
		if ( 'self' === $kind ) {
			return; // The step prints all its own buttons.
		}
		if ( 'none' === $kind ) {
			// A settings form with its own save buttons: only "Back" is left.
			if ( '' !== $prev ) {
				echo '<p class="vpconn-gs-nav"><a href="' . esc_url( $prev ) . '">' . esc_html__( 'Back', 'connector-for-vozcaster' ) . '</a></p>';
			}
			return;
		}
		echo '<p class="vpconn-gs-actions">';
		if ( 'continue' === $kind ) {
			echo '<a class="button button-primary" href="' . esc_url( $next ) . '">' . esc_html__( 'Continue', 'connector-for-vozcaster' ) . '</a> ';
		} elseif ( 'keep' === $kind ) {
			echo '<a class="button" href="' . esc_url( $next ) . '">' . esc_html__( 'Keep as is', 'connector-for-vozcaster' ) . '</a> ';
		} else {
			echo '<a class="button button-primary" href="' . esc_url( VPConn_Assistant::get_page_url() ) . '">' . esc_html__( 'Finish', 'connector-for-vozcaster' ) . '</a> ';
		}
		if ( '' !== $prev ) {
			echo '<a href="' . esc_url( $prev ) . '">' . esc_html__( 'Back', 'connector-for-vozcaster' ) . '</a>';
		}
		echo '</p>';
	}
}
