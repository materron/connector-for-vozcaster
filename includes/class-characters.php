<?php
/**
 * Cover characters: people (or mascots) of the podcast, each with a name and a
 * face photo. For every episode the creator marks in VozCaster who appears on
 * the cover, and the image model keeps their likeness.
 *
 * @package Connector_For_VozCaster
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores the list of characters in one option and validates it.
 */
class VPConn_Characters {

	/** Option holding the list: [ [ 'id' => string, 'name' => string, 'image_id' => int ], ... ]. */
	const OPTION = 'vpconn_characters';

	/** Most characters a site can have (each photo goes to the image model). */
	const MAX = 6;

	/** Longest name, in characters. */
	const NAME_MAX = 40;

	/**
	 * Stored characters, keeping only those whose photo still exists.
	 *
	 * @return array<int, array{id: string, name: string, image_id: int}>
	 */
	public static function get_all(): array {
		$list = get_option( self::OPTION, [] );
		if ( ! is_array( $list ) ) {
			return [];
		}
		$out = [];
		foreach ( $list as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$image_id = absint( $row['image_id'] ?? 0 );
			$name     = (string) ( $row['name'] ?? '' );
			$id       = (string) ( $row['id'] ?? '' );
			if ( '' === $id || '' === $name || ! $image_id || ! wp_attachment_is_image( $image_id ) ) {
				continue;
			}
			$out[] = [
				'id'       => $id,
				'name'     => $name,
				'image_id' => $image_id,
			];
		}
		return $out;
	}

	/**
	 * Characters as the REST API gives them, with the photo URL.
	 *
	 * @return array<int, array{id: string, name: string, image_id: int, image_url: string}>
	 */
	public static function for_api(): array {
		$out = [];
		foreach ( self::get_all() as $row ) {
			$row['image_url'] = (string) wp_get_attachment_url( $row['image_id'] );
			$out[]            = $row;
		}
		return $out;
	}

	/**
	 * Validates a list sent by VozCaster or by the settings page and saves it.
	 * Rows without a name or without a valid image are an error, not skipped,
	 * so nobody loses a character without noticing.
	 *
	 * @param mixed $list List of rows with name, image_id and, for existing ones, id.
	 * @return true|WP_Error
	 */
	public static function save( $list ) {
		if ( ! is_array( $list ) ) {
			return new WP_Error( 'vpconn_bad_characters', __( 'The list of characters is not valid.', 'connector-for-vozcaster' ), [ 'status' => 400 ] );
		}
		if ( count( $list ) > self::MAX ) {
			return new WP_Error(
				'vpconn_too_many_characters',
				/* translators: %d: maximum number of characters. */
				sprintf( __( 'A podcast can have up to %d characters.', 'connector-for-vozcaster' ), self::MAX ),
				[ 'status' => 400 ]
			);
		}
		$clean = [];
		$ids   = [];
		foreach ( array_values( $list ) as $row ) {
			if ( ! is_array( $row ) ) {
				return new WP_Error( 'vpconn_bad_characters', __( 'The list of characters is not valid.', 'connector-for-vozcaster' ), [ 'status' => 400 ] );
			}
			$name     = self::sanitize_name( (string) ( $row['name'] ?? '' ) );
			$image_id = absint( $row['image_id'] ?? 0 );
			if ( '' === $name ) {
				return new WP_Error( 'vpconn_character_name', __( 'Every character needs a name.', 'connector-for-vozcaster' ), [ 'status' => 400 ] );
			}
			if ( ! $image_id || ! wp_attachment_is_image( $image_id ) ) {
				return new WP_Error(
					'vpconn_character_image',
					/* translators: %s: character name. */
					sprintf( __( '%s needs a photo.', 'connector-for-vozcaster' ), $name ),
					[ 'status' => 400 ]
				);
			}
			$id = sanitize_key( (string) ( $row['id'] ?? '' ) );
			if ( '' === $id || isset( $ids[ $id ] ) ) {
				$id = 'c' . strtolower( wp_generate_password( 8, false, false ) );
			}
			$ids[ $id ] = true;
			$clean[]    = [
				'id'       => $id,
				'name'     => $name,
				'image_id' => $image_id,
			];
		}
		update_option( self::OPTION, $clean, false );
		return true;
	}

	/**
	 * Cleans a character name: one line of plain text.
	 *
	 * @param string $name Raw name.
	 */
	public static function sanitize_name( string $name ): string {
		return trim( mb_substr( sanitize_text_field( $name ), 0, self::NAME_MAX ) );
	}
}
