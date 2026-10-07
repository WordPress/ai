<?php
/**
 * Per-provider pause state.
 *
 * @package WordPress\AI\Embeddings\Sync
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Pauses a provider after a rate limit, or one provider model after a provider-level failure.
 *
 * @since x.x.x
 */
class Provider_Backoff {

	/**
	 * Transient name prefix.
	 */
	private const TRANSIENT_PREFIX = 'wpai_embedding_backoff_';

	/**
	 * First rate-limit pause, in seconds.
	 *
	 * @since x.x.x
	 */
	public const MIN_DELAY = 30;

	/**
	 * Longest rate-limit pause, in seconds.
	 *
	 * @since x.x.x
	 */
	public const MAX_DELAY = 900;

	/**
	 * Pause after a provider-level failure, in seconds.
	 *
	 * @since x.x.x
	 */
	public const PROVIDER_PAUSE = 3600;

	/**
	 * Returns the stored state for a provider, or for one of its models.
	 *
	 * @since x.x.x
	 *
	 * @param string      $provider Provider ID.
	 * @param string|null $model    Optional. Model ID for model-scoped state. Default null (provider-wide).
	 * @return array{until: int, delay: int, error_class: string, error: string}|null The state, or null.
	 */
	public function get( string $provider, ?string $model = null ): ?array {
		$state = get_transient( $this->key( $provider, $model ) );

		if ( ! is_array( $state ) || ! isset( $state['until'], $state['delay'], $state['error_class'], $state['error'] ) ) {
			return null;
		}

		return array(
			'until'       => (int) $state['until'],
			'delay'       => (int) $state['delay'],
			'error_class' => (string) $state['error_class'],
			'error'       => (string) $state['error'],
		);
	}

	/**
	 * Returns when a provider-wide pause ends.
	 *
	 * @since x.x.x
	 *
	 * @param string   $provider Provider ID.
	 * @param int|null $now      Optional. Unix time. Default now.
	 * @return int|null Unix time the pause ends, or null when not paused.
	 */
	public function get_until( string $provider, ?int $now = null ): ?int {
		$state = $this->get( $provider );

		return null !== $state && $state['until'] > ( $now ?? time() ) ? $state['until'] : null;
	}

	/**
	 * Checks whether a provider is paused provider-wide.
	 *
	 * @since x.x.x
	 *
	 * @param string   $provider Provider ID.
	 * @param int|null $now      Optional. Unix time. Default now.
	 * @return bool True when paused.
	 */
	public function is_paused( string $provider, ?int $now = null ): bool {
		return null !== $this->get_until( $provider, $now );
	}

	/**
	 * Returns when a target's pause ends.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Embeddings\Sync\Embedding_Target $target The target.
	 * @param int|null                                       $now    Optional. Unix time. Default now.
	 * @return int|null Unix time the pause ends, or null when not paused.
	 */
	public function get_until_for( Embedding_Target $target, ?int $now = null ): ?int {
		$now   = $now ?? time();
		$until = null;

		foreach ( array( $this->get( $target->get_provider() ), $this->get( $target->get_provider(), $target->get_model() ) ) as $state ) {
			if ( null === $state || $state['until'] <= $now ) {
				continue;
			}

			$until = max( (int) $until, $state['until'] );
		}

		return $until;
	}

	/**
	 * Records a rate limit: 30 s, doubling per consecutive limit, capped at 15 min.
	 *
	 * @since x.x.x
	 *
	 * @param string   $provider Provider ID.
	 * @param string   $error    Error message.
	 * @param int|null $now      Optional. Unix time. Default now.
	 * @return int Unix time the pause ends.
	 */
	public function record_rate_limit( string $provider, string $error, ?int $now = null ): int {
		$previous = $this->get( $provider );
		$delay    = null !== $previous && Embedding_Client_Exception::RATE_LIMITED === $previous['error_class']
			? min( self::MAX_DELAY, $previous['delay'] * 2 )
			: self::MIN_DELAY;

		return $this->store( $provider, $delay, Embedding_Client_Exception::RATE_LIMITED, $error, $now ?? time() );
	}

	/**
	 * Records a provider-level failure: pause for an hour.
	 *
	 * @since x.x.x
	 *
	 * @param string      $provider Provider ID.
	 * @param string      $error    Error message, shown in sync status.
	 * @param int|null    $now      Optional. Unix time. Default now.
	 * @param string|null $model    Optional. Model ID to pause only that model. Default null (provider-wide).
	 * @return int Unix time the pause ends.
	 */
	public function record_provider_error( string $provider, string $error, ?int $now = null, ?string $model = null ): int {
		return $this->store( $provider, self::PROVIDER_PAUSE, Embedding_Client_Exception::PROVIDER, $error, $now ?? time(), $model );
	}

	/**
	 * Clears a provider's or model's state after a success or an explicit retry.
	 *
	 * @since x.x.x
	 *
	 * @param string      $provider Provider ID.
	 * @param string|null $model    Optional. Model ID to clear model-scoped state. Default null (provider-wide).
	 */
	public function clear( string $provider, ?string $model = null ): void {
		if ( null === $this->get( $provider, $model ) ) {
			return;
		}

		delete_transient( $this->key( $provider, $model ) );
	}

	/**
	 * Stores a pause.
	 *
	 * @since x.x.x
	 *
	 * @param string      $provider    Provider ID.
	 * @param int         $delay       Pause length in seconds.
	 * @param string      $error_class Failure class.
	 * @param string      $error       Error message.
	 * @param int         $now         Unix time.
	 * @param string|null $model       Optional. Model ID for model-scoped state. Default null (provider-wide).
	 * @return int Unix time the pause ends.
	 */
	private function store( string $provider, int $delay, string $error_class, string $error, int $now, ?string $model = null ): int {
		$until = $now + $delay;

		set_transient(
			$this->key( $provider, $model ),
			array(
				'until'       => $until,
				'delay'       => $delay,
				'error_class' => $error_class,
				// Stored raw; escaped wherever it is output.
				'error'       => wp_specialchars_decode( $error, ENT_QUOTES ),
			),
			$delay + self::MAX_DELAY
		);

		return $until;
	}

	/**
	 * Returns the transient name for a provider, or for one of its models.
	 *
	 * @since x.x.x
	 *
	 * @param string      $provider Provider ID.
	 * @param string|null $model    Optional. Model ID. Default null (provider-wide).
	 * @return string The transient name.
	 */
	private function key( string $provider, ?string $model = null ): string {
		return self::TRANSIENT_PREFIX . md5( null === $model ? $provider : $provider . "\0" . $model );
	}
}
