<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional review before publishing.
 *
 * Roles authorized in VozCaster → Access can publish from the bot even when
 * WordPress does not let them publish posts (e.g. a custom "teachers" role).
 * With review turned on, what those users publish is saved as "Pending
 * review" instead, and the site administrator gets an email to approve it.
 * Users who can publish posts in WordPress (Author and above) are not
 * affected.
 */
class VPConn_Review {

	const OPTION = 'vpconn_review_mode';

	public static function is_enabled(): bool {
		return (bool) get_option( self::OPTION, 0 );
	}

	public static function set_enabled( bool $enabled ): void {
		update_option( self::OPTION, $enabled ? 1 : 0 );
	}

	/**
	 * Status the post is really saved with, for the current user.
	 */
	public static function effective_status( string $requested ): string {
		if ( 'publish' === $requested && self::is_enabled() && ! current_user_can( 'publish_posts' ) ) {
			return 'pending';
		}
		return $requested;
	}

	/**
	 * Emails the site administrator that a post from the bot awaits review.
	 */
	public static function notify( int $post_id ): void {
		$post   = get_post( $post_id );
		$author = $post ? get_userdata( (int) $post->post_author ) : false;
		if ( ! $post ) {
			return;
		}
		$subject = sprintf(
			/* translators: 1: site name, 2: post title. */
			__( '[%1$s] Podcast episode pending review: %2$s', 'connector-for-vozcaster' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			$post->post_title
		);
		$body = sprintf(
			/* translators: 1: author name, 2: post title, 3: link to edit the post. */
			__( "%1\$s has sent a new episode from the VozCaster bot and it is waiting for your review:\n\n%2\$s\n\nReview and publish it here:\n%3\$s", 'connector-for-vozcaster' ),
			$author ? $author->display_name : __( 'A user', 'connector-for-vozcaster' ),
			$post->post_title,
			admin_url( 'post.php?post=' . $post_id . '&action=edit' )
		);
		wp_mail( (string) get_option( 'admin_email' ), $subject, $body );
	}
}
