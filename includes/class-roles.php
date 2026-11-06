<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Roles created from VozCaster → Access.
 *
 * Administrators can create roles (for example "Teachers" or "English
 * teachers") without another plugin, and assign them to users as additional
 * roles. A created role is an exact copy of Subscriber: it can read the site
 * and edit its own profile, nothing more. What it can do in VozCaster is
 * decided in the roles × podcasts table.
 *
 * Only the roles recorded in the `vpconn_created_roles` option are "VozCaster
 * roles"; the plugin never edits or deletes any other role.
 */
class VPConn_Roles {

	const OPTION        = 'vpconn_created_roles';
	const ACTION        = 'vpconn_roles';
	const USER_NONCE    = 'vpconn_user_roles';
	const MAX_NAME_LEN  = 60;
	const RESERVED      = [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ];

	/**
	 * Roles whose assignment was submitted on the user screen, applied after
	 * core has set the primary role. User ID => VozCaster role slugs to keep.
	 *
	 * @var array<int, string[]>
	 */
	private static array $pending = [];

	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_action' ] );

		add_action( 'show_user_profile', [ $this, 'render_user_roles' ] );
		add_action( 'edit_user_profile', [ $this, 'render_user_roles' ] );

		// Core fires these two BEFORE edit_user(), which calls set_role() and
		// wipes every secondary role; so here we only read and verify what was
		// submitted, and apply it on profile_update (see apply_user_roles()).
		add_action( 'personal_options_update', [ $this, 'capture_user_roles' ] );
		add_action( 'edit_user_profile_update', [ $this, 'capture_user_roles' ] );
		add_action( 'profile_update', [ $this, 'apply_user_roles' ], 20 );
	}

	// -------------------------------------------------------------------------
	// Data
	// -------------------------------------------------------------------------

	/** @return string[] Slugs of the roles created by this plugin that still exist. */
	public static function get_created_roles(): array {
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) ) {
			return [];
		}
		$existing = wp_roles()->get_names();
		return array_values( array_filter( array_map( 'strval', $stored ), static fn( $slug ) => isset( $existing[ $slug ] ) ) );
	}

	private static function can_manage(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'promote_users' );
	}

	/** Turns a typed name into a role slug ('' if nothing usable is left). */
	public static function slug_from_name( string $name ): string {
		return str_replace( '-', '_', sanitize_key( sanitize_title( $name ) ) );
	}

	// -------------------------------------------------------------------------
	// Create / delete
	// -------------------------------------------------------------------------

	/** @return string Notice key. */
	private static function create_role( string $name ): string {
		$name = trim( sanitize_text_field( $name ) );
		$slug = self::slug_from_name( $name );
		if ( '' === $name || '' === $slug || mb_strlen( $name ) > self::MAX_NAME_LEN ) {
			return 'role_invalid';
		}
		if ( in_array( $slug, self::RESERVED, true ) || null !== get_role( $slug ) ) {
			return 'role_exists';
		}

		// Exact copy of Subscriber, never anything more.
		$subscriber = get_role( 'subscriber' );
		$caps       = $subscriber ? $subscriber->capabilities : [ 'read' => true ];
		if ( ! add_role( $slug, $name, $caps ) ) {
			return 'role_exists';
		}

		$created   = (array) get_option( self::OPTION, [] );
		$created[] = $slug;
		update_option( self::OPTION, array_values( array_unique( array_map( 'strval', $created ) ) ), false );
		return 'role_created';
	}

	/** @return string Notice key. */
	private static function delete_role( string $slug ): string {
		$created = array_map( 'strval', (array) get_option( self::OPTION, [] ) );
		if ( '' === $slug || ! in_array( $slug, $created, true ) ) {
			return 'forbidden';
		}
		$counts = count_users();
		if ( ! empty( $counts['avail_roles'][ $slug ] ) ) {
			return 'role_in_use';
		}

		remove_role( $slug );
		update_option( self::OPTION, array_values( array_diff( $created, [ $slug ] ) ), false );

		// Leave no trace in the access table.
		$matrix = get_option( VPConn_Access::OPTION, null );
		if ( is_array( $matrix ) && isset( $matrix[ $slug ] ) ) {
			unset( $matrix[ $slug ] );
			update_option( VPConn_Access::OPTION, $matrix );
		}
		$managers = array_map( 'strval', (array) get_option( VPConn_Access::OPTION_MANAGERS, [] ) );
		if ( in_array( $slug, $managers, true ) ) {
			update_option( VPConn_Access::OPTION_MANAGERS, array_values( array_diff( $managers, [ $slug ] ) ) );
		}
		$authorized = VPConn_Auth::get_authorized_roles();
		if ( in_array( $slug, $authorized, true ) ) {
			VPConn_Auth::set_authorized_roles( array_diff( $authorized, [ $slug ] ) );
		}
		return 'role_deleted';
	}

	public function handle_action(): void {
		check_admin_referer( self::ACTION, '_vpconn_nonce' );
		$redirect = VPConn_Frontend::redirect_target();
		if ( ! self::can_manage() ) {
			VPConn_Frontend::redirect( $redirect, 'forbidden' );
		}
		$what = isset( $_POST['role_action'] ) ? sanitize_key( wp_unslash( $_POST['role_action'] ) ) : '';
		if ( 'create' === $what ) {
			$name = isset( $_POST['role_name'] ) ? sanitize_text_field( wp_unslash( $_POST['role_name'] ) ) : '';
			VPConn_Frontend::redirect( $redirect, self::create_role( $name ) );
		}
		if ( 'delete' === $what ) {
			$slug = isset( $_POST['role_slug'] ) ? sanitize_key( wp_unslash( $_POST['role_slug'] ) ) : '';
			VPConn_Frontend::redirect( $redirect, self::delete_role( $slug ) );
		}
		VPConn_Frontend::redirect( $redirect, 'forbidden' );
	}

	// -------------------------------------------------------------------------
	// wp-admin box (below the access table)
	// -------------------------------------------------------------------------

	public static function render_box( string $redirect_to ): void {
		if ( ! self::can_manage() ) {
			return;
		}
		$created = self::get_created_roles();
		$names   = wp_roles()->get_names();
		$counts  = count_users();
		?>
		<h3><?php esc_html_e( 'Roles for your users', 'connector-for-vozcaster' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Create a role for each group of people (for example "Teachers" or "English teachers"). A new role only has the permissions of Subscriber: it can read the site and edit its own profile. Give it access to each podcast in the table below, and assign it to users from Users → Edit, or in their profile.', 'connector-for-vozcaster' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0;">
			<?php wp_nonce_field( self::ACTION, '_vpconn_nonce' ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="role_action" value="create">
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_to ); ?>">
			<label for="vpconn-role-name" class="screen-reader-text"><?php esc_html_e( 'Role name', 'connector-for-vozcaster' ); ?></label>
			<input type="text" id="vpconn-role-name" name="role_name" class="regular-text" maxlength="<?php echo esc_attr( (string) self::MAX_NAME_LEN ); ?>" placeholder="<?php esc_attr_e( 'Role name', 'connector-for-vozcaster' ); ?>" required>
			<button type="submit" class="button button-secondary"><?php esc_html_e( 'Create role', 'connector-for-vozcaster' ); ?></button>
		</form>

		<?php if ( empty( $created ) ) : ?>
			<p><em><?php esc_html_e( 'You have not created any role yet.', 'connector-for-vozcaster' ); ?></em></p>
		<?php else : ?>
			<table class="widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Role', 'connector-for-vozcaster' ); ?></th>
						<th><?php esc_html_e( 'Slug', 'connector-for-vozcaster' ); ?></th>
						<th><?php esc_html_e( 'Users', 'connector-for-vozcaster' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $created as $slug ) : ?>
						<?php $users = (int) ( $counts['avail_roles'][ $slug ] ?? 0 ); ?>
						<tr>
							<td><strong><?php echo esc_html( translate_user_role( $names[ $slug ] ) ); ?></strong></td>
							<td><code><?php echo esc_html( $slug ); ?></code></td>
							<td><?php echo esc_html( number_format_i18n( $users ) ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
									<?php wp_nonce_field( self::ACTION, '_vpconn_nonce' ); ?>
									<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
									<input type="hidden" name="role_action" value="delete">
									<input type="hidden" name="role_slug" value="<?php echo esc_attr( $slug ); ?>">
									<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_to ); ?>">
									<button type="submit" class="button button-small button-link-delete"
										onclick="return confirm('<?php echo esc_js( __( 'Delete this role?', 'connector-for-vozcaster' ) ); ?>')">
										<?php esc_html_e( 'Delete', 'connector-for-vozcaster' ); ?>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'A role can only be deleted when no user has it.', 'connector-for-vozcaster' ); ?></p>
		<?php endif; ?>
		<?php
	}

	// -------------------------------------------------------------------------
	// User screen: additional VozCaster roles
	// -------------------------------------------------------------------------

	private static function can_edit_user_roles( int $user_id ): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'promote_user', $user_id );
	}

	public function render_user_roles( WP_User $user ): void {
		$created = self::get_created_roles();
		if ( empty( $created ) || ! self::can_edit_user_roles( (int) $user->ID ) ) {
			return;
		}
		$names = wp_roles()->get_names();
		?>
		<h2><?php esc_html_e( 'VozCaster roles', 'connector-for-vozcaster' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Roles', 'connector-for-vozcaster' ); ?></th>
				<td>
					<?php wp_nonce_field( self::USER_NONCE . $user->ID, '_vpconn_user_roles_nonce' ); ?>
					<input type="hidden" name="vpconn_user_roles_present" value="1">
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'VozCaster roles', 'connector-for-vozcaster' ); ?></legend>
						<?php foreach ( $created as $slug ) : ?>
							<label style="display:block;margin-bottom:4px;">
								<input type="checkbox" name="vpconn_user_roles[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, (array) $user->roles, true ) ); ?>>
								<?php echo esc_html( translate_user_role( $names[ $slug ] ) ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<p class="description"><?php esc_html_e( 'A user can belong to several of these roles, for example to teach in two podcasts. They are added to the main role chosen above.', 'connector-for-vozcaster' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/** Reads and verifies the submitted checkboxes; nothing is saved yet. */
	public function capture_user_roles( $user_id ): void {
		$user_id = (int) $user_id;
		if ( ! isset( $_POST['vpconn_user_roles_present'] ) || ! self::can_edit_user_roles( $user_id ) ) {
			return;
		}
		$nonce = isset( $_POST['_vpconn_user_roles_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_vpconn_user_roles_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::USER_NONCE . $user_id ) ) {
			return;
		}
		$submitted = isset( $_POST['vpconn_user_roles'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['vpconn_user_roles'] ) ) : [];
		// The main role chosen in core's dropdown is never removed here, even
		// if it is a VozCaster role whose box was left unticked.
		if ( isset( $_POST['role'] ) ) {
			$submitted[] = sanitize_key( wp_unslash( $_POST['role'] ) );
		}
		self::$pending[ $user_id ] = array_values( array_unique( array_intersect( $submitted, self::get_created_roles() ) ) );
	}

	/**
	 * Applies the captured roles once core has finished.
	 *
	 * In wp-admin/user-edit.php, edit_user_profile_update (and
	 * personal_options_update) fire before edit_user(); edit_user() ends in
	 * wp_insert_user(), which calls WP_User::set_role() with the main role
	 * dropdown and so removes every other role. profile_update fires after
	 * that set_role() call, so adding and removing the VozCaster roles here
	 * is the only point where they survive.
	 */
	public function apply_user_roles( $user_id ): void {
		$user_id = (int) $user_id;
		if ( ! isset( self::$pending[ $user_id ] ) ) {
			return;
		}
		$keep = self::$pending[ $user_id ];
		unset( self::$pending[ $user_id ] );

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		// Only VozCaster roles are touched; every other role stays as core left it.
		foreach ( self::get_created_roles() as $slug ) {
			$has = in_array( $slug, (array) $user->roles, true );
			if ( in_array( $slug, $keep, true ) ) {
				if ( ! $has ) {
					$user->add_role( $slug );
				}
			} elseif ( $has ) {
				$user->remove_role( $slug );
			}
		}
		// Never leave somebody without any role.
		$fresh = get_userdata( $user_id );
		if ( $fresh && empty( $fresh->roles ) ) {
			$fresh->add_role( 'subscriber' );
		}
	}
}
