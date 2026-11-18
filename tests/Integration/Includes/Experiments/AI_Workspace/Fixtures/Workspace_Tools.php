<?php
/**
 * Registers AI Workspace tool abilities in tests.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace\Fixtures
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace\Fixtures;

/**
 * Registers a workspace tool the way the experiment does, without booting it.
 *
 * @since x.x.x
 */
final class Workspace_Tools {

	/**
	 * Registers a tool's ability, replacing any earlier registration.
	 *
	 * Must run on the `wp_abilities_api_init` hook.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Experiments\AI_Workspace\Propose_Drafts|\WordPress\AI\Experiments\AI_Workspace\Propose_Template|\WordPress\AI\Experiments\AI_Workspace\Propose_Post_Changes|\WordPress\AI\Experiments\AI_Workspace\Propose_Site_Identity|\WordPress\AI\Experiments\AI_Workspace\Ask_User $tool The tool.
	 */
	public static function register( $tool ): void {
		$args = $tool->get_ability_args();

		if ( null === $args ) {
			return;
		}

		if ( wp_has_ability( $tool::ABILITY ) ) {
			wp_unregister_ability( $tool::ABILITY );
		}

		wp_register_ability( $tool::ABILITY, $args );
	}
}
