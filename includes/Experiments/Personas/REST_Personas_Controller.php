<?php
/**
 * REST controller for the personas post type.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Personas;

use WP_Post;
use WP_REST_Posts_Controller;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for the personas post type.
 *
 * The core posts controller treats any published post as readable once its
 * post type is exposed to REST, regardless of the post type being non-public.
 * Persona text is prompt material rather than public content, so reads are
 * gated on `read_post`, the same way core protects `wp_block` through
 * `WP_REST_Blocks_Controller`.
 *
 * @since x.x.x
 */
class REST_Personas_Controller extends WP_REST_Posts_Controller {

	/**
	 * Checks if a persona can be read.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool Whether the persona can be read.
	 */
	public function check_read_permission( $post ): bool {
		if ( ! $post instanceof WP_Post || ! current_user_can( 'read_post', $post->ID ) ) {
			return false;
		}

		return (bool) parent::check_read_permission( $post );
	}
}
