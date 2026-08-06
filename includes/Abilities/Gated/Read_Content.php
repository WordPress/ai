<?php
/**
 * Gated ability: read content.
 *
 * @package WordPress\AI\Abilities\Gated
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Gated;

use WordPress\AI\Abilities\Content\Content as Content_Ability;
use WordPress\AI\Abstracts\Abstract_Gated_Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gates the core/read-content ability.
 *
 * @since x.x.x
 */
final class Read_Content extends Abstract_Gated_Ability {
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
