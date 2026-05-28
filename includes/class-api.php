<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registra y gestiona todos los endpoints REST de VozPress Connector.
 * Namespace: vozpress/v1
 */
class VPConn_API {

	const NAMESPACE = 'vozpress/v1';

	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		// GET /ping
		register_rest_route(
			self::NAMESPACE,
			'/ping',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'ping' ],
				'permission_callback' => '__return_true',
			]
		);

		// GET /auth/iniciar — inicia el flujo web: guarda state y redirige al login de WP
		register_rest_route(
			self::NAMESPACE,
			'/auth/iniciar',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'auth_iniciar' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'state'       => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'telegram_id' => [
						'required' => true,
						'type'     => 'integer',
					],
				],
			]
		);

		// GET /auth/estado — polling: devuelve token cuando el usuario se ha logueado
		register_rest_route(
			self::NAMESPACE,
			'/auth/estado',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'auth_estado' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'state' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// GET /feeds — lista de feeds accesibles para el usuario autenticado
		register_rest_route(
			self::NAMESPACE,
			'/feeds',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_feeds' ],
				'permission_callback' => [ $this, 'check_token_permission' ],
			]
		);

		// GET /media/intro-outro
		register_rest_route(
			self::NAMESPACE,
			'/media/intro-outro',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'media_intro_outro_status' ],
				'permission_callback' => [ $this, 'check_token_permission' ],
			]
		);

		// POST /media/audio
		register_rest_route(
			self::NAMESPACE,
			'/media/audio',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'upload_audio' ],
				'permission_callback' => [ $this, 'check_token_permission' ],
			]
		);

		// POST /media/audio/chunk — subida fragmentada para archivos grandes (>WAF limit)
		register_rest_route(
			self::NAMESPACE,
			'/media/audio/chunk',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'upload_audio_chunk' ],
				'permission_callback' => [ $this, 'check_token_permission' ],
			]
		);

		// POST /media/image
		register_rest_route(
			self::NAMESPACE,
			'/media/image',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'upload_image' ],
				'permission_callback' => [ $this, 'check_token_permission' ],
			]
		);

		// POST /media/intro
		register_rest_route(
			self::NAMESPACE,
			'/media/intro',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'upload_intro' ],
				'permission_callback' => [ $this, 'check_token_permission' ],
			]
		);

		// POST /media/outro
		register_rest_route(
			self::NAMESPACE,
			'/media/outro',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'upload_outro' ],
				'permission_callback' => [ $this, 'check_token_permission' ],
			]
		);

		// POST /media/transcript
		register_rest_route(
			self::NAMESPACE,
			'/media/transcript',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'upload_transcript' ],
				'permission_callback' => [ $this, 'check_token_permission' ],
			]
		);

		// POST /settings/intro-outro  (backward compat — delega en update_settings)
		register_rest_route(
			self::NAMESPACE,
			'/settings/intro-outro',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'update_intro_outro_settings' ],
				'permission_callback' => [ $this, 'check_token_permission' ],
			]
		);

		// GET /settings
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_settings' ],
				'permission_callback' => [ $this, 'check_token_permission' ],
			]
		);

		// POST /settings
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'update_settings' ],
				'permission_callback' => [ $this, 'check_token_permission' ],
			]
		);

		// GET /episode/next-number
		register_rest_route(
			self::NAMESPACE,
			'/episode/next-number',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_next_episode_number' ],
				'permission_callback' => [ $this, 'check_token_permission' ],
				'args'                => [
					'feed' => [
						'required'          => false,
						'type'              => 'string',
						'default'           => 'podcast',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// POST /episode
		register_rest_route(
			self::NAMESPACE,
			'/episode',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_episode' ],
				'permission_callback' => [ $this, 'check_token_permission' ],
				'args'                => [
					'title'           => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'content'         => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'wp_kses_post',
					],
					'excerpt'         => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
					'featured_media'  => [
						'required' => false,
						'type'     => 'integer',
						'default'  => 0,
					],
					'podcast_audio_id' => [
						'required' => true,
						'type'     => 'integer',
					],
					'telegram_id'     => [
						'required' => false,
						'type'     => 'integer',
						'default'  => 0,
					],
					'status'          => [
						'required' => false,
						'type'     => 'string',
						'default'  => 'draft',
						'enum'     => [ 'draft', 'publish', 'pending' ],
					],
					'feed_slug'            => [
						'required'          => false,
						'type'              => 'string',
						'default'           => 'podcast',
						'sanitize_callback' => 'sanitize_key',
					],
					'category_slug'        => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'transcript_url'       => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'esc_url_raw',
					],
					'title_prefix'         => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'title_include_season' => [
						'required' => false,
						'type'     => 'integer',
						'default'  => 0,
					],
				],
			]
		);

		// POST /season/increment — incrementa temporada manualmente
		register_rest_route(
			self::NAMESPACE,
			'/season/increment',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'season_increment' ],
				'permission_callback' => [ $this, 'check_token_permission' ],
			]
		);

		// POST /post — crea un post de blog sin metadatos de podcast
		register_rest_route(
			self::NAMESPACE,
			'/post',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_post_callback' ],
				'permission_callback' => [ $this, 'check_token_permission' ],
				'args'                => [
					'title'          => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'content'        => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'wp_kses_post',
					],
					'excerpt'        => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
					'featured_media' => [
						'required' => false,
						'type'     => 'integer',
						'default'  => 0,
					],
					'status'         => [
						'required' => false,
						'type'     => 'string',
						'default'  => 'draft',
						'enum'     => [ 'draft', 'publish', 'pending' ],
					],
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Permission callback compartido para endpoints protegidos
	// -------------------------------------------------------------------------

	public function check_token_permission(): bool {
		// determine_current_user (registrado en VPConn_Auth) ya ha autenticado al usuario
		// a partir del header X-VozPress-Token antes de que llegue aquí.
		$user_id = get_current_user_id();
		return $user_id > 0 && VPConn_Auth::is_user_allowed( $user_id );
	}

	// -------------------------------------------------------------------------
	// Callbacks
	// -------------------------------------------------------------------------

	/**
	 * GET /feeds
	 *
	 * Devuelve los feeds de PowerPress accesibles para el usuario autenticado
	 * y su nivel de permiso (publish / draft).
	 */
	public function get_feeds(): WP_REST_Response {
		$user_id     = get_current_user_id();
		$all_feeds   = VPConn_Settings::get_powerpress_feeds();
		$permissions = VPConn_Settings::get_user_feed_permissions( $user_id );

		if ( empty( $permissions ) ) {
			// Sin permisos específicos: backward compat — acceso a todo con publicar.
			$feeds = array_map( fn( $f ) => array_merge( $f, [ 'can_publish' => true ] ), $all_feeds );
		} else {
			$feeds = [];
			foreach ( $all_feeds as $feed ) {
				$slug = $feed['slug'];
				if ( isset( $permissions[ $slug ] ) ) {
					$feeds[] = [
						'slug'          => $slug,
						'name'          => $feed['name'],
						'category_slug' => $feed['category_slug'] ?? null,
						'can_publish'   => $permissions[ $slug ] === 'publish',
					];
				}
			}
			// Garantizar al menos el feed por defecto si el usuario no tiene ninguno.
			if ( empty( $feeds ) ) {
				$default = $all_feeds[0] ?? [ 'slug' => 'podcast', 'name' => 'Podcast', 'category_slug' => 'podcast' ];
				$feeds[] = array_merge( $default, [ 'can_publish' => true ] );
			}
		}

		$response = new WP_REST_Response( [ 'feeds' => $feeds ] );
		$response->header( 'Cache-Control', 'no-store, no-cache' );
		return $response;
	}

	/**
	 * GET /ping
	 */
	public function ping(): WP_REST_Response {
		return new WP_REST_Response(
			[
				'status'      => 'ok',
				'version'     => VPCONN_VERSION,
				'powerpress'  => $this->is_powerpress_active(),
			]
		);
	}

	/**
	 * GET /auth/iniciar?state=STATE&telegram_id=TID
	 * Guarda el state y redirige al login de WordPress.
	 * El navegador del usuario sigue la redirección; tras el login vuelve al callback.
	 */
	public function auth_iniciar( WP_REST_Request $request ): void {
		$state       = $request->get_param( 'state' );
		$telegram_id = (int) $request->get_param( 'telegram_id' );
		VPConn_Auth::initiate( $state, $telegram_id );
		// initiate() llama a wp_redirect() + exit; nunca llega aquí.
	}

	/**
	 * GET /auth/estado?state=STATE
	 * Polling: devuelve {status:"pending"} hasta que el usuario complete el login,
	 * luego {status:"ok", token:"...", wp_username:"..."} una sola vez.
	 */
	public function auth_estado( WP_REST_Request $request ): WP_REST_Response {
		$state  = $request->get_param( 'state' );
		$result = get_transient( 'vpconn_auth_result_' . $state );

		if ( ! $result ) {
			$response = new WP_REST_Response( [ 'status' => 'pending' ], 200 );
		} else {
			// Consumir el transient — solo se puede recoger una vez.
			delete_transient( 'vpconn_auth_result_' . $state );
			$response = new WP_REST_Response(
				[
					'status'      => 'ok',
					'token'       => $result['token'],
					'wp_username' => $result['wp_username'],
				],
				200
			);
		}

		// Evitar que LiteSpeed Cache u otros proxies cacheen esta respuesta.
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	/**
	 * GET /media/intro-outro
	 *
	 * Devuelve el estado de los archivos de intro/outro y la configuración de mezcla
	 * definida en Ajustes → VozPress Connector.
	 */
	public function media_intro_outro_status(): WP_REST_Response {
		$fade_end_raw  = get_option( 'vpconn_intro_fade_end', '' );
		$knee_time_raw = get_option( 'vpconn_intro_knee_time', '' );
		$response      = new WP_REST_Response(
			[
				'intro'  => VPConn_Media::get_intro_outro_info( 'intro' ),
				'outro'  => VPConn_Media::get_intro_outro_info( 'outro' ),
				'config' => [
					'intro_duck_start'  => (float) get_option( 'vpconn_intro_duck_start',  20 ),
					'intro_duck_volume' => (float) get_option( 'vpconn_intro_duck_volume', 30 ) / 100,
					'intro_fade_end'    => '' !== $fade_end_raw  ? (float) $fade_end_raw  : null,
					'intro_knee_time'   => '' !== $knee_time_raw ? (float) $knee_time_raw : null,
					'outro_fade_start'  => (float) get_option( 'vpconn_outro_fade_start',  17 ),
					'outro_duck_volume' => (float) get_option( 'vpconn_outro_duck_volume', 35 ) / 100,
				],
			]
		);
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	/**
	 * POST /settings/intro-outro
	 *
	 * Permite al bot actualizar la configuración de mezcla.
	 * Acepta los mismos campos que la página de ajustes.
	 */
	public function update_intro_outro_settings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'invalid_body', 'JSON body requerido.', [ 'status' => 400 ] );
		}

		if ( isset( $body['intro_duck_start'] ) ) {
			update_option( 'vpconn_intro_duck_start', max( 0, (float) $body['intro_duck_start'] ) );
		}
		if ( isset( $body['intro_duck_volume'] ) ) {
			// El bot envía fracción (0.30), la BD guarda porcentaje (30)
			$pct = (float) $body['intro_duck_volume'];
			if ( $pct <= 1 ) {
				$pct *= 100;
			}
			update_option( 'vpconn_intro_duck_volume', min( 100, max( 1, $pct ) ) );
		}
		if ( array_key_exists( 'intro_fade_end', $body ) ) {
			if ( is_null( $body['intro_fade_end'] ) ) {
				delete_option( 'vpconn_intro_fade_end' );
			} else {
				update_option( 'vpconn_intro_fade_end', (float) $body['intro_fade_end'] );
			}
		}
		if ( array_key_exists( 'intro_knee_time', $body ) ) {
			if ( is_null( $body['intro_knee_time'] ) ) {
				delete_option( 'vpconn_intro_knee_time' );
			} else {
				update_option( 'vpconn_intro_knee_time', max( 0, (float) $body['intro_knee_time'] ) );
			}
		}
		if ( isset( $body['outro_fade_start'] ) ) {
			update_option( 'vpconn_outro_fade_start', max( 1, (float) $body['outro_fade_start'] ) );
		}
		if ( isset( $body['outro_duck_volume'] ) ) {
			$pct = (float) $body['outro_duck_volume'];
			if ( $pct <= 1 ) {
				$pct *= 100;
			}
			update_option( 'vpconn_outro_duck_volume', min( 100, max( 1, $pct ) ) );
		}

		// Devolver la configuración actualizada
		$fade_end_raw  = get_option( 'vpconn_intro_fade_end', '' );
		$knee_time_raw = get_option( 'vpconn_intro_knee_time', '' );
		return new WP_REST_Response( [
			'updated' => true,
			'config'  => [
				'intro_duck_start'  => (float) get_option( 'vpconn_intro_duck_start',  20 ),
				'intro_duck_volume' => (float) get_option( 'vpconn_intro_duck_volume', 30 ) / 100,
				'intro_fade_end'    => '' !== $fade_end_raw  ? (float) $fade_end_raw  : null,
				'intro_knee_time'   => '' !== $knee_time_raw ? (float) $knee_time_raw : null,
				'outro_fade_start'  => (float) get_option( 'vpconn_outro_fade_start',  17 ),
				'outro_duck_volume' => (float) get_option( 'vpconn_outro_duck_volume', 35 ) / 100,
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Helpers para settings (usados por get_settings, update_settings y
	// la respuesta de update_intro_outro_settings)
	// -------------------------------------------------------------------------

	private function _read_all_settings(): array {
		$fade_end_raw  = get_option( 'vpconn_intro_fade_end', '' );
		$knee_time_raw = get_option( 'vpconn_intro_knee_time', '' );
		return [
			'title_prefix'            => (string) get_option( 'vpconn_title_prefix', '' ),
			'title_include_season'    => (bool) get_option( 'vpconn_title_include_season', false ),
			'episode_numbering_mode'  => (string) get_option( 'vpconn_episode_numbering_mode', 'enclosure' ),
			'intro_duck_start'        => (float) get_option( 'vpconn_intro_duck_start',  20 ),
			'intro_duck_volume'       => (float) get_option( 'vpconn_intro_duck_volume', 30 ) / 100,
			'intro_fade_end'          => '' !== $fade_end_raw  ? (float) $fade_end_raw  : null,
			'intro_knee_time'         => '' !== $knee_time_raw ? (float) $knee_time_raw : null,
			'outro_fade_start'        => (float) get_option( 'vpconn_outro_fade_start',  17 ),
			'outro_duck_volume'       => (float) get_option( 'vpconn_outro_duck_volume', 35 ) / 100,
			'post_footer'             => (string) get_option( 'vpconn_post_footer', '' ),
		];
	}

	/**
	 * GET /settings
	 */
	public function get_settings(): WP_REST_Response {
		return new WP_REST_Response( $this->_read_all_settings() );
	}

	/**
	 * POST /settings
	 *
	 * Actualiza cualquier subconjunto de ajustes del plugin.
	 * Acepta: title_prefix, title_include_season y todos los parámetros de mezcla.
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'invalid_body', 'JSON body requerido.', [ 'status' => 400 ] );
		}

		if ( array_key_exists( 'title_prefix', $body ) ) {
			$prefix = sanitize_text_field( (string) $body['title_prefix'] );
			update_option( 'vpconn_title_prefix', $prefix );
		}
		if ( array_key_exists( 'title_include_season', $body ) ) {
			update_option( 'vpconn_title_include_season', (bool) $body['title_include_season'] ? 1 : 0 );
		}
		if ( array_key_exists( 'episode_numbering_mode', $body ) ) {
			$mode_val = in_array( $body['episode_numbering_mode'], [ 'enclosure', 'title' ], true )
				? $body['episode_numbering_mode']
				: 'enclosure';
			update_option( 'vpconn_episode_numbering_mode', $mode_val );
		}

		// Reutilizar la lógica de intro/outro
		if ( isset( $body['intro_duck_start'] ) ) {
			update_option( 'vpconn_intro_duck_start', max( 0, (float) $body['intro_duck_start'] ) );
		}
		if ( isset( $body['intro_duck_volume'] ) ) {
			$pct = (float) $body['intro_duck_volume'];
			if ( $pct <= 1 ) {
				$pct *= 100;
			}
			update_option( 'vpconn_intro_duck_volume', min( 100, max( 1, $pct ) ) );
		}
		if ( array_key_exists( 'intro_fade_end', $body ) ) {
			if ( is_null( $body['intro_fade_end'] ) ) {
				delete_option( 'vpconn_intro_fade_end' );
			} else {
				update_option( 'vpconn_intro_fade_end', (float) $body['intro_fade_end'] );
			}
		}
		if ( array_key_exists( 'intro_knee_time', $body ) ) {
			if ( is_null( $body['intro_knee_time'] ) ) {
				delete_option( 'vpconn_intro_knee_time' );
			} else {
				update_option( 'vpconn_intro_knee_time', max( 0, (float) $body['intro_knee_time'] ) );
			}
		}
		if ( isset( $body['outro_fade_start'] ) ) {
			update_option( 'vpconn_outro_fade_start', max( 1, (float) $body['outro_fade_start'] ) );
		}
		if ( isset( $body['outro_duck_volume'] ) ) {
			$pct = (float) $body['outro_duck_volume'];
			if ( $pct <= 1 ) {
				$pct *= 100;
			}
			update_option( 'vpconn_outro_duck_volume', min( 100, max( 1, $pct ) ) );
		}

		if ( isset( $body['post_footer'] ) ) {
			update_option( 'vpconn_post_footer', wp_kses_post( (string) $body['post_footer'] ) );
		}

		return new WP_REST_Response( [ 'updated' => true, 'settings' => $this->_read_all_settings() ] );
	}

	/**
	 * POST /media/audio
	 */
	public function upload_audio( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->handle_file_upload( $request, [ 'audio/mpeg', 'audio/mp3', 'audio/x-m4a', 'audio/ogg', 'audio/wav' ] );
	}

	/**
	 * POST /media/image
	 */
	public function upload_image( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->handle_file_upload( $request, [ 'image/jpeg', 'image/png', 'image/webp' ] );
	}

	/**
	 * POST /media/intro
	 */
	public function upload_intro( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->handle_file_upload( $request, [ 'audio/mpeg', 'audio/mp3', 'audio/x-m4a', 'audio/ogg' ] );
		if ( ! is_wp_error( $result ) ) {
			$data = $result->get_data();
			VPConn_Media::set_intro_outro_id( 'intro', $data['id'] );
		}
		return $result;
	}

	/**
	 * POST /media/outro
	 */
	public function upload_outro( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->handle_file_upload( $request, [ 'audio/mpeg', 'audio/mp3', 'audio/x-m4a', 'audio/ogg' ] );
		if ( ! is_wp_error( $result ) ) {
			$data = $result->get_data();
			VPConn_Media::set_intro_outro_id( 'outro', $data['id'] );
		}
		return $result;
	}

	public function upload_transcript( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->handle_file_upload( $request, [ 'text/vtt', 'text/plain' ] );
	}

	/**
	 * POST /media/audio/chunk
	 *
	 * Recibe un fragmento del archivo de audio. Los chunks se almacenan temporalmente
	 * y se ensamblan en el último fragmento para crear el attachment en la biblioteca.
	 *
	 * Headers requeridos:
	 *   X-VozPress-Upload-ID    — identificador único de la subida (UUID generado por el bot)
	 *   X-VozPress-Chunk-Index  — índice del fragmento (0-based)
	 *   X-VozPress-Chunk-Total  — número total de fragmentos
	 *   X-VozPress-Filename     — nombre del archivo final (ej: episode.mp3)
	 */
	public function upload_audio_chunk( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$upload_id   = sanitize_key( (string) $request->get_header( 'X-VozPress-Upload-ID' ) );
		$chunk_index = (int) $request->get_header( 'X-VozPress-Chunk-Index' );
		$chunk_total = (int) $request->get_header( 'X-VozPress-Chunk-Total' );
		$filename    = sanitize_file_name( (string) $request->get_header( 'X-VozPress-Filename' ) );

		if ( ! $upload_id || $chunk_total < 1 || $chunk_index < 0 || $chunk_index >= $chunk_total ) {
			return new WP_Error( 'invalid_chunk_headers', 'Cabeceras de chunk inválidas.', [ 'status' => 400 ] );
		}
		if ( ! $filename ) {
			$filename = 'audio.mp3';
		}

		$chunk_data = $request->get_body();
		if ( empty( $chunk_data ) ) {
			return new WP_Error( 'empty_chunk', 'El fragmento está vacío.', [ 'status' => 400 ] );
		}

		// Descomprimir si el bot envió el chunk con gzip (para evitar falsos positivos del WAF).
		$content_encoding = strtolower( (string) $request->get_header( 'Content-Encoding' ) );
		if ( $content_encoding === 'gzip' ) {
			$decoded = gzdecode( $chunk_data );
			if ( $decoded === false ) {
				return new WP_Error( 'gzip_decode_error', 'No se pudo descomprimir el fragmento.', [ 'status' => 400 ] );
			}
			$chunk_data = $decoded;
		}

		// Directorio temporal para los chunks de esta subida.
		$upload_dir = wp_upload_dir();
		$chunk_dir  = $upload_dir['basedir'] . '/vozpress-chunks/' . $upload_id;
		wp_mkdir_p( $chunk_dir );

		// Guardar fragmento.
		$chunk_file = $chunk_dir . '/chunk-' . str_pad( $chunk_index, 5, '0', STR_PAD_LEFT ) . '.bin';
		if ( false === file_put_contents( $chunk_file, $chunk_data ) ) {
			return new WP_Error( 'chunk_write_error', 'No se pudo guardar el fragmento.', [ 'status' => 500 ] );
		}

		$received = count( glob( $chunk_dir . '/chunk-*.bin' ) );

		if ( $received < $chunk_total ) {
			return new WP_REST_Response(
				[ 'status' => 'pending', 'upload_id' => $upload_id, 'received' => $received, 'total' => $chunk_total ],
				202
			);
		}

		// Todos los chunks recibidos — ensamblar.
		$assembled_path = $chunk_dir . '/assembled.mp3';
		$out = fopen( $assembled_path, 'wb' );
		if ( ! $out ) {
			return new WP_Error( 'assemble_error', 'No se pudo crear el archivo ensamblado.', [ 'status' => 500 ] );
		}
		for ( $i = 0; $i < $chunk_total; $i++ ) {
			$cf = $chunk_dir . '/chunk-' . str_pad( $i, 5, '0', STR_PAD_LEFT ) . '.bin';
			fwrite( $out, file_get_contents( $cf ) );
			unlink( $cf );
		}
		fclose( $out );

		// Crear attachment en la biblioteca de medios.
		$result = $this->_create_attachment_from_file( $assembled_path, $filename, 'audio/mpeg' );

		// Limpiar directorio temporal.
		@unlink( $assembled_path );
		@rmdir( $chunk_dir );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * Crea un attachment en la biblioteca de medios a partir de un archivo ya en disco.
	 * Devuelve ['id' => int, 'url' => string] o WP_Error.
	 */
	private function _create_attachment_from_file( string $src_path, string $filename, string $mime ): array|WP_Error {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$upload_dir  = wp_upload_dir();
		$dest_path   = $upload_dir['path'] . '/' . wp_unique_filename( $upload_dir['path'], $filename );

		if ( ! rename( $src_path, $dest_path ) ) {
			return new WP_Error( 'move_error', 'No se pudo mover el archivo ensamblado.', [ 'status' => 500 ] );
		}

		$attachment_id = wp_insert_attachment(
			[
				'post_mime_type' => $mime,
				'post_title'     => sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			],
			$dest_path
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		wp_update_attachment_metadata(
			$attachment_id,
			wp_generate_attachment_metadata( $attachment_id, $dest_path )
		);

		return [
			'id'  => $attachment_id,
			'url' => wp_get_attachment_url( $attachment_id ),
		];
	}

	/**
	 * POST /episode
	 *
	 * Crea el episodio de podcast con:
	 * - Categoría "podcast" asignada automáticamente
	 * - Numeración de episodio y temporada calculada automáticamente
	 * - Imagen destacada fallback al cover del podcast si no se proporciona
	 * - Metadatos de PowerPress completos (episodio, temporada, título limpio)
	 */
	/**
	 * GET /episode/next-number
	 *
	 * Returns the episode_number and season_number that the next published episode
	 * would receive, without actually creating anything. Used by the bot to name
	 * the audio file before uploading it.
	 */
	public function get_next_episode_number( WP_REST_Request $request ): WP_REST_Response {
		$feed_slug    = $request->get_param( 'feed' ) ?: 'podcast';
		$episode_info = $this->get_next_episode_info( $feed_slug );
		return new WP_REST_Response( [
			'episode_number'       => $episode_info['episode_number'],
			'episode_no_in_season' => $episode_info['episode_no_in_season'],
			'season_number'        => $episode_info['season_number'],
		] );
	}

	public function create_episode( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$clean_title          = $request->get_param( 'title' );
		$content              = $request->get_param( 'content' );
		$excerpt              = $request->get_param( 'excerpt' );
		$featured_media       = (int) $request->get_param( 'featured_media' );
		$podcast_audio_id     = (int) $request->get_param( 'podcast_audio_id' );
		$telegram_id          = (int) $request->get_param( 'telegram_id' );
		$status               = $request->get_param( 'status' );
		$transcript_url       = $request->get_param( 'transcript_url' );
		$feed_slug            = $request->get_param( 'feed_slug' ) ?: 'podcast';
		// category_slug is the WordPress category to assign to the post.
		// When provided (new bot versions), it is used directly instead of
		// deducing the category from feed_slug. Falls back to feed_slug for
		// backward compatibility with older bot versions.
		$category_slug        = $request->get_param( 'category_slug' ) ?: $feed_slug;
		// Prefijo y temporada se leen de las opciones del plugin (fuente de la verdad).
		$title_prefix         = (string) get_option( 'vpconn_title_prefix', '' );
		$title_include_season = (bool) get_option( 'vpconn_title_include_season', false );

		// El autor es el usuario autenticado via X-VozPress-Token (establecido por determine_current_user).
		$author_id = get_current_user_id();

		// Calcular número de episodio y temporada (por feed).
		$episode_info        = $this->get_next_episode_info( $feed_slug );
		$episode_number      = $episode_info['episode_number'];       // número para el título del post
		$episode_no_powerpress = $episode_info['episode_no_in_season']; // número dentro de la temporada para PowerPress
		$season_number       = $episode_info['season_number'];

		// Construir el título del post según el prefijo configurado.
		if ( ! empty( $title_prefix ) ) {
			if ( $title_include_season && $season_number > 0 ) {
				$post_title = sprintf( '%s T%dE%d: %s', $title_prefix, $season_number, $episode_number, $clean_title );
			} else {
				$post_title = sprintf( '%s %d: %s', $title_prefix, $episode_number, $clean_title );
			}
		} else {
			$post_title = $clean_title;
		}

		$post_data = [
			'post_title'   => $post_title,
			'post_content' => $content,
			'post_excerpt' => $excerpt,
			'post_status'  => $status,
			'post_type'    => 'post',
		];

		if ( $author_id > 0 ) {
			$post_data['post_author'] = $author_id;
		}

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Assign the post to its podcast category.
		// Uses category_slug (exact WordPress category slug sent by the bot)
		// rather than feed_slug, so sites with non-standard category slugs
		// (e.g. Enteratec's 'el-consultorio-de-enteratec') work correctly.
		$this->assign_podcast_category( $post_id, $category_slug );

		// Imagen destacada: primero la subida por el bot, luego el cover del podcast del feed.
		if ( $featured_media > 0 ) {
			set_post_thumbnail( $post_id, $featured_media );
		} else {
			$cover_id = $this->get_podcast_cover_id( $feed_slug );
			if ( $cover_id > 0 ) {
				set_post_thumbnail( $post_id, $cover_id );
			}
		}

		// Asociar audio con PowerPress.
		// episode_title = título limpio (sin "Capítulo N:") → <itunes:title>
		$audio_url = wp_get_attachment_url( $podcast_audio_id );
		if ( $audio_url ) {
			$this->set_powerpress_audio( $post_id, $audio_url, $podcast_audio_id, $clean_title, $episode_no_powerpress, $season_number );
		}

		// Transcripción VTT para PowerPress.
		// Se añade al meta 'powerpress_feed' que PowerPress lee en el metabox.
		if ( ! empty( $transcript_url ) ) {
			$feed_data = get_post_meta( $post_id, 'powerpress_feed', true );
			if ( ! is_array( $feed_data ) ) {
				$feed_data = [];
			}
			$feed_data['pci_transcript']          = 1;
			$feed_data['pci_transcript_url']      = $transcript_url;
			$feed_data['pci_transcript_language'] = 'es';
			update_post_meta( $post_id, 'powerpress_feed', $feed_data );
		}

		// Registrar en el log.
		VPConn_Settings::add_to_log(
			[
				'post_id'    => $post_id,
				'title'      => $post_title,
				'status'     => $status,
				'author'     => $author_id > 0 ? get_userdata( $author_id )->display_name : 'desconocido',
				'published'  => current_time( 'mysql' ),
				'url'        => get_permalink( $post_id ),
			]
		);

		return new WP_REST_Response(
			[
				'id'             => $post_id,
				'url'            => get_permalink( $post_id ),
				'status'         => $status,
				'episode_number' => $episode_number,
				'season_number'  => $season_number,
			],
			201
		);
	}

	/**
	 * POST /post
	 *
	 * Crea un post de blog sin metadatos de PowerPress.
	 * Útil para publicar contenido de blog normal desde el bot.
	 */
	public function create_post_callback( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$title          = $request->get_param( 'title' );
		$content        = $request->get_param( 'content' );
		$excerpt        = $request->get_param( 'excerpt' );
		$featured_media = (int) $request->get_param( 'featured_media' );
		$status         = $request->get_param( 'status' );

		$author_id = get_current_user_id();

		$post_data = [
			'post_title'   => $title,
			'post_content' => $content,
			'post_excerpt' => $excerpt,
			'post_status'  => $status,
			'post_type'    => 'post',
		];

		if ( $author_id > 0 ) {
			$post_data['post_author'] = $author_id;
		}

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Asignar imagen destacada si se ha proporcionado.
		if ( $featured_media > 0 ) {
			set_post_thumbnail( $post_id, $featured_media );
		}

		return new WP_REST_Response(
			[
				'id'     => $post_id,
				'url'    => get_permalink( $post_id ),
				'status' => $status,
			],
			201
		);
	}

	/**
	 * POST /season/increment
	 * Incrementa manualmente el número de temporada en 1.
	 */
	public function season_increment(): WP_REST_Response {
		$manual = (int) get_option( 'vpconn_current_season', 0 );
		if ( $manual > 0 ) {
			$new = $manual + 1;
		} else {
			// Start from whatever the algorithm currently derives for the default feed.
			$info = $this->get_next_episode_info( 'podcast' );
			$new  = (int) $info['season_number'] + 1;
		}
		update_option( 'vpconn_current_season', $new );
		// Legacy option no longer used by the season algorithm — clean it up.
		delete_option( 'vpconn_season_start_year' );
		return new WP_REST_Response( [ 'season' => $new ], 200 );
	}

	// -------------------------------------------------------------------------
	// Helpers de episodio y temporada
	// -------------------------------------------------------------------------

	/**
	 * Calcula el número del próximo episodio y la temporada actual.
	 *
	 * Estrategia de episodio:
	 *   1. Busca el último post con `powerpress_feed[episode]` seteado.
	 *   2. Si no hay ninguno, busca el mayor número en títulos "Capítulo XX:".
	 *   3. Incrementa en 1.
	 *
	 * Estrategia de temporada:
	 *   - La temporada cambia el 1 de septiembre cada año.
	 *   - `vpconn_season_start_year` almacena el año en que empezó la temporada 1.
	 *   - Temporada actual = (año podcast actual - año inicio T1) + 1
	 *   - "Año podcast" = año actual si mes >= 9, si no año actual - 1.
	 *
	 * @return array{episode_number: int, season_number: int}
	 */
	private function get_next_episode_info( string $feed_slug = 'podcast' ): array {
		$mode = (string) get_option( 'vpconn_episode_numbering_mode', 'enclosure' );

		// All published posts in the feed's category that have a non-empty
		// enclosure meta (i.e., real podcast episodes with audio).
		$cat        = get_term_by( 'slug', $feed_slug, 'category' );
		$query_args = [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 500,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => [
				[
					'key'     => 'enclosure',
					'value'   => '',
					'compare' => '!=',
				],
			],
		];
		if ( $cat ) {
			$query_args['cat'] = $cat->term_id;
		}
		$podcast_posts = get_posts( $query_args );
		$most_recent   = $podcast_posts[0] ?? null;

		$season            = null;
		$episode_in_season = null;

		// Step 1: most recent podcast post with season+episode_no in serialized data.
		foreach ( $podcast_posts as $p ) {
			$enclosure = get_post_meta( $p->ID, 'enclosure', true );
			if ( ! $enclosure ) {
				continue;
			}
			$lines = explode( "\n", $enclosure, 4 );
			if ( ! isset( $lines[3] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$extra = @unserialize( $lines[3] );
			if ( ! is_array( $extra ) || empty( $extra['episode_no'] ) ) {
				continue;
			}
			$prev_season       = isset( $extra['season'] ) ? (int) $extra['season'] : 0;
			$episode_in_season = (int) $extra['episode_no'] + 1;
			$season            = $prev_season > 0 ? $prev_season : 1;
			break; // use the most recent one only
		}

		// Step 2: no podcast post has metadata — try to parse first number from
		// the most recent podcast post's title.
		if ( null === $season && $most_recent ) {
			if ( preg_match( '/\b(\d+)\b/', $most_recent->post_title, $m ) ) {
				$season            = 1;
				$episode_in_season = (int) $m[1] + 1;
			}
		}

		// Step 3: no usable info — fall back to count.
		if ( null === $season ) {
			$season            = 1;
			$episode_in_season = count( $podcast_posts ) + 1;
		}

		// Manual override (set via /season/increment or wp-admin). If the user
		// has fixed the current season to a different number, start the
		// in-season counter from 1.
		$manual_season = (int) get_option( 'vpconn_current_season', 0 );
		if ( $manual_season > 0 && $manual_season !== $season ) {
			$season            = $manual_season;
			$episode_in_season = 1;
		}

		// Number that goes in the post title (depends on numbering mode).
		if ( 'title' === $mode ) {
			// Title mode: derive global sequential number from the first
			// number in the most recent podcast post's title.
			$title_number = 1;
			if ( $most_recent && preg_match( '/\b(\d+)\b/', $most_recent->post_title, $m ) ) {
				$title_number = (int) $m[1] + 1;
			} else {
				$title_number = count( $podcast_posts ) + 1;
			}
			$episode_number = $title_number;
		} else {
			// Enclosure mode: title uses the chapter within the season.
			$episode_number = $episode_in_season;
		}

		return [
			'episode_number'       => $episode_number,
			'episode_no_in_season' => $episode_in_season,
			'season_number'        => $season,
		];
	}

	/**
	 * Assigns the post to the WordPress category identified by $category_slug.
	 *
	 * The slug is provided directly by the bot (derived from the /feeds endpoint),
	 * so no guessing or fallback logic is needed here. If the category does not
	 * exist the post is left uncategorised — PowerPress still picks up the episode
	 * via the enclosure meta.
	 *
	 * Never creates categories — that would silently fork PowerPress's
	 * "category podcasting" setup (which uses category IDs, not slugs).
	 */
	private function assign_podcast_category( int $post_id, string $category_slug ): void {
		if ( '' === $category_slug ) {
			return;
		}
		$cat = get_term_by( 'slug', $category_slug, 'category' );
		if ( ! $cat ) {
			return;
		}
		wp_set_post_terms( $post_id, [ $cat->term_id ], 'category', false );
	}

	/**
	 * Devuelve el ID del attachment del cover/artwork del podcast configurado en PowerPress.
	 * Retorna 0 si no se encuentra.
	 */
	private function get_podcast_cover_id( string $feed_slug = 'podcast' ): int {
		// PowerPress guarda la URL del artwork en la opción de canal de cada feed.
		$feed_options = get_option( 'powerpress_feed_' . $feed_slug, [] );
		if ( empty( $feed_options ) || ! is_array( $feed_options ) ) {
			$feed_options = get_option( 'powerpress_feed_podcast', [] );
		}
		if ( empty( $feed_options ) || ! is_array( $feed_options ) ) {
			$feed_options = get_option( 'powerpress_general', [] );
		}

		$image_url = '';
		if ( ! empty( $feed_options['itunes_image'] ) ) {
			$image_url = $feed_options['itunes_image'];
		} elseif ( ! empty( $feed_options['image'] ) ) {
			$image_url = $feed_options['image'];
		}

		if ( ! $image_url ) {
			return 0;
		}

		// Convertir URL a ID de attachment.
		$attachment_id = attachment_url_to_postid( $image_url );
		return (int) $attachment_id;
	}

	// -------------------------------------------------------------------------
	// Helpers de archivo
	// -------------------------------------------------------------------------

	/**
	 * Sube un archivo recibido como raw binary en el cuerpo de la petición.
	 * El nombre de archivo viene en la cabecera Content-Disposition.
	 *
	 * Usa binary upload en lugar de multipart para evitar bloqueos de WAF.
	 *
	 * @param string   $file_key    No usado (mantenido por compatibilidad de firma).
	 * @param string[] $allowed_mime Tipos MIME permitidos.
	 */
	private function handle_file_upload( WP_REST_Request $request, array $allowed_mime ): WP_REST_Response|WP_Error {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Leer el body binario (WordPress lo almacena internamente; php://input ya está consumido).
		$raw = $request->get_body();
		if ( empty( $raw ) ) {
			return new WP_Error( 'no_file', __( 'No file received.', 'connector-for-vozcaster' ), [ 'status' => 400 ] );
		}

		// Obtener nombre de archivo de Content-Disposition o Content-Type.
		$filename  = 'upload';
		$cd_header = (string) $request->get_header( 'content-disposition' );
		if ( preg_match( '/filename[^;=\n]*=[\'""]?([^\'""\n;]+)/', $cd_header, $m ) ) {
			$filename = sanitize_file_name( trim( $m[1] ) );
		}

		// Detectar extensión por Content-Type si el nombre no la tiene.
		$content_type = (string) $request->get_header( 'content-type' );
		if ( ! $content_type ) {
			$content_type = 'application/octet-stream';
		}
		// Normalizar (quitar parámetros como charset).
		$content_type = strtok( $content_type, ';' );
		$mime_map     = [
			'audio/mpeg'  => 'mp3',
			'audio/mp3'   => 'mp3',
			'audio/ogg'   => 'ogg',
			'audio/x-m4a' => 'm4a',
			'audio/wav'   => 'wav',
			'image/jpeg'  => 'jpg',
			'image/png'   => 'png',
			'image/webp'  => 'webp',
		];
		if ( ! pathinfo( $filename, PATHINFO_EXTENSION ) && isset( $mime_map[ $content_type ] ) ) {
			$filename .= '.' . $mime_map[ $content_type ];
		}

		// Verificar tipo MIME permitido.
		if ( ! in_array( $content_type, $allowed_mime, true ) ) {
			return new WP_Error(
				'invalid_mime',
				sprintf( __( 'File type not allowed: %s', 'connector-for-vozcaster' ), esc_html( $content_type ) ),
				[ 'status' => 415 ]
			);
		}

		// Guardar en el directorio de uploads de WordPress vía wp_upload_bits.
		$upload = wp_upload_bits( $filename, null, $raw );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'upload_error', $upload['error'], [ 'status' => 500 ] );
		}

		$attachment_id = wp_insert_attachment(
			[
				'post_mime_type' => $content_type,
				'post_title'     => sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			],
			$upload['file']
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		wp_update_attachment_metadata(
			$attachment_id,
			wp_generate_attachment_metadata( $attachment_id, $upload['file'] )
		);

		return new WP_REST_Response(
			[
				'id'  => $attachment_id,
				'url' => wp_get_attachment_url( $attachment_id ),
			],
			201
		);
	}

	/**
	 * Asocia una URL de audio a un post usando los metadatos de PowerPress.
	 *
	 * PowerPress almacena todos los datos del episodio en el meta 'enclosure'
	 * con este formato de 4 líneas:
	 *   línea 1: URL del audio
	 *   línea 2: tamaño en bytes
	 *   línea 3: tipo MIME
	 *   línea 4: datos adicionales PHP-serializados (episode_no, season, episode_title, etc.)
	 *
	 * @param int    $post_id        ID del post.
	 * @param string $audio_url      URL del archivo de audio.
	 * @param int    $audio_id       ID del attachment de audio.
	 * @param string $episode_title  Título limpio del episodio (sin prefijo de capítulo).
	 * @param int    $episode_number Número de episodio.
	 * @param int    $season_number  Número de temporada.
	 */
	private function set_powerpress_audio(
		int $post_id,
		string $audio_url,
		int $audio_id,
		string $episode_title = '',
		int $episode_number = 0,
		int $season_number = 1
	): void {
		$file_size = 0;
		$file_path = get_attached_file( $audio_id );
		if ( $file_path && file_exists( $file_path ) ) {
			$file_size = filesize( $file_path );
		}

		// Construir el array de datos extra que PowerPress serializa en la línea 4 del enclosure.
		$extra = [
			'duration'     => '',
			'explicit'     => 'no',
			'episode_type' => 'full',
		];

		if ( $episode_number > 0 ) {
			// PowerPress usa 'episode_no' → <itunes:episode> en el RSS feed.
			$extra['episode_no'] = $episode_number;
			// 'episode_no_display' es el texto que aparece en el metabox del editor.
			$extra['episode_no_display'] = $season_number > 0
				? sprintf( 'T%dE%d', $season_number, $episode_number )
				: (string) $episode_number;
		}
		if ( $season_number > 0 ) {
			// PowerPress usa 'season' → <itunes:season> en el RSS feed.
			$extra['season'] = $season_number;
		}
		if ( $episode_title ) {
			// PowerPress usa 'episode_title' → <itunes:title> en el RSS feed.
			$extra['episode_title'] = $episode_title;
		}

		// Formato real de PowerPress: url\nbytes\nmime\nSERIALIZED
		$enclosure_data = $audio_url . "\n" . $file_size . "\naudio/mpeg\n" . serialize( $extra );
		update_post_meta( $post_id, 'enclosure', $enclosure_data );

		// Attachment ID para referencia interna de VozPress.
		update_post_meta( $post_id, '_vpconn_audio_id', $audio_id );
	}

	private function is_powerpress_active(): bool {
		return is_plugin_active( 'powerpress/powerpress.php' );
	}
}
