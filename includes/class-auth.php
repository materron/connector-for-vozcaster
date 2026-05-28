<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestiona la autenticación de usuarios del bot de Telegram.
 *
 * Modelo de seguridad:
 * - El administrador define qué usuarios WP pueden usar el bot (whitelist por ID de usuario WP).
 * - Cada usuario se autentica via flujo web: el bot genera un enlace único y temporal,
 *   el usuario hace clic, inicia sesión en WordPress con sus propias credenciales,
 *   y el plugin emite un token personal que el bot almacena.
 * - Las peticiones del bot incluyen X-VozPress-Token; el plugin lo valida contra los tokens
 *   almacenados en usermeta y establece al usuario como current_user de WordPress.
 * - Ninguna contraseña pasa por Telegram.
 */
class VPConn_Auth {

	const OPTION_ALLOWED_USERS = 'vpconn_allowed_wp_users';
	const USER_META_TOKEN      = 'vpconn_bot_token';
	const STATE_PATTERN        = '/^[a-f0-9]{32}$/';

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
		$token = $_SERVER['HTTP_X_VOZPRESS_TOKEN'] ?? '';
		if ( '' === $token ) {
			return $user_id;
		}
		$found = self::find_user_id_by_token( $token );
		return $found ?: $user_id;
	}

	// -------------------------------------------------------------------------
	// Tokens por usuario (usermeta)
	// -------------------------------------------------------------------------

	/**
	 * Busca el WP user_id que tiene ese token en su usermeta.
	 * Solo busca entre los usuarios de la whitelist.
	 */
	public static function find_user_id_by_token( string $token ): int|false {
		if ( '' === $token ) {
			return false;
		}
		foreach ( self::get_allowed_user_ids() as $uid ) {
			$stored = get_user_meta( $uid, self::USER_META_TOKEN, true );
			if ( $stored && hash_equals( (string) $stored, $token ) ) {
				return $uid;
			}
		}
		return false;
	}

	/**
	 * Genera un token aleatorio para el usuario y lo guarda en usermeta.
	 * Si ya tenía token, lo sobreescribe (re-autenticación).
	 *
	 * @return string Token en texto plano.
	 */
	public static function generate_user_token( int $user_id ): string {
		$token = bin2hex( random_bytes( 32 ) );
		update_user_meta( $user_id, self::USER_META_TOKEN, $token );
		return $token;
	}

	/**
	 * Revoca el token de un usuario: el bot no podrá publicar con ese token.
	 * El usuario deberá reconectarse con /conectar.
	 */
	public static function revoke_user_token( int $user_id ): void {
		delete_user_meta( $user_id, self::USER_META_TOKEN );
	}

	public static function has_token( int $user_id ): bool {
		return (bool) get_user_meta( $user_id, self::USER_META_TOKEN, true );
	}

	// -------------------------------------------------------------------------
	// Whitelist de usuarios permitidos
	// -------------------------------------------------------------------------

	public static function is_user_allowed( int $user_id ): bool {
		return in_array( $user_id, self::get_allowed_user_ids(), true );
	}

	/** @return int[] */
	public static function get_allowed_user_ids(): array {
		return array_map( 'intval', (array) get_option( self::OPTION_ALLOWED_USERS, [] ) );
	}

	public static function set_allowed_user_ids( array $ids ): void {
		update_option( self::OPTION_ALLOWED_USERS, array_values( array_map( 'intval', $ids ) ) );
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
			wp_die( 'Parámetros inválidos.', 'Error', [ 'response' => 400 ] );
		}

		// Guardar telegram_id asociado a este state (TTL: 10 min).
		set_transient( 'vpconn_pending_' . $state, $telegram_id, 10 * MINUTE_IN_SECONDS );

		// URL de callback en el front-end (no REST) para que las cookies de sesión funcionen.
		$callback_url = add_query_arg( 'vpconn_auth', $state, home_url( '/' ) );

		wp_redirect( wp_login_url( $callback_url ) );
		exit;
	}

	/**
	 * Gestiona el callback tras el login de WordPress.
	 * Se activa cuando la URL contiene ?vpconn_auth=STATE.
	 * En este punto el usuario ya tiene la cookie de sesión de WP activa.
	 */
	public function handle_auth_callback(): void {
		$state = sanitize_text_field( $_GET['vpconn_auth'] ?? '' );
		if ( ! $state || ! preg_match( self::STATE_PATTERN, $state ) ) {
			return;
		}

		// Si el usuario no está logueado, redirigir al login de nuevo con este callback.
		if ( ! is_user_logged_in() ) {
			wp_redirect( wp_login_url( add_query_arg( 'vpconn_auth', $state, home_url( '/' ) ) ) );
			exit;
		}

		$telegram_id = (int) get_transient( 'vpconn_pending_' . $state );
		if ( ! $telegram_id ) {
			wp_die(
				self::auth_page(
					'⚠️ Enlace caducado',
					'El enlace de autorización ha expirado (10 minutos).',
					'Vuelve al bot y usa <code>/conectar</code> de nuevo.',
					'#e65c00'
				),
				'VozCaster — Enlace caducado',
				[ 'response' => 400 ]
			);
		}

		$user = wp_get_current_user();

		if ( ! self::is_user_allowed( $user->ID ) ) {
			wp_die(
				self::auth_page(
					'🚫 No autorizado',
					'El usuario <strong>' . esc_html( $user->user_login ) . '</strong> no está en la lista de usuarios permitidos.',
					'Pide al administrador que te active en <em>Ajustes → VozCaster</em>.',
					'#cc0000'
				),
				'VozCaster — No autorizado',
				[ 'response' => 403 ]
			);
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

		wp_die(
			self::auth_page(
				'✅ ¡Conectado!',
				'Hola, <strong>' . esc_html( $user->display_name ) . '</strong>.',
				'Ya puedes volver al bot de Telegram y continuar.',
				'#46b450'
			),
			'VozCaster — Conexión completada',
			[ 'response' => 200 ]
		);
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
			. '↩ Volver al bot de Telegram</a></div>';

		return '<div style="font-family:sans-serif;text-align:center;padding:2em;max-width:460px;margin:auto">'
			. '<h2 style="color:' . $color . '">' . $heading . '</h2>'
			. '<p>' . $body1 . '</p>'
			. '<p>' . $body2 . '</p>'
			. $back_btn
			. '</div>';
	}
}
