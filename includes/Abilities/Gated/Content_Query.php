<?php
/**
 * Gated ability: content query, create, update, and delete.
 *
 * @package WordPress\AI\Abilities\Gated
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Gated;

use WordPress\AI\Abilities\Content\Content as Content_Ability;
use WordPress\AI\Abstracts\Abstract_Gated_Ability;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Gates the content abilities: core/content-query, core/content-create,
 * core/content-update, and core/content-delete.
 *
 * @since 1.3.0
 * @since x.x.x Also gates the create, update, and delete abilities.
 */
final class Content_Query extends Abstract_Gated_Ability {
	/**
	 * {@inheritDoc}
	 */
	public function requires_core_object_exposure(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		( new Content_Ability() )->init();
	}
}
