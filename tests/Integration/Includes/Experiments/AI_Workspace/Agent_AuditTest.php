<?php
/**
 * Tests for the agent audit trail.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace;

use ReflectionProperty;
use WP_UnitTestCase;
use WordPress\AI\Experiments\AI_Workspace\Agent_Audit;
use WordPress\AI\Experiments\AI_Workspace\Draft_Writer;
use WordPress\AI\Logging\AI_Request_Log_Manager;
use WordPress\AI\Logging\AI_Request_Log_Schema;
use WordPress\AI\Logging\Logging_Integration;

/**
 * Agent_Audit test case.
 *
 * Every agent event is one log row of the `agent` type and one call of the
 * `wpai_agent_event` hook, and the hook fires whether or not the AI Request
 * Logging experiment is on (R14, R15).
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Experiments\AI_Workspace\Agent_Audit
 */
class Agent_AuditTest extends WP_UnitTestCase {

	/**
	 * Manager the logging integration held before the test replaced it.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Manager|null
	 */
	private $original_shared_manager = null;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_shared_manager = Logging_Integration::get_log_manager();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		$this->set_shared_manager( $this->original_shared_manager );

		delete_option( 'wpai_request_logs_schema_version' );
		wp_clear_scheduled_hook( 'wpai_request_logs_cleanup' );

		parent::tearDown();
	}

	/**
	 * Replaces the manager the logging integration shares.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Logging\AI_Request_Log_Manager|null $manager Manager, or null for a disabled experiment.
	 */
	private function set_shared_manager( ?AI_Request_Log_Manager $manager ): void {
		$property = new ReflectionProperty( Logging_Integration::class, 'log_manager' );
		$property->setAccessible( true );
		$property->setValue( null, $manager );
	}

	/**
	 * Boots the request log table and shares a fresh manager with the integration.
	 *
	 * @since x.x.x
	 */
	private function boot_logging(): void {
		delete_option( 'wpai_request_logs_schema_version' );

		$manager = new AI_Request_Log_Manager();
		$manager->init();

		global $wpdb;
		$table = $wpdb->prefix . AI_Request_Log_Schema::TABLE_NAME;
		$wpdb->query( "DELETE FROM {$table} WHERE 1=1" ); // phpcs:ignore

		$this->set_shared_manager( $manager );
	}

	/**
	 * Returns the agent log rows written so far.
	 *
	 * @since x.x.x
	 *
	 * @return list<array<string, mixed>> The rows, oldest first.
	 */
	private function rows(): array {
		global $wpdb;
		$table = $wpdb->prefix . AI_Request_Log_Schema::TABLE_NAME;

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s ORDER BY id ASC", Agent_Audit::LOG_TYPE ), ARRAY_A ); // phpcs:ignore

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Records the hook calls made from here on.
	 *
	 * @since x.x.x
	 *
	 * @return \ArrayObject<int, list<mixed>> The arguments of each call, in order.
	 */
	private function capture(): \ArrayObject {
		$calls = new \ArrayObject();

		add_action(
			Agent_Audit::ACTION,
			static function ( ...$args ) use ( $calls ): void {
				$calls[] = $args;
			},
			10,
			4
		);

		return $calls;
	}

	/**
	 * The agent type is one the log accepts and the log screen can filter on.
	 *
	 * @since x.x.x
	 */
	public function test_agent_is_a_log_type(): void {
		$this->assertContains( Agent_Audit::LOG_TYPE, AI_Request_Log_Manager::get_types() );
	}

	/**
	 * An event writes one agent row and fires the hook once with its operation, status, context and error.
	 *
	 * @since x.x.x
	 */
	public function test_an_event_writes_one_row_and_fires_the_hook(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->boot_logging();
		$calls = $this->capture();

		$context = array(
			'surface'         => 'ai-workspace',
			'conversation_id' => 'c1',
		);

		Agent_Audit::record(
			array(
				'operation'     => 'ai/search-content',
				'status'        => 'error',
				'user_id'       => $user_id,
				'context'       => $context,
				'duration_ms'   => 12,
				'error_message' => 'It broke.',
			)
		);

		$this->assertSame( array( array( 'ai/search-content', 'error', $context, 'It broke.' ) ), $calls->getArrayCopy() );

		$rows = $this->rows();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'ai/search-content', $rows[0]['operation'] );
		$this->assertSame( 'error', $rows[0]['status'] );
		$this->assertSame( (string) $user_id, (string) $rows[0]['user_id'] );
		$this->assertSame( '12', (string) $rows[0]['duration_ms'] );
		$this->assertSame( 'It broke.', $rows[0]['error_message'] );
		$this->assertSame( $context, json_decode( (string) $rows[0]['context'], true ) );
	}

	/**
	 * With the AI Request Logging experiment off, the hook still fires and nothing is written.
	 *
	 * @since x.x.x
	 */
	public function test_the_hook_fires_with_logging_off(): void {
		$this->boot_logging();
		$this->set_shared_manager( null );
		$calls = $this->capture();

		Agent_Audit::record(
			array(
				'operation' => 'proposal_created',
				'status'    => 'success',
				'context'   => array(),
			)
		);

		$this->assertCount( 1, $calls );
		$this->assertSame( '', $calls[0][3], 'An event without an error passes an empty string.' );
		$this->assertSame( array(), $this->rows() );
	}

	/**
	 * A proposal event names the proposal and its items, never their values.
	 *
	 * @since x.x.x
	 */
	public function test_a_proposal_event_names_the_proposal_and_its_items_only(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$calls   = $this->capture();

		Agent_Audit::proposal(
			'proposal_created',
			'success',
			array(
				'id'              => 'p1',
				'user_id'         => $user_id,
				'conversation_id' => 'c1',
				'kind'            => 'post-field',
				'items'           => array(
					array(
						'key'   => 'k1',
						'value' => 'A secret body',
					),
					array(
						'key'   => 'k2',
						'value' => 'Another secret',
					),
				),
			)
		);

		$this->assertCount( 1, $calls );

		[ $operation, $status, $context ] = $calls[0];

		$this->assertSame( 'proposal_created', $operation );
		$this->assertSame( 'success', $status );
		$this->assertSame(
			array(
				'surface'         => 'ai-workspace',
				'conversation_id' => 'c1',
				'proposal'        => array(
					'id'    => 'p1',
					'kind'  => 'post-field',
					'items' => array( 'k1', 'k2' ),
				),
			),
			$context
		);
		$this->assertStringNotContainsString( 'secret', (string) wp_json_encode( $calls[0] ) );
	}

	/**
	 * A proposal stored without a kind is a set of drafts.
	 *
	 * @since x.x.x
	 */
	public function test_a_proposal_without_a_kind_is_recorded_as_drafts(): void {
		$calls = $this->capture();

		Agent_Audit::proposal(
			'proposal_declined',
			'success',
			array(
				'id'    => 'p1',
				'items' => array(),
			)
		);

		$this->assertSame( Draft_Writer::KIND, $calls[0][2]['proposal']['kind'] );
		$this->assertSame( '', $calls[0][2]['conversation_id'] );
	}
}
