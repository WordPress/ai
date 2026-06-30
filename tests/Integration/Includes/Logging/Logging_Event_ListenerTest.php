<?php
/**
 * Integration tests for the event-based logging fallback.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Logging
 */

namespace WordPress\AI\Tests\Integration\Includes\Logging;

use ReflectionProperty;
use WP_UnitTestCase;
use WordPress\AI\Logging\AI_Request_Log_Manager;
use WordPress\AI\Logging\AI_Request_Log_Schema;
use WordPress\AI\Logging\Logging_Integration;
use WordPress\AiClient\Events\AfterGenerateResultEvent;
use WordPress\AiClient\Events\BeforeGenerateResultEvent;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;

/**
 * Tests that generations which bypass the SDK HTTP transporter are still logged.
 *
 * @since 1.0.0
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
	 * Set up test case.
	 *
	 * @since 1.0.0
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

		// Reset the one-shot init guard so init() wires the listener against our manager.
		$this->reset_integration();
		Logging_Integration::init( $this->manager );
	}

	/**
	 * Tear down test case.
	 *
	 * @since 1.0.0
	 */
	protected function tearDown(): void {
		remove_all_actions( 'wp_ai_client_before_generate_result' );
		remove_all_actions( 'wp_ai_client_after_generate_result' );
		$this->reset_integration();
		delete_option( 'wpai_request_logs_schema_version' );

		parent::tearDown();
	}

	/**
	 * Resets the static state on Logging_Integration so init() runs afresh.
	 *
	 * @since 1.0.0
	 */
	private function reset_integration(): void {
		$initialized = new ReflectionProperty( Logging_Integration::class, 'initialized' );
		$initialized->setAccessible( true );
		$initialized->setValue( null, false );

		$log_manager = new ReflectionProperty( Logging_Integration::class, 'log_manager' );
		$log_manager->setAccessible( true );
		$log_manager->setValue( null, null );
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
	 * Fires the core generation lifecycle hooks for a non-transporter provider.
	 *
	 * @param \WordPress\AiClient\Providers\Models\Contracts\ModelInterface $model  The model.
	 * @param \WordPress\AiClient\Results\DTO\GenerativeAiResult            $result The result.
	 */
	private function dispatch_generation( ModelInterface $model, GenerativeAiResult $result ): void {
		$capability = CapabilityEnum::textGeneration();

		do_action(
			'wp_ai_client_before_generate_result', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook bridged from the SDK.
			new BeforeGenerateResultEvent( array(), $model, $capability )
		);
		do_action(
			'wp_ai_client_after_generate_result', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook bridged from the SDK.
			new AfterGenerateResultEvent( array(), $model, $capability, $result )
		);
	}

	/**
	 * A generation that never touches the SDK transporter must still be logged.
	 *
	 * @since 1.0.0
	 */
	public function test_logs_generation_that_bypasses_the_transporter(): void {
		$model  = $this->make_model( 'codex', 'codex-mini' );
		$result = $this->make_result( 10, 20 );

		$this->dispatch_generation( $model, $result );

		$logs = $this->manager->get_logs();

		$this->assertSame( 1, $logs['total'], 'Expected the sidecar generation to produce one log row.' );

		$item = $logs['items'][0];
		$this->assertSame( 'codex', $item['provider'] );
		$this->assertSame( 'codex-mini', $item['model'] );
		$this->assertSame( 10, $item['tokens_input'] );
		$this->assertSame( 20, $item['tokens_output'] );
		$this->assertSame( 'success', $item['status'] );
	}

	/**
	 * Transporter-based generations must not be logged twice.
	 *
	 * When a provider routes through the SDK transporter, Logging_Http_Transporter
	 * logs the row and flags the generation; the after-event fallback must then skip
	 * it rather than write a duplicate.
	 *
	 * @since 1.0.0
	 */
	public function test_does_not_double_log_transporter_based_generations(): void {
		$capability = CapabilityEnum::textGeneration();
		$model      = $this->make_model( 'openai', 'gpt-4o' );

		do_action(
			'wp_ai_client_before_generate_result', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook bridged from the SDK.
			new BeforeGenerateResultEvent( array(), $model, $capability )
		);

		// Simulate the transporter handling the request: it writes its own row and
		// flags the generation as already logged (mirrors Logging_Http_Transporter::send()).
		$this->manager->log(
			array(
				'type'      => 'ai_client',
				'operation' => 'openai:chat/completions',
				'provider'  => 'openai',
				'model'     => 'gpt-4o',
				'status'    => 'success',
			)
		);
		\WordPress\AI\Logging\Logging_Event_Listener::mark_transporter_logged();

		do_action(
			'wp_ai_client_after_generate_result', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook bridged from the SDK.
			new AfterGenerateResultEvent( array(), $model, $capability, $this->make_result( 5, 7 ) )
		);

		$logs = $this->manager->get_logs();

		$this->assertSame(
			1,
			$logs['total'],
			'Transporter-based generation should yield exactly one row (no event-based duplicate).'
		);
	}
}
