<?php
/**
 * Gated ability: settings get and update.
 *
 * @package WordPress\AI\Abilities\Gated
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Gated;

use WordPress\AI\Abilities\Settings\Settings as Settings_Ability;
use WordPress\AI\Abstracts\Abstract_Gated_Ability;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Gates the settings abilities: core/settings-get and core/settings-update.
 *
 * @since 1.3.0
 * @since x.x.x Also gates the update ability.
 */
final class Settings_Get extends Abstract_Gated_Ability {
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
