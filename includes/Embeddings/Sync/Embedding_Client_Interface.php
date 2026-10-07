<?php
/**
 * Contract for generating embeddings for the sync layer.
 *
 * @package WordPress\AI\Embeddings\Sync
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Generates one vector per input for a target's model.
 *
 * @since x.x.x
 */
interface Embedding_Client_Interface {

	/**
	 * Generates embeddings.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Embeddings\Sync\Embedding_Target $target The provider and model to use.
	 * @param list<string>                                   $inputs Non-empty texts.
	 * @return list<list<float>> One vector per input, in input order.
	 *
	 * @throws \WordPress\AI\Embeddings\Sync\Embedding_Client_Exception On any failure, classified.
	 */
	public function embed( Embedding_Target $target, array $inputs ): array;
}
