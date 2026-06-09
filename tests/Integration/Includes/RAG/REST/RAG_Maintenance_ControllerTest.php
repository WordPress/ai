<?php
/**
 * Integration tests for the RAG maintenance REST controller.
 *
 * @package WordPress\AI\Tests\Integration\Includes\RAG\REST
 */

namespace WordPress\AI\Tests\Integration\Includes\RAG\REST;

use WP_UnitTestCase;
use WordPress\AI\RAG\Availability;
use WordPress\AI\RAG\Index_Manager;
use WordPress\AI\RAG\REST\RAG_Maintenance_Controller;

/**
 * RAG maintenance controller test case.
 *
 * @since 1.1.0
 */
class RAG_Maintenance_ControllerTest extends WP_UnitTestCase {
	/**
	 * Tests status can report cleanup availability when RAG is unavailable.
	 *
	 * @since 1.1.0
	 */
	public function test_status_reports_index_data_when_unavailable(): void {
		$controller = new RAG_Maintenance_Controller(
			$this->create_unavailable_availability(),
			$this->create_manager_with_data()
		);

		$response = $controller->get_status();
		$data     = $response->get_data();

		$this->assertFalse( $data['available'] );
		$this->assertSame( 'Unavailable for tests.', $data['unavailable_reason'] );
		$this->assertTrue( $data['has_index_data'] );
		$this->assertFalse( $data['storage_ready'] );
	}

	/**
	 * Creates unavailable availability.
	 *
	 * @since 1.1.0
	 *
	 * @return \WordPress\AI\RAG\Availability Availability.
	 */
	private function create_unavailable_availability(): Availability {
		return new class() extends Availability {
			/**
			 * Constructor.
			 */
			public function __construct() {}

			/**
			 * {@inheritDoc}
			 */
			public function is_available(): bool {
				return false;
			}

			/**
			 * {@inheritDoc}
			 */
			public function get_unavailable_reason(): string {
				return 'Unavailable for tests.';
			}

			/**
			 * {@inheritDoc}
			 */
			public function get_index_backend(): string {
				return Availability::BACKEND_MEMORY;
			}

			/**
			 * {@inheritDoc}
			 */
			public function get_index_backend_label(): string {
				return 'Fallback in-memory search backed by PHP';
			}

			/**
			 * {@inheritDoc}
			 */
			public function get_available_index_backends(): array {
				return array();
			}

			/**
			 * {@inheritDoc}
			 */
			public function get_index_backend_labels(): array {
				return array(
					Availability::BACKEND_MEMORY => 'Fallback in-memory search backed by PHP',
				);
			}

			/**
			 * {@inheritDoc}
			 */
			public function get_embedding_model(): string {
				return 'test-embedding';
			}

			/**
			 * {@inheritDoc}
			 */
			public function get_embedding_dimensions(): int {
				return 3;
			}
		};
	}

	/**
	 * Creates a manager reporting existing data.
	 *
	 * @since 1.1.0
	 *
	 * @return \WordPress\AI\RAG\Index_Manager Manager.
	 */
	private function create_manager_with_data(): Index_Manager {
		return new class() extends Index_Manager {
			/**
			 * Constructor.
			 */
			public function __construct() {}

			/**
			 * {@inheritDoc}
			 */
			public function ensure_index_storage(): bool {
				return false;
			}

			/**
			 * {@inheritDoc}
			 */
			public function has_index_data(): bool {
				return true;
			}

			/**
			 * {@inheritDoc}
			 */
			public function get_status_counts(): array {
				return array(
					Index_Manager::STATUS_DIRTY      => 1,
					Index_Manager::STATUS_PROCESSING => 0,
					Index_Manager::STATUS_CLEAN      => 2,
					Index_Manager::STATUS_ERROR      => 0,
				);
			}

			/**
			 * {@inheritDoc}
			 */
			public function get_next_scheduled_indexing(): ?int {
				return null;
			}
		};
	}
}
