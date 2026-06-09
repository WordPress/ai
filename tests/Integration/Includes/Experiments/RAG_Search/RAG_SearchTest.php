<?php
/**
 * Integration tests for the RAG Search experiment.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\RAG_Search
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\RAG_Search;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Experiments\RAG_Search\RAG_Search;
use WordPress\AI\RAG\Availability;

/**
 * RAG Search experiment test case.
 *
 * @since 1.1.0
 */
class RAG_SearchTest extends WP_UnitTestCase {
	/**
	 * Cleans up options.
	 */
	public function tearDown(): void {
		delete_option( Availability::BACKEND_OPTION );
		delete_option( 'wpai_feature_rag-search_field_augment_search' );

		parent::tearDown();
	}

	/**
	 * Tests feature metadata.
	 *
	 * @since 1.1.0
	 */
	public function test_metadata(): void {
		$experiment = new RAG_Search();

		$this->assertSame( 'rag-search', $experiment::get_id() );
		$this->assertSame( Experiment_Category::ADMIN, $experiment->get_category() );
		$this->assertSame( 'embedding_generation', $experiment->get_capability() );
	}

	/**
	 * Tests settings fields.
	 *
	 * @since 1.1.0
	 */
	public function test_get_settings_fields_returns_augment_search_field(): void {
		$experiment = new RAG_Search();
		$fields     = $experiment->get_settings_fields_metadata();
		$field_ids  = array_column( $fields, 'id' );
		$index      = array_search( 'wpai_feature_rag-search_field_augment_search', $field_ids, true );

		$this->assertNotFalse( $index );
		$this->assertSame( 'boolean', $fields[ $index ]['type'] );
		$this->assertFalse( $fields[ $index ]['default'] );
	}

	/**
	 * Tests that backend settings are shown when multiple backends are available.
	 *
	 * @since 1.1.0
	 */
	public function test_get_settings_fields_includes_backend_when_multiple_backends_are_available(): void {
		$experiment = $this->create_experiment_with_backends(
			array( Availability::BACKEND_MARIADB, Availability::BACKEND_MEMORY ),
			Availability::BACKEND_MARIADB
		);
		$fields     = $experiment->get_settings_fields_metadata();

		$this->assertCount( 2, $fields );
		$this->assertSame( Availability::BACKEND_OPTION, $fields[0]['id'] );
		$this->assertSame( 'text', $fields[0]['type'] );
		$this->assertSame( Availability::BACKEND_MARIADB, $fields[0]['default'] );
		$this->assertSame( Availability::BACKEND_MARIADB, $fields[0]['elements'][0]['value'] );
		$this->assertSame( 'Optimal search method backed by MariaDB', $fields[0]['elements'][0]['label'] );
		$this->assertSame( Availability::BACKEND_MEMORY, $fields[0]['elements'][1]['value'] );
		$this->assertSame( 'Fallback in-memory search backed by PHP', $fields[0]['elements'][1]['label'] );
		$this->assertSame( 'wpai_feature_rag-search_field_augment_search', $fields[1]['id'] );
	}

	/**
	 * Tests backend setting sanitization.
	 *
	 * @since 1.1.0
	 */
	public function test_sanitize_backend_only_accepts_available_backends(): void {
		$experiment = $this->create_experiment_with_backends(
			array( Availability::BACKEND_MEMORY ),
			Availability::BACKEND_MEMORY
		);

		$this->assertSame( Availability::BACKEND_MEMORY, $experiment->sanitize_backend( Availability::BACKEND_MEMORY ) );
		$this->assertSame( Availability::BACKEND_MEMORY, $experiment->sanitize_backend( Availability::BACKEND_MARIADB ) );
		$this->assertSame( Availability::BACKEND_MEMORY, $experiment->sanitize_backend( 'wat' ) );
	}

	/**
	 * Tests setting registration.
	 *
	 * @since 1.1.0
	 */
	public function test_register_settings_registers_backend_setting(): void {
		$experiment = $this->create_experiment_with_backends(
			array( Availability::BACKEND_MARIADB, Availability::BACKEND_MEMORY ),
			Availability::BACKEND_MARIADB
		);

		$experiment->register_settings();

		$registered = get_registered_settings();

		$this->assertArrayHasKey( Availability::BACKEND_OPTION, $registered );
		$this->assertNotEmpty( $registered[ Availability::BACKEND_OPTION ]['show_in_rest'] );
	}

	/**
	 * Tests setting lookup.
	 *
	 * @since 1.1.0
	 */
	public function test_is_search_augmentation_enabled_reads_option(): void {
		$experiment = new RAG_Search();

		$this->assertFalse( $experiment->is_search_augmentation_enabled() );

		update_option( 'wpai_feature_rag-search_field_augment_search', true );

		$this->assertTrue( $experiment->is_search_augmentation_enabled() );
	}

	/**
	 * Creates a RAG Search experiment with fixed backend availability.
	 *
	 * @since 1.1.0
	 *
	 * @param list<string> $backends        Available backends.
	 * @param string       $default_backend Default backend.
	 * @return \WordPress\AI\Experiments\RAG_Search\RAG_Search Experiment.
	 */
	private function create_experiment_with_backends( array $backends, string $default_backend ): RAG_Search {
		$experiment = new class() extends RAG_Search {
			/**
			 * Available backends.
			 *
			 * @var list<string>
			 */
			public array $backends = array();

			/**
			 * Default backend.
			 *
			 * @var string
			 */
			public string $default_backend = Availability::BACKEND_MEMORY;

			/**
			 * {@inheritDoc}
			 */
			protected function create_availability(): Availability {
				return new class( $this->backends, $this->default_backend ) extends Availability {
					/**
					 * Available backends.
					 *
					 * @var list<string>
					 */
					private array $backends;

					/**
					 * Default backend.
					 *
					 * @var string
					 */
					private string $default_backend;

					/**
					 * Constructor.
					 *
					 * @param list<string> $backends        Available backends.
					 * @param string       $default_backend Default backend.
					 */
					public function __construct( array $backends, string $default_backend ) {
						$this->backends        = $backends;
						$this->default_backend = $default_backend;
					}

					/**
					 * {@inheritDoc}
					 */
					public function get_available_index_backends(): array {
						return $this->backends;
					}

					/**
					 * {@inheritDoc}
					 */
					public function get_default_index_backend(): string {
						return $this->default_backend;
					}

					/**
					 * {@inheritDoc}
					 */
					public function get_index_backend_labels(): array {
						return array(
							Availability::BACKEND_MARIADB => 'Optimal search method backed by MariaDB',
							Availability::BACKEND_MEMORY  => 'Fallback in-memory search backed by PHP',
						);
					}
				};
			}
		};

		$experiment->backends        = $backends;
		$experiment->default_backend = $default_backend;

		return $experiment;
	}
}
