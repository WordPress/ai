<?php
/**
 * Revision change summary experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Revision_Change_Summary;

use WordPress\AI\Abilities\Revision_Change_Summary\Revision_Change_Summary as Revision_Change_Summary_Ability;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Experiment_Category;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Revision change summary experiment.
 *
 * Adds a "Summarize changes" action to the visual revisions screen in the
 * editor and to the classic revisions screen.
 *
 * @since x.x.x
 */
class Revision_Change_Summary extends Abstract_Feature {

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'revision-change-summary';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Revision Change Summary', 'ai' ),
			'description' => __( 'Summarizes what changed between two revisions of a post in plain language. Requires an AI connector that includes support for text generation models.', 'ai' ),
			'category'    => Experiment_Category::EDITOR,
			'usage'       => array(
				'trigger'  => 'on_demand',
				'runs'     => __( 'When you click Summarize changes on a revisions screen', 'ai' ),
				'sends'    => __( 'The changed lines of the title and content between the two revisions', 'ai' ),
				'requests' => __( 'One request per click', 'ai' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function register_feature(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_classic_assets' ) );
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
				'ability_class' => Revision_Change_Summary_Ability::class,
			),
		);
	}

	/**
	 * Enqueues the script for the visual revisions screen in the block editor.
	 *
	 * @since x.x.x
	 */
	public function enqueue_assets(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base || ! Revision_Change_Summary_Ability::post_type_is_supported( (string) $screen->post_type ) ) {
			return;
		}

		// Existing revisions can still be shown when revisions are turned off for the post.
		$post = get_post();
		if ( $post && ! wp_revisions_enabled( $post ) ) {
			return;
		}

		Asset_Loader::enqueue_script( 'revision_change_summary', 'experiments/revision-change-summary', array( 'include_core_abilities' => true ) );
		Asset_Loader::enqueue_style( 'revision_change_summary', 'experiments/revision-change-summary' );

		Asset_Loader::localize_script(
			'revision_change_summary',
			'RevisionChangeSummaryData',
			array(
				'enabled' => $this->is_enabled(),
			)
		);
	}

	/**
	 * Enqueues the script for the classic revisions screen.
	 *
	 * @since x.x.x
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function maybe_enqueue_classic_assets( string $hook_suffix ): void {
		if ( 'revision.php' !== $hook_suffix ) {
			return;
		}

		// The revisions screen loads its post into the global `$post`.
		$post = get_post();
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) || ! Revision_Change_Summary_Ability::post_type_is_supported( $post->post_type ) || ! wp_revisions_enabled( $post ) ) {
			return;
		}

		Asset_Loader::enqueue_script( 'revision_change_summary_classic', 'experiments/revision-change-summary-classic', array( 'include_core_abilities' => true ) );
		Asset_Loader::enqueue_style( 'revision_change_summary_classic', 'experiments/revision-change-summary-classic' );

		Asset_Loader::localize_script(
			'revision_change_summary_classic',
			'RevisionChangeSummaryData',
			array(
				'enabled' => $this->is_enabled(),
				'postId'  => $post->ID,
			)
		);
	}
}
