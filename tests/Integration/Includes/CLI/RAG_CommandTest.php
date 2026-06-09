<?php
/**
 * Integration tests for the RAG_Command WP-CLI class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\CLI
 */

namespace WordPress\AI\Tests\Integration\Includes\CLI;

use WP_UnitTestCase;
use WordPress\AI\CLI\RAG_Command;
use WordPress\AI\RAG\Availability;
use WordPress\AI\RAG\Index_Manager;

require_once __DIR__ . '/wp-cli-stubs.php';

/**
 * RAG_Command test case.
 *
 * @covers \WordPress\AI\CLI\RAG_Command
 *
 * @since 1.1.0
 */
class RAG_CommandTest extends WP_UnitTestCase {
	/**
	 * Set up test case.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( '\WP_CLI' ) || ! method_exists( '\WP_CLI', 'reset' ) ) {
			return;
		}

		\WP_CLI::reset();
	}

	/**
	 * Gets captured WP_CLI messages at a given level.
	 *
	 * @param string|null $level The level to filter by, or null for all.
	 * @return array<int, string> Captured messages.
	 */
	private function get_cli_messages( ?string $level = null ): array {
		$messages = array();
		foreach ( \WP_CLI::$messages as $entry ) {
			if ( null !== $level && $entry['level'] !== $level ) {
				continue;
			}

			$messages[] = $entry['message'];
		}
		return $messages;
	}

	/**
	 * Tests status output.
	 *
	 * @since 1.1.0
	 */
	public function test_status_outputs_index_counts(): void {
		$command = new RAG_Command(
			$this->create_availability( true ),
			$this->create_manager(
				array(
					'status_counts' => array(
						Index_Manager::STATUS_DIRTY => 2,
						Index_Manager::STATUS_CLEAN => 5,
						Index_Manager::STATUS_ERROR => 1,
					),
					'scheduled'     => 1234567890,
				)
			)
		);

		$command->status( array(), array() );

		$messages = implode( "\n", $this->get_cli_messages() );

		$this->assertStringContainsString( 'Available: yes', $messages );
		$this->assertStringContainsString( 'Backend: Optimal search method backed by MariaDB', $messages );
		$this->assertStringContainsString( 'Index storage: ready', $messages );
		$this->assertStringContainsString( 'Dirty: 2', $messages );
		$this->assertStringContainsString( 'Clean: 5', $messages );
		$this->assertStringContainsString( 'Error: 1', $messages );
	}

	/**
	 * Tests mark-dirty command.
	 *
	 * @since 1.1.0
	 */
	public function test_mark_dirty_marks_ids(): void {
		$manager = $this->create_manager();
		$command = new RAG_Command( $this->create_availability( true ), $manager );

		$command->mark_dirty( array(), array( 'ids' => '10,11' ) );

		$this->assertSame( array( 10, 11 ), $manager->marked_ids );
		$this->assertStringContainsString( 'Marked 2 post(s) dirty', implode( "\n", $this->get_cli_messages( 'success' ) ) );
	}

	/**
	 * Tests indexing runs until complete when --all is supplied.
	 *
	 * @since 1.1.0
	 */
	public function test_index_all_runs_batches_until_no_dirty_posts_remain(): void {
		$manager = $this->create_manager(
			array(
				'batches' => array(
					array(
						'processed' => 50,
						'clean'     => 50,
						'error'     => 0,
						'removed'   => 0,
						'remaining' => 1,
					),
					array(
						'processed' => 1,
						'clean'     => 1,
						'error'     => 0,
						'removed'   => 0,
						'remaining' => 0,
					),
				),
			)
		);
		$command = new RAG_Command( $this->create_availability( true ), $manager );

		$command->index( array(), array( 'all' => true ) );

		$this->assertSame( 2, $manager->index_runs );
		$this->assertStringContainsString( 'RAG indexing complete for 51 post(s)', implode( "\n", $this->get_cli_messages( 'success' ) ) );
	}

	/**
	 * Tests that tail scheduling can be disabled for a single batch.
	 *
	 * @since 1.1.0
	 */
	public function test_index_can_disable_tail_scheduling(): void {
		$manager = $this->create_manager(
			array(
				'batches' => array(
					array(
						'processed' => 1,
						'clean'     => 1,
						'error'     => 0,
						'removed'   => 0,
						'remaining' => 2,
					),
				),
			)
		);
		$command = new RAG_Command( $this->create_availability( true ), $manager );

		$command->index( array(), array( 'schedule' => false ) );

		$this->assertSame( array( false ), $manager->schedule_tail_values );
		$this->assertStringContainsString( '2 dirty post(s) remain', implode( "\n", $this->get_cli_messages() ) );
	}

	/**
	 * Tests schedule command.
	 *
	 * @since 1.1.0
	 */
	public function test_schedule_schedules_indexing(): void {
		$manager = $this->create_manager();
		$command = new RAG_Command( $this->create_availability( true ), $manager );

		$command->schedule( array(), array( 'delay' => 30 ) );

		$this->assertSame( 30, $manager->scheduled_delay );
		$this->assertStringContainsString( 'Scheduled RAG indexing in 30 second(s).', implode( "\n", $this->get_cli_messages( 'success' ) ) );
	}

	/**
	 * Tests cleanup command.
	 *
	 * @since 1.1.0
	 */
	public function test_cleanup_deletes_index_data_with_yes_flag(): void {
		$manager = $this->create_manager();
		$command = new RAG_Command( $this->create_availability( true ), $manager );

		$command->cleanup( array(), array( 'yes' => true ) );

		$this->assertTrue( $manager->cleaned_up );
		$this->assertStringContainsString( 'Deleted RAG index data.', implode( "\n", $this->get_cli_messages( 'success' ) ) );
	}

	/**
	 * Creates a fake availability service.
	 *
	 * @param bool $available Whether RAG is available.
	 * @return \WordPress\AI\RAG\Availability Fake availability service.
	 */
	private function create_availability( bool $available ): Availability {
		return new class( $available ) extends Availability {
			/**
			 * Whether RAG is available.
			 *
			 * @var bool
			 */
			private bool $available;

			/**
			 * Constructor.
			 *
			 * @param bool $available Whether RAG is available.
			 */
			public function __construct( bool $available ) {
				$this->available = $available;
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
				return 'Unavailable for tests.';
			}

			/**
			 * {@inheritDoc}
			 */
			public function get_index_backend(): string {
				return Availability::BACKEND_MARIADB;
			}

			/**
			 * {@inheritDoc}
			 */
			public function get_index_backend_label(): string {
				return 'Optimal search method backed by MariaDB';
			}
		};
	}

	/**
	 * Creates a fake index manager.
	 *
	 * @param array<string, mixed> $config Fake behavior config.
	 * @return \WordPress\AI\RAG\Index_Manager Fake manager.
	 */
	private function create_manager( array $config = array() ): Index_Manager {
		return new class( $config ) extends Index_Manager {
			/**
			 * IDs passed to mark dirty.
			 *
			 * @var list<int>
			 */
			public array $marked_ids = array();

			/**
			 * Scheduled delay.
			 *
			 * @var int|null
			 */
			public ?int $scheduled_delay = null;

			/**
			 * Number of indexing runs.
			 *
			 * @var int
			 */
			public int $index_runs = 0;

			/**
			 * Schedule-tail values passed to run_indexing_batch().
			 *
			 * @var list<bool>
			 */
			public array $schedule_tail_values = array();

			/**
			 * Whether cleanup was called.
			 *
			 * @var bool
			 */
			public bool $cleaned_up = false;

			/**
			 * Fake behavior config.
			 *
			 * @var array<string, mixed>
			 */
			private array $config;

			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $config Fake behavior config.
			 */
			public function __construct( array $config ) {
				$this->config = $config;
			}

			/**
			 * {@inheritDoc}
			 */
			public function ensure_index_storage(): bool {
				return true;
			}

			/**
			 * {@inheritDoc}
			 */
			public function get_status_counts(): array {
				return wp_parse_args(
					$this->config['status_counts'] ?? array(),
					array(
						Index_Manager::STATUS_DIRTY      => 0,
						Index_Manager::STATUS_PROCESSING => 0,
						Index_Manager::STATUS_CLEAN      => 0,
						Index_Manager::STATUS_ERROR      => 0,
					)
				);
			}

			/**
			 * {@inheritDoc}
			 */
			public function get_next_scheduled_indexing(): ?int {
				return isset( $this->config['scheduled'] ) ? (int) $this->config['scheduled'] : null;
			}

			/**
			 * {@inheritDoc}
			 */
			public function mark_posts_dirty_for_indexing( array $post_ids = array(), int $limit = 0 ): array {
				unset( $limit );
				$this->marked_ids = $post_ids;

				return array(
					'marked'  => count( $post_ids ),
					'skipped' => 0,
					'removed' => 0,
				);
			}

			/**
			 * {@inheritDoc}
			 */
			public function run_indexing_batch( int $limit = 0, bool $schedule_tail = true ) {
				unset( $limit );

				$this->schedule_tail_values[] = $schedule_tail;

				$batches = $this->config['batches'] ?? array();
				$stats   = $batches[ $this->index_runs ] ?? array(
					'processed' => 0,
					'clean'     => 0,
					'error'     => 0,
					'removed'   => 0,
					'remaining' => 0,
				);

				++$this->index_runs;

				return $stats;
			}

			/**
			 * {@inheritDoc}
			 */
			public function schedule_indexing( int $delay = 1 ): void {
				$this->scheduled_delay = $delay;
			}

			/**
			 * {@inheritDoc}
			 */
			public function cleanup_index_data(): void {
				$this->cleaned_up = true;
			}
		};
	}
}
