<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classes: groups inside a podcast (e.g. English → B1.1, or "Mila's
 * students"), each one a sub-category of the podcast's category.
 *
 * Episodes sent to a class are published in the podcast as usual (WordPress
 * includes a sub-category's posts in its parent's feed) and also get their
 * own page and feed. Whoever publishes directly in the podcast can create
 * classes and choose who reviews each one, so what a student sends for
 * review reaches the teacher of that class instead of the whole department.
 * Classes are archived at the end of the year, never deleted: their
 * episodes stay where they are.
 */
class VPConn_Classes {

	const META_CLASS     = 'vpconn_class';
	const META_REVIEWERS = 'vpconn_class_reviewers';
	const META_ARCHIVED  = 'vpconn_class_archived';
	const USER_META_LAST = 'vpconn_last_class';
	const ACTION         = 'vpconn_class';

	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_action' ] );
	}

	// -------------------------------------------------------------------------
	// Data
	// -------------------------------------------------------------------------

	/** Category of a podcast, or null when it has none (classes need one). */
	public static function feed_category( array $feed ): ?WP_Term {
		$slug = (string) ( $feed['category_slug'] ?? '' );
		if ( '' === $slug ) {
			return null;
		}
		$term = get_term_by( 'slug', $slug, 'category' );
		return $term instanceof WP_Term ? $term : null;
	}

	/**
	 * Classes of a podcast, by name.
	 *
	 * @return WP_Term[]
	 */
	public static function get_classes( array $feed, bool $include_archived = false ): array {
		$parent = self::feed_category( $feed );
		if ( ! $parent ) {
			return [];
		}
		$terms = get_terms(
			[
				'taxonomy'   => 'category',
				'parent'     => $parent->term_id,
				'hide_empty' => false,
				'orderby'    => 'name',
				// Classes are a handful of sub-categories of one podcast.
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'   => self::META_CLASS,
			]
		);
		if ( is_wp_error( $terms ) ) {
			return [];
		}
		return array_values( array_filter( $terms, static fn( $t ) => $include_archived || ! self::is_archived( $t->term_id ) ) );
	}

	/** The class with this ID if it belongs to the podcast (and is not archived). */
	public static function get_class( int $term_id, array $feed, bool $allow_archived = false ): ?WP_Term {
		$parent = self::feed_category( $feed );
		$term   = $term_id > 0 ? get_term( $term_id, 'category' ) : null;
		if ( ! $parent || ! $term instanceof WP_Term || (int) $term->parent !== $parent->term_id || ! get_term_meta( $term_id, self::META_CLASS, true ) ) {
			return null;
		}
		return ( $allow_archived || ! self::is_archived( $term_id ) ) ? $term : null;
	}

	/** The podcast a class belongs to. */
	public static function feed_of_class( int $term_id ): ?array {
		$term = get_term( $term_id, 'category' );
		if ( ! $term instanceof WP_Term || ! $term->parent ) {
			return null;
		}
		foreach ( VPConn_Settings::get_powerpress_feeds() as $feed ) {
			$cat = self::feed_category( $feed );
			if ( $cat && $cat->term_id === (int) $term->parent ) {
				return $feed;
			}
		}
		return null;
	}

	public static function is_archived( int $term_id ): bool {
		return (bool) get_term_meta( $term_id, self::META_ARCHIVED, true );
	}

	/** @return int[] User IDs who review the class (still allowed to). */
	public static function get_reviewers( int $term_id ): array {
		$feed = self::feed_of_class( $term_id );
		$ids  = array_map( 'intval', (array) get_term_meta( $term_id, self::META_REVIEWERS, true ) );
		if ( ! $feed ) {
			return [];
		}
		return array_values( array_filter( $ids, static fn( $id ) => VPConn_Access::DIRECT === VPConn_Access::user_level( $id, $feed['slug'] ) ) );
	}

	/** May this user create classes in the podcast and change them? */
	public static function can_manage( int $user_id, array $feed ): bool {
		return null !== self::feed_category( $feed ) && VPConn_Access::DIRECT === VPConn_Access::user_level( $user_id, $feed['slug'] );
	}

	/**
	 * Creates a class under a podcast. Its URL carries the podcast in front
	 * (ingles-a1, frances-a1): category slugs are unique across the site and
	 * every podcast is likely to have an A1.
	 *
	 * @param int[] $reviewers
	 */
	public static function create( array $feed, string $name, array $reviewers ): WP_Term|WP_Error {
		$parent = self::feed_category( $feed );
		if ( ! $parent ) {
			return new WP_Error( 'vpconn_no_category', __( 'This podcast has no category, so it cannot have classes.', 'connector-for-vozcaster' ) );
		}
		$name = trim( $name );
		if ( '' === $name ) {
			return new WP_Error( 'vpconn_no_name', __( 'Give the class a name.', 'connector-for-vozcaster' ) );
		}
		$created = wp_insert_term( $name, 'category', [ 'parent' => $parent->term_id, 'slug' => sanitize_title( $parent->slug . '-' . $name ) ] );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		$term_id = (int) $created['term_id'];
		update_term_meta( $term_id, self::META_CLASS, 1 );
		self::set_reviewers( $term_id, $feed, $reviewers );
		return get_term( $term_id, 'category' );
	}

	/** @param int[] $reviewers Only users who publish directly in the podcast are kept. */
	public static function set_reviewers( int $term_id, array $feed, array $reviewers ): void {
		$allowed = VPConn_Access::direct_publishers( $feed['slug'] );
		$ids     = array_values( array_intersect( array_map( 'intval', $reviewers ), $allowed ) );
		update_term_meta( $term_id, self::META_REVIEWERS, $ids );
	}

	/**
	 * Classes a user can send to, for the bot and the app.
	 *
	 * @return array<int, array{id: int, name: string}>
	 */
	public static function describe_for_feed( array $feed ): array {
		return array_map(
			static fn( WP_Term $t ) => [
				'id'   => $t->term_id,
				'name' => $t->name,
			],
			self::get_classes( $feed )
		);
	}

	/** Class the user last sent to in this podcast (0 if none or no longer valid). */
	public static function last_class( int $user_id, array $feed ): int {
		$last = (array) get_user_meta( $user_id, self::USER_META_LAST, true );
		$id   = (int) ( $last[ $feed['slug'] ] ?? 0 );
		return self::get_class( $id, $feed ) ? $id : 0;
	}

	public static function remember_class( int $user_id, array $feed, int $term_id ): void {
		$last                  = (array) get_user_meta( $user_id, self::USER_META_LAST, true );
		$last[ $feed['slug'] ] = $term_id;
		update_user_meta( $user_id, self::USER_META_LAST, $last );
	}

	// -------------------------------------------------------------------------
	// Screen (wp-admin page and front-end block)
	// -------------------------------------------------------------------------

	/** Podcasts where the user can manage classes. */
	public static function manageable_feeds( int $user_id ): array {
		return array_values( array_filter( VPConn_Access::reviewable_feeds( $user_id ), static fn( $f ) => null !== self::feed_category( $f ) ) );
	}

	/**
	 * The class manager: one table per podcast the user can manage, and a
	 * form to create a class. Used in wp-admin and in the "VozCaster classes"
	 * block, so teachers can do it from a page of the site.
	 */
	public static function render( string $redirect_to ): string {
		$user_id = get_current_user_id();
		$feeds   = self::manageable_feeds( $user_id );
		if ( ! $feeds ) {
			return '<p>' . esc_html__( 'You do not publish directly in any podcast of this site, so you cannot manage classes.', 'connector-for-vozcaster' ) . '</p>';
		}

		ob_start();
		echo VPConn_Frontend::notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside.
		?>
		<p class="vpconn-help"><?php esc_html_e( 'A class groups the episodes of a group of students inside a podcast. They are published in the podcast as usual and also have their own page. What a student sends for review reaches the teachers of their class.', 'connector-for-vozcaster' ); ?></p>
		<?php
		foreach ( $feeds as $feed ) {
			$classes    = self::get_classes( $feed, true );
			$candidates = VPConn_Access::direct_publishers( $feed['slug'] );
			?>
			<h3 class="vpconn-feed-title"><?php echo esc_html( $feed['name'] ); ?></h3>
			<?php if ( ! $classes ) : ?>
				<p><em><?php esc_html_e( 'No classes yet.', 'connector-for-vozcaster' ); ?></em></p>
			<?php else : ?>
				<table class="vpconn-table widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Class', 'connector-for-vozcaster' ); ?></th>
							<th><?php esc_html_e( 'Reviewed by', 'connector-for-vozcaster' ); ?></th>
							<th><?php esc_html_e( 'Episodes', 'connector-for-vozcaster' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $classes as $class ) : ?>
						<?php
						$archived  = self::is_archived( $class->term_id );
						$reviewers = self::get_reviewers( $class->term_id );
						?>
						<tr<?php echo $archived ? ' class="vpconn-archived"' : ''; ?>>
							<td>
								<strong><?php echo esc_html( $class->name ); ?></strong>
								<?php if ( $archived ) : ?>
									<span class="vpconn-badge"><?php esc_html_e( 'archived', 'connector-for-vozcaster' ); ?></span>
								<?php endif; ?>
								<br>
								<a href="<?php echo esc_url( (string) get_term_link( $class ) ); ?>"><?php esc_html_e( 'Page', 'connector-for-vozcaster' ); ?></a>
								· <a href="<?php echo esc_url( get_category_feed_link( $class->term_id ) ); ?>"><?php esc_html_e( 'Feed', 'connector-for-vozcaster' ); ?></a>
							</td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vpconn-inline-form">
									<?php self::hidden_fields( 'reviewers', $redirect_to, $class->term_id ); ?>
									<?php self::reviewer_select( $candidates, $reviewers, 'class-' . $class->term_id ); ?>
									<button type="submit" class="button button-small"><?php esc_html_e( 'Save', 'connector-for-vozcaster' ); ?></button>
								</form>
								<?php if ( ! $reviewers ) : ?>
									<small class="vpconn-muted"><?php esc_html_e( 'Nobody: reviews go to everyone who publishes in the podcast.', 'connector-for-vozcaster' ); ?></small>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( number_format_i18n( (int) $class->count ) ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vpconn-inline-form">
									<?php self::hidden_fields( $archived ? 'restore' : 'archive', $redirect_to, $class->term_id ); ?>
									<button type="submit" class="button button-small">
										<?php echo $archived ? esc_html__( 'Restore', 'connector-for-vozcaster' ) : esc_html__( 'Archive', 'connector-for-vozcaster' ); ?>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vpconn-create-form">
				<?php self::hidden_fields( 'create', $redirect_to ); ?>
				<input type="hidden" name="feed" value="<?php echo esc_attr( $feed['slug'] ); ?>">
				<label>
					<?php esc_html_e( 'New class', 'connector-for-vozcaster' ); ?>
					<input type="text" name="class_name" required maxlength="80" placeholder="<?php esc_attr_e( 'e.g. B1.1 or Mila’s students', 'connector-for-vozcaster' ); ?>">
				</label>
				<?php self::reviewer_select( $candidates, [ $user_id ], 'new-' . $feed['slug'] ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Create class', 'connector-for-vozcaster' ); ?></button>
			</form>
			<?php
		}
		return (string) ob_get_clean();
	}

	private static function hidden_fields( string $op, string $redirect_to, int $term_id = 0 ): void {
		wp_nonce_field( self::ACTION, '_vpconn_nonce' );
		?>
		<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
		<input type="hidden" name="op" value="<?php echo esc_attr( $op ); ?>">
		<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_to ); ?>">
		<?php if ( $term_id ) : ?>
			<input type="hidden" name="class_id" value="<?php echo esc_attr( (string) $term_id ); ?>">
		<?php endif; ?>
		<?php
	}

	/**
	 * @param int[] $candidates
	 * @param int[] $selected
	 */
	private static function reviewer_select( array $candidates, array $selected, string $id ): void {
		?>
		<label class="screen-reader-text" for="vpconn-rev-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Reviewed by', 'connector-for-vozcaster' ); ?></label>
		<select multiple name="reviewers[]" id="vpconn-rev-<?php echo esc_attr( $id ); ?>" class="vpconn-reviewers" size="<?php echo esc_attr( (string) min( 4, max( 2, count( $candidates ) ) ) ); ?>">
			<?php foreach ( $candidates as $candidate_id ) : ?>
				<?php $candidate = get_userdata( $candidate_id ); ?>
				<?php if ( $candidate ) : ?>
					<option value="<?php echo esc_attr( (string) $candidate_id ); ?>" <?php selected( in_array( $candidate_id, $selected, true ) ); ?>>
						<?php echo esc_html( $candidate->display_name ); ?>
					</option>
				<?php endif; ?>
			<?php endforeach; ?>
		</select>
		<?php
	}

	// -------------------------------------------------------------------------
	// Form handler (admin-post.php, from wp-admin or the front end)
	// -------------------------------------------------------------------------

	public function handle_action(): void {
		check_admin_referer( self::ACTION, '_vpconn_nonce' );
		$user_id  = get_current_user_id();
		$redirect = VPConn_Frontend::redirect_target();
		$op       = sanitize_key( wp_unslash( $_POST['op'] ?? '' ) );
		$ids      = isset( $_POST['reviewers'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['reviewers'] ) ) : [];

		if ( 'create' === $op ) {
			$feed = VPConn_Access::find_feed( sanitize_key( wp_unslash( $_POST['feed'] ?? '' ) ) );
			if ( ! $feed || ! self::can_manage( $user_id, $feed ) ) {
				VPConn_Frontend::redirect( $redirect, 'forbidden' );
			}
			$created = self::create( $feed, sanitize_text_field( wp_unslash( $_POST['class_name'] ?? '' ) ), $ids );
			VPConn_Frontend::redirect( $redirect, is_wp_error( $created ) ? 'class_error' : 'class_created' );
		}

		$term_id = absint( wp_unslash( $_POST['class_id'] ?? 0 ) );
		$feed    = self::feed_of_class( $term_id );
		if ( ! $feed || ! self::get_class( $term_id, $feed, true ) || ! self::can_manage( $user_id, $feed ) ) {
			VPConn_Frontend::redirect( $redirect, 'forbidden' );
		}
		switch ( $op ) {
			case 'reviewers':
				self::set_reviewers( $term_id, $feed, $ids );
				VPConn_Frontend::redirect( $redirect, 'class_saved' );
				break;
			case 'archive':
			case 'restore':
				update_term_meta( $term_id, self::META_ARCHIVED, 'archive' === $op ? 1 : 0 );
				VPConn_Frontend::redirect( $redirect, 'class_saved' );
				break;
		}
		VPConn_Frontend::redirect( $redirect, 'forbidden' );
	}
}
