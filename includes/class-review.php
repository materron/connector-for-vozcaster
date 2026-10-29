<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Review before publishing.
 *
 * What a user sends to a podcast where their access is "review" (see
 * VPConn_Access) is saved as "Pending review". It reaches the reviewers of
 * its class, or everyone who publishes directly in the podcast when the class
 * has none, by email and, through VozCaster, in the app and Telegram. They
 * listen to it and publish it or return it to its author with a comment,
 * from VozCaster → Review, the "VozCaster review" block or VozCaster itself.
 *
 * Before the access table is saved, the old single switch applies: with it
 * on, roles that cannot publish posts in WordPress are held for review and
 * the site administrator gets the email.
 */
class VPConn_Review {

	const OPTION          = 'vpconn_review_mode';
	const META_FEED       = 'vpconn_review_feed';
	const META_CLASS      = 'vpconn_review_class';
	const META_REQUESTED  = 'vpconn_review_requested';
	const META_COMMENT    = 'vpconn_review_comment';
	const META_REVIEWED   = 'vpconn_reviewed_by';
	const META_SENT       = 'vpconn_review_sent';
	const ACTION          = 'vpconn_review';
	const PAGE_SLUG       = 'vozcaster-review';

	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_action' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public static function is_enabled(): bool {
		return (bool) get_option( self::OPTION, 0 );
	}

	public static function set_enabled( bool $enabled ): void {
		update_option( self::OPTION, $enabled ? 1 : 0 );
	}

	/**
	 * Status the post is really saved with: "publish" and "future" become
	 * "pending" when the user's access to the podcast is "review".
	 */
	public static function effective_status( string $requested, string $feed = 'podcast' ): string {
		if ( in_array( $requested, [ 'publish', 'future' ], true )
			&& VPConn_Access::REVIEW === VPConn_Access::user_level( get_current_user_id(), $feed ) ) {
			return 'pending';
		}
		return $requested;
	}

	// -------------------------------------------------------------------------
	// Pending episodes
	// -------------------------------------------------------------------------

	/** Records what a held episode is waiting for, then tells its reviewers. */
	public static function hold( int $post_id, string $feed, int $class_id, string $requested ): void {
		update_post_meta( $post_id, self::META_FEED, $feed );
		update_post_meta( $post_id, self::META_CLASS, $class_id );
		update_post_meta( $post_id, self::META_REQUESTED, $requested );
		update_post_meta( $post_id, self::META_SENT, time() );
		self::notify( $post_id );
	}

	/**
	 * Who reviews a pending episode: the reviewers of its class, or else
	 * everyone who publishes directly in its podcast. Never its own author.
	 *
	 * @return int[]
	 */
	public static function reviewers_for( int $post_id ): array {
		$post = get_post( $post_id );
		$feed = (string) get_post_meta( $post_id, self::META_FEED, true );
		if ( ! $post || '' === $feed ) {
			return [];
		}
		$class_id = (int) get_post_meta( $post_id, self::META_CLASS, true );
		$ids      = $class_id ? VPConn_Classes::get_reviewers( $class_id ) : [];
		if ( ! $ids ) {
			$ids = VPConn_Access::direct_publishers( $feed );
		}
		return array_values( array_diff( $ids, [ (int) $post->post_author ] ) );
	}

	/** May this user publish or return this pending episode? */
	public static function can_review( int $user_id, int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post || 'pending' !== $post->post_status ) {
			return false;
		}
		$feed = (string) get_post_meta( $post_id, self::META_FEED, true );
		if ( '' === $feed ) {
			// Held by a version before 1.15.0: only administrators.
			return user_can( $user_id, 'manage_options' );
		}
		return VPConn_Access::DIRECT === VPConn_Access::user_level( $user_id, $feed );
	}

	/**
	 * Pending episodes this user may review: those of their classes first.
	 *
	 * @return WP_Post[]
	 */
	public static function pending_for( int $user_id ): array {
		$feeds = wp_list_pluck( VPConn_Access::reviewable_feeds( $user_id ), 'slug' );
		if ( ! $feeds ) {
			return [];
		}
		$posts = get_posts(
			[
				'post_type'      => 'post',
				'post_status'    => 'pending',
				'posts_per_page' => 100,
				'orderby'        => 'date',
				'order'          => 'ASC',
				// Only episodes held by VozCaster, a short list.
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => [
					[
						'key'     => self::META_FEED,
						'value'   => $feeds,
						'compare' => 'IN',
					],
				],
			]
		);
		$posts = array_values( array_filter( $posts, static fn( $p ) => (int) $p->post_author !== $user_id ) );
		usort(
			$posts,
			static fn( $a, $b ) => (int) ! in_array( $user_id, self::reviewers_for( $a->ID ), true )
				<=> (int) ! in_array( $user_id, self::reviewers_for( $b->ID ), true )
		);
		return $posts;
	}

	/**
	 * Publishes a pending episode (or schedules it, if it was sent scheduled
	 * and the date has not passed).
	 *
	 * @return array|WP_Error What the API returns about the episode.
	 */
	public static function approve( int $post_id, int $reviewer_id ): array|WP_Error {
		if ( ! self::can_review( $reviewer_id, $post_id ) ) {
			return new WP_Error( 'vpconn_review_forbidden', __( 'You cannot review this episode.', 'connector-for-vozcaster' ), [ 'status' => 403 ] );
		}
		$post      = get_post( $post_id );
		// Pending posts have no GMT date in WordPress: read the local one.
		$scheduled = 'future' === get_post_meta( $post_id, self::META_REQUESTED, true ) && (int) get_post_timestamp( $post ) > time();
		$update    = [ 'ID' => $post_id ];
		if ( $scheduled ) {
			$update['post_status'] = 'future';
		} else {
			// Published now, so it shows up as the newest episode.
			$update['post_status']   = 'publish';
			$update['post_date']     = current_time( 'mysql' );
			$update['post_date_gmt'] = current_time( 'mysql', true );
			$update['edit_date']     = true;
		}
		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		update_post_meta( $post_id, self::META_REVIEWED, $reviewer_id );
		delete_post_meta( $post_id, self::META_COMMENT );
		self::email_author( $post_id, '' );
		return self::describe( get_post( $post_id ) );
	}

	/**
	 * Returns a pending episode to its author as a draft, with a comment.
	 *
	 * @return array|WP_Error
	 */
	public static function send_back( int $post_id, int $reviewer_id, string $comment ): array|WP_Error {
		if ( ! self::can_review( $reviewer_id, $post_id ) ) {
			return new WP_Error( 'vpconn_review_forbidden', __( 'You cannot review this episode.', 'connector-for-vozcaster' ), [ 'status' => 403 ] );
		}
		$result = wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'draft',
			],
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		update_post_meta( $post_id, self::META_REVIEWED, $reviewer_id );
		update_post_meta( $post_id, self::META_COMMENT, $comment );
		self::email_author( $post_id, $comment );
		return self::describe( get_post( $post_id ) );
	}

	/** An episode as the API and VozCaster see it. */
	public static function describe( WP_Post $post ): array {
		$feed_slug = (string) get_post_meta( $post->ID, self::META_FEED, true );
		$feed      = '' !== $feed_slug ? VPConn_Access::find_feed( $feed_slug ) : null;
		$class_id  = (int) get_post_meta( $post->ID, self::META_CLASS, true );
		$class     = $class_id ? get_term( $class_id, 'category' ) : null;
		$author    = get_userdata( (int) $post->post_author );
		$reviewer  = get_userdata( (int) get_post_meta( $post->ID, self::META_REVIEWED, true ) );
		return [
			'id'              => $post->ID,
			'title'           => get_the_title( $post ),
			'excerpt'         => wp_strip_all_tags( (string) $post->post_excerpt ),
			'status'          => $post->post_status,
			'url'             => 'publish' === $post->post_status ? get_permalink( $post ) : '',
			'audio_url'       => self::audio_url( $post->ID ),
			'feed_slug'       => $feed_slug,
			'feed_name'       => $feed['name'] ?? '',
			'class_id'        => $class_id,
			'class_name'      => $class instanceof WP_Term ? $class->name : '',
			'author_login'    => $author ? $author->user_login : '',
			'author_name'     => $author ? $author->display_name : '',
			'reviewer_name'   => $reviewer ? $reviewer->display_name : '',
			'reviewer_logins' => array_values( array_filter( array_map( static fn( $id ) => get_userdata( $id )->user_login ?? '', self::reviewers_for( $post->ID ) ) ) ),
			'comment'         => (string) get_post_meta( $post->ID, self::META_COMMENT, true ),
			'sent'            => wp_date( 'c', self::sent_at( $post ) ),
			'scheduled_for'   => 'future' === get_post_meta( $post->ID, self::META_REQUESTED, true ) ? wp_date( 'c', (int) get_post_timestamp( $post ) ) : '',
		];
	}

	/**
	 * When the episode was sent. Not its date: a scheduled one carries the
	 * future date (WordPress copies it into the modified date too), and
	 * pending posts have no GMT date at all.
	 */
	public static function sent_at( WP_Post $post ): int {
		return (int) get_post_meta( $post->ID, self::META_SENT, true ) ?: (int) get_post_timestamp( $post, 'modified' );
	}

	/** The episode's audio, from PowerPress's enclosure. */
	public static function audio_url( int $post_id ): string {
		$enclosure = (string) get_post_meta( $post_id, 'enclosure', true );
		$url       = trim( (string) strtok( $enclosure, "\n" ) );
		return esc_url_raw( $url );
	}

	// -------------------------------------------------------------------------
	// Emails
	// -------------------------------------------------------------------------

	/** Tells the reviewers (or, with none, the site administrator) that an episode waits. */
	public static function notify( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		$info    = self::describe( $post );
		$emails  = array_filter( array_map( static fn( $id ) => get_userdata( $id )->user_email ?? '', self::reviewers_for( $post_id ) ) );
		$emails  = $emails ?: [ (string) get_option( 'admin_email' ) ];
		$where   = $info['class_name'] ? $info['feed_name'] . ' → ' . $info['class_name'] : $info['feed_name'];
		$subject = sprintf(
			/* translators: 1: site name, 2: post title. */
			__( '[%1$s] Podcast episode pending review: %2$s', 'connector-for-vozcaster' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			$post->post_title
		);
		$body = sprintf(
			/* translators: 1: author name, 2: post title, 3: podcast (and class), 4: link to the review page. */
			__( "%1\$s has sent a new episode with VozCaster and it is waiting for your review:\n\n%2\$s\n%3\$s\n\nListen to it and publish it or return it here:\n%4\$s", 'connector-for-vozcaster' ),
			$info['author_name'] ?: __( 'A user', 'connector-for-vozcaster' ),
			$post->post_title,
			$where,
			admin_url( 'admin.php?page=' . self::PAGE_SLUG )
		);
		wp_mail( array_values( array_unique( $emails ) ), $subject, $body );
	}

	private static function email_author( int $post_id, string $comment ): void {
		$post   = get_post( $post_id );
		$author = $post ? get_userdata( (int) $post->post_author ) : false;
		if ( ! $author || ! $author->user_email ) {
			return;
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		if ( '' === $comment && 'draft' !== $post->post_status ) {
			$subject = sprintf(
				/* translators: 1: site name, 2: post title. */
				__( '[%1$s] Your episode has been approved: %2$s', 'connector-for-vozcaster' ),
				$site,
				$post->post_title
			);
			$body = 'publish' === $post->post_status
				? sprintf(
					/* translators: %s: link to the episode. */
					__( "Your episode is published:\n%s", 'connector-for-vozcaster' ),
					get_permalink( $post )
				)
				: __( 'Your episode has been approved and will be published on its scheduled date.', 'connector-for-vozcaster' );
		} else {
			$subject = sprintf(
				/* translators: 1: site name, 2: post title. */
				__( '[%1$s] Your episode needs changes: %2$s', 'connector-for-vozcaster' ),
				$site,
				$post->post_title
			);
			$body = sprintf(
				/* translators: %s: the reviewer's comment. */
				__( "Your episode has not been published yet. The reviewer says:\n\n%s\n\nRecord it again and send it with VozCaster when it is ready.", 'connector-for-vozcaster' ),
				'' !== $comment ? $comment : '—'
			);
		}
		wp_mail( $author->user_email, $subject, $body );
	}

	// -------------------------------------------------------------------------
	// Screen (wp-admin page and front-end block)
	// -------------------------------------------------------------------------

	public static function render( string $redirect_to ): string {
		$user_id = get_current_user_id();
		if ( ! VPConn_Access::reviewable_feeds( $user_id ) ) {
			return '<p>' . esc_html__( 'You do not publish directly in any podcast of this site, so you have nothing to review.', 'connector-for-vozcaster' ) . '</p>';
		}
		$posts = self::pending_for( $user_id );

		ob_start();
		echo VPConn_Frontend::notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside.
		if ( ! $posts ) {
			echo '<p>' . esc_html__( 'Nothing waiting for review. 🎉', 'connector-for-vozcaster' ) . '</p>';
			return (string) ob_get_clean();
		}
		foreach ( $posts as $post ) {
			$info  = self::describe( $post );
			$mine  = in_array( $user_id, self::reviewers_for( $post->ID ), true );
			$where = $info['class_name'] ? $info['feed_name'] . ' → ' . $info['class_name'] : $info['feed_name'];
			?>
			<div class="vpconn-review-item<?php echo $mine ? ' vpconn-mine' : ''; ?>">
				<h3 class="vpconn-review-title"><?php echo esc_html( $info['title'] ); ?></h3>
				<p class="vpconn-muted">
					<?php
					printf(
						/* translators: 1: author name, 2: podcast (and class), 3: how long ago it was sent. */
						esc_html__( 'By %1$s · %2$s · sent %3$s ago', 'connector-for-vozcaster' ),
						esc_html( $info['author_name'] ),
						esc_html( $where ),
						esc_html( human_time_diff( self::sent_at( $post ) ) )
					);
					?>
					<?php if ( $mine ) : ?>
						<span class="vpconn-badge"><?php esc_html_e( 'your class', 'connector-for-vozcaster' ); ?></span>
					<?php endif; ?>
				</p>
				<?php if ( $info['audio_url'] ) : ?>
					<audio controls preload="none" src="<?php echo esc_url( $info['audio_url'] ); ?>" class="vpconn-audio"></audio>
				<?php endif; ?>
				<?php if ( $info['excerpt'] ) : ?>
					<p><?php echo esc_html( $info['excerpt'] ); ?></p>
				<?php endif; ?>
				<div class="vpconn-review-actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php self::hidden_fields( 'approve', $redirect_to, $post->ID ); ?>
						<button type="submit" class="button button-primary wp-element-button">✅ <?php esc_html_e( 'Publish', 'connector-for-vozcaster' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vpconn-return-form">
						<?php self::hidden_fields( 'return', $redirect_to, $post->ID ); ?>
						<label class="screen-reader-text" for="vpconn-comment-<?php echo esc_attr( (string) $post->ID ); ?>"><?php esc_html_e( 'Comment for the author', 'connector-for-vozcaster' ); ?></label>
						<textarea name="comment" id="vpconn-comment-<?php echo esc_attr( (string) $post->ID ); ?>" rows="2" placeholder="<?php esc_attr_e( 'What should they change? (optional)', 'connector-for-vozcaster' ); ?>"></textarea>
						<button type="submit" class="button">↩️ <?php esc_html_e( 'Return to the author', 'connector-for-vozcaster' ); ?></button>
					</form>
					<?php if ( current_user_can( 'edit_post', $post->ID ) ) : ?>
						<a href="<?php echo esc_url( (string) get_edit_post_link( $post->ID ) ); ?>"><?php esc_html_e( 'Edit in WordPress', 'connector-for-vozcaster' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
			<?php
		}
		return (string) ob_get_clean();
	}

	private static function hidden_fields( string $op, string $redirect_to, int $post_id ): void {
		wp_nonce_field( self::ACTION, '_vpconn_nonce' );
		?>
		<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
		<input type="hidden" name="op" value="<?php echo esc_attr( $op ); ?>">
		<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post_id ); ?>">
		<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_to ); ?>">
		<?php
	}

	public function handle_action(): void {
		check_admin_referer( self::ACTION, '_vpconn_nonce' );
		$redirect = VPConn_Frontend::redirect_target();
		$post_id  = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$user_id  = get_current_user_id();
		$op       = sanitize_key( wp_unslash( $_POST['op'] ?? '' ) );

		if ( ! self::can_review( $user_id, $post_id ) ) {
			VPConn_Frontend::redirect( $redirect, 'review_gone' );
		}
		if ( 'approve' === $op ) {
			$result = self::approve( $post_id, $user_id );
			VPConn_Frontend::redirect( $redirect, is_wp_error( $result ) ? 'forbidden' : ( 'future' === $result['status'] ? 'scheduled' : 'approved' ) );
		}
		if ( 'return' === $op ) {
			$result = self::send_back( $post_id, $user_id, sanitize_textarea_field( wp_unslash( $_POST['comment'] ?? '' ) ) );
			VPConn_Frontend::redirect( $redirect, is_wp_error( $result ) ? 'forbidden' : 'returned' );
		}
		VPConn_Frontend::redirect( $redirect, 'forbidden' );
	}

	// -------------------------------------------------------------------------
	// REST: the app and the bot review too
	// -------------------------------------------------------------------------

	public function register_routes(): void {
		$permission = static fn(): bool => get_current_user_id() > 0 && VPConn_Auth::is_user_allowed( get_current_user_id() );
		register_rest_route(
			'vozpress/v1',
			'/review/pending',
			[
				'methods'             => 'GET',
				'callback'            => static function (): WP_REST_Response {
					$items    = array_map( [ self::class, 'describe' ], self::pending_for( get_current_user_id() ) );
					$response = new WP_REST_Response( [ 'episodes' => $items ] );
					$response->header( 'Cache-Control', 'no-store, no-cache' );
					return $response;
				},
				'permission_callback' => $permission,
			]
		);
		register_rest_route(
			'vozpress/v1',
			'/review/(?P<id>\d+)/(?P<op>approve|return)',
			[
				'methods'             => 'POST',
				'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response|WP_Error {
					$post_id = (int) $request['id'];
					$result  = 'approve' === $request['op']
						? self::approve( $post_id, get_current_user_id() )
						: self::send_back( $post_id, get_current_user_id(), sanitize_textarea_field( (string) $request->get_param( 'comment' ) ) );
					return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
				},
				'permission_callback' => $permission,
				'args'                => [
					'comment' => [
						'type'    => 'string',
						'default' => '',
					],
				],
			]
		);
	}
}
