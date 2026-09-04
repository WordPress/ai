<?php
/**
 * Semantic Search experiment.
 *
 * Adds embedding-powered semantic search to two surfaces in wp-admin:
 *
 *   1. Posts list (edit.php) — "Search semantically" checkbox replaces keyword
 *      search with cosine-similarity ranked results from indexed embeddings.
 *
 *   2. Command palette (Cmd/Ctrl+K in the block editor) — a live REST call
 *      fetches semantically ranked posts and injects them as clickable commands.
 *
 * Embedding generation goes through the PHP AI Client via Embedding_Generator,
 * so provider selection and credentials are owned by the connector settings.
 * Storage is in Embedding_Store (swap for the VECTOR column once
 * WordPress/ai#683 merges). Everything in this class and the UI layer is permanent.
 *
 * @package WordPress\AI\Experiments\Semantic_Search
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Semantic_Search;

use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Settings\Settings_Registration;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Semantic Search experiment.
 *
 * Registered in Experiments::EXPERIMENT_CLASSES. When enabled, this class wires
 * the posts list checkbox, command palette JS, REST routes, and the indexing
 * admin page. Settings fields are surfaced in the AI plugin's React settings page
 * via get_settings_fields() and persisted through the WordPress settings REST API.
 *
 * @since x.x.x
 */
class Semantic_Search extends Abstract_Feature {

	/**
	 * Returns the unique experiment identifier.
	 *
	 * Used as the slug for option names (wpai_feature_semantic-search_*) and
	 * as the key in the Experiments::EXPERIMENT_CLASSES registry.
	 *
	 * @since x.x.x
	 *
	 * @return string Experiment ID.
	 */
	public static function get_id(): string {
		return 'semantic-search';
	}

	/**
	 * Returns the metadata array used to populate label, description, category,
	 * stability, and capability properties on the Abstract_Feature base class.
	 *
	 * @since x.x.x
	 *
	 * @return array{label:string, description:string, category:string, capability:string} Feature metadata.
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Semantic Search', 'ai' ),
			'description' => __( 'Adds AI-powered semantic search to the posts list and command palette. Uses embedding models to find conceptually related content even when keywords don\'t match.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
			'capability'  => 'embedding_generation',
		);
	}

	/**
	 * Registers all WordPress hooks for the experiment.
	 *
	 * Called by the Features Loader once the experiment is confirmed enabled.
	 * Initialises the posts list integration and indexing admin page, and adds
	 * actions for the command palette JS, REST routes, and save_post reindexing.
	 *
	 * @since x.x.x
	 *
	 * @return void
	 */
	public function register(): void {
		( new List_Table_Integration() )->register();
		( new Index_Page() )->init();

		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_command_palette' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 2 );
	}

	/**
	 * Registers each settings field as a WordPress option with REST API exposure.
	 *
	 * Called by Settings_Registration::register_settings() for every registered
	 * feature. Each option is registered with show_in_rest: true so the React
	 * settings page can read and write values via the WordPress settings REST API
	 * (/wp/v2/settings). Without this flag, saves from the UI are silently dropped.
	 *
	 * @since x.x.x
	 *
	 * @return void
	 */
	public function register_settings(): void {
		foreach ( $this->get_settings_fields() as $field ) {
			$option_name = static::get_field_option_name( $field['id'] );
			register_setting(
				Settings_Registration::OPTION_GROUP,
				$option_name,
				array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => $field['default'] ?? '',
					'show_in_rest'      => true,
				)
			);
		}
	}

	/**
	 * Returns the field definitions rendered by the React settings DataForm.
	 *
	 * IDs use short names (e.g. 'provider'). Abstract_Feature::get_settings_fields_metadata()
	 * expands them to full option names (e.g. 'wpai_feature_semantic-search_field_provider')
	 * before passing them to the settings page script module.
	 *
	 * @since x.x.x
	 *
	 * @return array<int, array{
	 *   id: string,
	 *   label: string,
	 *   type: string,
	 *   default?: string,
	 *   elements?: list<array{value: string, label: string}>,
	 * }> Field definitions for the settings DataForm.
	 */
	public function get_settings_fields(): array {
		return array(
			array(
				'id'      => 'model',
				'label'   => __( 'Model', 'ai' ),
				'type'    => 'text',
				'default' => '',
			),
			array(
				'id'      => 'score_threshold',
				'label'   => __( 'Score Threshold', 'ai' ),
				'type'    => 'text',
				'default' => '',
			),
		);
	}

	/**
	 * Enqueues the command palette JS in the block editor.
	 *
	 * Delegates to Asset_Loader, which resolves the generated asset manifest and
	 * reports a clear error if the build output is missing. The script is only
	 * enqueued when embedding generation is available, to avoid registering a
	 * no-op command loader.
	 *
	 * @since x.x.x
	 *
	 * @return void
	 */
	public function enqueue_command_palette(): void {
		if ( ! ( new Embedding_Generator() )->is_available() ) {
			return;
		}

		$handle = 'semantic_search';

		Asset_Loader::enqueue_script( $handle, 'experiments/semantic-search' );

		Asset_Loader::localize_script(
			$handle,
			'wpaiSemanticSearch',
			array(
				'restUrl' => rest_url( 'ai/v1/semantic-search' ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Delegates REST route registration to REST_Controller.
	 *
	 * Hooked to rest_api_init so routes are only registered when the REST API
	 * is initialised, not on every admin page load.
	 *
	 * @since x.x.x
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		( new REST_Controller() )->register_routes();
	}

	/**
	 * Re-indexes a post immediately when it is published or updated.
	 *
	 * Skips autosaves, revisions, and non-published posts. Runs synchronously
	 * which is acceptable for single-post saves; bulk indexing goes through
	 * POST /ai/v1/semantic-search/index in batches of 5. Does nothing when
	 * embedding generation is unavailable.
	 *
	 * @since x.x.x
	 *
	 * @param int      $post_id Post ID being saved.
	 * @param \WP_Post $post    Post object being saved.
	 * @return void
	 */
	public function on_save_post( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			return;
		}

		if ( ! ( new Embedding_Generator() )->is_available() ) {
			return;
		}

		( new Indexer() )->index_post( $post_id );
	}
}
