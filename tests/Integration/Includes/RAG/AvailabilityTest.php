<?php
/**
 * Integration tests for RAG availability checks.
 *
 * @package WordPress\AI\Tests\Integration\Includes\RAG
 */

namespace WordPress\AI\Tests\Integration\Includes\RAG;

use WP_UnitTestCase;
use WordPress\AI\RAG\Availability;
use WordPress\AI\RAG\Index_Backend_Interface;
use WordPress\AI\RAG\Index_Repository_Interface;
use WordPress\AI\RAG\MariaDB_Index_Backend;
use WordPress\AI\RAG\Memory_Index_Backend;
use WordPress\AI\RAG\Memory_Index_Repository;
use WordPress\AI\RAG\OpenAI_Embedding_Client;

/**
 * Availability test case.
 *
 * @since 1.1.0
 */
class AvailabilityTest extends WP_UnitTestCase {
	/**
	 * Cleans up filters.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wpai_rag_memory_fallback_enabled' );
		delete_option( Availability::BACKEND_OPTION );

		parent::tearDown();
	}

	/**
	 * Tests MariaDB vector index version support detection.
	 *
	 * @dataProvider data_mariadb_versions
	 *
	 * @since 1.1.0
	 *
	 * @param string $version  Raw DB version.
	 * @param bool   $expected Expected support.
	 */
	public function test_is_supported_mariadb_version( string $version, bool $expected ): void {
		$availability = new Availability();

		$this->assertSame( $expected, $availability->is_supported_mariadb_version( $version ) );
	}

	/**
	 * Data provider for version checks.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, array{string, bool}>
	 */
	public function data_mariadb_versions(): array {
		return array(
			'mariadb 11.8'     => array( '11.8.0-MariaDB', true ),
			'mariadb 11.8.2'   => array( '11.8.2-MariaDB-ubu2404', true ),
			'mariadb 11.7'     => array( '11.7.2-MariaDB', false ),
			'prefixed mariadb' => array( '5.5.5-11.8.1-MariaDB', true ),
			'mysql 8'          => array( '8.0.36', false ),
			'empty'            => array( '', false ),
		);
	}

	/**
	 * Tests backend selection for supported MariaDB.
	 *
	 * @since 1.1.0
	 */
	public function test_get_index_backend_prefers_mariadb_when_supported(): void {
		$availability = $this->create_availability_for_database_version( '11.8.2-MariaDB' );

		$this->assertSame( Availability::BACKEND_MARIADB, $availability->get_index_backend() );
	}

	/**
	 * Tests explicit backend selection.
	 *
	 * @since 1.1.0
	 */
	public function test_get_index_backend_honors_backend_setting_when_available(): void {
		update_option( Availability::BACKEND_OPTION, Availability::BACKEND_MEMORY );

		$availability = $this->create_availability_for_database_version( '11.8.2-MariaDB' );

		$this->assertSame( Availability::BACKEND_MEMORY, $availability->get_index_backend() );
	}

	/**
	 * Tests fallback when the selected backend is unavailable.
	 *
	 * @since 1.1.0
	 */
	public function test_get_index_backend_uses_default_when_setting_is_unavailable(): void {
		update_option( Availability::BACKEND_OPTION, Availability::BACKEND_MARIADB );

		$availability = $this->create_availability_for_database_version( '8.0.36' );

		$this->assertSame( Availability::BACKEND_MEMORY, $availability->get_index_backend() );
	}

	/**
	 * Tests backend selection for the compact fallback.
	 *
	 * @since 1.1.0
	 */
	public function test_get_index_backend_uses_memory_fallback_without_mariadb(): void {
		$availability = $this->create_availability_for_database_version( '8.0.36' );

		$this->assertSame( Availability::BACKEND_MEMORY, $availability->get_index_backend() );
	}

	/**
	 * Tests backend selection when the fallback is disabled.
	 *
	 * @since 1.1.0
	 */
	public function test_get_index_backend_returns_mariadb_when_memory_fallback_is_disabled(): void {
		add_filter( 'wpai_rag_memory_fallback_enabled', '__return_false' );

		$availability = $this->create_availability_for_database_version( '8.0.36' );

		$this->assertSame( Availability::BACKEND_MARIADB, $availability->get_index_backend() );
	}

	/**
	 * Tests availability delegates backend checks.
	 *
	 * @since 1.1.0
	 */
	public function test_is_available_delegates_to_registered_backends(): void {
		$availability = new Availability(
			array(
				$this->create_backend( Availability::BACKEND_MARIADB, false, 'MariaDB unavailable.' ),
				$this->create_backend( Availability::BACKEND_MEMORY, true, 'Memory unavailable.' ),
			),
			$this->create_embedding_client( true )
		);

		$this->assertTrue( $availability->is_available() );
		$this->assertSame( Availability::BACKEND_MEMORY, $availability->get_index_backend() );
	}

	/**
	 * Tests unavailable backend reasons are reported when no backend can run.
	 *
	 * @since 1.1.0
	 */
	public function test_is_available_reports_backend_reason_when_no_backend_is_available(): void {
		$availability = new Availability(
			array(
				$this->create_backend( Availability::BACKEND_MARIADB, false, 'MariaDB unavailable.' ),
				$this->create_backend( Availability::BACKEND_MEMORY, false, 'Memory unavailable.' ),
			),
			$this->create_embedding_client( true )
		);

		$this->assertFalse( $availability->is_available() );
		$this->assertStringContainsString( 'MariaDB unavailable.', $availability->get_unavailable_reason() );
		$this->assertStringContainsString( 'Memory unavailable.', $availability->get_unavailable_reason() );
	}

	/**
	 * Tests embedding client availability is part of RAG availability.
	 *
	 * @since 1.1.0
	 */
	public function test_is_available_reports_embedding_client_reason(): void {
		$availability = new Availability(
			array(
				$this->create_backend( Availability::BACKEND_MARIADB, false, 'MariaDB unavailable.' ),
				$this->create_backend( Availability::BACKEND_MEMORY, true, 'Memory unavailable.' ),
			),
			$this->create_embedding_client( false, 'Embedding unavailable.' )
		);

		$this->assertFalse( $availability->is_available() );
		$this->assertSame( 'Embedding unavailable.', $availability->get_unavailable_reason() );
	}

	/**
	 * Creates an availability service with a fixed database version.
	 *
	 * @since 1.1.0
	 *
	 * @param string $version Database version.
	 * @return \WordPress\AI\RAG\Availability Availability service.
	 */
	private function create_availability_for_database_version( string $version ): Availability {
		return new Availability(
			array(
				new class( $version ) extends MariaDB_Index_Backend {
					/**
					 * Database version.
					 *
					 * @var string
					 */
					private string $version;

					/**
					 * Constructor.
					 *
					 * @param string $version Database version.
					 */
					public function __construct( string $version ) {
						parent::__construct();

						$this->version = $version;
					}

					/**
					 * {@inheritDoc}
					 */
					protected function get_database_version(): string {
						return $this->version;
					}
				},
				new Memory_Index_Backend(),
			),
			$this->create_embedding_client( true )
		);
	}

	/**
	 * Creates a test backend.
	 *
	 * @since 1.1.0
	 *
	 * @param string $id        Backend ID.
	 * @param bool   $available Whether the backend is available.
	 * @param string $reason    Unavailable reason.
	 * @return \WordPress\AI\RAG\Index_Backend_Interface Backend.
	 */
	private function create_backend( string $id, bool $available, string $reason ): Index_Backend_Interface {
		return new class( $id, $available, $reason ) implements Index_Backend_Interface {
			/**
			 * Backend ID.
			 *
			 * @var string
			 */
			private string $id;

			/**
			 * Availability.
			 *
			 * @var bool
			 */
			private bool $available;

			/**
			 * Unavailable reason.
			 *
			 * @var string
			 */
			private string $reason;

			/**
			 * Constructor.
			 *
			 * @param string $id        Backend ID.
			 * @param bool   $available Whether the backend is available.
			 * @param string $reason    Unavailable reason.
			 */
			public function __construct( string $id, bool $available, string $reason ) {
				$this->id        = $id;
				$this->available = $available;
				$this->reason    = $reason;
			}

			/**
			 * {@inheritDoc}
			 */
			public function get_id(): string {
				return $this->id;
			}

			/**
			 * {@inheritDoc}
			 */
			public function get_label(): string {
				return $this->id;
			}

			/**
			 * {@inheritDoc}
			 */
			public function is_available(): bool {
				return $this->available;
			}

			/**
			 * {@inheritDoc}
			 */
			public function get_unavailable_reason(): string {
				return $this->reason;
			}

			/**
			 * {@inheritDoc}
			 */
			public function create_repository( int $dimensions ): Index_Repository_Interface {
				return new Memory_Index_Repository( $dimensions );
			}

			/**
			 * {@inheritDoc}
			 */
			public function ensure_storage(): bool {
				return $this->available;
			}

			/**
			 * {@inheritDoc}
			 */
			public function has_index_data(): bool {
				return false;
			}

			/**
			 * {@inheritDoc}
			 */
			public function cleanup(): void {}
		};
	}

	/**
	 * Creates a test embedding client.
	 *
	 * @since 1.1.0
	 *
	 * @param bool   $available Whether embeddings are available.
	 * @param string $reason    Unavailable reason.
	 * @return \WordPress\AI\RAG\OpenAI_Embedding_Client Embedding client.
	 */
	private function create_embedding_client( bool $available, string $reason = '' ): OpenAI_Embedding_Client {
		return new class( $available, $reason ) extends OpenAI_Embedding_Client {
			/**
			 * Availability.
			 *
			 * @var bool
			 */
			private bool $available;

			/**
			 * Unavailable reason.
			 *
			 * @var string
			 */
			private string $reason;

			/**
			 * Constructor.
			 *
			 * @param bool   $available Whether embeddings are available.
			 * @param string $reason    Unavailable reason.
			 */
			public function __construct( bool $available, string $reason ) {
				$this->available = $available;
				$this->reason    = $reason;
			}

			/**
			 * {@inheritDoc}
			 */
			public function is_available(): bool {
				return $this->available;
			}

			/**
			 * {@inheritDoc}
			 */
			public function get_unavailable_reason(): string {
				return $this->reason;
			}
		};
	}
}
