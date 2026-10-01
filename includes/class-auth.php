<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestiona la autenticación de usuarios del bot de Telegram.
 *
 * Modelo de seguridad:
 * - Pueden usar el bot los usuarios cuyo rol esté autorizado (por defecto, los que pueden
 *   publicar entradas: Autor o superior; el administrador puede añadir roles propios).
 * - Cada usuario se autentica via flujo web: el bot genera un enlace único y temporal,
 *   el usuario hace clic, inicia sesión en WordPress con sus propias credenciales,
 *   y el plugin emite un token personal que el bot almacena.
 * - Las peticiones del bot incluyen X-VozPress-Token; el plugin lo valida contra el hash
 *   guardado en usermeta y establece al usuario como current_user de WordPress.
 * - Ninguna contraseña pasa por Telegram.
 */
class VPConn_Auth {

	const OPTION_AUTHORIZED_ROLES     = 'vpconn_authorized_roles';
	const USER_META_TOKEN_HASH        = 'vpconn_bot_token_hash';
	const STATE_PATTERN               = '/^[a-f0-9]{32}$/';

	// Pre-1.8.0 storage, kept only so the upgrade routine can migrate it.
	const LEGACY_OPTION_ALLOWED_USERS = 'vpconn_allowed_wp_users';
	const LEGACY_USER_META_TOKEN      = 'vpconn_bot_token';

	public function register_hooks(): void {
		// Autenticar peticiones REST via X-VozPress-Token.
		add_filter( 'determine_current_user', [ $this, 'authenticate_via_token' ], 20 );
		// Gestionar callback post-login (página normal WP, no REST, para que las cookies funcionen).
		add_action( 'init', [ $this, 'handle_auth_callback' ] );
	}

	// -------------------------------------------------------------------------
	// Autenticación de peticiones REST vía X-VozPress-Token
	// -------------------------------------------------------------------------

	/**
	 * Si la petición REST incluye X-VozPress-Token válido, establece al usuario
	 * correspondiente como current_user. Integra con el sistema de auth de WP.
	 */
	public function authenticate_via_token( int|false $user_id ): int|false {
		if ( $user_id ) {
			return $user_id; // Ya autenticado por otro mecanismo.
		}
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return $user_id;
		}
		$token = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_VOZPRESS_TOKEN'] ?? '' ) );
		if ( '' === $token ) {
			return $user_id;
		}
		$found = self::find_user_id_by_token( $token );
		return $found ?: $user_id;
	}

	// -------------------------------------------------------------------------
	// Per-user tokens (usermeta, stored as a SHA-256 hash)
	// -------------------------------------------------------------------------

	/**
	 * Returns the ID of the authorized user that owns this token, or false.
	 *
	 * Only the SHA-256 hash of the token is stored, so the lookup is a direct
	 * meta query. A user whose role is no longer authorized keeps the token
	 * (it shows up in the connected users list) but cannot use it.
	 */
	public static function find_user_id_by_token( string $token ): int|false {
		if ( '' === $token ) {
			return false;
		}
		$ids = get_users(
			[
				// Single lookup by a unique hash, only on requests carrying the bot token header.
				'meta_key'    => self::USER_META_TOKEN_HASH, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => self::hash_token( $token ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'      => 1,
				'fields'      => 'ID',
				'count_total' => false,
			]
		);
		if ( empty( $ids ) ) {
			return false;
		}
		$user_id = (int) $ids[0];
		return self::is_user_allowed( $user_id ) ? $user_id : false;
	}

	/**
	 * Generates a random token for the user and stores its hash.
	 * Any previous token of that user stops working (re-authentication).
	 *
	 * @return string Plain-text token, handed to the bot once.
	 */
	public static function generate_user_token( int $user_id ): string {
		$token = bin2hex( random_bytes( 32 ) );
		update_user_meta( $user_id, self::USER_META_TOKEN_HASH, self::hash_token( $token ) );
		return $token;
	}

	/**
	 * Revokes the user's token: the bot can no longer publish with it.
	 * The user has to connect again.
	 */
	public static function revoke_user_token( int $user_id ): void {
		delete_user_meta( $user_id, self::USER_META_TOKEN_HASH );
	}

	public static function has_token( int $user_id ): bool {
		return (bool) get_user_meta( $user_id, self::USER_META_TOKEN_HASH, true );
	}

	/** @return int[] IDs of the users that have connected the bot. */
	public static function get_connected_user_ids(): array {
		return array_map(
			'intval',
			get_users(
				[
					'meta_key'    => self::USER_META_TOKEN_HASH, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin screen only.
					'fields'      => 'ID',
					'orderby'     => 'display_name',
					'count_total' => false,
				]
			)
		);
	}

	private static function hash_token( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * 1.8.0 migration: replaces the plain-text tokens of earlier versions with
	 * their hash, so connected users keep working without reconnecting, and
	 * drops the per-user allowlist and per-feed permissions (authorization is
	 * now by role).
	 */
	public static function migrate_to_role_authorization(): void {
		$user_ids = get_users(
			[
				'meta_key'    => self::LEGACY_USER_META_TOKEN, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-off migration.
				'fields'      => 'ID',
				'count_total' => false,
			]
		);
		foreach ( $user_ids as $user_id ) {
			$plain = (string) get_user_meta( (int) $user_id, self::LEGACY_USER_META_TOKEN, true );
			if ( '' !== $plain ) {
				update_user_meta( (int) $user_id, self::USER_META_TOKEN_HASH, self::hash_token( $plain ) );
			}
			delete_user_meta( (int) $user_id, self::LEGACY_USER_META_TOKEN );
		}
		delete_option( self::LEGACY_OPTION_ALLOWED_USERS );
		delete_option( 'vpconn_feed_permissions' );
	}

	// -------------------------------------------------------------------------
	// Authorization by role
	// -------------------------------------------------------------------------

	/**
	 * A user may use the bot when any of their roles is authorized.
	 * On multisite, super admins are always allowed.
	 */
	public static function is_user_allowed( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		if ( is_multisite() && is_super_admin( $user_id ) ) {
			return true;
		}
		return (bool) array_intersect( (array) $user->roles, self::get_authorized_roles() );
	}

	/**
	 * Roles authorized to publish from the bot.
	 *
	 * Until the administrator saves a choice, every role that can publish posts
	 * (Administrator, Editor, Author and any custom role with `publish_posts`).
	 * Administrator is always included so nobody locks themselves out.
	 *
	 * @return string[] Role slugs.
	 */
	public static function get_authorized_roles(): array {
		$stored = get_option( self::OPTION_AUTHORIZED_ROLES, null );
		$roles  = is_array( $stored ) ? array_map( 'strval', $stored ) : self::get_default_authorized_roles();
		$roles[] = 'administrator';
		return array_values( array_unique( $roles ) );
	}

	/** @return string[] Slugs of the roles that have the `publish_posts` capability. */
	public static function get_default_authorized_roles(): array {
		$roles = [];
		foreach ( wp_roles()->role_objects as $slug => $role ) {
			if ( $role->has_cap( 'publish_posts' ) ) {
				$roles[] = (string) $slug;
			}
		}
		return $roles;
	}

	/** @param string[] $roles Role slugs; unknown roles are ignored. */
	public static function set_authorized_roles( array $roles ): void {
		$valid = array_keys( wp_roles()->get_names() );
		$roles = array_values( array_intersect( array_map( 'sanitize_key', $roles ), $valid ) );
		update_option( self::OPTION_AUTHORIZED_ROLES, $roles );
	}

	// -------------------------------------------------------------------------
	// Flujo web de autenticación
	// -------------------------------------------------------------------------

	/**
	 * Inicia el flujo: guarda el state y redirige al login de WordPress.
	 * Llamado desde el endpoint REST GET /auth/iniciar.
	 */
	public static function initiate( string $state, int $telegram_id ): void {
		if ( ! preg_match( self::STATE_PATTERN, $state ) || $telegram_id <= 0 ) {
			status_header( 400 );
			wp_die(
				esc_html__( 'Invalid parameters.', 'connector-for-vozcaster' ),
				esc_html__( 'Error', 'connector-for-vozcaster' ),
				[ 'response' => 400 ]
			);
		}

		// Guardar telegram_id asociado a este state (TTL: 10 min).
		set_transient( 'vpconn_pending_' . $state, $telegram_id, 10 * MINUTE_IN_SECONDS );

		// URL de callback en el front-end (no REST) para que las cookies de sesión funcionen.
		$callback_url = add_query_arg( 'vpconn_auth', $state, home_url( '/' ) );

		wp_safe_redirect( wp_login_url( $callback_url ) );
		exit;
	}

	/**
	 * Gestiona el callback tras el login de WordPress.
	 * Se activa cuando la URL contiene ?vpconn_auth=STATE.
	 * En este punto el usuario ya tiene la cookie de sesión de WP activa.
	 */
	public function handle_auth_callback(): void {
		// Public OAuth-like callback after WP login. $state is a single-use 32-hex
		// random token validated against a server-side transient, so it is the CSRF
		// protection here; a form nonce does not apply to this flow.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = isset( $_GET['vpconn_auth'] ) ? sanitize_text_field( wp_unslash( $_GET['vpconn_auth'] ) ) : '';
		if ( ! $state || ! preg_match( self::STATE_PATTERN, $state ) ) {
			return;
		}

		// Si el usuario no está logueado, redirigir al login de nuevo con este callback.
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( add_query_arg( 'vpconn_auth', $state, home_url( '/' ) ) ) );
			exit;
		}

		$telegram_id = (int) get_transient( 'vpconn_pending_' . $state );
		if ( ! $telegram_id ) {
			$page = self::auth_page(
				'⚠️ ' . esc_html__( 'Link expired', 'connector-for-vozcaster' ),
				esc_html__( 'This authorization link has expired (10 minutes).', 'connector-for-vozcaster' ),
				sprintf(
					/* translators: %s: the /conectar bot command, wrapped in a <code> tag. */
					esc_html__( 'Go back to the bot and use %s again.', 'connector-for-vozcaster' ),
					'<code>/conectar</code>'
				),
				'#e65c00'
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $page is trusted HTML built by auth_page(); all dynamic values are escaped within.
			wp_die( $page, esc_html__( 'VozCaster — Link expired', 'connector-for-vozcaster' ), [ 'response' => 400 ] );
		}

		$user = wp_get_current_user();

		if ( ! self::is_user_allowed( $user->ID ) ) {
			$page = self::auth_page(
				'🚫 ' . esc_html__( 'Not authorized', 'connector-for-vozcaster' ),
				sprintf(
					/* translators: %s: the WordPress username, wrapped in a <strong> tag. */
					esc_html__( 'The role of %s is not authorized to publish from VozCaster.', 'connector-for-vozcaster' ),
					'<strong>' . esc_html( $user->user_login ) . '</strong>'
				),
				sprintf(
					/* translators: %s: the plugin settings location, wrapped in an <em> tag. */
					esc_html__( 'Ask the administrator to authorize your role under %s.', 'connector-for-vozcaster' ),
					'<em>' . esc_html__( 'Settings → VozCaster', 'connector-for-vozcaster' ) . '</em>'
				),
				'#cc0000'
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $page is trusted HTML built by auth_page(); all dynamic values are escaped within.
			wp_die( $page, esc_html__( 'VozCaster — Not authorized', 'connector-for-vozcaster' ), [ 'response' => 403 ] );
		}

		// Generar token personal para este usuario.
		$token = self::generate_user_token( $user->ID );

		// Guardar resultado para que el bot lo recoja via polling (TTL: 10 min).
		set_transient(
			'vpconn_auth_result_' . $state,
			[
				'token'       => $token,
				'wp_username' => $user->user_login,
				'telegram_id' => $telegram_id,
			],
			10 * MINUTE_IN_SECONDS
		);

		delete_transient( 'vpconn_pending_' . $state );

		$page = self::auth_page(
			'✅ ' . esc_html__( 'Connected!', 'connector-for-vozcaster' ),
			sprintf(
				/* translators: %s: the user's display name, wrapped in a <strong> tag. */
				esc_html__( 'Hello, %s.', 'connector-for-vozcaster' ),
				'<strong>' . esc_html( $user->display_name ) . '</strong>'
			),
			esc_html__( 'You can now go back to the Telegram bot and continue.', 'connector-for-vozcaster' ),
			'#46b450'
		);
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $page is trusted HTML built by auth_page(); all dynamic values are escaped within.
		wp_die( $page, esc_html__( 'VozCaster — Connection completed', 'connector-for-vozcaster' ), [ 'response' => 200 ] );
	}

	/**
	 * Genera el HTML de las páginas de resultado del flujo de auth.
	 * En la página de éxito intenta cerrar la ventana automáticamente.
	 */
	private static function auth_page(
		string $heading,
		string $body1,
		string $body2,
		string $color
	): string {
		$bot_username = get_option( 'vpconn_bot_username', 'VozCasterBot' );
		$bot_url      = 'https://t.me/' . ltrim( $bot_username, '@' );

		$back_btn =
			'<div style="margin-top:1.5em">'
			. '<a href="' . esc_url( $bot_url ) . '" '
			. 'style="display:inline-block;background:#0088cc;color:#fff;padding:.55em 1.4em;'
			. 'border-radius:6px;text-decoration:none;font-weight:bold;font-size:.95rem">'
			. '↩ ' . esc_html__( 'Back to the Telegram bot', 'connector-for-vozcaster' ) . '</a></div>';

		return '<div style="font-family:sans-serif;text-align:center;padding:2em;max-width:460px;margin:auto">'
			. '<h2 style="color:' . esc_attr( $color ) . '">' . $heading . '</h2>'
			. '<p>' . $body1 . '</p>'
			. '<p>' . $body2 . '</p>'
			. $back_btn
			. '</div>';
	}
}
