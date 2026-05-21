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

use function WordPress\AI\get_provider_availability_data;
use function WordPress\AI\has_ai_credentials;

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
	 * Sentiment: positive.
	 *
	 * @var string
	 */
	public const SENTIMENT_POSITIVE = 'positive';

	/**
	 * Sentiment: neutral.
	 *
	 * @var string
	 */
	public const SENTIMENT_NEUTRAL = 'neutral';

	/**
	 * Sentiment: negative.
	 *
	 * @var string
	 */
	public const SENTIMENT_NEGATIVE = 'negative';

	/**
	 * Toxicity level: low.
	 *
	 * @var string
	 */
	public const TOXICITY_LOW = 'low';

	/**
	 * Toxicity level: medium.
	 *
	 * @var string
	 */
	public const TOXICITY_MEDIUM = 'medium';

	/**
	 * Toxicity level: high.
	 *
	 * @var string
	 */
	public const TOXICITY_HIGH = 'high';

	/**
	 * Gets the configuration for sentiment levels.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array{label: string, filterLabel: string, class: string, icon: string}> The sentiment configuration.
	 */
	public static function get_sentiment_config(): array {
		return array(
			self::SENTIMENT_POSITIVE => array(
				'label'       => __( 'Positive', 'ai' ),
				'filterLabel' => __( 'Positive', 'ai' ),
				'class'       => 'ai-badge--positive',
				'icon'        => '😊',
			),
			self::SENTIMENT_NEUTRAL  => array(
				'label'       => __( 'Neutral', 'ai' ),
				'filterLabel' => __( 'Neutral', 'ai' ),
				'class'       => 'ai-badge--neutral',
				'icon'        => '😐',
			),
			self::SENTIMENT_NEGATIVE => array(
				'label'       => __( 'Negative', 'ai' ),
				'filterLabel' => __( 'Negative', 'ai' ),
				'class'       => 'ai-badge--negative',
				'icon'        => '😟',
			),
		);
	}

	/**
	 * Gets the configuration for toxicity levels.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array{label: string, filterLabel: string, class: string, icon: string, min: float, max: float}> The toxicity configuration.
	 */
	public static function get_toxicity_config(): array {
		return array(
			self::TOXICITY_LOW    => array(
				'label'       => __( 'Low', 'ai' ),
				'filterLabel' => __( 'Low Toxicity (<40%)', 'ai' ),
				'class'       => 'ai-badge--low-toxicity',
				'icon'        => '✓',
				'min'         => 0.0,
				'max'         => 0.4,
			),
			self::TOXICITY_MEDIUM => array(
				'label'       => __( 'Medium', 'ai' ),
				'filterLabel' => __( 'Medium Toxicity (40%-69%)', 'ai' ),
				'class'       => 'ai-badge--medium-toxicity',
				'icon'        => '⚡',
				'min'         => 0.4,
				'max'         => 0.7,
			),
			self::TOXICITY_HIGH   => array(
				'label'       => __( 'High', 'ai' ),
				'filterLabel' => __( 'High Toxicity (>=70%)', 'ai' ),
				'class'       => 'ai-badge--high-toxicity',
				'icon'        => '⚠️',
				'min'         => 0.7,
				'max'         => 1.0,
			),
		);
	}

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

		// Add sortable columns.
		add_filter( 'manage_edit-comments_sortable_columns', array( $this, 'add_sortable_columns' ) );

		// Add custom sorting and filtering.
		add_action( 'restrict_manage_comments', array( $this, 'add_filter_dropdowns' ) );
		add_action( 'pre_get_comments', array( $this, 'handle_sorting_and_filtering' ) );

		// Enqueue assets.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Add inline styles for badges.
		add_action( 'admin_head-edit-comments.php', array( $this, 'add_inline_styles' ) );
		add_action( 'admin_head-index.php', array( $this, 'add_inline_styles' ) );

		// Add dashboard pills.
		add_filter( 'get_comment_excerpt', array( $this, 'add_dashboard_pills' ), 10, 3 );
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
		if ( ! has_ai_credentials() ) {
			return;
		}

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
	 * Adds sentiment and toxicity pills to the dashboard recent comments widget.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $comment_excerpt The comment excerpt.
	 * @param string      $comment_id      The comment ID.
	 * @param \WP_Comment $comment         The comment object.
	 * @return string The modified comment excerpt.
	 */
	public function add_dashboard_pills( $comment_excerpt, $comment_id, $comment ): string {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return $comment_excerpt;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'dashboard' !== $screen->id ) {
			return $comment_excerpt;
		}

		$comment_id = (int) $comment_id;

		/**
		 * Filters whether to show AI sentiment and toxicity pills in the dashboard.
		 *
		 * @since 1.0.0
		 *
		 * @param bool        $show       Whether to show the pills. Default true.
		 * @param int         $comment_id The comment ID.
		 * @param \WP_Comment $comment    The comment object.
		 */
		if ( ! apply_filters( 'wpai_comment_moderation_show_dashboard_pills', true, $comment_id, $comment ) ) {
			return $comment_excerpt;
		}

		$status = get_comment_meta( $comment_id, self::META_ANALYSIS_STATUS, true );
		if ( self::STATUS_COMPLETE !== $status ) {
			return $comment_excerpt;
		}

		$sentiment = get_comment_meta( $comment_id, self::META_SENTIMENT, true );
		$score     = (float) get_comment_meta( $comment_id, self::META_TOXICITY_SCORE, true );

		// Capture the pills HTML in an output buffer.
		ob_start();
		?>
		<div class="ai-dashboard-pills">
			<?php
			$this->render_sentiment_badge( (string) $sentiment );
			$this->render_toxicity_badge( $score );
			?>
		</div>
		<?php
		$pills = ob_get_clean();

		return $comment_excerpt . $pills;
	}

	/**
	 * Adds filter dropdowns for sentiment and toxicity.
	 *
	 * @since 1.0.0
	 */
	public function add_filter_dropdowns(): void {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'edit-comments' !== $screen->id ) {
			return;
		}

		$current_sentiment = isset( $_GET['wpai_sentiment'] ) ? sanitize_text_field( wp_unslash( $_GET['wpai_sentiment'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_toxicity  = isset( $_GET['wpai_toxicity'] ) ? sanitize_text_field( wp_unslash( $_GET['wpai_toxicity'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Sentiment Dropdown.
		$sentiments = self::get_sentiment_config();
		?>
		<label class="screen-reader-text" for="wpai-filter-sentiment"><?php esc_html_e( 'Filter by Sentiment', 'ai' ); ?></label>
		<select name="wpai_sentiment" id="wpai-filter-sentiment">
			<option value=""><?php esc_html_e( 'All Sentiments', 'ai' ); ?></option>
			<?php foreach ( $sentiments as $value => $config ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_sentiment, $value ); ?>>
					<?php echo esc_html( $config['filterLabel'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<?php
		// Toxicity Dropdown.
		$toxicities = self::get_toxicity_config();
		?>
		<label class="screen-reader-text" for="wpai-filter-toxicity"><?php esc_html_e( 'Filter by Toxicity', 'ai' ); ?></label>
		<select name="wpai_toxicity" id="wpai-filter-toxicity">
			<option value=""><?php esc_html_e( 'All Toxicities', 'ai' ); ?></option>
			<?php foreach ( $toxicities as $value => $config ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_toxicity, $value ); ?>>
					<?php echo esc_html( $config['filterLabel'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Adds sortable columns to the comments list table.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string> $columns The existing sortable columns.
	 * @return array<string, string> The modified sortable columns.
	 */
	public function add_sortable_columns( $columns ): array {
		$columns['wpai_sentiment'] = 'wpai_sentiment';
		$columns['wpai_toxicity']  = 'wpai_toxicity';
		return $columns;
	}

	/**
	 * Handles the custom sorting and filtering for comments.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Comment_Query $query The comment query object.
	 */
	public function handle_sorting_and_filtering( $query ): void {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'edit-comments' !== $screen->id ) {
			return;
		}

		$meta_query = $query->query_vars['meta_query'] ?? array();
		if ( ! is_array( $meta_query ) ) {  // Handle empty strings.
			$meta_query = array();
		}

		// Handle filtering.
		$sentiment = isset( $_GET['wpai_sentiment'] ) ? sanitize_text_field( wp_unslash( $_GET['wpai_sentiment'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$toxicity  = isset( $_GET['wpai_toxicity'] ) ? sanitize_text_field( wp_unslash( $_GET['wpai_toxicity'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$sentiments = self::get_sentiment_config();
		if ( ! empty( $sentiment ) && array_key_exists( $sentiment, $sentiments ) ) {
			$meta_query[] = array(
				'key'   => self::META_SENTIMENT,
				'value' => $sentiment,
			);
		}

		$toxicities = self::get_toxicity_config();
		if ( ! empty( $toxicity ) && array_key_exists( $toxicity, $toxicities ) ) {
			$config = $toxicities[ $toxicity ];
			$min    = $config['min'];
			$max    = $config['max'];

			$meta_query[] = array(
				'relation' => 'AND',
				array(
					'key'     => self::META_TOXICITY_SCORE,
					'value'   => $min,
					'type'    => 'DECIMAL(10, 5)',
					'compare' => '>=',
				),
				array(
					'key'     => self::META_TOXICITY_SCORE,
					'value'   => $max,
					'type'    => 'DECIMAL(10, 5)',
					'compare' => 1.0 === $max ? '<=' : '<', // For the end boundary of 1.0 to be included.
				),
			);
		}

		// Handle sorting.
		$orderby = $query->query_vars['orderby'] ?? '';

		// Use named meta queries so comments without analysis metadata remain visible when sorted.
		if ( 'wpai_sentiment' === $orderby ) {
			$meta_query[] = array(
				'relation'             => 'OR',
				'wpai_sentiment_sort'  => array(
					'key'     => self::META_SENTIMENT,
					'compare' => 'EXISTS',
				),
				'wpai_sentiment_empty' => array(
					'key'     => self::META_SENTIMENT,
					'compare' => 'NOT EXISTS',
				),
			);

			$query->query_vars['orderby'] = 'wpai_sentiment_sort';
		} elseif ( 'wpai_toxicity' === $orderby ) {
			$meta_query[] = array(
				'relation'            => 'OR',
				'wpai_toxicity_sort'  => array(
					'key'     => self::META_TOXICITY_SCORE,
					'compare' => 'EXISTS',
					'type'    => 'DECIMAL(10, 5)',
				),
				'wpai_toxicity_empty' => array(
					'key'     => self::META_TOXICITY_SCORE,
					'compare' => 'NOT EXISTS',
				),
			);

			$query->query_vars['orderby'] = 'wpai_toxicity_sort';
		}

		if ( empty( $meta_query ) ) {
			return;
		}

		$query->query_vars['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
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
		} elseif ( self::STATUS_FAILED === $status ) {
			$this->render_failed_badge();
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
		} elseif ( self::STATUS_FAILED === $status ) {
			$this->render_failed_badge();
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
		$badges = self::get_sentiment_config();

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
		$config = self::get_toxicity_config();
		$badge  = $config[ self::TOXICITY_LOW ];

		foreach ( $config as $tier ) {
			if ( $score >= $tier['min'] && ( $score < $tier['max'] || 1.0 === $tier['max'] ) ) {
				$badge = $tier;
				break;
			}
		}

		$label = $badge['label'];
		$class = $badge['class'];
		$icon  = $badge['icon'];

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
	 * Renders a failed analysis badge.
	 *
	 * @since 1.0.0
	 */
	private function render_failed_badge(): void {
		printf(
			'<span class="ai-badge ai-badge--failed">%s</span>',
			esc_html__( 'Failed', 'ai' )
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

		if ( ! has_ai_credentials() ) {
			return add_query_arg( 'wpai_no_provider', 1, (string) $redirect_url );
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
		if ( isset( $_GET['wpai_no_provider'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::show_missing_provider_notice();
			return;
		}

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
	 * Shows an admin notice if the inline action is attempted without a provider.
	 *
	 * @since 1.0.0
	 */
	private function show_missing_provider_notice(): void {
		$connectors_url = get_provider_availability_data()['connectorsUrl'];
		$notice_message = sprintf(
			/* translators: %s: Link to connectors settings page. */
			__( 'This feature requires a valid AI Connector to function properly. Please set up a provider to use this feature in %s.', 'ai' ),
			'<a href="' . esc_url( $connectors_url ) . '">' . esc_html__( 'Settings → Connectors', 'ai' ) . '</a>'
		);

		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			wp_kses(
				$notice_message,
				array(
					'a' => array(
						'href' => array(),
					),
				)
			)
		);
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

		Asset_Loader::enqueue_script( 'comment_moderation', 'experiments/comment-moderation', array( 'include_core_abilities' => true ) );
		Asset_Loader::localize_script(
			'comment_moderation',
			'CommentModerationData',
			array(
				'enabled' => $this->is_enabled(),
				'labels'  => array(
					'sentiment' => self::get_sentiment_config(),
					'toxicity'  => self::get_toxicity_config(),
				),
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
			.edit-comments-php .column-wpai_sentiment,
			.edit-comments-php .column-wpai_toxicity {
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

			.dashboard-comment-wrap .ai-dashboard-pills {
				margin-top: 8px;
				display: flex;
				gap: 8px;
			}
		</style>
		<?php
	}
}
