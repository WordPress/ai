<?php
/**
 * Gated ability: read settings.
 *
 * @package WordPress\AI\Abilities\Gated
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Gated;

use WordPress\AI\Abilities\Settings\Settings as Settings_Ability;
use WordPress\AI\Abstracts\Abstract_Gated_Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gates the core/read-settings ability.
 *
 * @since x.x.x
 */
final class Read_Settings extends Abstract_Gated_Ability {
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
		( new Settings_Ability() )->init();
	}
}
