<?php
/**
 * Content summarization experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Summarization;

use WordPress\AI\Abilities\Summarization\Summarization as Summarization_Ability;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Experiment_Category;

use function WordPress\AI\get_min_content_length;
use function WordPress\AI\post_type_supports_bulk_action;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content summarization experiment.
 *
 * @since 0.2.0
 */
class Summarization extends Abstract_Feature {

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'summarization';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Content Summarization', 'ai' ),
			'description' => __( 'Summarizes long-form content into digestible overviews. Requires an AI connector that includes support for text generation models.', 'ai' ),
			'category'    => Experiment_Category::EDITOR,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		$this->register_post_meta();
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_assets' ), 5 );
		add_action( 'enqueue_block_assets', array( $this, 'enqueue_block_assets' ) );

		add_action( 'load-edit.php', array( $this, 'register_bulk_action_hooks_for_screen' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_bulk_assets' ) );
	}

	/**
	 * Registers the bulk action hooks for the current post list table screen.
	 *
	 * Hooked to load-edit.php so it only fires on post list tables. Reads the
	 * requested post type from the query string and restricts bulk summarization
	 * to post types exposed via the REST API.
	 *
	 * @since x.x.x
	 */
	public function register_bulk_action_hooks_for_screen(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : 'post';

		if ( ! post_type_supports_bulk_action( $post_type, $this->get_id() ) ) {
			return;
		}

		add_filter( "bulk_actions-edit-{$post_type}", array( $this, 'register_bulk_action' ) );
		add_filter( "handle_bulk_actions-edit-{$post_type}", array( $this, 'handle_bulk_action' ), 10, 3 );
	}

	/**
	 * Register any needed post meta.
	 *
	 * @since 0.3.0
	 */
	public function register_post_meta(): void {
		register_meta(
			'post',
			'ai_generated_summary',
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			)
		);
	}

	/**
	 * Registers any needed abilities.
	 *
	 * @since 0.2.0
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'ai/' . $this->get_id(),
			array(
				'label'         => $this->get_label(),
				'description'   => $this->get_description(),
				'ability_class' => Summarization_Ability::class,
			),
		);
	}

	/**
	 * Enqueues and localizes the block editor script.
	 *
	 * @since 0.3.0
	 */
	public function enqueue_assets(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}

		Asset_Loader::enqueue_script( 'summarization', 'experiments/summarization', array( 'include_core_abilities' => true ) );

		/**
		 * Filters the minimum content length required to enable summarization.
		 *
		 * @since 1.0.0
		 * @deprecated 1.1.0 Use {@see 'wpai_min_content_length'} instead.
		 *
		 * @param int $min_content_length The minimum number of characters required. Default 250.
		 */
		$min_content_length = (int) apply_filters_deprecated(
			'wpai_summarization_min_content_length',
			array( get_min_content_length( 'summarization', 250 ) ),
			'1.1.0',
			'wpai_min_content_length'
		);

		Asset_Loader::localize_script(
			'summarization',
			'SummarizationData',
			array(
				'enabled'          => $this->is_enabled(),
				'minContentLength' => $min_content_length,
			)
		);
	}

	/**
	 * Adds the "Generate Summary" option to the posts list bulk actions menu.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, string> $actions The existing bulk actions.
	 * @return array<string, string> The modified bulk actions.
	 */
	public function register_bulk_action( array $actions ): array {
		if ( ! $this->is_enabled() ) {
			return $actions;
		}

		$actions['wpai_generate_summary'] = __( 'Generate Summary', 'ai' );

		return $actions;
	}

	/**
	 * Handles the "Generate Summary" bulk action by redirecting with selected post IDs.
	 *
	 * The actual generation is performed client-side after the redirect so that slow
	 * AI API calls do not risk hitting PHP's execution time limit.
	 *
	 * @since x.x.x
	 *
	 * @param string    $redirect_url The current redirect URL.
	 * @param string    $doaction     The bulk action being performed.
	 * @param list<int> $post_ids     The list of post IDs to process.
	 * @return string The redirect URL, possibly with bulk summary query args appended.
	 */
	public function handle_bulk_action( string $redirect_url, string $doaction, array $post_ids ): string {
		if ( 'wpai_generate_summary' !== $doaction || ! current_user_can( 'edit_posts' ) ) {
			return $redirect_url;
		}

		// Only keep posts the current user is allowed to edit.
		$editable_ids = array_values(
			array_filter(
				$post_ids,
				static function ( $id ) {
					return current_user_can( 'edit_post', (int) $id );
				}
			)
		);

		if ( empty( $editable_ids ) ) {
			return $redirect_url;
		}

		return add_query_arg(
			array(
				'wpai_bulk_summary' => 1,
				'wpai_post_ids'     => implode( ',', array_map( 'absint', $editable_ids ) ),
			),
			$redirect_url
		);
	}

	/**
	 * Enqueues the bulk summarization script when a bulk action redirect is detected.
	 *
	 * @since x.x.x
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function maybe_enqueue_bulk_assets( string $hook_suffix ): void {
		// Reading query param for script enqueue only.
		if ( 'edit.php' !== $hook_suffix || ! isset( $_GET['wpai_bulk_summary'] ) || ! current_user_can( 'edit_posts' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		// Reading query param for script enqueue only.
		$raw_ids = isset( $_GET['wpai_post_ids'] ) ? sanitize_text_field( wp_unslash( $_GET['wpai_post_ids'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ids     = array_values( array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) ) );

		if ( empty( $ids ) ) {
			return;
		}

		// Resolve the REST base once all posts in a list table share the same post type.
		$post_type     = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : 'post'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_type_obj = get_post_type_object( $post_type );
		$rest_base     = $post_type_obj && $post_type_obj->rest_base ? (string) $post_type_obj->rest_base : 'posts';

		Asset_Loader::enqueue_script( 'summarization_bulk', 'experiments/summarization-bulk', array( 'include_core_abilities' => true ) );
		Asset_Loader::localize_script(
			'summarization_bulk',
			'SummarizationBulkData',
			array(
				'postIds'  => $ids,
				'restBase' => $rest_base,
			)
		);
	}

	/**
	 * Enqueues the block stylesheet for the editor iframe and the front end.
	 *
	 * @since 0.9.0
	 */
	public function enqueue_block_assets(): void {
		Asset_Loader::enqueue_style( 'summarization', 'experiments/summarization' );
	}
}
