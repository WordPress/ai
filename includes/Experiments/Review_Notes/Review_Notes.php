<?php
/**
 * AI Review Notes experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Review_Notes;

use WordPress\AI\Abilities\Review_Notes\Review_Notes as Review_Notes_Ability;
use WordPress\AI\Abstracts\Abstract_Experiment;
use WordPress\AI\Asset_Loader;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI Review Notes experiment.
 *
 * Runs a block-by-block AI review pass on post content, creating WordPress Notes
 * with actionable suggestions for Accessibility, Readability, Grammar, and SEO.
 *
 * @since x.x.x
 */
class Review_Notes extends Abstract_Experiment {

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @return array{id: string, label: string, description: string} Experiment metadata.
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'review-notes',
			'label'       => __( 'Review Notes', 'ai' ),
			'description' => __( 'Reviews post content block-by-block and adds Notes with suggestions for Accessibility, Readability, Grammar, and SEO.', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_assets' ) );
		add_action( 'deleted_comment', array( $this, 'clear_block_note_meta' ), 10, 2 );
		add_action( 'trashed_comment', array( $this, 'clear_block_note_meta' ), 10, 2 );
	}

	/**
	 * Registers any needed abilities.
	 *
	 * @since x.x.x
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'ai/' . $this->get_id(),
			array(
				'label'         => $this->get_label(),
				'description'   => $this->get_description(),
				'ability_class' => Review_Notes_Ability::class,
			),
		);
	}

	/**
	 * Clears the noteId from block metadata when its associated note is deleted or trashed.
	 *
	 * Only root notes (those stored directly in block metadata) need clearing;
	 * reply deletions do not affect block metadata.
	 *
	 * @since x.x.x
	 *
	 * @param int        $comment_id The deleted or trashed comment ID.
	 * @param \WP_Comment $comment   The comment object.
	 */
	public function clear_block_note_meta( int $comment_id, \WP_Comment $comment ): void {
		if ( 'note' !== $comment->comment_type || 0 !== (int) $comment->comment_parent ) {
			return;
		}

		$post = get_post( (int) $comment->comment_post_ID );

		if ( ! $post ) {
			return;
		}

		$blocks = parse_blocks( $post->post_content );
		$result = $this->clear_note_id_from_blocks( $blocks, $comment_id );

		if ( ! $result['changed'] ) {
			return;
		}

		wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => serialize_blocks( $result['blocks'] ),
			)
		);
	}

	/**
	 * Recursively clears a specific noteId from block metadata in a blocks array.
	 *
	 * @since x.x.x
	 *
	 * @param array<int|string, mixed> $blocks  The blocks array from parse_blocks().
	 * @param int                      $note_id The note ID to remove.
	 * @return array{blocks: array<int|string, mixed>, changed: bool}
	 */
	private function clear_note_id_from_blocks( array $blocks, int $note_id ): array {
		$changed = false;

		foreach ( $blocks as &$block ) {
			if (
				isset( $block['attrs']['metadata']['noteId'] ) &&
				(int) $block['attrs']['metadata']['noteId'] === $note_id
			) {
				unset( $block['attrs']['metadata']['noteId'] );

				if ( empty( $block['attrs']['metadata'] ) ) {
					unset( $block['attrs']['metadata'] );
				}

				$changed = true;
			}

			if ( empty( $block['innerBlocks'] ) ) {
				continue;
			}

			$inner                = $this->clear_note_id_from_blocks( $block['innerBlocks'], $note_id );
			$block['innerBlocks'] = $inner['blocks'];

			if ( ! $inner['changed'] ) {
				continue;
			}

			$changed = true;
		}

		unset( $block );

		return array(
			'blocks'  => $blocks,
			'changed' => $changed,
		);
	}

	/**
	 * Enqueues and localizes the block editor script.
	 *
	 * @since x.x.x
	 */
	public function enqueue_assets(): void {
		Asset_Loader::enqueue_script( 'review_notes', 'experiments/review-notes' );
		Asset_Loader::localize_script(
			'review_notes',
			'ReviewNotesData',
			array(
				'enabled' => $this->is_enabled(),
			)
		);
	}
}
