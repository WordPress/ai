<?php
/**
 * Gated ability: post utility abilities.
 *
 * @package WordPress\AI\Abilities\Gated
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Gated;

use WordPress\AI\Abilities\Utilities\Posts;
use WordPress\AI\Abstracts\Abstract_Gated_Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gates the ai/get-post-details and ai/get-post-terms abilities.
 *
 * @since x.x.x
 */
final class Post_Utilities extends Abstract_Gated_Ability {
	/**
	 * {@inheritDoc}
	 */
	public static function get_key(): string {
		return 'post_utilities';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Post details & terms', 'ai' ),
			'description' => __( 'Exposes the ai/get-post-details and ai/get-post-terms abilities.', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		( new Posts() )->register();
	}
}
