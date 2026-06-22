<?php
/**
 * MariaDB vector RAG search experiment.
 *
 * @package WordPress\AI\Experiments\RAG_Search
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\RAG_Search;

use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\CLI\RAG_Command;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\RAG\Availability;
use WordPress\AI\RAG\Index_Manager;
use WordPress\AI\RAG\REST\RAG_Maintenance_Controller;
use WordPress\AI\RAG\REST\RAG_Search_Controller;
use WordPress\AI\RAG\Related_Posts;
use WordPress\AI\RAG\Search_Augmenter;
use WordPress\AI\Settings\Settings_Registration;

defined( 'ABSPATH' ) || exit;

/**
 * Adds semantic RAG search backed by MariaDB vector indexes or compact exact scan.
 *
 * @since 1.1.0
 */
class RAG_Search extends Abstract_Feature {
	/**
	 * Default search augmentation setting.
	 */
	private const DEFAULT_AUGMENT_SEARCH = false;

	/**
	 * Tracks WP-CLI command registration.
	 */
	private static bool $cli_registered = false;

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
			'description' => __( 'Semantic search using OpenAI embeddings with native MariaDB vector indexes or a compact exact-scan fallback.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
			'capability'  => 'embedding_generation',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		$availability = $this->create_availability();

		$this->register_cli_command();

		if ( ! $availability->is_available() ) {
			add_action( 'admin_notices', array( $this, 'render_unavailable_notice' ) );
			return;
		}

		$index_manager = new Index_Manager( $availability );
		$index_manager->init();

		( new RAG_Search_Controller( $availability ) )->init();
		( new Related_Posts() )->init();

		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );

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
		$availability = $this->create_availability();

		$this->register_cli_command();
		( new RAG_Maintenance_Controller( $availability ) )->init();

		register_setting(
			Settings_Registration::OPTION_GROUP,
			Availability::BACKEND_OPTION,
			array(
				'type'              => 'string',
				'default'           => $availability->get_default_index_backend(),
				'sanitize_callback' => array( $this, 'sanitize_backend' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type' => 'string',
						'enum' => array( Availability::BACKEND_MARIADB, Availability::BACKEND_MEMORY ),
					),
				),
			)
		);

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
		$fields = array(
			array(
				'id'      => 'augment_search',
				'label'   => __( 'Augment WordPress search page results with semantic search', 'ai' ),
				'type'    => 'boolean',
				'default' => self::DEFAULT_AUGMENT_SEARCH,
			),
		);

		$availability = $this->create_availability();
		$backends     = $availability->get_available_index_backends();
		if ( count( $backends ) > 1 ) {
			array_unshift(
				$fields,
				array(
					'id'       => 'backend',
					'label'    => __( 'RAG backend', 'ai' ),
					'type'     => 'text',
					'default'  => $availability->get_default_index_backend(),
					'elements' => $this->get_backend_field_elements( $availability, $backends ),
				)
			);
		}

		return $fields;
	}

	/**
	 * Sanitizes the backend setting.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $value Raw backend value.
	 * @return string Sanitized backend.
	 */
	public function sanitize_backend( $value ): string {
		$value        = is_string( $value ) ? $value : '';
		$availability = $this->create_availability();

		if ( in_array( $value, $availability->get_available_index_backends(), true ) ) {
			return $value;
		}

		return $availability->get_default_index_backend();
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
	 * Enqueues the Related Posts Query Loop variation in block editors.
	 *
	 * @since 1.1.0
	 */
	public function enqueue_editor_assets(): void {
		Asset_Loader::enqueue_script( 'rag_search', 'experiments/rag-search' );
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

		$availability = $this->create_availability();
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

	/**
	 * Creates the availability service.
	 *
	 * @since 1.1.0
	 *
	 * @return \WordPress\AI\RAG\Availability Availability service.
	 */
	protected function create_availability(): Availability {
		return new Availability();
	}

	/**
	 * Returns backend selector elements.
	 *
	 * @since 1.1.0
	 *
	 * @param \WordPress\AI\RAG\Availability $availability Availability service.
	 * @param list<string>                    $backends     Available backends.
	 * @return list<array{value: string, label: string}> Backend field elements.
	 */
	private function get_backend_field_elements( Availability $availability, array $backends ): array {
		$labels   = $availability->get_index_backend_labels();
		$elements = array();

		foreach ( $backends as $backend ) {
			if ( ! isset( $labels[ $backend ] ) ) {
				continue;
			}

			$elements[] = array(
				'value' => $backend,
				'label' => $labels[ $backend ],
			);
		}

		return $elements;
	}

	/**
	 * Registers the WP-CLI command once.
	 *
	 * @since 1.1.0
	 */
	private function register_cli_command(): void {
		if ( self::$cli_registered || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		\WP_CLI::add_command( 'ai rag', RAG_Command::class );
		self::$cli_registered = true;
	}
}
