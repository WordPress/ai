<?php
/**
 * Provider-agnostic logging fallback driven by SDK generation events.
 *
 * @package WordPress\AI\Logging
 */

declare( strict_types=1 );

namespace WordPress\AI\Logging;

use WordPress\AiClient\Events\AfterGenerateResultEvent;

defined( 'ABSPATH' ) || exit;

/**
 * Logs successful generations that do not flow through the SDK HTTP transporter.
 *
 * The {@see Logging_Http_Transporter} only sees requests routed through the SDK's
 * HTTP transporter. Providers with a custom transport (for example one that proxies
 * to a localhost sidecar) never reach it and would otherwise be absent from the log.
 *
 * This listener taps the core generation lifecycle hooks, which fire for every
 * provider regardless of transport:
 *
 * - `wp_ai_client_before_generate_result`
 * - `wp_ai_client_after_generate_result`
 *
 * These are bridged from the SDK's `BeforeGenerateResultEvent` / `AfterGenerateResultEvent`
 * by the dispatcher registered in WordPress core (`wp-settings.php`).
 *
 * To avoid double-logging transporter-based providers (which fire the event *and*
 * pass through the decorator), a per-generation flag is reset on the before-event and
 * set by {@see Logging_Http_Transporter::send()}; the after-event only writes a row
 * when the transporter did not already log the current generation.
 *
 * Known limitation: the SDK's after-event fires on success only (there is no error
 * event), so failed custom-transport generations are not captured here.
 *
 * @since 1.0.0
 */
class Logging_Event_Listener {

	/**
	 * Whether the transporter logged the current generation.
	 *
	 * @var bool
	 */
	private static bool $transporter_logged = false;

	/**
	 * The log manager instance.
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Manager
	 */
	private AI_Request_Log_Manager $log_manager;

	/**
	 * Timer for the in-progress generation.
	 *
	 * @var array{start: int}|null
	 */
	private ?array $timer = null;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param \WordPress\AI\Logging\AI_Request_Log_Manager $log_manager The log manager.
	 */
	public function __construct( AI_Request_Log_Manager $log_manager ) {
		$this->log_manager = $log_manager;
	}

	/**
	 * Registers the generation lifecycle listeners.
	 *
	 * @since 1.0.0
	 */
	public function register(): void {
		add_action( 'wp_ai_client_before_generate_result', array( $this, 'handle_before_generate' ) );
		add_action( 'wp_ai_client_after_generate_result', array( $this, 'handle_after_generate' ) );
	}

	/**
	 * Marks the current generation as already logged by the HTTP transporter.
	 *
	 * Called by {@see Logging_Http_Transporter::send()} so the after-event does not
	 * write a duplicate row for transporter-based providers.
	 *
	 * @since 1.0.0
	 */
	public static function mark_transporter_logged(): void {
		self::$transporter_logged = true;
	}

	/**
	 * Resets per-generation state at the start of a generation.
	 *
	 * @since 1.0.0
	 */
	public function handle_before_generate(): void {
		self::$transporter_logged = false;
		$this->timer              = $this->log_manager->start_timer();
	}

	/**
	 * Logs the generation result unless the transporter already logged it.
	 *
	 * @since 1.0.0
	 *
	 * @param object $event The after-generate event.
	 */
	public function handle_after_generate( object $event ): void {
		$timer       = $this->timer;
		$this->timer = null;

		// The SDK transporter already captured this generation; avoid a duplicate row.
		if ( self::$transporter_logged ) {
			return;
		}

		if ( ! $event instanceof AfterGenerateResultEvent ) {
			return;
		}

		$model      = $event->getModel();
		$provider   = $model->providerMetadata()->getId();
		$model_id   = $model->metadata()->getId();
		$capability = $event->getCapability();
		$capability = null !== $capability ? (string) $capability : '';
		$tokens     = $event->getResult()->getTokenUsage();

		$log_data = array(
			'type'          => 'ai_client',
			'operation'     => '' !== $capability ? $provider . ':' . $capability : $provider,
			'provider'      => $provider,
			'model'         => $model_id,
			'tokens_input'  => $tokens->getPromptTokens(),
			'tokens_output' => $tokens->getCompletionTokens(),
			'status'        => 'success',
			'context'       => array( 'logged_via' => 'generation_event' ),
		);

		if ( null !== $timer ) {
			$log_data['duration_ms'] = $this->log_manager->end_timer( $timer );
		}

		$this->log_manager->log( $log_data );
	}
}
