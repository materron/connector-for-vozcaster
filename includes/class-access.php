<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Who may send episodes to which podcast, and whether they need a review.
 *
 * The administrator fills a table of roles × podcasts (VozCaster → Access,
 * or the "VozCaster access" block): each cell is empty (no access), "direct"
 * (publishes straight away) or "review" (held as pending until someone who
 * publishes directly in that podcast approves it). A separate column marks
 * the roles that manage VozCaster (classes and this table) without being
 * WordPress administrators, e.g. the school's coordinators.
 *
 * Until the table is saved the site keeps the 1.8–1.14 behaviour: every
 * authorized role publishes in every podcast, held for review only when the
 * old "Review before publishing" box is on and the role cannot publish posts.
 */
class VPConn_Access {

	const OPTION          = 'vpconn_feed_access';
	const OPTION_MANAGERS = 'vpconn_manager_roles';
	const DIRECT          = 'direct';
	const REVIEW          = 'review';

	/** Saved table (role => [feed slug => level]), or null while never saved. */
	public static function get_matrix(): ?array {
		$stored = get_option( self::OPTION, null );
		return is_array( $stored ) ? $stored : null;
	}

	public static function is_configured(): bool {
		return null !== self::get_matrix();
	}

	/**
	 * Saves the table. Unknown roles, podcasts and levels are dropped; the
	 * administrator row is never stored because it always publishes directly.
	 *
	 * @param array<string, array<string, string>> $matrix  Role => feed => level.
	 * @param string[]                             $managers Roles that manage VozCaster.
	 */
	public static function save( array $matrix, array $managers ): void {
		$valid_roles = array_keys( wp_roles()->get_names() );
		$valid_feeds = wp_list_pluck( VPConn_Settings::get_powerpress_feeds(), 'slug' );
		$clean       = [];
		foreach ( $matrix as $role => $feeds ) {
			$role = sanitize_key( (string) $role );
			if ( 'administrator' === $role || ! in_array( $role, $valid_roles, true ) || ! is_array( $feeds ) ) {
				continue;
			}
			foreach ( $feeds as $feed => $level ) {
				$feed  = sanitize_key( (string) $feed );
				$level = sanitize_key( (string) $level );
				if ( in_array( $feed, $valid_feeds, true ) && in_array( $level, [ self::DIRECT, self::REVIEW ], true ) ) {
					$clean[ $role ][ $feed ] = $level;
				}
			}
		}
		$managers = array_values( array_diff( array_intersect( array_map( 'sanitize_key', $managers ), $valid_roles ), [ 'administrator' ] ) );

		update_option( self::OPTION, $clean );
		update_option( self::OPTION_MANAGERS, $managers );
		// Every "may this user use VozCaster?" check reads the authorized
		// roles, so they follow the table: a role with any podcast is in, and
		// so are the managers.
		VPConn_Auth::set_authorized_roles( array_merge( array_keys( $clean ), $managers ) );
	}

	/** @return string[] Roles that manage VozCaster besides administrators. */
	public static function get_manager_roles(): array {
		return array_values( array_map( 'strval', (array) get_option( self::OPTION_MANAGERS, [] ) ) );
	}

	/** Administrators, and users with a role marked as manager. */
	public static function is_manager( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		if ( user_can( $user, 'manage_options' ) || ( is_multisite() && is_super_admin( $user_id ) ) ) {
			return true;
		}
		return (bool) array_intersect( (array) $user->roles, self::get_manager_roles() );
	}

	/** Level of a role in a podcast as the table shows it: direct, review or ''. */
	public static function role_level( string $role, string $feed ): string {
		if ( 'administrator' === $role ) {
			return self::DIRECT;
		}
		$matrix = self::get_matrix();
		if ( null === $matrix ) {
			if ( ! in_array( $role, VPConn_Auth::get_authorized_roles(), true ) ) {
				return '';
			}
			$role_obj = get_role( $role );
			return ( VPConn_Review::is_enabled() && ! ( $role_obj && $role_obj->has_cap( 'publish_posts' ) ) ) ? self::REVIEW : self::DIRECT;
		}
		return (string) ( $matrix[ $role ][ $feed ] ?? '' );
	}

	/**
	 * What a user may do in a podcast: direct, review or '' (nothing). With
	 * several roles the most permissive one wins; managers publish directly.
	 */
	public static function user_level( int $user_id, string $feed ): string {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return '';
		}
		if ( self::is_manager( $user_id ) ) {
			$level = self::DIRECT;
		} elseif ( ! self::is_configured() ) {
			if ( ! VPConn_Auth::is_user_allowed( $user_id ) ) {
				$level = '';
			} else {
				$level = ( VPConn_Review::is_enabled() && ! user_can( $user, 'publish_posts' ) ) ? self::REVIEW : self::DIRECT;
			}
		} else {
			$level = '';
			foreach ( (array) $user->roles as $role ) {
				$role_level = self::role_level( (string) $role, $feed );
				if ( self::DIRECT === $role_level ) {
					$level = self::DIRECT;
					break;
				}
				if ( self::REVIEW === $role_level ) {
					$level = self::REVIEW;
				}
			}
		}
		/**
		 * Filters what a user may do in a podcast.
		 *
		 * @param string $level   'direct', 'review' or ''.
		 * @param int    $user_id WordPress user ID.
		 * @param string $feed    Podcast (feed) slug.
		 */
		return (string) apply_filters( 'vpconn_user_feed_level', $level, $user_id, $feed );
	}

	/**
	 * Podcasts the user may send episodes to, each with its 'access' level.
	 *
	 * @return array<int, array{slug: string, name: string, category_slug: ?string, access: string}>
	 */
	public static function feeds_for_user( int $user_id ): array {
		$out = [];
		foreach ( VPConn_Settings::get_powerpress_feeds() as $feed ) {
			$level = self::user_level( $user_id, $feed['slug'] );
			if ( '' !== $level ) {
				$out[] = array_merge( $feed, [ 'access' => $level ] );
			}
		}
		return $out;
	}

	/** @return array<int, array> Podcasts where the user publishes directly (and so reviews). */
	public static function reviewable_feeds( int $user_id ): array {
		return array_values( array_filter( self::feeds_for_user( $user_id ), static fn( $f ) => self::DIRECT === $f['access'] ) );
	}

	/**
	 * Users who publish directly in a podcast, i.e. the ones who may approve
	 * what is sent there for review.
	 *
	 * @return int[] User IDs.
	 */
	public static function direct_publishers( string $feed ): array {
		$roles = array_merge( [ 'administrator' ], self::get_manager_roles() );
		foreach ( wp_roles()->get_names() as $role => $name ) {
			if ( self::DIRECT === self::role_level( (string) $role, $feed ) ) {
				$roles[] = (string) $role;
			}
		}
		$ids = get_users(
			[
				'role__in' => array_values( array_unique( $roles ) ),
				'fields'   => 'ID',
			]
		);
		return array_values( array_filter( array_map( 'intval', $ids ), static fn( $id ) => self::DIRECT === self::user_level( $id, $feed ) ) );
	}

	// -------------------------------------------------------------------------
	// Screen (wp-admin Access page and the "VozCaster access" block)
	// -------------------------------------------------------------------------

	const ACTION = 'vpconn_access';

	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_action' ] );
	}

	public static function render( string $redirect_to ): string {
		$user_id = get_current_user_id();
		if ( ! self::is_manager( $user_id ) ) {
			return '<p>' . esc_html__( 'Only the people who manage VozCaster on this site can change who publishes.', 'connector-for-vozcaster' ) . '</p>';
		}
		$is_admin = user_can( $user_id, 'manage_options' );
		$feeds    = VPConn_Settings::get_powerpress_feeds();
		$roles    = wp_roles()->get_names();
		$managers = self::get_manager_roles();
		$labels   = [
			''           => __( '—', 'connector-for-vozcaster' ),
			self::DIRECT => __( 'Publishes', 'connector-for-vozcaster' ),
			self::REVIEW => __( 'With review', 'connector-for-vozcaster' ),
		];

		ob_start();
		echo VPConn_Frontend::notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside.
		?>
		<p class="vpconn-help"><?php esc_html_e( 'Choose, for each role and podcast, whether its users publish directly, send their episodes for review, or cannot use that podcast. Whoever publishes directly in a podcast reviews what is sent to it and can create classes in it. Users connect with their usual WordPress account; their password never goes through VozCaster.', 'connector-for-vozcaster' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::ACTION, '_vpconn_nonce' ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_to ); ?>">
			<div class="vpconn-scroll">
			<table class="vpconn-table widefat striped vpconn-access-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Role', 'connector-for-vozcaster' ); ?></th>
						<?php foreach ( $feeds as $feed ) : ?>
							<th><?php echo esc_html( $feed['name'] ); ?></th>
						<?php endforeach; ?>
						<th><?php esc_html_e( 'Manages VozCaster', 'connector-for-vozcaster' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $roles as $role => $role_name ) : ?>
					<?php $is_admin_role = 'administrator' === $role; ?>
					<tr>
						<th scope="row"><?php echo esc_html( translate_user_role( $role_name ) ); ?></th>
						<?php foreach ( $feeds as $feed ) : ?>
							<td>
								<?php if ( $is_admin_role ) : ?>
									<?php echo esc_html( $labels[ self::DIRECT ] ); ?>
								<?php else : ?>
									<?php $current = self::role_level( (string) $role, $feed['slug'] ); ?>
									<label class="screen-reader-text" for="vpconn-<?php echo esc_attr( $role . '-' . $feed['slug'] ); ?>">
										<?php echo esc_html( translate_user_role( $role_name ) . ' — ' . $feed['name'] ); ?>
									</label>
									<select name="access[<?php echo esc_attr( (string) $role ); ?>][<?php echo esc_attr( $feed['slug'] ); ?>]" id="vpconn-<?php echo esc_attr( $role . '-' . $feed['slug'] ); ?>">
										<?php foreach ( $labels as $value => $label ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								<?php endif; ?>
							</td>
						<?php endforeach; ?>
						<td>
							<?php if ( $is_admin_role ) : ?>
								✔
							<?php else : ?>
								<input type="checkbox" name="managers[]" value="<?php echo esc_attr( (string) $role ); ?>"
									aria-label="<?php echo esc_attr( translate_user_role( $role_name ) ); ?>"
									<?php checked( in_array( $role, $managers, true ) ); ?> <?php disabled( ! $is_admin ); ?>>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<p class="vpconn-muted"><?php esc_html_e( 'Administrators always publish everywhere. "Manages VozCaster" lets a role change this table and the classes of every podcast, without being a WordPress administrator; only administrators can change that column.', 'connector-for-vozcaster' ); ?></p>
			<p><button type="submit" class="button button-primary wp-element-button"><?php esc_html_e( 'Save access settings', 'connector-for-vozcaster' ); ?></button></p>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	public function handle_action(): void {
		check_admin_referer( self::ACTION, '_vpconn_nonce' );
		$redirect = VPConn_Frontend::redirect_target();
		$user_id  = get_current_user_id();
		if ( ! self::is_manager( $user_id ) ) {
			VPConn_Frontend::redirect( $redirect, 'forbidden' );
		}
		$raw    = isset( $_POST['access'] ) && is_array( $_POST['access'] ) ? wp_unslash( $_POST['access'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized key by key in save().
		$matrix = [];
		foreach ( (array) $raw as $role => $feeds ) {
			$matrix[ sanitize_key( (string) $role ) ] = array_map( 'sanitize_key', (array) $feeds );
		}
		// Only administrators decide who manages VozCaster.
		$managers = user_can( $user_id, 'manage_options' )
			? ( isset( $_POST['managers'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['managers'] ) ) : [] )
			: self::get_manager_roles();
		self::save( $matrix, $managers );
		VPConn_Frontend::redirect( $redirect, 'access_saved' );
	}

	/** Finds a podcast by slug among all the site's podcasts. */
	public static function find_feed( string $slug ): ?array {
		foreach ( VPConn_Settings::get_powerpress_feeds() as $feed ) {
			if ( $feed['slug'] === $slug ) {
				return $feed;
			}
		}
		return null;
	}
}
