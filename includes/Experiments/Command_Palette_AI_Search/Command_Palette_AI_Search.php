<?php
/**
 * Command palette AI search experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Command_Palette_AI_Search;

use WordPress\AI\Abilities\Command_Palette_AI_Search\Command_Palette_AI_Search as Command_Palette_AI_Search_Ability;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Experiment_Category;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Command palette AI search experiment.
 *
 * @since x.x.x
 */
class Command_Palette_AI_Search extends Abstract_Feature {

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public static function get_id(): string {
		return 'command-palette-ai-search';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Command Palette AI Search', 'ai' ),
			'description' => __( 'Uses AI to suggest admin screens when the command palette (Cmd/Ctrl+K) has no results for a search. Requires an AI connector that includes support for text generation models.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
	}

	/**
	 * Registers any needed abilities.
	 *
	 * @since x.x.x
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'ai/' . $this->get_id(),
			array(
				'label'         => $this->get_label(),
				'description'   => $this->get_description(),
				'ability_class' => Command_Palette_AI_Search_Ability::class,
			),
		);
	}

	/**
	 * Adds the command loader to the command palette.
	 *
	 * @since x.x.x
	 */
	public function enqueue_assets(): void {
		// Only enqueue on screens that have the command palette, for users who can run the ability.
		if ( ! wp_script_is( 'wp-commands', 'enqueued' ) || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		Asset_Loader::enqueue_script( 'command_palette_ai_search', 'experiments/command-palette-ai-search', array( 'include_core_abilities' => true ) );
		Asset_Loader::enqueue_style( 'command_palette_ai_search', 'experiments/command-palette-ai-search', array( 'wp-commands' ) );
	}
}
