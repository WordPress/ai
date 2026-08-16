<?php
/**
 * Gated ability: edit content.
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
 * Gates the core/edit-content ability.
 *
 * Kept separate from Read_Content so write access is its own opt-out unit: removing
 * this class through the `wpai_gated_abilities` filter disables content writes
 * without affecting read access.
 *
 * @since x.x.x
 */
final class Edit_Content extends Abstract_Gated_Ability {
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
		( new Content_Ability() )->init_edit();
	}
}
