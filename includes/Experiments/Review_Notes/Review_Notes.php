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
use WordPress\AI\Experiment_Category;

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
 * @since 0.4.0
 */
class Review_Notes extends Abstract_Experiment {

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.4.0
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'review-notes',
			'label'       => __( 'Review Notes', 'ai' ),
			'description' => __( 'Reviews post content block-by-block and adds Notes with suggestions for Accessibility, Readability, Grammar, and SEO.', 'ai' ),
			'category'    => Experiment_Category::EDITOR,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.4.0
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_assets' ) );
		add_filter( 'rest_pre_insert_comment', array( $this, 'maybe_set_ai_author' ), 10, 2 );

		register_meta(
			'comment',
			'ai_note',
			array(
				'type'          => 'boolean',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => static function (): bool {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	/**
	 * Registers any needed abilities.
	 *
	 * @since 0.4.0
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
	 * Overrides the author fields for AI-generated Notes before they are inserted.
	 *
	 * Fires via the rest_pre_insert_comment filter. When the REST request includes
	 * meta.ai_note = true, replaces the authenticated user's identity with a generic
	 * "AI" author so Notes are not attributed to a personal account.
	 *
	 * @since 0.4.0
	 *
	 * @param array<string, mixed>|\WP_Error $prepared_comment The prepared comment data for wp_insert_comment().
	 * @param \WP_REST_Request<array<string, mixed>> $request The REST API request.
	 * @return array<string, mixed>|\WP_Error Modified comment data, or original on WP_Error or non-AI requests.
	 */
	public function maybe_set_ai_author( $prepared_comment, \WP_REST_Request $request ) {
		if ( is_wp_error( $prepared_comment ) ) {
			return $prepared_comment;
		}

		$meta = $request->get_param( 'meta' );

		if ( ! is_array( $meta ) || empty( $meta['ai_note'] ) ) {
			return $prepared_comment;
		}

		$prepared_comment['comment_author']       = __( 'WordPress AI', 'ai' );
		$prepared_comment['comment_author_email'] = '';
		$prepared_comment['comment_author_url']   = '';
		$prepared_comment['user_id']              = 0;

		return $prepared_comment;
	}

	/**
	 * Enqueues and localizes the block editor script.
	 *
	 * @since 0.4.0
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
