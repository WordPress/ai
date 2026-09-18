<?php
/**
 * Integration tests for the streaming-capable HTTP transporter.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace\Streaming
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace\Streaming;

use ReflectionProperty;
use WP_Connector_Registry;
use WP_UnitTestCase;
use WordPress\AI\Connector_Approval\Approvals_Store;
use WordPress\AI\Connector_Approval\Caller_Identifier;
use WordPress\AI\Connector_Approval\Connector_Key_Index;
use WordPress\AI\Experiments\AI_Workspace\Streaming\Streaming_Exception;
use WordPress\AI\Experiments\AI_Workspace\Streaming\Streaming_Http_Transporter;
use WordPress\AI\Logging\AI_Request_Log_Manager;
use WordPress\AI\Logging\AI_Request_Log_Schema;
use WordPress\AI\Logging\Logging_Integration;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;

/**
 * Streaming_Http_Transporter test case.
 *
 * The transport itself is replaced by a recording stream opener, so no bytes
 * ever leave the site during these tests. That also makes "the opener was never
 * called" a direct assertion that nothing was sent.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Experiments\AI_Workspace\Streaming\Streaming_Http_Transporter
 */
class Streaming_Http_TransporterTest extends WP_UnitTestCase {

	/**
	 * Test connector ID registered during setUp.
	 *
	 * @var string
	 */
	private const TEST_CONNECTOR_ID = 'wpai_stream_test_provider';

	/**
	 * Setting name holding the test connector's credential.
	 *
	 * @var string
	 */
	private const TEST_SETTING = 'wpai_stream_test_provider_key';

	/**
	 * Credential long enough to clear the key index's minimum-length filter.
	 *
	 * @var string
	 */
	private const TEST_CREDENTIAL = 'stream-credential-value-1234567890';

	/**
	 * Approvals store used by each test.
	 *
	 * @var \WordPress\AI\Connector_Approval\Approvals_Store
	 */
	private Approvals_Store $store;

	/**
	 * Caller identifier used by each test.
	 *
	 * @var \WordPress\AI\Connector_Approval\Caller_Identifier
	 */
	private Caller_Identifier $identifier;

	/**
	 * Key index used by each test.
	 *
	 * @var \WordPress\AI\Connector_Approval\Connector_Key_Index
	 */
	private Connector_Key_Index $key_index;

	/**
	 * Recording stream opener injected into the transporter.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace\Streaming\Spy_Stream_Opener
	 */
	private Spy_Stream_Opener $opener;

	/**
	 * Recording inner transporter.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace\Streaming\Spy_Http_Transporter
	 */
	private Spy_Http_Transporter $inner;

	/**
	 * Log manager shared with the logging integration during a test.
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Manager|null
	 */
	private ?AI_Request_Log_Manager $manager = null;

	/**
	 * Manager the logging integration held before the test replaced it.
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Manager|null
	 */
	private ?AI_Request_Log_Manager $original_shared_manager = null;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$registry = WP_Connector_Registry::get_instance();
		if ( null !== $registry && ! $registry->is_registered( self::TEST_CONNECTOR_ID ) ) {
			$registry->register(
				self::TEST_CONNECTOR_ID,
				array(
					'name'           => 'Stream Test Provider',
					'description'    => 'Test provider for streaming transporter tests.',
					'type'           => 'ai_provider',
					'authentication' => array(
						'method'       => 'api_key',
						'setting_name' => self::TEST_SETTING,
					),
				)
			);
		}

		update_option( self::TEST_SETTING, self::TEST_CREDENTIAL );

		$this->original_shared_manager = Logging_Integration::get_log_manager();

		/*
		 * Start every test with the logging experiment modelled as disabled. Tests that
		 * assert on log entries call boot_logging() to turn it on; the rest exercise the
		 * transporter's behaviour when there is nothing to log to.
		 */
		$this->set_shared_manager( null );

		$this->store      = new Approvals_Store();
		$this->identifier = new Caller_Identifier();
		$this->key_index  = new Connector_Key_Index();
		$this->opener     = new Spy_Stream_Opener();
		$this->inner      = new Spy_Http_Transporter();

		$this->key_index->invalidate();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		$this->set_shared_manager( $this->original_shared_manager );

		$registry = WP_Connector_Registry::get_instance();
		if ( null !== $registry && $registry->is_registered( self::TEST_CONNECTOR_ID ) ) {
			$registry->unregister( self::TEST_CONNECTOR_ID );
		}

		delete_option( self::TEST_SETTING );
		delete_option( Approvals_Store::OPTION_APPROVALS );
		delete_option( Approvals_Store::OPTION_PENDING );
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
	 *
	 * @return \WordPress\AI\Logging\AI_Request_Log_Manager The manager.
	 */
	private function boot_logging(): AI_Request_Log_Manager {
		delete_option( 'wpai_request_logs_schema_version' );

		$manager = new AI_Request_Log_Manager();
		$manager->init();

		global $wpdb;
		$table = $wpdb->prefix . AI_Request_Log_Schema::TABLE_NAME;
		$wpdb->query( "DELETE FROM {$table} WHERE 1=1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

		$this->set_shared_manager( $manager );
		$this->manager = $manager;

		return $manager;
	}

	/**
	 * Counts rows currently in the request log table.
	 *
	 * @since x.x.x
	 *
	 * @return int Row count.
	 */
	private function count_log_rows(): int {
		global $wpdb;
		$table = $wpdb->prefix . AI_Request_Log_Schema::TABLE_NAME;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Builds the transporter under test.
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AI\Experiments\AI_Workspace\Streaming\Streaming_Http_Transporter The transporter.
	 */
	private function transporter(): Streaming_Http_Transporter {
		return new Streaming_Http_Transporter(
			$this->inner,
			$this->store,
			$this->identifier,
			$this->key_index,
			$this->opener
		);
	}

	/**
	 * Builds a request carrying the test connector's credential.
	 *
	 * @since x.x.x
	 *
	 * @param bool $stream Whether to mark the request as streaming.
	 * @return \WordPress\AiClient\Providers\Http\DTO\Request The request.
	 */
	private function request( bool $stream ): Request {
		$options = new RequestOptions();
		$options->setStream( $stream );

		return new Request(
			HttpMethodEnum::POST(),
			'https://api.anthropic.example/v1/messages',
			array(
				'Content-Type' => 'application/json',
				'x-api-key'    => self::TEST_CREDENTIAL,
			),
			array(
				'model'    => 'claude-test',
				'messages' => array(),
				'stream'   => $stream,
			),
			$options
		);
	}

	/**
	 * A non-streaming request is handed to the wrapped transporter untouched.
	 *
	 * @since x.x.x
	 */
	public function test_non_streaming_request_delegates_to_the_wrapped_transporter(): void {
		$request  = $this->request( false );
		$options  = $request->getOptions();
		$response = $this->transporter()->send( $request, $options );

		$this->assertSame( $this->inner->response, $response, 'The wrapped response must be returned unchanged.' );
		$this->assertSame( 1, $this->inner->calls, 'The wrapped transporter must be called exactly once.' );
		$this->assertSame( $request, $this->inner->last_request, 'The wrapped transporter must receive the same request.' );
		$this->assertSame( $options, $this->inner->last_options, 'The wrapped transporter must receive the same options.' );
		$this->assertSame( 0, $this->opener->calls, 'The streaming transport must not run for a buffered request.' );
	}

	/**
	 * A request with no options at all is also delegated unchanged.
	 *
	 * @since x.x.x
	 */
	public function test_request_without_options_delegates(): void {
		$request  = new Request(
			HttpMethodEnum::POST(),
			'https://api.anthropic.example/v1/messages',
			array( 'x-api-key' => self::TEST_CREDENTIAL ),
			array( 'model' => 'claude-test' )
		);
		$response = $this->transporter()->send( $request );

		$this->assertSame( $this->inner->response, $response );
		$this->assertSame( 1, $this->inner->calls );
		$this->assertNull( $this->inner->last_options );
		$this->assertSame( 0, $this->opener->calls );
	}

	/**
	 * A streaming flag carried on the request itself is honoured.
	 *
	 * @since x.x.x
	 */
	public function test_streaming_flag_on_the_request_is_honoured(): void {
		$this->store->set_approval( 'ai/ai.php', self::TEST_CONNECTOR_ID, true );

		$response = $this->transporter()->send( $this->request( true ) );

		$this->assertSame( 1, $this->opener->calls );
		$this->assertSame( 0, $this->inner->calls );
		$this->assertSame( 200, $response->getStatusCode() );
	}

	/**
	 * An unapproved caller is refused before any bytes leave the site.
	 *
	 * @since x.x.x
	 */
	public function test_unapproved_connector_is_refused_before_egress(): void {
		$thrown = null;

		try {
			$this->transporter()->send( $this->request( true ) );
		} catch ( Streaming_Exception $e ) {
			$thrown = $e;
		}

		$this->assertInstanceOf( Streaming_Exception::class, $thrown, 'An unapproved streaming request must be refused.' );
		$this->assertStringContainsString( self::TEST_CONNECTOR_ID, $thrown->getMessage() );
		$this->assertSame( 0, $this->opener->calls, 'No bytes may leave the site for an unapproved connector.' );
		$this->assertSame( 0, $this->inner->calls, 'The refusal must not fall back to the buffered transport.' );
	}

	/**
	 * A refused streaming request records the caller/connector pair as pending approval.
	 *
	 * @since x.x.x
	 */
	public function test_refusal_records_a_pending_approval(): void {
		try {
			$this->transporter()->send( $this->request( true ) );
		} catch ( Streaming_Exception $e ) {
			unset( $e );
		}

		$pending = $this->store->get_pending();

		$this->assertNotEmpty( $pending, 'The refusal must leave a pending approval behind.' );
	}

	/**
	 * An approved caller reaches the streaming transport and gets a stream back.
	 *
	 * @since x.x.x
	 */
	public function test_approved_connector_streams(): void {
		$this->store->set_approval( 'ai/ai.php', self::TEST_CONNECTOR_ID, true );
		$this->opener->body = "event: ping\ndata: {}\n\n";

		$response = $this->transporter()->send( $this->request( true ) );

		$this->assertSame( 1, $this->opener->calls );
		$this->assertSame( 'POST', $this->opener->last_method );
		$this->assertSame( 'https://api.anthropic.example/v1/messages', $this->opener->last_url );
		$this->assertSame( "event: ping\ndata: {}\n\n", $response->getStream()->getContents() );
	}

	/**
	 * A request that matches no connector is allowed through, matching the HTTP guard.
	 *
	 * @since x.x.x
	 */
	public function test_request_without_a_matching_connector_is_allowed(): void {
		$request = new Request(
			HttpMethodEnum::POST(),
			'https://unrelated.example/v1/things',
			array( 'Content-Type' => 'application/json' ),
			array( 'model' => 'whatever' ),
			( function (): RequestOptions {
				$options = new RequestOptions();
				$options->setStream( true );
				return $options;
			} )()
		);

		$this->transporter()->send( $request );

		$this->assertSame( 1, $this->opener->calls );
	}

	/**
	 * A streaming request produces a request-log entry.
	 *
	 * @since x.x.x
	 */
	public function test_streaming_request_is_logged(): void {
		$this->boot_logging();
		$this->store->set_approval( 'ai/ai.php', self::TEST_CONNECTOR_ID, true );

		$before = $this->count_log_rows();

		$this->transporter()->send( $this->request( true ) );

		$this->assertSame( $before + 1, $this->count_log_rows(), 'A streaming request must be logged.' );
	}

	/**
	 * The logged entry records the streaming request's provider, model, and success.
	 *
	 * @since x.x.x
	 */
	public function test_logged_entry_carries_the_request_details(): void {
		$manager = $this->boot_logging();
		$this->store->set_approval( 'ai/ai.php', self::TEST_CONNECTOR_ID, true );

		$this->transporter()->send( $this->request( true ) );

		$logs = $manager->get_logs( array( 'per_page' => 5 ) );

		$this->assertNotEmpty( $logs['items'] );

		$entry = $logs['items'][0];

		$this->assertSame( 'ai_client', $entry['type'] );
		$this->assertSame( 'claude-test', $entry['model'] );
		$this->assertSame( 'success', $entry['status'] );
		$this->assertTrue( ! empty( $entry['context']['streaming'] ) );
	}

	/**
	 * A refused streaming request is logged as an error.
	 *
	 * @since x.x.x
	 */
	public function test_refused_streaming_request_is_logged_as_an_error(): void {
		$manager = $this->boot_logging();

		try {
			$this->transporter()->send( $this->request( true ) );
		} catch ( Streaming_Exception $e ) {
			unset( $e );
		}

		$logs = $manager->get_logs( array( 'per_page' => 5 ) );

		$this->assertNotEmpty( $logs['items'] );
		$this->assertSame( 'error', $logs['items'][0]['status'] );
	}

	/**
	 * A non-successful upstream status is surfaced rather than returned as a stream.
	 *
	 * @since x.x.x
	 */
	public function test_upstream_error_status_is_returned_with_its_body(): void {
		$this->store->set_approval( 'ai/ai.php', self::TEST_CONNECTOR_ID, true );
		$this->opener->status = 429;
		$this->opener->body   = '{"error":{"message":"rate limited"}}';

		$response = $this->transporter()->send( $this->request( true ) );

		$this->assertSame( 429, $response->getStatusCode() );
		$this->assertFalse( $response->isSuccessful() );
	}
}

/**
 * Recording inner transporter used to assert decorator identity.
 *
 * @since x.x.x
 */
class Spy_Http_Transporter implements HttpTransporterInterface {

	/**
	 * Number of times send() was called.
	 *
	 * @var int
	 */
	public int $calls = 0;

	/**
	 * The last request received.
	 *
	 * @var \WordPress\AiClient\Providers\Http\DTO\Request|null
	 */
	public ?Request $last_request = null;

	/**
	 * The last options received.
	 *
	 * @var \WordPress\AiClient\Providers\Http\DTO\RequestOptions|null
	 */
	public ?RequestOptions $last_options = null;

	/**
	 * The response this transporter returns.
	 *
	 * @var \WordPress\AiClient\Providers\Http\DTO\Response
	 */
	public Response $response;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->response = new Response( 200, array( 'Content-Type' => 'application/json' ), '{"ok":true}' );
	}

	/**
	 * Records the call and returns the canned response.
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Request             $request The request.
	 * @param \WordPress\AiClient\Providers\Http\DTO\RequestOptions|null $options The options.
	 * @return \WordPress\AiClient\Providers\Http\DTO\Response The response.
	 */
	public function send( Request $request, ?RequestOptions $options = null ): Response {
		++$this->calls;
		$this->last_request = $request;
		$this->last_options = $options;

		return $this->response;
	}
}

/**
 * Recording stream opener that never touches the network.
 *
 * @since x.x.x
 */
class Spy_Stream_Opener implements \WordPress\AI\Experiments\AI_Workspace\Streaming\Stream_Opener_Interface {

	/**
	 * Number of times open() was called.
	 *
	 * @var int
	 */
	public int $calls = 0;

	/**
	 * The last method received.
	 *
	 * @var string|null
	 */
	public ?string $last_method = null;

	/**
	 * The last URL received.
	 *
	 * @var string|null
	 */
	public ?string $last_url = null;

	/**
	 * The last body received.
	 *
	 * @var string|null
	 */
	public ?string $last_body = null;

	/**
	 * Status code to report.
	 *
	 * @var int
	 */
	public int $status = 200;

	/**
	 * Body to serve as the stream.
	 *
	 * @var string
	 */
	public string $body = '';

	/**
	 * Records the call and returns a canned stream.
	 *
	 * @param string                    $method  HTTP method.
	 * @param string                    $url     Request URL.
	 * @param array<string, list<string>> $headers Request headers.
	 * @param string|null               $body    Request body.
	 * @param float|null                $timeout Timeout in seconds.
	 * @return array{status: int, headers: array<string, list<string>>, stream: \WordPress\AiClientDependencies\Psr\Http\Message\StreamInterface} The opened stream.
	 */
	public function open( string $method, string $url, array $headers, ?string $body, ?float $timeout ): array {
		unset( $headers, $timeout );

		++$this->calls;
		$this->last_method = $method;
		$this->last_url    = $url;
		$this->last_body   = $body;

		return array(
			'status'  => $this->status,
			'headers' => array( 'Content-Type' => array( 'text/event-stream' ) ),
			'stream'  => \WordPress\AiClientDependencies\Nyholm\Psr7\Stream::create( $this->body ),
		);
	}
}
