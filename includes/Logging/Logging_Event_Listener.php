<?php
/**
 * Provider-agnostic logging fallback driven by AI Client generation events.
 *
 * @package WordPress\AI\Logging
 */

declare( strict_types=1 );

namespace WordPress\AI\Logging;

use WordPress\AiClient\Events\AfterGenerateEmbeddingEvent;
use WordPress\AiClient\Events\AfterGenerateResultEvent;
use WordPress\AiClient\Events\BeforeGenerateEmbeddingEvent;
use WordPress\AiClient\Events\BeforeGenerateResultEvent;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Logs successful generations that were not logged by any other path.
 *
 * Known limitation: core only fires the after-events on success, so failed
 * generations from custom-transport providers are not captured here.
 *
 * @since 1.5.0
 */
class Logging_Event_Listener {

	/**
	 * The log manager instance.
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Manager
	 */
	private AI_Request_Log_Manager $log_manager;

	/**
	 * Stack of in-flight generations, innermost last.
	 *
	 * @var list<array{model: \WordPress\AiClient\Providers\Models\Contracts\ModelInterface, provider: string, capability: string, timer: array{start: int}, logged: bool}>
	 */
	private array $generations = array();

	/**
	 * Constructor.
	 *
	 * @since 1.5.0
	 *
	 * @param \WordPress\AI\Logging\AI_Request_Log_Manager $log_manager The log manager.
	 */
	public function __construct( AI_Request_Log_Manager $log_manager ) {
		$this->log_manager = $log_manager;
	}

	/**
	 * Registers the generation lifecycle listeners.
	 *
	 * @since 1.5.0
	 */
	public function register(): void {
		add_action( 'wp_ai_client_before_generate_result', array( $this, 'handle_before_generate' ) );
		add_action( 'wp_ai_client_after_generate_result', array( $this, 'handle_after_generate' ) );
		add_action( 'wp_ai_client_before_generate_embedding', array( $this, 'handle_before_generate' ) );
		add_action( 'wp_ai_client_after_generate_embedding', array( $this, 'handle_after_generate' ) );
		add_action( 'wpai_request_logged', array( $this, 'handle_request_logged' ), 10, 2 );
	}

	/**
	 * Starts tracking a generation.
	 *
	 * @since 1.5.0
	 *
	 * @param object $event The before-generate event.
	 */
	public function handle_before_generate( object $event ): void {
		if ( ! $event instanceof BeforeGenerateResultEvent && ! $event instanceof BeforeGenerateEmbeddingEvent ) {
			return;
		}

		$model = $event->getModel();

		$this->generations[] = array(
			'model'      => $model,
			'provider'   => $model->providerMetadata()->getId(),
			'capability' => (string) $event->getCapability(),
			'timer'      => $this->log_manager->start_timer(),
			'logged'     => false,
		);
	}

	/**
	 * Marks the innermost generation as logged when a row is written for its provider.
	 *
	 * @since 1.5.0
	 *
	 * @param string               $log_id The log identifier.
	 * @param array<string, mixed> $data   The logged row.
	 */
	public function handle_request_logged( string $log_id, array $data ): void {
		$index = array_key_last( $this->generations );

		if ( null === $index || ( $data['provider'] ?? null ) !== $this->generations[ $index ]['provider'] ) {
			return;
		}

		$this->generations[ $index ]['logged'] = true;
	}

	/**
	 * Logs a completed generation unless another path already logged it.
	 *
	 * @since 1.5.0
	 *
	 * @param object $event The after-generate event.
	 */
	public function handle_after_generate( object $event ): void {
		if ( ! $event instanceof AfterGenerateResultEvent && ! $event instanceof AfterGenerateEmbeddingEvent ) {
			return;
		}

		$index = $this->find_generation( $event->getModel() );

		if ( null === $index ) {
			return;
		}

		$generation = $this->generations[ $index ];

		if ( ! $generation['logged'] ) {
			$tokens = $event->getResult()->getTokenUsage();

			$this->log_manager->log(
				array(
					'type'          => 'ai_client',
					'operation'     => $generation['provider'] . ':' . $generation['capability'],
					'provider'      => $generation['provider'],
					'model'         => $event->getModel()->metadata()->getId(),
					'duration_ms'   => $this->log_manager->end_timer( $generation['timer'] ),
					'tokens_input'  => $tokens->getPromptTokens(),
					'tokens_output' => $tokens->getCompletionTokens(),
					'status'        => 'success',
					'context'       => array( 'logged_via' => 'generation_event' ),
				)
			);
		}

		// Drop this generation along with any inner ones that failed without an after-event.
		// Done after logging so our own row marks this generation, not its parent.
		$this->generations = array_slice( $this->generations, 0, $index );
	}

	/**
	 * Finds the in-flight generation that was started for the given model.
	 *
	 * @since 1.5.0
	 *
	 * @param \WordPress\AiClient\Providers\Models\Contracts\ModelInterface $model The model from the after-event.
	 * @return int|null The stack index, or null when the generation is not tracked.
	 */
	private function find_generation( ModelInterface $model ): ?int {
		for ( $i = count( $this->generations ) - 1; $i >= 0; $i-- ) {
			if ( $this->generations[ $i ]['model'] === $model ) {
				return $i;
			}
		}

		return null;
	}
}
