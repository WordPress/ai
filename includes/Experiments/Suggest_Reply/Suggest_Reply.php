<?php
declare( strict_types=1 );

namespace WordPress\AI\Experiments\Suggest_Reply;

use WordPress\AI\Abilities\Suggest_Reply\Reply_Suggestion as Reply_Suggestion_Ability;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Experiment_Category;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class Suggest_Reply extends Abstract_Feature {

	public static function get_id(): string {
		return 'suggest-reply';
	}

	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Suggest Reply', 'ai' ),
			'description' => __( 'Adds a "Suggest reply" action to the Comments screen. AI generates reply candidates based on the comment content, post context, and optional editorial guidelines, which the moderator can review, edit, and insert.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
		);
	}

	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );

		add_filter( 'comment_row_actions', array( $this, 'add_row_action' ), 10, 2 );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_abilities(): void {
		wp_register_ability(
			'ai/reply-suggestion',
			array(
				'label'         => __( 'Reply Suggestion', 'ai' ),
				'description'   => __( 'Generates AI-powered reply suggestions for a comment.', 'ai' ),
				'ability_class' => Reply_Suggestion_Ability::class,
			)
		);
	}

	public function add_row_action( $actions, $comment ): array {
		if (
			! is_array( $actions ) ||
			! $comment ||
			! is_a( $comment, '\WP_Comment' )
		) {
			return $actions;
		}

		$actions['wpai_suggest_reply'] = sprintf(
			'<a href="#" class="wpai-suggest-reply" data-comment-id="%d" aria-label="%s">%s</a>',
			absint( $comment->comment_ID ),
			esc_attr__( 'Suggest a reply for this comment', 'ai' ),
			esc_html__( 'Suggest reply', 'ai' )
		);

		return $actions;
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'edit-comments.php', 'index.php' ), true ) ) {
			return;
		}

		Asset_Loader::enqueue_script(
			'suggest_reply',
			'experiments/suggest-reply',
			array( 'include_core_abilities' => true )
		);

		Asset_Loader::localize_script(
			'suggest_reply',
			'SuggestReplyData',
			array(
				'enabled' => $this->is_enabled(),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}
}
