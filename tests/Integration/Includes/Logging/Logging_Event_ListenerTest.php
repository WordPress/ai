<?php
/**
 * Integration tests for the event-based logging fallback.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Logging
 */

namespace WordPress\AI\Tests\Integration\Includes\Logging;

use WP_UnitTestCase;
use WordPress\AI\Logging\AI_Request_Log_Manager;
use WordPress\AI\Logging\AI_Request_Log_Schema;
use WordPress\AI\Logging\Logging_Event_Listener;
use WordPress\AI\Logging\Logging_Http_Transporter;
use WordPress\AiClient\Events\AfterGenerateEmbeddingEvent;
use WordPress\AiClient\Events\AfterGenerateResultEvent;
use WordPress\AiClient\Events\BeforeGenerateEmbeddingEvent;
use WordPress\AiClient\Events\BeforeGenerateResultEvent;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Results\DTO\EmbeddingResult;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;

/**
 * Tests that generations are logged exactly once, whichever path logs them.
 *
 * @since 1.5.0
 *
 * @covers \WordPress\AI\Logging\Logging_Event_Listener
 */
class Logging_Event_ListenerTest extends WP_UnitTestCase {

	/**
	 * Manager bound to the freshly-prepared log table.
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Manager
	 */
	private AI_Request_Log_Manager $manager;

	/**
	 * Listener under test.
	 *
	 * @var \WordPress\AI\Logging\Logging_Event_Listener
	 */
	private Logging_Event_Listener $listener;

	/**
	 * Set up test case.
	 *
	 * @since 1.5.0
	 */
	protected function setUp(): void {
		parent::setUp();

		// Force schema recreation in case a prior test's TRUNCATE broke the table state.
		delete_option( 'wpai_request_logs_schema_version' );

		$schema = new AI_Request_Log_Schema();
		$schema->maybe_upgrade_table();

		$this->manager = new AI_Request_Log_Manager();

		global $wpdb;
		$table = $wpdb->prefix . AI_Request_Log_Schema::TABLE_NAME;
		$wpdb->query( "DELETE FROM {$table} WHERE 1=1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

		$this->listener = new Logging_Event_Listener( $this->manager );
		$this->listener->register();
	}

	/**
	 * Tear down test case.
	 *
	 * @since 1.5.0
	 */
	protected function tearDown(): void {
		delete_option( 'wpai_request_logs_schema_version' );

		parent::tearDown();
	}

	/**
	 * Builds a model whose provider/model identifiers are known.
	 *
	 * @param string $provider Provider identifier.
	 * @param string $model    Model identifier.
	 * @return \WordPress\AiClient\Providers\Models\Contracts\ModelInterface
	 */
	private function make_model( string $provider, string $model ): ModelInterface {
		$provider_meta = $this->createMock( ProviderMetadata::class );
		$provider_meta->method( 'getId' )->willReturn( $provider );

		$model_meta = $this->createMock( ModelMetadata::class );
		$model_meta->method( 'getId' )->willReturn( $model );

		$model_obj = $this->createMock( ModelInterface::class );
		$model_obj->method( 'providerMetadata' )->willReturn( $provider_meta );
		$model_obj->method( 'metadata' )->willReturn( $model_meta );

		return $model_obj;
	}

	/**
	 * Builds a result carrying the given token usage.
	 *
	 * @param int $input  Prompt tokens.
	 * @param int $output Completion tokens.
	 * @return \WordPress\AiClient\Results\DTO\GenerativeAiResult
	 */
	private function make_result( int $input, int $output ): GenerativeAiResult {
		$result = $this->createMock( GenerativeAiResult::class );
		$result->method( 'getTokenUsage' )->willReturn(
			new TokenUsage( $input, $output, $input + $output )
		);

		return $result;
	}

	/**
	 * Fires the before-generate hook for a text generation.
	 *
	 * @param \WordPress\AiClient\Providers\Models\Contracts\ModelInterface $model The model.
	 */
	private function start_generation( ModelInterface $model ): void {
		do_action(
			'wp_ai_client_before_generate_result', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook bridged from the SDK.
			new BeforeGenerateResultEvent( array(), $model, CapabilityEnum::textGeneration() )
		);
	}

	/**
	 * Fires the after-generate hook for a text generation.
	 *
	 * @param \WordPress\AiClient\Providers\Models\Contracts\ModelInterface $model  The model.
	 * @param int                                                           $input  Prompt tokens.
	 * @param int                                                           $output Completion tokens.
	 */
	private function finish_generation( ModelInterface $model, int $input = 10, int $output = 20 ): void {
		do_action(
			'wp_ai_client_after_generate_result', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook bridged from the SDK.
			new AfterGenerateResultEvent( array(), $model, CapabilityEnum::textGeneration(), $this->make_result( $input, $output ) )
		);
	}

	/**
	 * Writes a log row for the given provider, as another logger would.
	 *
	 * @param string $provider  Provider identifier.
	 * @param string $operation Operation label.
	 */
	private function log_row( string $provider, string $operation ): void {
		$this->manager->log(
			array(
				'type'      => 'ai_client',
				'operation' => $operation,
				'provider'  => $provider,
				'status'    => 'success',
			)
		);
	}

	/**
	 * Builds a logging transporter that recognizes the `acme` test provider.
	 *
	 * @return \WordPress\AI\Logging\Logging_Http_Transporter
	 */
	private function make_transporter(): Logging_Http_Transporter {
		add_filter(
			'wpai_request_log_providers',
			static function () {
				return array( 'acme' => array( 'api.acme.test' ) );
			}
		);

		$upstream = $this->createMock( HttpTransporterInterface::class );
		$upstream->method( 'send' )->willReturn( new Response( 200, array(), '{}' ) );

		return new Logging_Http_Transporter( $upstream, $this->manager );
	}

	/**
	 * Returns the logged rows, oldest first.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(): array {
		return array_reverse( $this->manager->get_logs()['items'] );
	}

	/**
	 * A generation that never touches the SDK transporter must still be logged.
	 *
	 * @since 1.5.0
	 */
	public function test_logs_generation_that_bypasses_the_transporter(): void {
		$model = $this->make_model( 'codex', 'codex-mini' );

		$this->start_generation( $model );
		$this->finish_generation( $model, 10, 20 );

		$rows = $this->rows();

		$this->assertCount( 1, $rows, 'Expected the sidecar generation to produce one log row.' );
		$this->assertSame( 'codex', $rows[0]['provider'] );
		$this->assertSame( 'codex-mini', $rows[0]['model'] );
		$this->assertSame( 'codex:text_generation', $rows[0]['operation'] );
		$this->assertSame( 10, $rows[0]['tokens_input'] );
		$this->assertSame( 20, $rows[0]['tokens_output'] );
		$this->assertSame( 'success', $rows[0]['status'] );
	}

	/**
	 * Generations sent through the logging transporter must be logged once, keeping
	 * the transporter's endpoint-based operation.
	 *
	 * @since 1.5.0
	 */
	public function test_transporter_generation_is_logged_once(): void {
		$transporter = $this->make_transporter();
		$model       = $this->make_model( 'acme', 'acme-1' );

		$this->start_generation( $model );
		$transporter->send( new Request( HttpMethodEnum::POST(), 'https://api.acme.test/v1/chat/completions' ) );
		$this->finish_generation( $model );

		$rows = $this->rows();

		$this->assertCount( 1, $rows, 'Transporter-based generation should yield exactly one row.' );
		$this->assertSame( 'acme:completions', $rows[0]['operation'] );
		$this->assertArrayNotHasKey( 'logged_via', $rows[0]['context'] ?? array() );
	}

	/**
	 * A provider that writes its own row must not get a duplicate from the fallback.
	 *
	 * @since 1.5.0
	 */
	public function test_does_not_duplicate_row_written_by_provider(): void {
		$model = $this->make_model( 'codex', 'codex-mini' );

		$this->start_generation( $model );
		$this->log_row( 'codex', 'codex:responses' );
		$this->finish_generation( $model );

		$this->assertCount( 1, $this->rows() );
	}

	/**
	 * A failed write must not stop the fallback from logging the generation.
	 *
	 * @since 1.5.0
	 */
	public function test_failed_write_does_not_suppress_fallback(): void {
		$this->setExpectedIncorrectUsage( AI_Request_Log_Manager::class . '::log' );

		$model = $this->make_model( 'codex', 'codex-mini' );

		$this->start_generation( $model );
		$this->log_row( 'codex', '' );
		$this->finish_generation( $model );

		$rows = $this->rows();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'codex:text_generation', $rows[0]['operation'] );
	}

	/**
	 * A row for another provider during the generation (e.g. a token refresh) must
	 * not suppress the generation's own row.
	 *
	 * @since 1.5.0
	 */
	public function test_row_for_other_provider_does_not_suppress_fallback(): void {
		$model = $this->make_model( 'codex', 'codex-mini' );

		$this->start_generation( $model );
		$this->log_row( 'auth', 'auth:token' );
		$this->finish_generation( $model );

		$operations = wp_list_pluck( $this->rows(), 'operation' );

		$this->assertSame( array( 'auth:token', 'codex:text_generation' ), $operations );
	}

	/**
	 * Nested generations are each logged once, and the inner one does not affect the outer.
	 *
	 * @since 1.5.0
	 */
	public function test_nested_generations_are_logged_independently(): void {
		$outer = $this->make_model( 'codex', 'codex-mini' );
		$inner = $this->make_model( 'acme', 'acme-1' );

		$this->start_generation( $outer );
		usleep( 2000 ); // Durations under 1ms are stored as null.
		$this->start_generation( $inner );
		$this->make_transporter()->send( new Request( HttpMethodEnum::POST(), 'https://api.acme.test/v1/chat/completions' ) );
		$this->finish_generation( $inner );
		$this->finish_generation( $outer );

		$rows = $this->rows();

		$this->assertSame( array( 'acme:completions', 'codex:text_generation' ), wp_list_pluck( $rows, 'operation' ) );
		$this->assertNotNull( $rows[1]['duration_ms'], 'The outer row should keep its duration.' );
	}

	/**
	 * Nested fallback generations for the same provider are each logged.
	 *
	 * @since 1.5.0
	 */
	public function test_nested_fallback_generations_for_same_provider_are_both_logged(): void {
		$outer = $this->make_model( 'codex', 'codex-mini' );
		$inner = $this->make_model( 'codex', 'codex-mini' );

		$this->start_generation( $outer );
		$this->start_generation( $inner );
		$this->finish_generation( $inner );
		$this->finish_generation( $outer );

		$this->assertCount( 2, $this->rows() );
	}

	/**
	 * An inner generation that fails (no after-event) must not affect the outer one.
	 *
	 * @since 1.5.0
	 */
	public function test_failed_inner_generation_does_not_affect_outer(): void {
		$outer = $this->make_model( 'codex', 'codex-mini' );

		$this->start_generation( $outer );
		$this->start_generation( $this->make_model( 'codex', 'codex-mini' ) );
		$this->finish_generation( $outer );

		$rows = $this->rows();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'codex', $rows[0]['provider'] );
	}

	/**
	 * Embedding generations that bypass the transporter are logged too.
	 *
	 * @since 1.5.0
	 */
	public function test_logs_embedding_generation(): void {
		if ( ! class_exists( AfterGenerateEmbeddingEvent::class ) ) {
			$this->markTestSkipped( 'Embedding events require AI Client 1.4.0 or later.' );
		}

		$model      = $this->make_model( 'codex', 'codex-embed' );
		$capability = CapabilityEnum::embeddingGeneration();
		$result     = $this->createMock( EmbeddingResult::class );
		$result->method( 'getTokenUsage' )->willReturn( new TokenUsage( 8, 0, 8 ) );

		do_action(
			'wp_ai_client_before_generate_embedding', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook bridged from the SDK.
			new BeforeGenerateEmbeddingEvent( array(), $model, $capability )
		);
		do_action(
			'wp_ai_client_after_generate_embedding', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook bridged from the SDK.
			new AfterGenerateEmbeddingEvent( array(), $model, $capability, $result )
		);

		$rows = $this->rows();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'codex:embedding_generation', $rows[0]['operation'] );
		$this->assertSame( 8, $rows[0]['tokens_input'] );
	}
}
