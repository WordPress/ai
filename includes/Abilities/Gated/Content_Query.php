<?php
/**
 * Gated ability: content query.
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
 * Gates the core/content-query ability.
 *
 * @since 1.3.0
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
