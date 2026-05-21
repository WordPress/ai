<?php
/**
 * Editorial Updates experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Editorial_Updates;

use WordPress\AI\Abilities\Editorial_Updates\Editorial_Updates as Editorial_Updates_Ability;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Experiment_Category;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Editorial Updates experiment.
 *
 * Adds functionality to apply refinements based on editorial
 * feedback (Notes) left on individual blocks.
 *
 * @since 0.8.0
 */
class Editorial_Updates extends Abstract_Feature {

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'editorial-updates';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.8.0
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Editorial Updates', 'ai' ),
			'description' => __( 'Applies pending editorial Notes to your content automatically. Requires an AI connector that includes support for text generation models.', 'ai' ),
			'category'    => Experiment_Category::EDITOR,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.8.0
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registers any needed abilities.
	 *
	 * @since 0.8.0
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'ai/' . $this->get_id(),
			array(
				'label'         => $this->get_label(),
				'description'   => $this->get_description(),
				'ability_class' => Editorial_Updates_Ability::class,
			),
		);
	}

	/**
	 * Enqueues and localizes the block editor script.
	 *
	 * @since 0.8.0
	 */
	public function enqueue_assets(): void {
		Asset_Loader::enqueue_script( 'editorial_updates', 'experiments/editorial-updates', array( 'include_core_abilities' => true ) );

		$post_type        = get_post_type();
		$post_type_object = $post_type ? get_post_type_object( $post_type ) : null;
		$rest_base        = $post_type_object && $post_type_object->rest_base
			? $post_type_object->rest_base
			: null;

		Asset_Loader::localize_script(
			'editorial_updates',
			'EditorialUpdatesData',
			array(
				'enabled'   => $this->is_enabled(),
				'rest_base' => $rest_base,
				'admin_url' => admin_url(),
			)
		);
	}
}
