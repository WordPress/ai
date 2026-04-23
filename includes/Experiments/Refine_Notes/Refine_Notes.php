<?php
/**
 * Refine from Notes experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Refine_Notes;

use WordPress\AI\Abilities\Refine_Notes\Refine_Notes as Refine_Notes_Ability;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Experiment_Category;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Refine from Notes experiment.
 *
 * Adds functionality to apply AI-generated refinements based on editorial
 * feedback (Notes) left on individual blocks.
 *
 * @since 0.8.0
 */
class Refine_Notes extends Abstract_Feature {

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'refine-notes';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.8.0
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Refine from Notes', 'ai' ),
			'description' => __( 'Analyze feedback that has been left via Notes and apply edits where needed.', 'ai' ),
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
				'ability_class' => Refine_Notes_Ability::class,
			),
		);
	}

	/**
	 * Enqueues and localizes the block editor script.
	 *
	 * @since 0.8.0
	 */
	public function enqueue_assets(): void {
		Asset_Loader::enqueue_script( 'refine_notes', 'experiments/refine-notes' );

		$post_type        = get_post_type();
		$post_type_object = $post_type ? get_post_type_object( $post_type ) : null;
		$rest_base        = $post_type_object && $post_type_object->rest_base
			? $post_type_object->rest_base
			: null;

		Asset_Loader::localize_script(
			'refine_notes',
			'RefineNotesData',
			array(
				'enabled'   => $this->is_enabled(),
				'rest_base' => $rest_base,
				'admin_url' => admin_url(),
			)
		);
	}
}
