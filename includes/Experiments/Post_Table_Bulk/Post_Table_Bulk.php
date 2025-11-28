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

use function admin_url;

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
	 * Tracks whether bulk and quick mount points have been rendered.
	 *
	 * @since x.x.x
	 *
	 * @var bool
	 */
	private bool $bulk_mount_rendered = false;

	/**
	 * Tracks whether the quick edit mount point has been rendered.
	 *
	 * @since x.x.x
	 *
	 * @var bool
	 */
	private bool $quick_mount_rendered = false;
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
			$this->get_taxonomy_suggestions_ability_name(),
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

		if ( function_exists( 'wp_script_is' ) && wp_script_is( 'wp-abilities', 'registered' ) ) {
			wp_enqueue_script( 'wp-abilities' );
		}

		Asset_Loader::enqueue_script( 'post_table_bulk', 'experiments/post-table-bulk' );
		Asset_Loader::localize_script(
			'post_table_bulk',
			'PostTableBulkData',
			array(
				'enabled'       => $this->is_enabled(),
				'ability'       => $this->get_taxonomy_suggestions_ability_name(),
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
		if ( $this->bulk_mount_rendered || ! $this->is_enabled() ) {
			return;
		}

		$taxonomies = $this->supported_taxonomies( $post_type );

		if ( empty( $taxonomies ) || ! $this->is_taxonomy_column( $column_name, $taxonomies ) ) {
			return;
		}

		echo '<div class="wp-ai-taxonomy-suggestions" data-mode="bulk" aria-live="polite"></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$this->bulk_mount_rendered = true;
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
		if ( $this->quick_mount_rendered || ! $this->is_enabled() ) {
			return;
		}

		$taxonomies = $this->supported_taxonomies( $post_type );

		if ( empty( $taxonomies ) || ! $this->is_taxonomy_column( $column_name, $taxonomies ) ) {
			return;
		}

		echo '<div class="wp-ai-taxonomy-suggestions" data-mode="quick" aria-live="polite"></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$this->quick_mount_rendered = true;
	}

	/**
	 * Determines whether the provided column represents a supported taxonomy field.
	 *
	 * WordPress renders taxonomy columns as either `categories`, `tags`, the taxonomy slug,
	 * or the `taxonomy-{$slug}` format. Support each style so the assistant works no matter
	 * which taxonomy column (categories, tags, or a custom taxonomy) triggers the hook.
	 *
	 * @since x.x.x
	 *
	 * @param string $column_name        Column identifier.
	 * @param array  $supported_taxonomies Supported taxonomies keyed by slug.
	 * @return bool
	 */
	private function is_taxonomy_column( string $column_name, array $supported_taxonomies ): bool {
		if ( empty( $column_name ) ) {
			return false;
		}

		$normalized = $column_name;

		if ( 0 === strpos( $normalized, 'taxonomy-' ) ) {
			$normalized = substr( $normalized, strlen( 'taxonomy-' ) );
		}

		$aliases = array(
			'categories' => 'category',
			'category'   => 'category',
			'tags'       => 'post_tag',
		);

		if ( isset( $aliases[ $normalized ] ) ) {
			$normalized = $aliases[ $normalized ];
		}

		return isset( $supported_taxonomies[ $normalized ] );
	}

	/**
	 * Returns the ability name used for taxonomy suggestions.
	 *
	 * @since x.x.x
	 *
	 * @return string
	 */
	private function get_taxonomy_suggestions_ability_name(): string {
		return 'ai-post-table-bulk/taxonomy-suggestions';
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

	/**
	 * {@inheritDoc}
	 */
	public function get_entry_points(): array {
		return array(
			array(
				'label' => __( 'Try', 'ai' ),
				'url'   => admin_url( 'edit.php' ),
				'type'  => 'try',
			),
		);
	}
}
