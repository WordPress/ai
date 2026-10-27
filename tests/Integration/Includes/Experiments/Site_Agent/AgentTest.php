<?php
/**
 * Integration tests for the Site Agent turn loop.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\Site_Agent
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\Site_Agent;

use ReflectionProperty;
use WP_AI_Client_Ability_Function_Resolver;
use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Experiments\Site_Agent\Agent;
use WordPress\AI\Experiments\Site_Agent\Audit;
use WordPress\AI\Experiments\Site_Agent\Catalog;
use WordPress\AI\Experiments\Site_Agent\Conversation_Store;
use WordPress\AI\Experiments\Site_Agent\Model_Client_Interface;
use WordPress\AI\Experiments\Site_Agent\Proposal_Store;
use WordPress\AI\Logging\AI_Request_Log_Manager;
use WordPress\AI\Logging\AI_Request_Log_Schema;
use WordPress\AI\Logging\Logging_Integration;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;

/**
 * A model client that replays a fixed queue of messages.
 */
final class Fake_Model_Client implements Model_Client_Interface {

	/**
	 * @var list<Message>
	 */
	private array $queue;

	/**
	 * @param list<Message> $queue The messages to replay.
	 */
	public function __construct( array $queue ) {
		$this->queue = $queue;
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports_text_generation(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports_function_calling(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function generate( array $messages, array $ability_names, string $system_instruction, ?callable $on_text = null ) {
		unset( $messages, $ability_names, $system_instruction, $on_text );

		return array_shift( $this->queue ) ?? self::text( 'done' );
	}

	/**
	 * Builds a plain assistant message.
	 */
	public static function text( string $text ): Message {
		return new Message( MessageRoleEnum::model(), array( new MessagePart( $text ) ) );
	}

	/**
	 * Builds an assistant message that calls one ability.
	 *
	 * @param string               $ability_name The ability.
	 * @param array<string, mixed> $args         The arguments.
	 */
	public static function call( string $ability_name, array $args = array() ): Message {
		return new Message(
			MessageRoleEnum::model(),
			array(
				new MessagePart(
					new FunctionCall(
						'call_' . wp_generate_password( 6, false ),
						WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name( $ability_name ),
						$args
					)
				),
			)
		);
	}
}

/**
 * Site Agent turn loop test case.
 *
 * @since x.x.x
 */
class AgentTest extends WP_UnitTestCase {

	private const READ   = 'wpai-test/read';
	private const WRITE  = 'wpai-test/write';
	private const SECRET = 'wpai-test/secret';

	/**
	 * Executions recorded by the write ability.
	 *
	 * @var list<array<string, mixed>>
	 */
	private static array $writes = array();

	/**
	 * Executions recorded by the secret ability.
	 *
	 * @var int
	 */
	private static int $secret_runs = 0;

	/**
	 * Audit events captured from the plugin hook.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $audit = array();

	/**
	 * @var int
	 */
	private int $admin_id;

	/**
	 * @var int
	 */
	private int $other_admin_id;

	/**
	 * Manager the logging integration held before the test replaced it.
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Manager|null
	 */
	private $original_shared_manager = null;

	public function setUp(): void {
		parent::setUp();

		$this->ensure_category();
		$this->register_abilities();

		$this->admin_id       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->other_admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		update_option( 'wpai_feature_site-agent_enabled', true );
		update_option( 'wpai_feature_site-agent_field_allow_writes', true );

		add_filter(
			Catalog::FILTER,
			static function (): array {
				return array( self::READ, self::WRITE );
			}
		);

		self::$writes      = array();
		self::$secret_runs = 0;
		$this->audit       = array();

		Audit::init();
		$this->boot_logging();

		add_action(
			Audit::ACTION,
			function ( $ability, $status, $context ): void {
				$this->audit[] = compact( 'ability', 'status', 'context' );
			},
			10,
			3
		);
	}

	public function tearDown(): void {
		remove_all_filters( Catalog::FILTER );
		remove_all_filters( 'user_has_cap' );
		remove_all_actions( Audit::ACTION );
		remove_action( 'wp_after_execute_ability', array( Audit::class, 'on_execute' ) );

		$this->set_shared_manager( $this->original_shared_manager );

		delete_option( 'wpai_request_logs_schema_version' );
		wp_clear_scheduled_hook( 'wpai_request_logs_cleanup' );

		foreach ( array( self::READ, self::WRITE, self::SECRET ) as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}

		delete_option( 'wpai_feature_site-agent_enabled' );
		delete_option( 'wpai_feature_site-agent_field_allow_writes' );

		wp_set_current_user( 0 );

		parent::tearDown();
	}

	public function test_read_runs_immediately_and_is_audited(): void {
		$agent = new Agent(
			new Fake_Model_Client(
				array(
					Fake_Model_Client::call( self::READ ),
					Fake_Model_Client::text( 'The site is Spike Site.' ),
				)
			)
		);

		$result = $agent->run( '', 'What is this site called?' );

		$this->assertIsArray( $result );
		$this->assertSame( Agent::STATUS_COMPLETE, $result['status'] );
		$this->assertNull( $result['proposal'] );
		$this->assertSame( 'The site is Spike Site.', $result['text'] );
		$this->assertContains( self::READ, $result['tools'] );
		$this->assertCount( 1, $this->audit );
		$this->assertSame( 'execute', $this->audit[0]['context']['phase'] );
		$this->assertSame( 'success', $this->audit[0]['status'] );

		$rows = $this->log_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( self::READ, $rows[0]['operation'] );
		$this->assertSame( 'success', $rows[0]['status'] );
	}

	public function test_unlisted_ability_cannot_run(): void {
		$agent = new Agent(
			new Fake_Model_Client(
				array(
					Fake_Model_Client::call( self::SECRET ),
					Fake_Model_Client::text( 'I cannot read that.' ),
				)
			)
		);

		$result = $agent->run( '', 'Read the secret.' );

		$this->assertIsArray( $result );
		$this->assertSame( 0, self::$secret_runs );
		$this->assertSame( Agent::STATUS_COMPLETE, $result['status'] );
		$this->assertSame( array(), $this->audit );
	}

	public function test_write_is_proposed_and_not_executed(): void {
		$agent = new Agent(
			new Fake_Model_Client(
				array( Fake_Model_Client::call( self::WRITE, array( 'title' => 'Hello' ) ) )
			)
		);

		$result = $agent->run( '', 'Create a draft called Hello.' );

		$this->assertIsArray( $result );
		$this->assertSame( Agent::STATUS_PENDING, $result['status'] );
		$this->assertSame( array(), self::$writes );
		$this->assertSame( self::WRITE, $result['proposal']['ability'] );
		$this->assertSame( array( 'title' => 'Hello' ), $result['proposal']['args'] );

		$stored = ( new Proposal_Store() )->get( $result['proposal']['id'], $this->admin_id );
		$this->assertIsArray( $stored );
		$this->assertSame( 'pending', $stored['status'] );

		$conversation = ( new Conversation_Store() )->get( $result['conversation_id'], $this->admin_id );
		$this->assertIsArray( $conversation );
		$this->assertNotEmpty( $conversation['messages'] );
	}

	public function test_approval_executes_once_and_is_audited(): void {
		$agent    = new Agent(
			new Fake_Model_Client(
				array( Fake_Model_Client::call( self::WRITE, array( 'title' => 'Approved' ) ) )
			)
		);
		$turn     = $agent->run( '', 'Create it.' );
		$proposal = $turn['proposal'];

		$this->assertSame( array(), self::$writes );

		$result = $agent->approve( $proposal['id'], $turn['conversation_id'] );

		$this->assertIsArray( $result );
		$this->assertCount( 1, self::$writes );
		$this->assertSame( 'Approved', self::$writes[0]['title'] );

		$second = $agent->approve( $proposal['id'], $turn['conversation_id'] );
		$this->assertWPError( $second );
		$this->assertCount( 1, self::$writes );

		$phases   = array_column( array_column( $this->audit, 'context' ), 'phase' );
		$statuses = array_column( $this->audit, 'status' );
		$this->assertContains( 'proposal', $phases );
		$this->assertContains( 'execute', $phases );
		$this->assertContains( 'approval', $phases );
		$this->assertNotContains( 'error', $statuses );

		$conversation = ( new Conversation_Store() )->get( $turn['conversation_id'], $this->admin_id );
		$this->assertIsArray( $conversation );
		$last = end( $conversation['messages'] );
		$this->assertIsArray( $last );
		$this->assertStringContainsString( 'was approved', wp_json_encode( $last ) );

		$rows = $this->log_rows();
		$this->assertGreaterThanOrEqual( 3, count( $rows ) );

		foreach ( $rows as $row ) {
			$this->assertSame( self::WRITE, $row['operation'] );
		}
	}

	public function test_approval_rechecks_capability(): void {
		$agent = new Agent(
			new Fake_Model_Client(
				array( Fake_Model_Client::call( self::WRITE, array( 'title' => 'Nope' ) ) )
			)
		);
		$turn  = $agent->run( '', 'Create it.' );

		add_filter(
			'user_has_cap',
			static function ( array $caps ): array {
				$caps['edit_posts'] = false;

				return $caps;
			}
		);

		$result = $agent->approve( $turn['proposal']['id'], $turn['conversation_id'] );

		$this->assertWPError( $result );
		$this->assertSame( 'site_agent_ability_denied', $result->get_error_code() );
		$this->assertSame( array(), self::$writes );
	}

	public function test_proposal_is_user_scoped(): void {
		$agent = new Agent(
			new Fake_Model_Client(
				array( Fake_Model_Client::call( self::WRITE, array( 'title' => 'Scoped' ) ) )
			)
		);
		$turn  = $agent->run( '', 'Create it.' );

		wp_set_current_user( $this->other_admin_id );

		$result = $agent->approve( $turn['proposal']['id'], $turn['conversation_id'] );

		$this->assertWPError( $result );
		$this->assertSame( 'site_agent_proposal_not_found', $result->get_error_code() );
		$this->assertSame( array(), self::$writes );
	}

	public function test_proposal_is_conversation_bound(): void {
		$agent = new Agent(
			new Fake_Model_Client(
				array( Fake_Model_Client::call( self::WRITE, array( 'title' => 'Bound' ) ) )
			)
		);
		$turn  = $agent->run( '', 'Create it.' );

		$result = $agent->approve( $turn['proposal']['id'], 'another-conversation' );

		$this->assertWPError( $result );
		$this->assertSame( 'site_agent_proposal_wrong_conversation', $result->get_error_code() );
		$this->assertSame( array(), self::$writes );
	}

	public function test_decline_writes_nothing(): void {
		$agent = new Agent(
			new Fake_Model_Client(
				array( Fake_Model_Client::call( self::WRITE, array( 'title' => 'Declined' ) ) )
			)
		);
		$turn  = $agent->run( '', 'Create it.' );

		$this->assertTrue( $agent->decline( $turn['proposal']['id'], $turn['conversation_id'] ) );
		$this->assertSame( array(), self::$writes );

		$approve = $agent->approve( $turn['proposal']['id'], $turn['conversation_id'] );
		$this->assertWPError( $approve );
		$this->assertSame( array(), self::$writes );
	}

	public function test_decline_after_approval_is_rejected(): void {
		$agent = new Agent(
			new Fake_Model_Client(
				array( Fake_Model_Client::call( self::WRITE, array( 'title' => 'Already done' ) ) )
			)
		);
		$turn  = $agent->run( '', 'Create it.' );

		$this->assertIsArray( $agent->approve( $turn['proposal']['id'], $turn['conversation_id'] ) );

		$result = $agent->decline( $turn['proposal']['id'], $turn['conversation_id'] );

		$this->assertWPError( $result );
		$this->assertSame( 'site_agent_proposal_used', $result->get_error_code() );
		$this->assertCount( 1, self::$writes );
	}

	public function test_unknown_conversation_is_not_started(): void {
		$agent  = new Agent( new Fake_Model_Client( array( Fake_Model_Client::text( 'unused' ) ) ) );
		$result = $agent->run( 'does-not-exist', 'Hello.' );

		$this->assertWPError( $result );
		$this->assertSame( 'site_agent_conversation_not_found', $result->get_error_code() );
	}

	public function test_overlong_prompt_is_rejected(): void {
		$agent  = new Agent( new Fake_Model_Client( array( Fake_Model_Client::text( 'unused' ) ) ) );
		$result = $agent->run( '', str_repeat( 'a', 8001 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'site_agent_message_too_long', $result->get_error_code() );
	}

	/**
	 * Replaces the manager the logging integration shares.
	 *
	 * @param \WordPress\AI\Logging\AI_Request_Log_Manager|null $manager Manager, or null.
	 */
	private function set_shared_manager( ?AI_Request_Log_Manager $manager ): void {
		$property = new ReflectionProperty( Logging_Integration::class, 'log_manager' );
		$property->setAccessible( true );
		$property->setValue( null, $manager );
	}

	/**
	 * Boots the request log table and shares a fresh manager.
	 */
	private function boot_logging(): void {
		delete_option( 'wpai_request_logs_schema_version' );

		$manager = new AI_Request_Log_Manager();
		$manager->init();

		global $wpdb;
		$table = $wpdb->prefix . AI_Request_Log_Schema::TABLE_NAME;
		$wpdb->query( "DELETE FROM {$table} WHERE 1=1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup of the plugin's own log table.

		$this->original_shared_manager = Logging_Integration::get_log_manager();
		$this->set_shared_manager( $manager );
	}

	/**
	 * Returns the Site Agent log rows written so far.
	 *
	 * @return list<array<string, mixed>> The rows, oldest first.
	 */
	private function log_rows(): array {
		global $wpdb;
		$table = $wpdb->prefix . AI_Request_Log_Schema::TABLE_NAME;

		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE type = 'site_agent' ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the plugin's own log table in a test.

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Ensures the plugin's ability category exists.
	 */
	private function ensure_category(): void {
		if ( wp_has_ability_category( WPAI_DEFAULT_ABILITY_CATEGORY ) ) {
			return;
		}

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context.
		try {
			wp_register_ability_category(
				WPAI_DEFAULT_ABILITY_CATEGORY,
				array(
					'label'       => 'AI',
					'description' => 'AI abilities.',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Registers the fixture abilities.
	 */
	private function register_abilities(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context.

		try {
			if ( ! wp_has_ability( self::READ ) ) {
				wp_register_ability(
					self::READ,
					array(
						'label'               => 'Read site name',
						'description'         => 'Returns the site name.',
						'category'            => WPAI_DEFAULT_ABILITY_CATEGORY,
						'input_schema'        => array(
							'type'                 => 'object',
							'properties'           => array(),
							'additionalProperties' => false,
						),
						'output_schema'       => array( 'type' => 'string' ),
						'execute_callback'    => static function () {
							return get_bloginfo( 'name' );
						},
						'permission_callback' => static function () {
							return is_user_logged_in();
						},
						'meta'                => array(
							'annotations' => array( 'readonly' => true ),
						),
					)
				);
			}

			if ( ! wp_has_ability( self::WRITE ) ) {
				wp_register_ability(
					self::WRITE,
					array(
						'label'               => 'Create draft',
						'description'         => 'Creates a draft post.',
						'category'            => WPAI_DEFAULT_ABILITY_CATEGORY,
						'input_schema'        => array(
							'type'                 => 'object',
							'properties'           => array(
								'title' => array( 'type' => 'string' ),
							),
							'required'             => array( 'title' ),
							'additionalProperties' => false,
						),
						'output_schema'       => array(
							'type'       => 'object',
							'properties' => array( 'id' => array( 'type' => 'integer' ) ),
							'required'   => array( 'id' ),
						),
						'execute_callback'    => static function ( $input ) {
							self::$writes[] = is_array( $input ) ? $input : array();

							return array( 'id' => 123 );
						},
						'permission_callback' => static function () {
							return current_user_can( 'edit_posts' );
						},
						'meta'                => array(
							'annotations' => array( 'readonly' => false ),
						),
					)
				);
			}

			if ( ! wp_has_ability( self::SECRET ) ) {
				wp_register_ability(
					self::SECRET,
					array(
						'label'               => 'Secret read',
						'description'         => 'Registered but never in the catalog.',
						'category'            => WPAI_DEFAULT_ABILITY_CATEGORY,
						'input_schema'        => array(),
						'output_schema'       => array( 'type' => 'string' ),
						'execute_callback'    => static function () {
							++self::$secret_runs;

							return 'secret';
						},
						'permission_callback' => static function () {
							return true;
						},
						'meta'                => array(
							'annotations' => array( 'readonly' => true ),
						),
					)
				);
			}
		} finally {
			array_pop( $wp_current_filter );
		}
	}
}
