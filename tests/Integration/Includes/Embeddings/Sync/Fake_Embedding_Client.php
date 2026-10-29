<?php
/**
 * Deterministic embedding client for tests.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embeddings\Sync
 */

namespace WordPress\AI\Tests\Integration\Includes\Embeddings\Sync;

use WordPress\AI\Embeddings\Sync\Embedding_Client_Exception;
use WordPress\AI\Embeddings\Sync\Embedding_Client_Interface;
use WordPress\AI\Embeddings\Sync\Embedding_Target;

/**
 * Returns fixed vectors and fails on demand. Never touches the network.
 *
 * @since x.x.x
 */
class Fake_Embedding_Client implements Embedding_Client_Interface {

	/**
	 * Every call made, in order.
	 *
	 * @var list<array{provider: string, model: string, inputs: list<string>}>
	 */
	public array $calls = array();

	/**
	 * Outcomes for the next calls, consumed in order; null means succeed.
	 *
	 * @var list<\WordPress\AI\Embeddings\Sync\Embedding_Client_Exception|null>
	 */
	public array $failures = array();

	/**
	 * Optional callback deciding failure from the inputs; return an exception to throw it.
	 *
	 * @var callable(list<string>, \WordPress\AI\Embeddings\Sync\Embedding_Target): (\WordPress\AI\Embeddings\Sync\Embedding_Client_Exception|null)|null
	 */
	public $fail_when = null;

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function embed( Embedding_Target $target, array $inputs ): array {
		$this->calls[] = array(
			'provider' => $target->get_provider(),
			'model'    => $target->get_model(),
			'inputs'   => $inputs,
		);

		if ( null !== $this->fail_when ) {
			$failure = ( $this->fail_when )( $inputs, $target );

			if ( $failure instanceof Embedding_Client_Exception ) {
				throw $failure;
			}
		}

		if ( array() !== $this->failures ) {
			$failure = array_shift( $this->failures );

			if ( $failure instanceof Embedding_Client_Exception ) {
				throw $failure;
			}
		}

		return array_map( array( self::class, 'vector_for' ), $inputs );
	}

	/**
	 * Returns a deterministic non-zero vector for an input.
	 *
	 * @since x.x.x
	 *
	 * @param string $input Input text.
	 * @return list<float> The vector.
	 */
	public static function vector_for( string $input ): array {
		return array( ( crc32( $input ) % 1000 ) / 1000 + 0.001, 0.5, 0.25 );
	}

	/**
	 * Returns how many inputs were sent across all calls.
	 *
	 * @since x.x.x
	 *
	 * @return int The input count.
	 */
	public function input_count(): int {
		return array_sum( array_map( static fn( array $call ): int => count( $call['inputs'] ), $this->calls ) );
	}
}
