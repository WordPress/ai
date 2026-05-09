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

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Comment moderation experiment.
 *
 * Provides toxicity detection, sentiment analysis, and moderation
 * for WordPress comments.
 *
 * @since 0.9.0
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
	 * Comment analysis ability.
	 *
	 * @since 0.9.0
	 *
	 * @var \WordPress\AI\Abilities\Comment_Moderation\Comment_Analysis|null
	 */
	private $comment_analysis_ability = null;

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.9.0
	 */
	public static function get_id(): string {
		return 'comment-moderation';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.9.0
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Comment Moderation', 'ai' ),
			'description' => __( 'Automatically moderate comments based on toxicity detection and sentiment analysis. Requires an AI connector that includes support for text generation models.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.9.0
	 */
	public function register(): void {
		// Register abilities.
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );

		// Moderate new comments.
		add_action( 'wp_insert_comment', array( $this, 'moderate_comment' ) );

		// Add columns to comments list table.
		add_filter( 'manage_edit-comments_columns', array( $this, 'add_columns' ) );
		add_action( 'manage_comments_custom_column', array( $this, 'render_column' ), 10, 2 );

		// Add bulk action.
		add_filter( 'bulk_actions-edit-comments', array( $this, 'add_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-comments', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'show_bulk_action_notice' ) );

		// Add inline action.
		add_filter( 'comment_row_actions', array( $this, 'add_inline_action' ), 10, 2 );
		add_action( 'load-edit-comments.php', array( $this, 'handle_inline_action' ) );

		// Enqueue assets.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Add inline styles for badges.
		add_action( 'admin_head-edit-comments.php', array( $this, 'add_inline_styles' ) );
	}

	/**
	 * Registers the comment moderation abilities.
	 *
	 * @since 0.9.0
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
	 * Moderate newly added comments.
	 *
	 * @since 0.9.0
	 *
	 * @param int $comment_id Comment ID.
	 */
	public function moderate_comment( $comment_id ): void {
		$comment = get_comment( (int) $comment_id );
		if ( ! $comment || ! is_a( $comment, '\WP_Comment' ) ) {
			return;
		}

		$analysis = $this->get_comment_analysis_ability()->analyze_comment_by_id( (int) $comment_id );
		if ( is_wp_error( $analysis ) ) {
			return;
		}

		// Moderate the comment if it is above the toxicity threshold and has a negative sentiment.
		$should_moderate = $analysis['toxicity_score'] >= 0.7 && 'negative' === $analysis['sentiment'];

		/**
		 * Filters whether the comment should be moderated.
		 *
		 * @since 0.9.0
		 *
		 * @param bool $should_moderate Whether the comment should be moderated.
		 * @param array $analysis The analysis results.
		 * @param int $comment_id The comment ID.
		 */
		$should_moderate = (bool) apply_filters( 'wpai_comment_moderation_should_moderate', $should_moderate, $analysis, $comment_id );

		if ( ! $should_moderate ) {
			return;
		}

		wp_update_comment(
			array(
				'comment_ID'       => $comment_id,
				'comment_approved' => '0',
			)
		);
	}

	/**
	 * Gets the comment analysis ability for trusted internal use.
	 *
	 * @since 0.9.0
	 *
	 * @return \WordPress\AI\Abilities\Comment_Moderation\Comment_Analysis Comment analysis ability.
	 */
	private function get_comment_analysis_ability(): Comment_Analysis_Ability {
		if ( ! $this->comment_analysis_ability ) {
			$this->comment_analysis_ability = new Comment_Analysis_Ability(
				'ai/comment-analysis',
				array(
					'label'       => __( 'Comment Analysis', 'ai' ),
					'description' => __( 'Analyzes a comment for toxicity and sentiment.', 'ai' ),
				)
			);
		}

		return $this->comment_analysis_ability;
	}

	/**
	 * Adds custom columns to the comments list table.
	 *
	 * @since 0.9.0
	 *
	 * @param array<string, string> $columns The existing columns.
	 * @return array<string, string> The modified columns.
	 */
	public function add_columns( $columns ): array {
		$new_columns = array();

		foreach ( (array) $columns as $key => $value ) {
			$new_columns[ $key ] = $value;

			// Insert our columns after the 'comment' column.
			if ( 'comment' !== $key ) {
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
	 * @since 0.9.0
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
	 * @since 0.9.0
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
	 * @since 0.9.0
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
	 * @since 0.9.0
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
	 * @since 0.9.0
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
	 * @since 0.9.0
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
	 * @since 0.9.0
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
	 * @since 0.9.0
	 *
	 * @param array<string, string> $actions The existing bulk actions.
	 * @return array<string, string> The modified bulk actions.
	 */
	public function add_bulk_actions( $actions ): array {
		if ( ! is_array( $actions ) ) {
			return $actions;
		}

		$actions['wpai_analyze'] = __( 'Analyze Sentiment and Toxicity', 'ai' );
		return $actions;
	}

	/**
	 * Handles the bulk action for AI analysis.
	 *
	 * @since 0.9.0
	 *
	 * @param string $redirect_url The redirect URL.
	 * @param string $action       The action being performed.
	 * @param array<int> $comment_ids The comment IDs.
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
			$comment    = get_comment( $comment_id );
			if ( ! $comment || ! is_a( $comment, '\WP_Comment' ) ) {
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
	 * @since 0.9.0
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
						'%d comment queued for analysis.',
						'%d comments queued for analysis.',
						$count,
						'ai'
					),
					$count
				)
			)
		);
	}

	/**
	 * Adds an inline action to the comment row actions.
	 *
	 * @since 0.9.0
	 *
	 * @param array<string, string> $actions The existing actions.
	 * @param \WP_Comment $comment The comment object.
	 * @return array<string, string> The modified actions.
	 */
	public function add_inline_action( $actions, $comment ): array {
		if (
			! is_array( $actions ) ||
			! $comment ||
			! is_a( $comment, '\WP_Comment' )
		) {
			return $actions;
		}

		$url = add_query_arg(
			array(
				'wpai_analyze_comment' => (int) $comment->comment_ID,
			),
			admin_url( 'edit-comments.php' )
		);
		$url = wp_nonce_url( $url, 'wpai_analyze_comment_' . (int) $comment->comment_ID );

		$actions['wpai_analyze'] = sprintf(
			'<a href="%s" aria-label="%s">%s</a>',
			esc_url( $url ),
			esc_attr__( 'Analyze this comment', 'ai' ),
			esc_html__( 'Analyze Sentiment and Toxicity', 'ai' )
		);

		return $actions;
	}

	/**
	 * Handles the inline analyze action from the comment row.
	 *
	 * @since 0.9.0
	 */
	public function handle_inline_action(): void {
		if ( ! current_user_can( 'moderate_comments' ) ) {
			return;
		}

		if ( ! isset( $_GET['wpai_analyze_comment'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$comment_id = absint( wp_unslash( $_GET['wpai_analyze_comment'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $comment_id ) {
			return;
		}

		check_admin_referer( 'wpai_analyze_comment_' . $comment_id );

		$redirect_url = remove_query_arg(
			array(
				'wpai_analyze_comment',
				'_wpnonce',
			)
		);
		$redirect_url = $this->handle_bulk_action( $redirect_url, 'wpai_analyze', array( $comment_id ) );

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Enqueues admin assets for the comments screen.
	 *
	 * @since 0.9.0
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
	}

	/**
	 * Adds inline styles for the comment moderation badges.
	 *
	 * @since 0.9.0
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
		</style>
		<?php
	}
}
