<?php
/**
 * Comment Moderation experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Comment_Moderation;

use WordPress\AI\Abilities\Comment_Moderation\Comment_Analysis as Comment_Analysis_Ability;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Experiment_Category;

/**
 * Comment moderation experiment.
 *
 * Provides toxicity detection, sentiment analysis, and moderation
 * for WordPress comments.
 *
 * @since x.x.x
 */
class Comment_Moderation extends Abstract_Feature {

	/**
	 * Comment meta key for toxicity score.
	 *
	 * @var string
	 */
	public const META_TOXICITY_SCORE = '_wpai_toxicity_score';

	/**
	 * Comment meta key for sentiment.
	 *
	 * @var string
	 */
	public const META_SENTIMENT = '_wpai_sentiment';

	/**
	 * Comment meta key for analysis status.
	 *
	 * @var string
	 */
	public const META_ANALYSIS_STATUS = '_wpai_analysis_status';

	/**
	 * Comment meta key for analysis timestamp.
	 *
	 * @var string
	 */
	public const META_ANALYZED_AT = '_wpai_analyzed_at';

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
	 * @since x.x.x
	 */
	public static function get_id(): string {
		return 'comment-moderation';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Comment Moderation', 'ai' ),
			'description' => __( 'Automatically moderate comments based on toxicity detection and sentiment analysis. Requires an AI connector that includes support for text generation models.', 'ai' ),
			'category'    => Experiment_Category::EDITOR,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		// Register abilities.
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );

		// Add columns to comments list table.
		add_filter( 'manage_edit-comments_columns', array( $this, 'add_columns' ) );
		add_action( 'manage_comments_custom_column', array( $this, 'render_column' ), 10, 2 );

		// Add row action for suggest reply.
		add_filter( 'comment_row_actions', array( $this, 'add_row_actions' ), 10, 2 );

		// Add bulk action.
		add_filter( 'bulk_actions-edit-comments', array( $this, 'add_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-comments', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'show_bulk_action_notice' ) );

		// Enqueue assets.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Add inline styles for badges.
		add_action( 'admin_head-edit-comments.php', array( $this, 'add_inline_styles' ) );
	}

	/**
	 * Registers the comment moderation abilities.
	 *
	 * @since x.x.x
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'ai/comment-analysis',
			array(
				'label'         => __( 'Comment Analysis', 'ai' ),
				'description'   => __( 'Analyzes a comment for toxicity and sentiment.', 'ai' ),
				'ability_class' => Comment_Analysis_Ability::class,
			)
		);
	}

	/**
	 * Adds custom columns to the comments list table.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, string> $columns The existing columns.
	 * @return array<string, string> The modified columns.
	 */
	public function add_columns( $columns ): array {
		$new_columns = array();

		foreach ( (array) $columns as $key => $value ) {
			$new_columns[ $key ] = $value;

			// Insert our columns after the 'author' column.
			if ( 'author' !== $key ) {
				continue;
			}

			$new_columns['wpai_sentiment'] = __( 'Sentiment', 'ai' );
			$new_columns['wpai_toxicity']  = __( 'Toxicity', 'ai' );
		}

		return $new_columns;
	}

	/**
	 * Renders the custom column content.
	 *
	 * @since x.x.x
	 *
	 * @param string $column_name The column name.
	 * @param int    $comment_id  The comment ID.
	 */
	public function render_column( $column_name, $comment_id ): void {
		$status = get_comment_meta( (int) $comment_id, self::META_ANALYSIS_STATUS, true );

		if ( 'wpai_sentiment' === (string) $column_name ) {
			$this->render_sentiment_column( (int) $comment_id, $status );
		} elseif ( 'wpai_toxicity' === (string) $column_name ) {
			$this->render_toxicity_column( (int) $comment_id, $status );
		}
	}

	/**
	 * Renders the sentiment column content.
	 *
	 * @since x.x.x
	 *
	 * @param int    $comment_id The comment ID.
	 * @param string $status     The analysis status.
	 */
	private function render_sentiment_column( int $comment_id, string $status ): void {
		if ( self::STATUS_COMPLETE === $status ) {
			$sentiment = get_comment_meta( $comment_id, self::META_SENTIMENT, true );
			$this->render_sentiment_badge( $sentiment );
		} elseif ( self::STATUS_PENDING === $status ) {
			$this->render_pending_badge( $comment_id );
		} elseif ( self::STATUS_PROCESSING === $status ) {
			$this->render_processing_badge( $comment_id );
		} else {
			// Empty or not analyzed - show dash.
			echo '<span class="ai-badge ai-badge--empty">—</span>';
		}
	}

	/**
	 * Renders the toxicity column content.
	 *
	 * @since x.x.x
	 *
	 * @param int    $comment_id The comment ID.
	 * @param string $status     The analysis status.
	 */
	private function render_toxicity_column( int $comment_id, string $status ): void {
		if ( self::STATUS_COMPLETE === $status ) {
			$score = (float) get_comment_meta( $comment_id, self::META_TOXICITY_SCORE, true );
			$this->render_toxicity_badge( $score );
		} elseif ( self::STATUS_PENDING === $status ) {
			$this->render_pending_badge( $comment_id );
		} elseif ( self::STATUS_PROCESSING === $status ) {
			$this->render_processing_badge( $comment_id );
		} else {
			// Empty or not analyzed - show dash.
			echo '<span class="ai-badge ai-badge--empty">—</span>';
		}
	}

	/**
	 * Renders a sentiment badge.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
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
			'<span class="ai-badge %s" title="%s (%d%%)">%s %s</span>',
			esc_attr( $class ),
			esc_attr( $label ),
			absint( $score * 100 ),
			esc_html( $icon ),
			esc_html( $label )
		);
	}

	/**
	 * Renders a pending badge for comments queued for analysis.
	 *
	 * @since x.x.x
	 *
	 * @param int $comment_id The comment ID.
	 */
	private function render_pending_badge( int $comment_id ): void {
		printf(
			'<span class="ai-badge ai-badge--pending" data-comment-id="%d" data-ai-status="pending">%s</span>',
			absint( $comment_id ),
			esc_html__( 'Queued', 'ai' )
		);
	}

	/**
	 * Renders a processing badge.
	 *
	 * @since x.x.x
	 *
	 * @param int $comment_id The comment ID.
	 */
	private function render_processing_badge( int $comment_id ): void {
		printf(
			'<span class="ai-badge ai-badge--processing" data-comment-id="%d" data-ai-status="processing">%s</span>',
			absint( $comment_id ),
			esc_html__( 'Analyzing…', 'ai' )
		);
	}

	/**
	 * Adds bulk actions to the comments list.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, string> $actions The existing bulk actions.
	 * @return array<string, string> The modified bulk actions.
	 */
	public function add_bulk_actions( $actions ): array {
		if ( ! is_array( $actions ) ) {
			return $actions;
		}

		$actions['wpai_analyze'] = __( 'Analyze with AI', 'ai' );
		return $actions;
	}

	/**
	 * Handles the bulk action for AI analysis.
	 *
	 * @since x.x.x
	 *
	 * @param string $redirect_url The redirect URL.
	 * @param string $action       The action being performed.
	 * @param array  $comment_ids  The comment IDs.
	 * @return string The modified redirect URL.
	 */
	public function handle_bulk_action( $redirect_url, $action, $comment_ids ): string {
		if ( 'wpai_analyze' !== (string) $action ) {
			return $redirect_url;
		}

		// Mark selected comments as pending for analysis.
		$queued = 0;
		foreach ( (array) $comment_ids as $comment_id ) {
			$comment_id = absint( $comment_id );
			if ( ! $comment_id || ! is_a( get_comment( $comment_id ), '\WP_Comment' ) ) {
				continue;
			}

			update_comment_meta( $comment_id, self::META_ANALYSIS_STATUS, self::STATUS_PENDING );
			++$queued;
		}

		// Add query arg to show notice.
		return add_query_arg( 'wpai_analysis_queued', $queued, (string) $redirect_url );
	}

	/**
	 * Shows admin notice after bulk action.
	 *
	 * @since x.x.x
	 */
	public function show_bulk_action_notice(): void {
		if ( ! isset( $_GET['wpai_analysis_queued'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$count = absint( wp_unslash( $_GET['wpai_analysis_queued'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $count <= 0 ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: Number of comments queued for analysis. */
					_n(
						'%d comment queued for AI analysis.',
						'%d comments queued for AI analysis.',
						$count,
						'ai'
					),
					$count
				)
			)
		);
	}

	/**
	 * Adds row actions to the comments list.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, string> $actions The existing actions.
	 * @param \WP_Comment           $comment The comment object.
	 * @return array<string, string> The modified actions.
	 */
	public function add_row_actions( $actions, $comment ): array {
		if ( ! is_array( $actions ) || ! is_a( $comment, '\WP_Comment' ) ) {
			return $actions;
		}

		// Only show for approved comments.
		if ( '1' !== $comment->comment_approved ) {
			return $actions;
		}

		$actions['wpai_suggest_reply'] = sprintf(
			'<a href="#" class="ai-suggest-reply" data-comment-id="%d">%s</a>',
			absint( $comment->comment_ID ),
			esc_html__( 'AI Reply', 'ai' )
		);

		return $actions;
	}

	/**
	 * Enqueues admin assets for the comments screen.
	 *
	 * @since x.x.x
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ): void {
		if ( 'edit-comments.php' !== (string) $hook_suffix ) {
			return;
		}

		Asset_Loader::enqueue_script( 'comment_moderation', 'experiments/comment-moderation' );
		Asset_Loader::localize_script(
			'comment_moderation',
			'CommentModerationData',
			array(
				'enabled' => $this->is_enabled(),
			)
		);

		// Enqueue WordPress components styles.
		wp_enqueue_style( 'wp-components' );
	}

	/**
	 * Adds inline styles for the comment moderation badges.
	 *
	 * @since x.x.x
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

			.ai-badge--empty {
				background-color: transparent;
				color: #999;
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
