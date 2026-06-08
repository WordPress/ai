<?php
/**
 * MariaDB vector RAG search experiment.
 *
 * @package WordPress\AI\Experiments\RAG_Search
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\RAG_Search;

use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\CLI\RAG_Command;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\RAG\Availability;
use WordPress\AI\RAG\Index_Manager;
use WordPress\AI\RAG\REST\RAG_Search_Controller;
use WordPress\AI\RAG\Search_Augmenter;
use WordPress\AI\Settings\Settings_Registration;

defined( 'ABSPATH' ) || exit;

/**
 * Adds semantic RAG search backed by MariaDB vector indexes.
 *
 * @since 1.1.0
 */
class RAG_Search extends Abstract_Feature {
	/**
	 * Default search augmentation setting.
	 */
	private const DEFAULT_AUGMENT_SEARCH = false;

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'rag-search';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'RAG Search', 'ai' ),
			'description' => __( 'Semantic search using OpenAI embeddings and native MariaDB 11.8+ vector indexes.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
			'capability'  => 'embedding_generation',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		$availability = new Availability();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'ai rag', RAG_Command::class );
		}

		if ( ! $availability->is_available() ) {
			add_action( 'admin_notices', array( $this, 'render_unavailable_notice' ) );
			return;
		}

		$index_manager = new Index_Manager( $availability );
		$index_manager->init();

		( new RAG_Search_Controller( $availability ) )->init();

		if ( ! $this->is_search_augmentation_enabled() ) {
			return;
		}

		( new Search_Augmenter() )->init();
	}

	/**
	 * Registers feature settings.
	 *
	 * @since 1.1.0
	 */
	public function register_settings(): void {
		register_setting(
			Settings_Registration::OPTION_GROUP,
			static::get_field_option_name( 'augment_search' ),
			array(
				'type'              => 'boolean',
				'default'           => self::DEFAULT_AUGMENT_SEARCH,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => true,
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return array(
			array(
				'id'      => 'augment_search',
				'label'   => __( 'Augment WordPress search results', 'ai' ),
				'type'    => 'boolean',
				'default' => self::DEFAULT_AUGMENT_SEARCH,
			),
		);
	}

	/**
	 * Returns whether front-end search should be augmented.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when enabled.
	 */
	public function is_search_augmentation_enabled(): bool {
		return (bool) get_option( static::get_field_option_name( 'augment_search' ), self::DEFAULT_AUGMENT_SEARCH );
	}

	/**
	 * Renders an admin notice when requirements are unmet.
	 *
	 * @since 1.1.0
	 */
	public function render_unavailable_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$availability = new Availability();
		$message      = $availability->get_unavailable_reason();

		if ( '' === $message ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: Unavailable reason. */
					__( 'RAG Search is enabled but inactive: %s', 'ai' ),
					$message
				)
			)
		);
	}
}
