<?php
/**
 * Integration tests for Logging_Http_Transporter.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Logging
 */

namespace WordPress\AI\Tests\Integration\Includes\Logging;

use PHPUnit\Framework\MockObject\MockObject;
use ReflectionMethod;
use WP_UnitTestCase;
use WordPress\AI\Logging\AI_Request_Log_Manager;
use WordPress\AI\Logging\AI_Request_Log_Schema;
use WordPress\AI\Logging\Logging_Http_Transporter;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;

/**
 * Logging_Http_Transporter test case.
 *
 * @since 1.0.0
 *
 * @covers \WordPress\AI\Logging\Logging_Http_Transporter
 */
class Logging_Http_TransporterTest extends WP_UnitTestCase {

	/**
	 * Transporter instance under test, with no upstream HTTP transporter.
	 *
	 * @var \WordPress\AI\Logging\Logging_Http_Transporter
	 */
	private Logging_Http_Transporter $transporter;

	/**
	 * Mock of the wrapped upstream transporter.
	 *
	 * @var \PHPUnit\Framework\MockObject\MockObject&\WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface
	 */
	private MockObject $upstream;

	/**
	 * Log manager instance under test, backed by the real request log table.
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Manager
	 */
	private AI_Request_Log_Manager $manager;

	/**
	 * Set up test case.
	 */
	protected function setUp(): void {
		parent::setUp();

		// Force schema recreation in case a prior test's TRUNCATE broke the table state.
		delete_option( 'wpai_request_logs_schema_version' );

		$this->upstream = $this->createMock( HttpTransporterInterface::class );
		$this->manager  = new AI_Request_Log_Manager();
		$this->manager->init();

		global $wpdb;
		$table = $wpdb->prefix . AI_Request_Log_Schema::TABLE_NAME;
		$wpdb->query( "DELETE FROM {$table} WHERE 1=1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

		$this->transporter = new Logging_Http_Transporter( $this->upstream, $this->manager );
	}

	/**
	 * Tear down test case.
	 */
	protected function tearDown(): void {
		delete_option( 'wpai_request_logs_schema_version' );
		parent::tearDown();
	}

	/**
	 * Returns the most recently logged entry.
	 *
	 * @return array<string, mixed>
	 */
	private function latest_log(): array {
		$items = $this->manager->get_logs( array( 'per_page' => 1 ) )['items'];

		$this->assertNotEmpty( $items, 'Expected a log entry to have been recorded.' );

		return $items[0];
	}

	/**
	 * Invokes the private is_infrastructure_file() helper via reflection.
	 *
	 * @param string $file File path to check.
	 * @return bool
	 */
	private function call_is_infrastructure_file( string $file ): bool {
		$method = new ReflectionMethod( $this->transporter, 'is_infrastructure_file' );
		$method->setAccessible( true );
		return (bool) $method->invoke( $this->transporter, $file );
	}

	/**
	 * Tests that frames inside the logging directory are skipped.
	 *
	 * @since 1.0.0
	 */
	public function test_skips_logging_dir(): void {
		$file = wp_normalize_path( WP_PLUGIN_DIR . '/ai/includes/Logging/Logging_Http_Transporter.php' );

		$this->assertTrue( $this->call_is_infrastructure_file( $file ) );
	}

	/**
	 * Tests that any /vendor/ frame is skipped.
	 *
	 * @since 1.0.0
	 */
	public function test_skips_vendor(): void {
		$file = wp_normalize_path( WP_PLUGIN_DIR . '/some-plugin/vendor/whatever/Library.php' );

		$this->assertTrue( $this->call_is_infrastructure_file( $file ) );
	}

	/**
	 * Tests that frames inside the AI Client SDK shipped with core are skipped.
	 *
	 * @since 1.0.0
	 */
	public function test_skips_php_ai_client_sdk(): void {
		$file = wp_normalize_path( ABSPATH . 'wp-includes/php-ai-client/src/Providers/Anthropic.php' );

		$this->assertTrue( $this->call_is_infrastructure_file( $file ) );
	}

	/**
	 * Tests that frames inside core's WP_AI_Client_* wrapper are skipped.
	 *
	 * @since 1.0.0
	 */
	public function test_skips_core_ai_client_wrapper(): void {
		$file = wp_normalize_path( ABSPATH . 'wp-includes/ai-client/class-wp-ai-client-prompt-builder.php' );

		$this->assertTrue( $this->call_is_infrastructure_file( $file ) );
	}

	/**
	 * Tests that frames inside a registered AI provider plugin are skipped.
	 *
	 * Without this skip, an AI request initiated by the AI plugin would be
	 * attributed to the provider plugin since its class sits between the
	 * caller and the transporter on the call stack.
	 *
	 * @since 1.0.0
	 */
	public function test_skips_registered_ai_provider_plugin(): void {
		$registered_slug = $this->first_ai_provider_plugin_slug();

		if ( null === $registered_slug ) {
			$this->markTestSkipped( 'No AI provider connectors registered in this environment.' );
		}

		$file = wp_normalize_path( WP_PLUGIN_DIR . '/' . $registered_slug . '/src/Provider/Anything.php' );

		$this->assertTrue( $this->call_is_infrastructure_file( $file ) );
	}

	/**
	 * Tests that frames in the AI plugin (the originator) are not skipped.
	 *
	 * @since 1.0.0
	 */
	public function test_does_not_skip_ai_plugin_originator(): void {
		$file = wp_normalize_path( WP_PLUGIN_DIR . '/ai/includes/Abilities/Title_Generation/Title_Generation.php' );

		$this->assertFalse( $this->call_is_infrastructure_file( $file ) );
	}

	/**
	 * Tests that frames in arbitrary plugins are not skipped.
	 *
	 * @since 1.0.0
	 */
	public function test_does_not_skip_arbitrary_plugin(): void {
		$file = wp_normalize_path( WP_PLUGIN_DIR . '/my-custom-plugin/src/Caller.php' );

		$this->assertFalse( $this->call_is_infrastructure_file( $file ) );
	}

	/**
	 * Returns the slug of the first registered AI provider connector whose
	 * plugin directory can be resolved, or null if none is available.
	 */
	private function first_ai_provider_plugin_slug(): ?string {
		if ( ! function_exists( 'wp_get_connectors' ) ) {
			return null;
		}

		$resolver = new ReflectionMethod( $this->transporter, 'resolve_connector_plugin_slug' );
		$resolver->setAccessible( true );

		foreach ( wp_get_connectors() as $connector_data ) {
			if ( ! is_array( $connector_data ) || 'ai_provider' !== ( $connector_data['type'] ?? '' ) ) {
				continue;
			}

			$slug = (string) $resolver->invoke( $this->transporter, $connector_data );
			if ( '' !== $slug ) {
				return $slug;
			}
		}

		return null;
	}

	/**
	 * Tests that a non-2xx response is logged as an error, not a success.
	 *
	 * The SDK's transporter only throws for PSR-18 network or client exceptions;
	 * a non-2xx HTTP response comes back as an ordinary Response and is rejected
	 * later by the caller. Without this, such requests were logged as 'success'.
	 *
	 * @since x.x.x
	 */
	public function test_send_logs_non_successful_response_as_error(): void {
		$body = wp_json_encode(
			array(
				'error' => array(
					'message' => 'Service Unavailable (503) - This model is currently experiencing high demand.',
				),
			)
		);

		$this->upstream->method( 'send' )->willReturn( new Response( 503, array(), $body ) );

		$request  = new Request( HttpMethodEnum::POST(), 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent' );
		$response = $this->transporter->send( $request );

		// The response is still returned unchanged; only the log entry reflects the error.
		$this->assertSame( 503, $response->getStatusCode() );

		$log = $this->latest_log();

		$this->assertSame( 'error', $log['status'] );
		$this->assertIsString( $log['error_message'] );
		$this->assertStringContainsString( 'HTTP 503', $log['error_message'] );
		$this->assertStringContainsString( 'high demand', $log['error_message'] );
		$this->assertIsArray( $log['context'] );
		$this->assertSame( 503, $log['context']['http_status'] );
	}

	/**
	 * Tests that a non-2xx response without a JSON error body still logs the status code.
	 *
	 * @since x.x.x
	 */
	public function test_send_logs_non_successful_response_without_error_body(): void {
		$this->upstream->method( 'send' )->willReturn( new Response( 429, array(), null ) );

		$request = new Request( HttpMethodEnum::POST(), 'https://api.openai.com/v1/chat/completions' );
		$this->transporter->send( $request );

		$log = $this->latest_log();

		$this->assertSame( 'error', $log['status'] );
		$this->assertSame( 'HTTP 429', $log['error_message'] );
	}

	/**
	 * Tests that a successful 2xx response is still logged as a success.
	 *
	 * @since x.x.x
	 */
	public function test_send_logs_successful_response_as_success(): void {
		$this->upstream->method( 'send' )->willReturn(
			new Response( 200, array(), wp_json_encode( array( 'ok' => true ) ) )
		);

		$request = new Request( HttpMethodEnum::POST(), 'https://api.openai.com/v1/chat/completions' );
		$this->transporter->send( $request );

		$log = $this->latest_log();

		$this->assertSame( 'success', $log['status'] );
		$this->assertNull( $log['error_message'] );
	}

	/**
	 * Tests that a thrown PSR-18 exception is still logged as an error (pre-existing behavior).
	 *
	 * @since x.x.x
	 */
	public function test_send_logs_thrown_exception_as_error(): void {
		$this->upstream->method( 'send' )->willThrowException( new \RuntimeException( 'Connection timed out' ) );

		$request = new Request( HttpMethodEnum::POST(), 'https://api.openai.com/v1/chat/completions' );

		try {
			$this->transporter->send( $request );
			$this->fail( 'Expected exception was not thrown.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'Connection timed out', $e->getMessage() );
		}

		$log = $this->latest_log();

		$this->assertSame( 'error', $log['status'] );
		$this->assertSame( 'Connection timed out', $log['error_message'] );
	}
}
