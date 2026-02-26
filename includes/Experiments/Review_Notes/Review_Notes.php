<?php
/**
 * AI Review Notes experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Review_Notes;

use WordPress\AI\Abilities\Review_Notes\Review_Notes as Review_Notes_Ability;
use WordPress\AI\Abstracts\Abstract_Experiment;
use WordPress\AI\Asset_Loader;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI Review Notes experiment.
 *
 * Runs a block-by-block AI review pass on post content, creating WordPress Notes
 * with actionable suggestions for Accessibility, Readability, Grammar, and SEO.
 *
 * @since x.x.x
 */
class Review_Notes extends Abstract_Experiment {

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @return array{id: string, label: string, description: string} Experiment metadata.
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'review-notes',
			'label'       => __( 'Review Notes', 'ai' ),
			'description' => __( 'Reviews post content block-by-block and adds Notes with suggestions for Accessibility, Readability, Grammar, and SEO.', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_assets' ) );
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
				'ability_class' => Review_Notes_Ability::class,
			),
		);
	}

	/**
	 * Enqueues and localizes the block editor script.
	 *
	 * @since x.x.x
	 */
	public function enqueue_assets(): void {
		Asset_Loader::enqueue_script( 'review_notes', 'experiments/review-notes' );
		Asset_Loader::localize_script(
			'review_notes',
			'ReviewNotesData',
			array(
				'enabled' => $this->is_enabled(),
			)
		);
	}
}
