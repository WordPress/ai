<?php
/**
 * Comment Moderation Feature Class.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Comment_Moderation;

use WordPress\AI\Abilities\Comment_Moderation\Comment_Moderation as Comment_Moderation_Ability;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Experiments\Experiment_Category;

/**
 * Class Comment_Moderation
 *
 * @since 0.7.0
 */
class Comment_Moderation extends Abstract_Feature {

	/**
	 * Get the feature ID.
	 *
	 * @since 0.7.0
	 *
	 * @return string
	 */
	public static function get_id(): string {
		return 'comment_moderation';
	}

	/**
	 * Loads feature metadata.
	 *
	 * @since 0.7.0
	 *
	 * @return array{
	 *  label: string,
	 *  description: string,
	 *  category?: string,
	 *  stability?: 'deprecated'|'experimental'|'stable',
	 * } Feature metadata.
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'AI Comment Moderation', 'ai' ),
			'description' => __( 'Automatically holds or trashes comments based on AI toxicity and spam analysis.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
		);
	}

	/**
	 * Initialize the feature by hooking into WordPress.
	 *
	 * @since 0.7.0
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );

		// Hook into the pre-processing of comments to evaluate them.
		add_filter( 'preprocess_comment', array( $this, 'evaluate_comment_with_ai' ), 10, 1 );

		// Add an admin notice if the comment was flagged by AI.
		add_action( 'admin_notices', array( $this, 'display_moderation_notice' ) );
	}

	/**
	 * Registers any needed abilities.
	 *
	 * @since 0.7.0
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'ai/' . $this->get_id(),
			array(
				'label'         => $this->get_label(),
				'description'   => $this->get_description(),
				'ability_class' => Comment_Moderation_Ability::class,
			),
		);
	}

	/**
	 * Evaluate the comment using the AI ability.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string, mixed> $commentdata The comment data array.
	 * @return array<string, mixed> Modified comment data array.
	 */
	public function evaluate_comment_with_ai( array $commentdata ) {
		// Skip moderation if the user is a logged-in administrator.
		if ( current_user_can( 'manage_options' ) ) {
			return $commentdata;
		}

		$args = array(
			'comment_text' => $commentdata['comment_content'] ?? '',
			'author_name'  => $commentdata['comment_author'] ?? '',
			'author_url'   => $commentdata['comment_author_url'] ?? '',
		);

		// Execute through the WP_Ability execution
		if ( function_exists( 'wp_execute_ability' ) ) {
			$result = wp_execute_ability( 'ai/' . $this->get_id(), $args );
		} else {
			$ability = new Comment_Moderation_Ability( 'ai/comment_moderation' );
			$result  = $ability->execute( $args );
		}

		// If AI fails, fallback to default WordPress behavior.
		if ( is_wp_error( $result ) ) {
			return $commentdata;
		}

		// Apply the AI's recommendation to the comment status.
		if ( isset( $result['recommendation'] ) ) {
			if ( 'spam' === $result['recommendation'] || ( isset( $result['is_spam'] ) && true === $result['is_spam'] ) ) {
				add_filter(
					'pre_comment_approved',
					static function () {
						return 'spam';
					}
				);
			} elseif ( 'hold' === $result['recommendation'] || ( isset( $result['toxicity_score'] ) && $result['toxicity_score'] >= 50 ) ) {
				add_filter(
					'pre_comment_approved',
					static function () {
						return '0';
					}
				);
			}
		}

		if ( isset( $result['reason'] ) && isset( $result['toxicity_score'] ) && $result['toxicity_score'] >= 50 ) {
			set_transient( 'wpai_last_moderation_reason', $result['reason'], 60 );
		}

		return $commentdata;
	}

	/**
	 * Display an admin notice if a comment was recently held by AI.
	 *
	 * @since 0.7.0
	 */
	public function display_moderation_notice(): void {
		$reason = get_transient( 'wpai_last_moderation_reason' );
		if ( ! $reason ) {
			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'AI Moderation Alert:', 'ai' ),
			esc_html( $reason )
		);
		delete_transient( 'wpai_last_moderation_reason' );
	}
}
