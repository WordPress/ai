<?php
/**
 * Internal Links experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Internal_Links;

use WordPress\AI\Abilities\Internal_Links\Internal_Links as Internal_Links_Ability;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Experiment_Category;

use function WordPress\AI\get_min_content_length;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Internal Links experiment.
 *
 * Uses AI to suggest contextual internal links within a post by analysing
 * the current draft and identifying relevant published posts or pages on
 * the same site. All suggestions require editor review before being applied.
 *
 * @since x.x.x
 */
class Internal_Links extends Abstract_Feature {

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'internal-links';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Internal Link Suggestions', 'ai' ),
			'description' => __( 'Uses AI to suggest relevant internal links within post content, using existing text as anchor text. All suggestions require editor review before being applied. Requires an AI connector that includes support for text generation models.', 'ai' ),
			'category'    => Experiment_Category::EDITOR,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registers the internal links ability.
	 *
	 * @since x.x.x
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'ai/' . $this->get_id(),
			array(
				'label'         => $this->get_label(),
				'description'   => $this->get_description(),
				'ability_class' => Internal_Links_Ability::class,
			)
		);
	}

	/**
	 * Enqueues and localises the block editor script.
	 *
	 * @since x.x.x
	 */
	public function enqueue_assets(): void {
		Asset_Loader::enqueue_script( 'internal_links', 'experiments/internal-links', array( 'include_core_abilities' => true ) );
		Asset_Loader::enqueue_style( 'internal_links', 'experiments/internal-links' );
		Asset_Loader::localize_script(
			'internal_links',
			'InternalLinksData',
			array(
				'enabled'          => $this->is_enabled(),
				'minContentLength' => get_min_content_length( 'internal-links', 100 ),
				'maxSuggestions'   => $this->get_max_suggestions(),
			)
		);
	}

	/**
	 * Returns the configured maximum number of link suggestions.
	 *
	 * Defaults to 5 and can be overridden via the `wpai_internal_links_max_suggestions` filter.
	 *
	 * @since x.x.x
	 *
	 * @return int Maximum number of suggestions (clamped to 1–10).
	 */
	private function get_max_suggestions(): int {
		/**
		 * Filters the maximum number of internal link suggestions returned per request.
		 *
		 * @since x.x.x
		 *
		 * @param int $max Maximum suggestions (default 5, clamped to 1–10).
		 */
		$max = (int) apply_filters( 'wpai_internal_links_max_suggestions', 5 );

		return max( 1, min( 10, $max ) );
	}
}
