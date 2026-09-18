<?php
/**
 * A testable Embedding_Sync_Manager that never makes a live provider request.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embedding_Sync
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Includes\Embedding_Sync;

use WP_Error;
use WordPress\AI\Embedding_Sync\Embedding_Sync_Manager;

/**
 * Overrides the one method that would otherwise call the AI Client SDK, so
 * {@see Embedding_Sync_Manager::process_queue()} can be tested without a live provider.
 *
 * @since x.x.x
 */
class Testable_Embedding_Sync_Manager extends Embedding_Sync_Manager {

	/**
	 * Number of times generate_batch() was called.
	 *
	 * @var int
	 */
	public int $generate_calls = 0;

	/**
	 * The texts passed to the most recent generate_batch() call.
	 *
	 * @var list<string>
	 */
	public array $last_texts = array();

	/**
	 * Error to return instead of generating vectors, if set.
	 *
	 * @var \WP_Error|null
	 */
	public ?WP_Error $error_to_return = null;

	/**
	 * Vector to return for each text, if generation succeeds.
	 *
	 * @var list<float>
	 */
	public array $vector_to_return = array( 0.1, 0.2, 0.3 );

	/**
	 * {@inheritDoc}
	 */
	protected function generate_batch( array $texts, string $provider, string $model ) {
		++$this->generate_calls;
		$this->last_texts = $texts;

		if ( null !== $this->error_to_return ) {
			return $this->error_to_return;
		}

		return array_fill( 0, count( $texts ), $this->vector_to_return );
	}
}
