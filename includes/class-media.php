<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestiona los archivos de intro y outro en la biblioteca de medios de WordPress.
 */
class VPConn_Media {

	const OPTION_INTRO_ID = 'vpconn_intro_attachment_id';
	const OPTION_OUTRO_ID = 'vpconn_outro_attachment_id';

	/**
	 * Devuelve la información de un archivo intro u outro.
	 *
	 * @param 'intro'|'outro' $type Tipo de archivo.
	 * @return array{exists: bool, url?: string, id?: int}
	 */
	public static function get_intro_outro_info( string $type ): array {
		$option = 'intro' === $type ? self::OPTION_INTRO_ID : self::OPTION_OUTRO_ID;
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
	 * @param 'intro'|'outro' $type Tipo de archivo.
	 * @param int             $attachment_id ID del attachment en la biblioteca.
	 */
	public static function set_intro_outro_id( string $type, int $attachment_id ): void {
		$option = 'intro' === $type ? self::OPTION_INTRO_ID : self::OPTION_OUTRO_ID;
		update_option( $option, $attachment_id );
	}

	/**
	 * Elimina el registro de intro u outro (no elimina el archivo de la biblioteca).
	 *
	 * @param 'intro'|'outro' $type Tipo de archivo.
	 */
	public static function clear_intro_outro( string $type ): void {
		$option = 'intro' === $type ? self::OPTION_INTRO_ID : self::OPTION_OUTRO_ID;
		delete_option( $option );
	}

	/**
	 * Elimina el attachment de intro u outro de la biblioteca de medios.
	 *
	 * @param 'intro'|'outro' $type Tipo de archivo.
	 */
	public static function delete_intro_outro( string $type ): bool {
		$option = 'intro' === $type ? self::OPTION_INTRO_ID : self::OPTION_OUTRO_ID;
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
