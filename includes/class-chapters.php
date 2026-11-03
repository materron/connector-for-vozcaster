<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Episode chapters sent by the bot.
 *
 * They are kept in post meta and shown in three places:
 * - in the post, as a list added when it is displayed (it can be turned off
 *   in VozCaster → Episodes, and it follows the times, not the post text);
 * - in a chapters file (Podcasting 2.0 JSON) in the uploads folder;
 * - in the feed, as <podcast:chapters>, which PowerPress writes from the
 *   episode's "pci_chapters_url".
 */
class VPConn_Chapters {

	const META          = '_vpconn_chapters';
	const OPTION_SHOW   = 'vpconn_show_chapters';
	const MAX_CHAPTERS  = 50;
	const DIR           = 'vozcaster-chapters';

	public function register_hooks(): void {
		add_filter( 'the_content', [ $this, 'filter_content' ], 15 );
		add_action( 'before_delete_post', [ __CLASS__, 'delete_file' ] );
	}

	/** True when the chapter list is shown in the post (the default). */
	public static function show_in_post(): bool {
		return (bool) get_option( self::OPTION_SHOW, 1 );
	}

	/**
	 * Cleans the bot's chapters: [{start: seconds, title}], sorted, unique
	 * starts, the first one at 0.
	 *
	 * @param mixed $raw Value of the "chapters" request parameter.
	 * @return array<int, array{start:int, title:string}>
	 */
	public static function sanitize( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['start'], $item['title'] ) || ! is_numeric( $item['start'] ) ) {
				continue;
			}
			$title = sanitize_text_field( (string) $item['title'] );
			if ( '' === $title ) {
				continue;
			}
			$out[] = [
				'start' => max( 0, (int) round( (float) $item['start'] ) ),
				'title' => mb_substr( $title, 0, 120 ),
			];
		}
		usort( $out, static fn( $a, $b ) => $a['start'] <=> $b['start'] );
		$clean = [];
		foreach ( $out as $chapter ) {
			if ( $clean && $chapter['start'] <= end( $clean )['start'] ) {
				continue;
			}
			$clean[] = $chapter;
		}
		if ( $clean ) {
			$clean[0]['start'] = 0;
		}
		return array_slice( $clean, 0, self::MAX_CHAPTERS );
	}

	/**
	 * Stores the chapters of a post and writes its chapters file.
	 *
	 * @param array<int, array{start:int, title:string}> $chapters Clean chapters.
	 * @return string URL of the chapters file, or '' if it could not be written.
	 */
	public static function save( int $post_id, array $chapters ): string {
		if ( ! $chapters ) {
			return '';
		}
		update_post_meta( $post_id, self::META, $chapters );

		$json = wp_json_encode(
			[
				'version'  => '1.2.0',
				'chapters' => array_map(
					static fn( $c ) => [
						'startTime' => $c['start'],
						'title'     => $c['title'],
					],
					$chapters
				),
			],
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
		);

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . self::DIR;
		if ( ! WP_Filesystem() || ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		$file = $dir . '/' . $post_id . '.json';
		if ( ! $wp_filesystem->put_contents( $file, (string) $json, FS_CHMOD_FILE ) ) {
			return '';
		}
		return trailingslashit( $uploads['baseurl'] ) . self::DIR . '/' . $post_id . '.json';
	}

	/** Removes the chapters file when its post is deleted for good. */
	public static function delete_file( int $post_id ): void {
		if ( ! get_post_meta( $post_id, self::META, true ) ) {
			return;
		}
		$uploads = wp_upload_dir();
		wp_delete_file( trailingslashit( $uploads['basedir'] ) . self::DIR . '/' . $post_id . '.json' );
	}

	/** "MM:SS", or "H:MM:SS" from one hour on. */
	public static function format_time( int $seconds ): string {
		$h = intdiv( $seconds, 3600 );
		$m = intdiv( $seconds % 3600, 60 );
		$s = $seconds % 60;
		return $h ? sprintf( '%d:%02d:%02d', $h, $m, $s ) : sprintf( '%02d:%02d', $m, $s );
	}

	/**
	 * Adds the chapter list at the end of the post, before the AI notice.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function filter_content( $content ) {
		if ( is_admin() || doing_filter( 'get_the_excerpt' ) || ! self::show_in_post() ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post || 'post' !== $post->post_type ) {
			return $content;
		}
		$chapters = get_post_meta( $post->ID, self::META, true );
		if ( ! is_array( $chapters ) || ! $chapters ) {
			return $content;
		}
		$items = '';
		foreach ( $chapters as $chapter ) {
			$items .= sprintf(
				'<li><span class="vpconn-chapter-time">%s</span> · %s</li>',
				esc_html( self::format_time( (int) $chapter['start'] ) ),
				esc_html( (string) $chapter['title'] )
			);
		}
		return $content
			. '<div class="vpconn-chapters"><h2 class="wp-block-heading">'
			. esc_html__( 'Chapters', 'connector-for-vozcaster' )
			. '</h2><ul class="wp-block-list">' . $items . '</ul></div>';
	}
}
