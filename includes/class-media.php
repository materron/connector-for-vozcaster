<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestiona los archivos de intro, outro y sonido de corte en la biblioteca de medios de WordPress.
 */
class VPConn_Media {

	const OPTION_INTRO_ID     = 'vpconn_intro_attachment_id';
	const OPTION_OUTRO_ID     = 'vpconn_outro_attachment_id';
	const OPTION_SEPARATOR_ID = 'vpconn_separator_attachment_id';

	/**
	 * Option that stores the attachment ID of each audio slot.
	 *
	 * @param 'intro'|'outro'|'separator' $type Slot.
	 */
	private static function option_for( string $type ): string {
		if ( 'separator' === $type ) {
			return self::OPTION_SEPARATOR_ID;
		}
		return 'intro' === $type ? self::OPTION_INTRO_ID : self::OPTION_OUTRO_ID;
	}

	/**
	 * Devuelve la información de un archivo intro u outro.
	 *
	 * @param 'intro'|'outro'|'separator' $type Tipo de archivo.
	 * @return array{exists: bool, url?: string, id?: int}
	 */
	/**
	 * Default mix: the intro plays alone for 20 s and drops to 30 %, reaching
	 * it 3 s later (the knee); the outro mirrors it, so its last 20 s play alone.
	 * Music of 20 s or less plays whole. Saved options always win.
	 */
	const MIX_SOLO_S   = 20.0;
	const MIX_KNEE_S   = 3.0;
	const MIX_DUCK_PCT = 30.0;

	/**
	 * Length in seconds of the site's intro or outro, or null if unknown.
	 *
	 * @param 'intro'|'outro' $type Music slot.
	 */
	public static function music_length( string $type ): ?float {
		$id = (int) get_option( self::option_for( $type ), 0 );
		if ( ! $id ) {
			return null;
		}
		$meta = wp_get_attachment_metadata( $id );
		if ( is_array( $meta ) && ! empty( $meta['length'] ) ) {
			return (float) $meta['length'];
		}
		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) ) {
			return null;
		}
		if ( ! function_exists( 'wp_read_audio_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		$read = wp_read_audio_metadata( $file );
		return is_array( $read ) && ! empty( $read['length'] ) ? (float) $read['length'] : null;
	}

	/**
	 * Mixing settings with volumes in percent, as stored and shown in wp-admin.
	 *
	 * @return array{intro_duck_start: float, intro_duck_volume: float, intro_fade_end: ?float, intro_knee_time: ?float, outro_fade_start: float, outro_duck_volume: float}
	 */
	public static function mix_settings(): array {
		$intro_len  = self::music_length( 'intro' );
		$outro_len  = self::music_length( 'outro' );
		$configured = false !== get_option( 'vpconn_intro_duck_start', false );

		$ds = (float) get_option( 'vpconn_intro_duck_start', null !== $intro_len ? min( self::MIX_SOLO_S, $intro_len ) : self::MIX_SOLO_S );

		$knee_raw = get_option( 'vpconn_intro_knee_time', '' );
		if ( '' !== $knee_raw ) {
			$knee = (float) $knee_raw;
		} elseif ( ! $configured && ( null === $intro_len || $intro_len > self::MIX_SOLO_S ) ) {
			$knee = $ds + self::MIX_KNEE_S;
		} else {
			$knee = null;
		}

		$fade_end_raw = get_option( 'vpconn_intro_fade_end', '' );

		return [
			'intro_duck_start'  => $ds,
			'intro_duck_volume' => (float) get_option( 'vpconn_intro_duck_volume', self::MIX_DUCK_PCT ),
			'intro_fade_end'    => '' !== $fade_end_raw ? (float) $fade_end_raw : null,
			'intro_knee_time'   => $knee,
			'outro_fade_start'  => (float) get_option( 'vpconn_outro_fade_start', null !== $outro_len ? max( 0.0, $outro_len - self::MIX_SOLO_S ) : 17.0 ),
			'outro_duck_volume' => (float) get_option( 'vpconn_outro_duck_volume', self::MIX_DUCK_PCT ),
		];
	}

	/**
	 * Mixing settings for the bot: volumes as fractions (0.30).
	 */
	public static function mix_config(): array {
		$mix                      = self::mix_settings();
		$mix['intro_duck_volume'] /= 100;
		$mix['outro_duck_volume'] /= 100;
		return $mix;
	}

	public static function get_intro_outro_info( string $type ): array {
		$option = self::option_for( $type );
		$id     = (int) get_option( $option, 0 );

		if ( ! $id ) {
			return [ 'exists' => false ];
		}

		// Verificar que el attachment sigue existiendo en la biblioteca.
		$url = wp_get_attachment_url( $id );
		if ( ! $url ) {
			delete_option( $option );
			return [ 'exists' => false ];
		}

		return [
			'exists' => true,
			'url'    => $url,
			'id'     => $id,
		];
	}

	/**
	 * Guarda el ID del attachment de intro u outro.
	 *
	 * @param 'intro'|'outro'|'separator' $type Tipo de archivo.
	 * @param int             $attachment_id ID del attachment en la biblioteca.
	 */
	public static function set_intro_outro_id( string $type, int $attachment_id ): void {
		$option = self::option_for( $type );
		update_option( $option, $attachment_id );
	}

	/**
	 * Elimina el registro de intro u outro (no elimina el archivo de la biblioteca).
	 *
	 * @param 'intro'|'outro'|'separator' $type Tipo de archivo.
	 */
	public static function clear_intro_outro( string $type ): void {
		$option = self::option_for( $type );
		delete_option( $option );
	}

	/**
	 * Elimina el attachment de intro u outro de la biblioteca de medios.
	 *
	 * @param 'intro'|'outro'|'separator' $type Tipo de archivo.
	 */
	public static function delete_intro_outro( string $type ): bool {
		$option = self::option_for( $type );
		$id     = (int) get_option( $option, 0 );

		if ( ! $id ) {
			return false;
		}

		$deleted = (bool) wp_delete_attachment( $id, true );
		if ( $deleted ) {
			delete_option( $option );
		}
		return $deleted;
	}
}
