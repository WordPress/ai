<?php
/**
 * Embedding Sync experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Embedding_Sync;

use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Embedding_Sync\Embedding_Sync_Manager;
use WordPress\AI\Experiments\Experiment_Category;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps stored post embeddings in sync with post content in the background.
 *
 * Requires a provider and model to be configured in this experiment's Developer Options before it
 * does anything: embedding vectors are only comparable to other vectors from the same model, so
 * background sync never chooses one on its own.
 *
 * @since x.x.x
 */
class Embedding_Sync extends Abstract_Feature {

	/**
	 * Shared sync manager instance.
	 *
	 * @var \WordPress\AI\Embedding_Sync\Embedding_Sync_Manager|null
	 */
	private ?Embedding_Sync_Manager $manager = null;

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'embedding-sync';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Embedding Sync', 'ai' ),
			'description' => __( 'Keeps stored embeddings for posts and pages up to date in the background as content changes. Requires a provider and model to be selected in Developer Options below.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
			'capability'  => 'none',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		$this->get_manager()->init();
	}

	/**
	 * Lazily instantiates the sync manager.
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AI\Embedding_Sync\Embedding_Sync_Manager
	 */
	private function get_manager(): Embedding_Sync_Manager {
		if ( null === $this->manager ) {
			$this->manager = new Embedding_Sync_Manager( static::get_id() );
		}

		return $this->manager;
	}
}
