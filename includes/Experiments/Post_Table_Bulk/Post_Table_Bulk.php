<?php
/**
 * Post table bulk AI experiment.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Post_Table_Bulk;

use WordPress\AI\Abilities\Post_Table_Bulk\Taxonomy_Suggestions;
use WordPress\AI\Abstracts\Abstract_Experiment;
use WordPress\AI\Asset_Loader;

/**
 * Enables AI-powered taxonomy suggestions in the posts list table.
 *
 * Experiment notes:
 * - Surfaces a React assistant (`experiments/post-table-bulk`) inside both Bulk and Quick Edit
 *   panels on `edit.php`, letting editors fetch suggestions via the
 *   `ai/post-table-bulk/taxonomy-suggestions` ability.
 * - The JS populates the existing taxonomy fields (checkboxes/tags input) so core bulk save
 *   continues to run synchronously with no custom REST handler.
 * - Manual test: select several posts → Bulk Edit → click "Suggest categories & tags" →
 *   apply recommendations, then click Update. Repeat with Quick Edit for single-record flows.
 *
 * @since 0.1.0
 */
class Post_Table_Bulk extends Abstract_Experiment {
	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'post-table-bulk',
			'label'       => __( 'Post Table Bulk Actions', 'ai' ),
			'description' => __( 'Suggests categories and tags for posts inside the list table bulk and quick edit UI.', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'bulk_edit_custom_box', array( $this, 'render_bulk_mount_point' ), 10, 2 );
		add_action( 'quick_edit_custom_box', array( $this, 'render_quick_mount_point' ), 10, 2 );
	}

	/**
	 * Register ability with Abilities API.
	 *
	 * @since 0.1.0
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'ai/' . $this->get_id() . '/taxonomy-suggestions',
			array(
				'label'         => $this->get_label(),
				'description'   => __( 'Suggests taxonomy terms for selected posts.', 'ai' ),
				'ability_class' => Taxonomy_Suggestions::class,
			)
		);
	}

	/**
	 * Enqueue experiment assets on the posts list screen.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! $this->is_enabled() || 'edit.php' !== $hook_suffix ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || 'edit' !== $screen->base ) {
			return;
		}

		$taxonomies = $this->supported_taxonomies( $screen->post_type ?? 'post' );

		if ( empty( $taxonomies ) ) {
			return;
		}

		Asset_Loader::enqueue_script( 'post_table_bulk', 'experiments/post-table-bulk' );
		Asset_Loader::localize_script(
			'post_table_bulk',
			'PostTableBulkData',
			array(
				'enabled'       => $this->is_enabled(),
				'ability'       => 'ai/' . $this->get_id() . '/taxonomy-suggestions',
				'postType'      => $screen->post_type,
				'taxonomies'    => array_values( $taxonomies ),
				'maxBatchSize'  => 20,
				'suggestionLimit' => 5,
			)
		);
	}

	/**
	 * Render mount point inside the bulk edit form.
	 *
	 * @since 0.1.0
	 *
	 * @param string $column_name Column identifier.
	 * @param string $post_type   Current post type.
	 */
	public function render_bulk_mount_point( string $column_name, string $post_type ): void {
		if ( 'categories' !== $column_name || ! $this->should_render_mount_point( $post_type ) ) {
			return;
		}

		echo '<div class="wp-ai-taxonomy-suggestions" data-mode="bulk" aria-live="polite"></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Render mount point inside the quick edit form.
	 *
	 * @since 0.1.0
	 *
	 * @param string $column_name Column identifier.
	 * @param string $post_type   Current post type.
	 */
	public function render_quick_mount_point( string $column_name, string $post_type ): void {
		if ( 'categories' !== $column_name || ! $this->should_render_mount_point( $post_type ) ) {
			return;
		}

		echo '<div class="wp-ai-taxonomy-suggestions" data-mode="quick" aria-live="polite"></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Determines whether we should render mount points for the provided post type.
	 *
	 * @since 0.1.0
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	private function should_render_mount_point( string $post_type ): bool {
		return $this->is_enabled() && ! empty( $this->supported_taxonomies( $post_type ) );
	}

	/**
	 * Returns supported taxonomies for the current post type.
	 *
	 * Only categories and tags are surfaced because the default bulk edit UI
	 * only exposes those controls.
	 *
	 * @since 0.1.0
	 *
	 * @param string $post_type Post type.
	 * @return array<string, array<string, mixed>>
	 */
	private function supported_taxonomies( string $post_type ): array {
		$supported = array();
		$candidates = array( 'category', 'post_tag' );

		foreach ( $candidates as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
				continue;
			}

			$tax_object = get_taxonomy( $taxonomy );

			if ( ! $tax_object ) {
				continue;
			}

			$supported[ $taxonomy ] = array(
				'name'         => $taxonomy,
				'label'        => $tax_object->labels->name ?? $taxonomy,
				'hierarchical' => (bool) $tax_object->hierarchical,
			);
		}

		return $supported;
	}
}
