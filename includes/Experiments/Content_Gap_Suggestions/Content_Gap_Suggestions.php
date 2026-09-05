<?php
/**
 * Content Gap Suggestions experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Content_Gap_Suggestions;

use WordPress\AI\Abilities\Content_Gap_Suggestions\Content_Gap_Suggestions as Content_Gap_Suggestions_Ability;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Admin\Dashboard\Content_Opportunities_Widget;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Experiment_Category;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Surfaces new post/draft topic ideas derived from anonymized search patterns.
 *
 * Reads query patterns from whichever Stats_Provider is available (Jetpack
 * Stats in v1), anonymizes them, and asks the AI to suggest post topics and
 * outlines. Suggestions are shown in a dashboard widget and only ever result
 * in a draft post - never publishing - so a human always reviews before
 * anything goes live.
 *
 * @since x.x.x
 */
class Content_Gap_Suggestions extends Abstract_Feature {

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'content-gap-suggestions';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Content Gap Suggestions', 'ai' ),
			'description' => __( 'Surfaces new post topic ideas based on anonymized search patterns from your connected analytics plugin (currently Jetpack Stats). Suggestions only ever create a draft for you to review - nothing is published automatically.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
			'capability'  => 'text_generation',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registers the ability used to generate suggestions.
	 *
	 * @since x.x.x
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'ai/' . $this->get_id(),
			array(
				'label'         => $this->get_label(),
				'description'   => $this->get_description(),
				'ability_class' => Content_Gap_Suggestions_Ability::class,
			),
		);
	}

	/**
	 * Enqueues and localizes the dashboard widget script.
	 *
	 * @since x.x.x
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'index.php' !== $hook_suffix ) {
			return;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		Asset_Loader::enqueue_script( 'content_gap_suggestions', 'experiments/content-gap-suggestions', array( 'include_core_abilities' => true ) );
		Asset_Loader::localize_script(
			'content_gap_suggestions',
			'ContentGapSuggestionsData',
			array(
				'enabled'         => $this->is_enabled(),
				'widgetRoot'      => Content_Opportunities_Widget::ROOT_ID,
				'postEditBaseUrl' => admin_url( 'post.php' ),
			)
		);
	}
}
