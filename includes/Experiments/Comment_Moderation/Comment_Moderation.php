<?php
/**
 * Comment Moderation experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Comment_Moderation;

use WordPress\AI\Abstracts\Abstract_Experiment;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Abilities\Comment_Moderation\Comment_Analysis;
use WordPress\AI\Abilities\Comment_Moderation\Reply_Suggestion;

/**
 * AI-powered comment moderation experiment.
 *
 * Provides toxicity detection, sentiment analysis, and AI-generated reply suggestions
 * for WordPress comments.
 *
 * @since 0.1.0
 */
class Comment_Moderation extends Abstract_Experiment {

	/**
	 * Comment meta key for toxicity score.
	 *
	 * @var string
	 */
	public const META_TOXICITY_SCORE = '_ai_toxicity_score';

	/**
	 * Comment meta key for sentiment.
	 *
	 * @var string
	 */
	public const META_SENTIMENT = '_ai_sentiment';

	/**
	 * Comment meta key for analysis status.
	 *
	 * @var string
	 */
	public const META_ANALYSIS_STATUS = '_ai_analysis_status';

	/**
	 * Comment meta key for analysis timestamp.
	 *
	 * @var string
	 */
	public const META_ANALYZED_AT = '_ai_analyzed_at';

	/**
	 * Analysis status: pending.
	 *
	 * @var string
	 */
	public const STATUS_PENDING = 'pending';

	/**
	 * Analysis status: processing.
	 *
	 * @var string
	 */
	public const STATUS_PROCESSING = 'processing';

	/**
	 * Analysis status: complete.
	 *
	 * @var string
	 */
	public const STATUS_COMPLETE = 'complete';

	/**
	 * Analysis status: failed.
	 *
	 * @var string
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'comment-moderation',
			'label'       => __( 'Comment Moderation', 'ai' ),
			'description' => __( 'AI-powered comment analysis with toxicity detection, sentiment analysis, and reply suggestions.', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		// Register abilities.
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );

		// Mark new comments as pending analysis.
		add_action( 'comment_post', array( $this, 'mark_comment_pending' ), 10, 1 );

		// Add columns to comments list table.
		add_filter( 'manage_edit-comments_columns', array( $this, 'add_columns' ) );
		add_action( 'manage_comments_custom_column', array( $this, 'render_column' ), 10, 2 );

		// Add row action for suggest reply.
		add_filter( 'comment_row_actions', array( $this, 'add_row_actions' ), 10, 2 );

		// Enqueue assets.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Add inline styles for badges.
		add_action( 'admin_head-edit-comments.php', array( $this, 'add_inline_styles' ) );
	}

	/**
	 * Registers the comment moderation abilities.
	 *
	 * @since 0.1.0
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'ai/comment-analysis',
			array(
				'label'         => __( 'Comment Analysis', 'ai' ),
				'description'   => __( 'Analyzes a comment for toxicity and sentiment.', 'ai' ),
				'ability_class' => Comment_Analysis::class,
			)
		);

		wp_register_ability(
			'ai/reply-suggestion',
			array(
				'label'         => __( 'Reply Suggestion', 'ai' ),
				'description'   => __( 'Generates reply suggestions for a comment.', 'ai' ),
				'ability_class' => Reply_Suggestion::class,
			)
		);
	}

	/**
	 * Marks a new comment as pending analysis.
	 *
	 * @since 0.1.0
	 *
	 * @param int $comment_id The comment ID.
	 */
	public function mark_comment_pending( int $comment_id ): void {
		update_comment_meta( $comment_id, self::META_ANALYSIS_STATUS, self::STATUS_PENDING );
	}

	/**
	 * Adds custom columns to the comments list table.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $columns The existing columns.
	 * @return array<string, string> The modified columns.
	 */
	public function add_columns( array $columns ): array {
		$new_columns = array();

		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;

			// Insert our columns after the 'author' column.
			if ( 'author' === $key ) {
				$new_columns['ai_sentiment'] = __( 'Sentiment', 'ai' );
				$new_columns['ai_toxicity']  = __( 'Toxicity', 'ai' );
			}
		}

		return $new_columns;
	}

	/**
	 * Renders the custom column content.
	 *
	 * @since 0.1.0
	 *
	 * @param string $column_name The column name.
	 * @param int    $comment_id  The comment ID.
	 */
	public function render_column( string $column_name, int $comment_id ): void {
		$status = get_comment_meta( $comment_id, self::META_ANALYSIS_STATUS, true );

		if ( 'ai_sentiment' === $column_name ) {
			$this->render_sentiment_column( $comment_id, $status );
		} elseif ( 'ai_toxicity' === $column_name ) {
			$this->render_toxicity_column( $comment_id, $status );
		}
	}

	/**
	 * Renders the sentiment column content.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $comment_id The comment ID.
	 * @param string $status     The analysis status.
	 */
	private function render_sentiment_column( int $comment_id, string $status ): void {
		if ( self::STATUS_COMPLETE === $status ) {
			$sentiment = get_comment_meta( $comment_id, self::META_SENTIMENT, true );
			$this->render_sentiment_badge( $sentiment );
		} else {
			$this->render_pending_badge( $comment_id, $status );
		}
	}

	/**
	 * Renders the toxicity column content.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $comment_id The comment ID.
	 * @param string $status     The analysis status.
	 */
	private function render_toxicity_column( int $comment_id, string $status ): void {
		if ( self::STATUS_COMPLETE === $status ) {
			$score = (float) get_comment_meta( $comment_id, self::META_TOXICITY_SCORE, true );
			$this->render_toxicity_badge( $score );
		} else {
			echo '<span class="ai-badge ai-badge--pending">—</span>';
		}
	}

	/**
	 * Renders a sentiment badge.
	 *
	 * @since 0.1.0
	 *
	 * @param string $sentiment The sentiment value.
	 */
	private function render_sentiment_badge( string $sentiment ): void {
		$badges = array(
			'positive' => array(
				'label' => __( 'Positive', 'ai' ),
				'class' => 'ai-badge--positive',
				'icon'  => '😊',
			),
			'negative' => array(
				'label' => __( 'Negative', 'ai' ),
				'class' => 'ai-badge--negative',
				'icon'  => '😟',
			),
			'neutral'  => array(
				'label' => __( 'Neutral', 'ai' ),
				'class' => 'ai-badge--neutral',
				'icon'  => '😐',
			),
		);

		$badge = $badges[ $sentiment ] ?? $badges['neutral'];

		printf(
			'<span class="ai-badge %s" title="%s">%s %s</span>',
			esc_attr( $badge['class'] ),
			esc_attr( $badge['label'] ),
			esc_html( $badge['icon'] ),
			esc_html( $badge['label'] )
		);
	}

	/**
	 * Renders a toxicity badge.
	 *
	 * @since 0.1.0
	 *
	 * @param float $score The toxicity score (0-1).
	 */
	private function render_toxicity_badge( float $score ): void {
		if ( $score >= 0.7 ) {
			$label = __( 'High', 'ai' );
			$class = 'ai-badge--high-toxicity';
			$icon  = '⚠️';
		} elseif ( $score >= 0.4 ) {
			$label = __( 'Medium', 'ai' );
			$class = 'ai-badge--medium-toxicity';
			$icon  = '⚡';
		} else {
			$label = __( 'Low', 'ai' );
			$class = 'ai-badge--low-toxicity';
			$icon  = '✓';
		}

		printf(
			'<span class="ai-badge %s" title="%s (%.0f%%)">%s %s</span>',
			esc_attr( $class ),
			esc_attr( $label ),
			$score * 100,
			esc_html( $icon ),
			esc_html( $label )
		);
	}

	/**
	 * Renders a pending analysis badge.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $comment_id The comment ID.
	 * @param string $status     The analysis status.
	 */
	private function render_pending_badge( int $comment_id, string $status ): void {
		$status_class = '';
		$status_text  = '';

		switch ( $status ) {
			case self::STATUS_PROCESSING:
				$status_class = 'ai-badge--processing';
				$status_text  = __( 'Analyzing...', 'ai' );
				break;
			case self::STATUS_FAILED:
				$status_class = 'ai-badge--failed';
				$status_text  = __( 'Failed', 'ai' );
				break;
			default:
				$status_class = 'ai-badge--pending';
				$status_text  = __( 'Pending', 'ai' );
				break;
		}

		printf(
			'<span class="ai-badge %s" data-comment-id="%d" data-ai-status="%s">%s</span>',
			esc_attr( $status_class ),
			absint( $comment_id ),
			esc_attr( $status ?: self::STATUS_PENDING ),
			esc_html( $status_text )
		);
	}

	/**
	 * Adds row actions to the comments list.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $actions The existing actions.
	 * @param \WP_Comment           $comment The comment object.
	 * @return array<string, string> The modified actions.
	 */
	public function add_row_actions( array $actions, \WP_Comment $comment ): array {
		// Only show for approved comments.
		if ( '1' !== $comment->comment_approved ) {
			return $actions;
		}

		$actions['ai_suggest_reply'] = sprintf(
			'<a href="#" class="ai-suggest-reply" data-comment-id="%d">%s</a>',
			absint( $comment->comment_ID ),
			esc_html__( 'AI Reply', 'ai' )
		);

		return $actions;
	}

	/**
	 * Enqueues admin assets for the comments screen.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'edit-comments.php' !== $hook_suffix ) {
			return;
		}

		Asset_Loader::enqueue_script( 'comment_moderation', 'experiments/comment-moderation' );
		Asset_Loader::localize_script(
			'comment_moderation',
			'CommentModerationData',
			array(
				'enabled' => $this->is_enabled(),
				'nonce'   => wp_create_nonce( 'ai_comment_moderation' ),
			)
		);

		// Enqueue WordPress components styles.
		wp_enqueue_style( 'wp-components' );
	}

	/**
	 * Adds inline styles for the comment moderation badges.
	 *
	 * @since 0.1.0
	 */
	public function add_inline_styles(): void {
		?>
		<style>
			.column-ai_sentiment,
			.column-ai_toxicity {
				width: 100px;
			}

			.ai-badge {
				display: inline-flex;
				align-items: center;
				gap: 4px;
				padding: 2px 8px;
				border-radius: 3px;
				font-size: 12px;
				font-weight: 500;
				line-height: 1.4;
				white-space: nowrap;
			}

			.ai-badge--positive {
				background-color: #d4edda;
				color: #155724;
			}

			.ai-badge--negative {
				background-color: #f8d7da;
				color: #721c24;
			}

			.ai-badge--neutral {
				background-color: #e2e3e5;
				color: #383d41;
			}

			.ai-badge--low-toxicity {
				background-color: #d4edda;
				color: #155724;
			}

			.ai-badge--medium-toxicity {
				background-color: #fff3cd;
				color: #856404;
			}

			.ai-badge--high-toxicity {
				background-color: #f8d7da;
				color: #721c24;
			}

			.ai-badge--pending {
				background-color: #f0f0f0;
				color: #666;
			}

			.ai-badge--processing {
				background-color: #cce5ff;
				color: #004085;
			}

			.ai-badge--failed {
				background-color: #f8d7da;
				color: #721c24;
			}

			.ai-suggest-reply {
				color: #2271b1;
			}

			.ai-suggest-reply:hover {
				color: #135e96;
			}
		</style>
		<?php
	}
}
