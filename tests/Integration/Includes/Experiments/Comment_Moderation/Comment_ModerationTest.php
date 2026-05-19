<?php
/**
 * Integration tests for the Comment_Moderation experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Comment_Moderation
 */

namespace WordPress\AI\Tests\Integration\Experiments\Comment_Moderation;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Comment_Moderation\Comment_Moderation;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Comment_Moderation experiment test case.
 *
 * @since 0.9.0
 */
class Comment_ModerationTest extends WP_UnitTestCase {
	/**
	 * Admin user ID used for capability-sensitive tests.
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * Creates a comment row directly without triggering wp_insert_comment hooks.
	 *
	 * @since 0.9.0
	 *
	 * @return int Comment ID.
	 */
	private function create_comment_without_hooks(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct insert avoids comment hooks for moderation tests.
		$wpdb->insert(
			$wpdb->comments,
			array(
				'comment_post_ID'      => 0,
				'comment_author'       => 'Test Author',
				'comment_author_email' => 'test@example.com',
				'comment_author_url'   => '',
				'comment_author_IP'    => '127.0.0.1',
				'comment_date'         => current_time( 'mysql' ),
				'comment_date_gmt'     => current_time( 'mysql', true ),
				'comment_content'      => 'Test comment content',
				'comment_karma'        => 0,
				'comment_approved'     => '1',
				'comment_agent'        => 'phpunit',
				'comment_type'         => 'comment',
				'comment_parent'       => 0,
				'user_id'              => 0,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Set up test case.
	 *
	 * @since 0.9.0
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		add_filter( 'wpai_has_ai_credentials', '__return_true' );

		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_comment-moderation_enabled', true );
		$this->admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->init();

		$experiment = $registry->get_feature( 'comment-moderation' );
		$this->assertInstanceOf(
			Comment_Moderation::class,
			$experiment,
			'Comment moderation experiment should be registered in the registry.'
		);
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.9.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_comment-moderation_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		remove_filter( 'wpai_has_ai_credentials', '__return_true' );
		unset( $_GET['wpai_analysis_queued'] );
		parent::tearDown();
	}

	/**
	 * Test that the experiment is registered correctly.
	 *
	 * @since 0.9.0
	 */
	public function test_experiment_registration() {
		$experiment = new Comment_Moderation();

		$this->assertSame( 'comment-moderation', $experiment->get_id() );
		$this->assertSame( 'Comment Moderation', $experiment->get_label() );
		$this->assertSame( Experiment_Category::ADMIN, $experiment->get_category() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Test that register() adds expected hooks.
	 *
	 * @since 0.9.0
	 */
	public function test_register_adds_expected_hooks() {
		$experiment = new Comment_Moderation();
		$experiment->register();

		$this->assertIsInt( has_action( 'wp_abilities_api_init', array( $experiment, 'register_abilities' ) ) );
		$this->assertIsInt( has_action( 'wp_insert_comment', array( $experiment, 'moderate_comment' ) ) );
		$this->assertIsInt( has_filter( 'manage_edit-comments_columns', array( $experiment, 'add_columns' ) ) );
		$this->assertIsInt( has_action( 'manage_comments_custom_column', array( $experiment, 'render_column' ) ) );
		$this->assertIsInt( has_filter( 'bulk_actions-edit-comments', array( $experiment, 'add_bulk_actions' ) ) );
		$this->assertIsInt( has_filter( 'handle_bulk_actions-edit-comments', array( $experiment, 'handle_bulk_action' ) ) );
		$this->assertIsInt( has_action( 'admin_notices', array( $experiment, 'show_bulk_action_notice' ) ) );
		$this->assertIsInt( has_action( 'load-edit-comments.php', array( $experiment, 'handle_inline_action' ) ) );
		$this->assertIsInt( has_action( 'admin_enqueue_scripts', array( $experiment, 'enqueue_assets' ) ) );
		$this->assertIsInt( has_action( 'admin_head-edit-comments.php', array( $experiment, 'add_inline_styles' ) ) );
	}

	/**
	 * Test that register_abilities() registers comment analysis ability.
	 *
	 * @since 0.9.0
	 */
	public function test_register_abilities_registers_comment_analysis() {
		$experiment = new Comment_Moderation();
		$experiment->register();

		$this->assertIsInt( has_action( 'wp_abilities_api_init', array( $experiment, 'register_abilities' ) ) );
	}

	/**
	 * Test add_columns() inserts sentiment and toxicity columns after comment.
	 *
	 * @since 0.9.0
	 */
	public function test_add_columns_inserts_custom_columns_after_comment() {
		$experiment = new Comment_Moderation();
		$columns    = $experiment->add_columns(
			array(
				'cb'      => '<input type="checkbox" />',
				'author'  => 'Author',
				'comment' => 'Comment',
				'date'    => 'Date',
			)
		);

		$this->assertArrayHasKey( 'wpai_sentiment', $columns );
		$this->assertArrayHasKey( 'wpai_toxicity', $columns );

		$keys = array_keys( $columns );
		$this->assertSame( 'comment', $keys[2] );
		$this->assertSame( 'wpai_sentiment', $keys[3] );
		$this->assertSame( 'wpai_toxicity', $keys[4] );
	}

	/**
	 * Test add_bulk_actions() adds expected action.
	 *
	 * @since 0.9.0
	 */
	public function test_add_bulk_actions_adds_analyze_with_ai() {
		$experiment = new Comment_Moderation();
		$actions    = $experiment->add_bulk_actions( array( 'delete' => 'Move to Trash' ) );

		$this->assertArrayHasKey( 'wpai_analyze', $actions );
		$this->assertSame( 'Analyze Sentiment and Toxicity', $actions['wpai_analyze'] );
	}

	/**
	 * Test add_inline_action() adds a nonce-protected link for a comment.
	 *
	 * @since 0.9.0
	 */
	public function test_add_inline_action_adds_nonce_protected_link() {
		$comment_id = $this->create_comment_without_hooks();
		$comment    = get_comment( $comment_id );
		$experiment = new Comment_Moderation();

		$actions = $experiment->add_inline_action( array(), $comment );

		$this->assertArrayHasKey( 'wpai_analyze', $actions );
		$this->assertStringContainsString( 'wpai_analyze_comment=' . $comment_id, $actions['wpai_analyze'] );
		$this->assertStringContainsString( '_wpnonce=', $actions['wpai_analyze'] );
		$this->assertStringContainsString( 'Analyze Sentiment and Toxicity', $actions['wpai_analyze'] );
	}

	/**
	 * Test handle_bulk_action() ignores unrelated actions.
	 *
	 * @since 0.9.0
	 */
	public function test_handle_bulk_action_ignores_other_actions() {
		$experiment = new Comment_Moderation();
		$redirect   = 'https://example.com/wp-admin/edit-comments.php';
		$result     = $experiment->handle_bulk_action( $redirect, 'delete', array( 1, 2 ) );

		$this->assertSame( $redirect, $result );
	}

	/**
	 * Test handle_bulk_action() marks selected comments as pending.
	 *
	 * @since 0.9.0
	 */
	public function test_handle_bulk_action_marks_comments_pending_and_adds_query_arg() {
		wp_set_current_user( $this->admin_user_id );
		$comment_id = $this->create_comment_without_hooks();
		$experiment = new Comment_Moderation();
		$redirect   = 'https://example.com/wp-admin/edit-comments.php';

		$result = $experiment->handle_bulk_action( $redirect, 'wpai_analyze', array( $comment_id ) );

		$this->assertStringContainsString( 'wpai_analysis_queued=1', $result );
		$this->assertSame(
			Comment_Moderation::STATUS_PENDING,
			get_comment_meta( $comment_id, Comment_Moderation::META_ANALYSIS_STATUS, true )
		);
	}

	/**
	 * Test moderate_comment() analyzes comments when the current user cannot moderate comments.
	 *
	 * @since 0.9.0
	 */
	public function test_moderate_comment_analyzes_comment_for_user_without_moderate_comments_capability() {
		$user_id    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$comment_id = $this->create_comment_without_hooks();
		$experiment = new Comment_Moderation();

		wp_set_current_user( $user_id );
		add_filter( 'wpai_comment_analysis_result', array( $this, 'filter_comment_analysis_result' ) );

		$experiment->moderate_comment( $comment_id );

		remove_filter( 'wpai_comment_analysis_result', array( $this, 'filter_comment_analysis_result' ) );

		$this->assertSame(
			Comment_Moderation::STATUS_COMPLETE,
			get_comment_meta( $comment_id, Comment_Moderation::META_ANALYSIS_STATUS, true )
		);
		$this->assertSame( 'negative', get_comment_meta( $comment_id, Comment_Moderation::META_SENTIMENT, true ) );
		$this->assertSame( '0.8', get_comment_meta( $comment_id, Comment_Moderation::META_TOXICITY_SCORE, true ) );
		$this->assertSame( '0', get_comment( $comment_id )->comment_approved );
	}

	/**
	 * Test moderate_comment() skips automatic analysis when credentials are missing.
	 *
	 * @since x.x.x
	 */
	public function test_moderate_comment_skips_analysis_when_credentials_are_missing() {
		remove_filter( 'wpai_has_ai_credentials', '__return_true' );

		$comment_id = $this->create_comment_without_hooks();
		$experiment = new Comment_Moderation();

		$experiment->moderate_comment( $comment_id );

		$this->assertSame(
			'',
			get_comment_meta( $comment_id, Comment_Moderation::META_ANALYSIS_STATUS, true ),
			'Automatic analysis should not mark comments as failed when no provider credentials are configured.'
		);
		$this->assertSame( '', get_comment_meta( $comment_id, Comment_Moderation::META_SENTIMENT, true ) );
		$this->assertSame( '', get_comment_meta( $comment_id, Comment_Moderation::META_TOXICITY_SCORE, true ) );
	}

	/**
	 * Filters the analysis result for tests.
	 *
	 * @since 0.9.0
	 *
	 * @return array{toxicity_score: float, sentiment: string} Analysis result.
	 */
	public function filter_comment_analysis_result(): array {
		return array(
			'toxicity_score' => 0.8,
			'sentiment'      => 'negative',
		);
	}

	/**
	 * Test show_bulk_action_notice() renders notice when queued count is valid.
	 *
	 * @since 0.9.0
	 */
	public function test_show_bulk_action_notice_renders_notice() {
		$experiment                   = new Comment_Moderation();
		$_GET['wpai_analysis_queued'] = '2';

		ob_start();
		$experiment->show_bulk_action_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-success', $output );
		$this->assertStringContainsString( '2 comments queued for analysis.', $output );
	}

	/**
	 * Test show_bulk_action_notice() does nothing for empty/invalid counts.
	 *
	 * @since 0.9.0
	 */
	public function test_show_bulk_action_notice_does_nothing_for_invalid_count() {
		$experiment                   = new Comment_Moderation();
		$_GET['wpai_analysis_queued'] = '0';

		ob_start();
		$experiment->show_bulk_action_notice();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Test enqueue_assets() returns early for non-comments screens.
	 *
	 * @since 0.9.0
	 */
	public function test_enqueue_assets_returns_early_for_non_comment_screens() {
		$experiment = new Comment_Moderation();
		$experiment->enqueue_assets( 'options-general.php' );

		$this->assertFalse( wp_script_is( 'ai_comment_moderation', 'enqueued' ) );
	}

	/**
	 * Test handle_bulk_action() redirects with no_provider arg when credentials are missing.
	 *
	 * @since x.x.x
	 */
	public function test_handle_bulk_action_redirects_with_no_provider_when_credentials_missing() {
		remove_filter( 'wpai_has_ai_credentials', '__return_true' );

		$experiment = new Comment_Moderation();
		$redirect   = 'https://example.com/wp-admin/edit-comments.php';
		$comment_id = $this->create_comment_without_hooks();

		$result = $experiment->handle_bulk_action( $redirect, 'wpai_analyze', array( $comment_id ) );

		$this->assertStringContainsString( 'wpai_no_provider=1', $result );
		$this->assertStringNotContainsString( 'wpai_analysis_queued', $result );
	}

	/**
	 * Test show_bulk_action_notice() renders provider notice when no_provider query arg is set.
	 *
	 * @since x.x.x
	 */
	public function test_show_bulk_action_notice_renders_provider_notice_when_no_provider() {
		$experiment               = new Comment_Moderation();
		$_GET['wpai_no_provider'] = '1';

		ob_start();
		$experiment->show_bulk_action_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );

		unset( $_GET['wpai_no_provider'] );
	}

	/**
	 * Test show_bulk_action_notice() renders notice with connectors link.
	 *
	 * @since x.x.x
	 */
	public function test_show_bulk_action_notice_renders_connectors_link_when_no_provider() {
		$experiment               = new Comment_Moderation();
		$_GET['wpai_no_provider'] = '1';

		ob_start();
		$experiment->show_bulk_action_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'options-connectors.php', $output );
		$this->assertStringContainsString( 'Settings', $output );

		unset( $_GET['wpai_no_provider'] );
	}

	/**
	 * Test handle_inline_action() delegates to handle_bulk_action() which redirects
	 * with no_provider arg when credentials are missing.
	 *
	 * Since handle_inline_action() calls wp_safe_redirect() and exit, we test
	 * the underlying handle_bulk_action() path it uses.
	 *
	 * @since x.x.x
	 */
	public function test_handle_bulk_action_from_inline_returns_no_provider_redirect() {
		remove_filter( 'wpai_has_ai_credentials', '__return_true' );

		$experiment = new Comment_Moderation();
		$comment_id = $this->create_comment_without_hooks();

		$redirect = 'https://example.com/wp-admin/edit-comments.php';
		$result   = $experiment->handle_bulk_action( $redirect, 'wpai_analyze', array( $comment_id ) );

		$this->assertStringContainsString( 'wpai_no_provider=1', $result );
		$this->assertStringNotContainsString( 'wpai_analysis_queued', $result );

		$comment_meta = get_comment_meta( $comment_id, Comment_Moderation::META_ANALYSIS_STATUS, true );
		$this->assertEmpty( $comment_meta, 'No analysis metadata should be set when credentials are missing.' );
	}

	/**
	 * Test render_column() outputs pending badge markup for pending status.
	 *
	 * @since 0.9.0
	 */
	public function test_render_column_outputs_pending_badge_for_pending_status() {
		wp_set_current_user( $this->admin_user_id );
		$comment_id = $this->create_comment_without_hooks();
		update_comment_meta( $comment_id, Comment_Moderation::META_ANALYSIS_STATUS, Comment_Moderation::STATUS_PENDING );

		$experiment = new Comment_Moderation();

		ob_start();
		$experiment->render_column( 'wpai_sentiment', $comment_id );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'data-ai-status="pending"', $output );
		$this->assertStringContainsString( 'data-comment-id="' . $comment_id . '"', $output );
	}

	/**
	 * Test render_column() outputs failed badge markup for failed status.
	 *
	 * @since x.x.x
	 */
	public function test_render_column_outputs_failed_badge_for_failed_status() {
		wp_set_current_user( $this->admin_user_id );
		$comment_id = $this->create_comment_without_hooks();
		update_comment_meta( $comment_id, Comment_Moderation::META_ANALYSIS_STATUS, Comment_Moderation::STATUS_FAILED );

		$experiment = new Comment_Moderation();

		ob_start();
		$experiment->render_column( 'wpai_sentiment', $comment_id );
		$sentiment_output = ob_get_clean();

		ob_start();
		$experiment->render_column( 'wpai_toxicity', $comment_id );
		$toxicity_output = ob_get_clean();

		$this->assertStringContainsString( 'ai-badge--failed', $sentiment_output );
		$this->assertStringContainsString( 'Failed', $sentiment_output );
		$this->assertStringContainsString( 'ai-badge--failed', $toxicity_output );
		$this->assertStringContainsString( 'Failed', $toxicity_output );
	}

	/**
	 * Test filtering logic via handle_sorting_and_filtering.
	 *
	 * @since x.x.x
	 */
	public function test_comment_filtering_integration() {
		set_current_screen( 'edit-comments' );
		$experiment = new Comment_Moderation();
		add_action( 'pre_get_comments', array( $experiment, 'handle_sorting_and_filtering' ) );

		$comment_pos = $this->create_comment_without_hooks();
		update_comment_meta( $comment_pos, Comment_Moderation::META_SENTIMENT, 'positive' );
		update_comment_meta( $comment_pos, Comment_Moderation::META_TOXICITY_SCORE, 0.2 );

		$comment_neg = $this->create_comment_without_hooks();
		update_comment_meta( $comment_neg, Comment_Moderation::META_SENTIMENT, 'negative' );
		update_comment_meta( $comment_neg, Comment_Moderation::META_TOXICITY_SCORE, 0.8 );

		$comment_neu = $this->create_comment_without_hooks();
		update_comment_meta( $comment_neu, Comment_Moderation::META_SENTIMENT, 'neutral' );
		update_comment_meta( $comment_neu, Comment_Moderation::META_TOXICITY_SCORE, 0.5 );

		// Filter for positive sentiment.
		$_GET['wpai_sentiment'] = 'positive';
		$comments               = get_comments( array( 'fields' => 'ids' ) );
		$this->assertContains( $comment_pos, $comments );
		$this->assertNotContains( $comment_neg, $comments );
		$this->assertNotContains( $comment_neu, $comments );

		// Filter for medium toxicity.
		unset( $_GET['wpai_sentiment'] );
		$_GET['wpai_toxicity'] = 'medium';
		$comments              = get_comments( array( 'fields' => 'ids' ) );
		$this->assertContains( $comment_neu, $comments );
		$this->assertNotContains( $comment_pos, $comments );
		$this->assertNotContains( $comment_neg, $comments );

		// Cleanup.
		unset( $_GET['wpai_toxicity'] );
		remove_action( 'pre_get_comments', array( $experiment, 'handle_sorting_and_filtering' ) );
		set_current_screen( 'front' );
	}

	/**
	 * Test that sorting keeps comments without moderation metadata visible.
	 *
	 * @since x.x.x
	 */
	public function test_comment_sorting_includes_comments_without_analysis_meta() {
		set_current_screen( 'edit-comments' );
		$experiment = new Comment_Moderation();
		add_action( 'pre_get_comments', array( $experiment, 'handle_sorting_and_filtering' ) );

		try {
			$comment_without_meta = $this->create_comment_without_hooks();

			$comment_neg = $this->create_comment_without_hooks();
			update_comment_meta( $comment_neg, Comment_Moderation::META_SENTIMENT, 'negative' );
			update_comment_meta( $comment_neg, Comment_Moderation::META_TOXICITY_SCORE, 0.8 );

			$comment_pos = $this->create_comment_without_hooks();
			update_comment_meta( $comment_pos, Comment_Moderation::META_SENTIMENT, 'positive' );
			update_comment_meta( $comment_pos, Comment_Moderation::META_TOXICITY_SCORE, 0.2 );

			$comments = get_comments(
				array(
					'fields'  => 'ids',
					'orderby' => 'wpai_sentiment',
					'order'   => 'ASC',
				)
			);

			$this->assertSame( array( $comment_without_meta, $comment_neg, $comment_pos ), $comments );

			$comments = get_comments(
				array(
					'fields'  => 'ids',
					'orderby' => 'wpai_sentiment',
					'order'   => 'DESC',
				)
			);

			$this->assertSame( array( $comment_pos, $comment_neg, $comment_without_meta ), $comments );

			$comments = get_comments(
				array(
					'fields'  => 'ids',
					'orderby' => 'wpai_toxicity',
					'order'   => 'ASC',
				)
			);

			$this->assertSame( array( $comment_without_meta, $comment_pos, $comment_neg ), $comments );

			$comments = get_comments(
				array(
					'fields'  => 'ids',
					'orderby' => 'wpai_toxicity',
					'order'   => 'DESC',
				)
			);

			$this->assertSame( array( $comment_neg, $comment_pos, $comment_without_meta ), $comments );
		} finally {
			remove_action( 'pre_get_comments', array( $experiment, 'handle_sorting_and_filtering' ) );
			set_current_screen( 'front' );
		}
	}

	/**
	 * Test that add_dashboard_pills appends HTML only on the dashboard screen.
	 *
	 * @since x.x.x
	 */
	public function test_add_dashboard_pills() {
		$experiment = new Comment_Moderation();
		$comment_id = $this->create_comment_without_hooks();
		$comment    = get_comment( $comment_id );

		update_comment_meta( $comment_id, Comment_Moderation::META_ANALYSIS_STATUS, Comment_Moderation::STATUS_COMPLETE );
		update_comment_meta( $comment_id, Comment_Moderation::META_SENTIMENT, 'positive' );
		update_comment_meta( $comment_id, Comment_Moderation::META_TOXICITY_SCORE, 0.2 );

		$original_excerpt = 'This is an excerpt.';

		// Not on dashboard: no change.
		set_current_screen( 'edit-comments' );
		$result = $experiment->add_dashboard_pills( $original_excerpt, $comment_id, $comment );
		$this->assertSame( $original_excerpt, $result );

		// On dashboard: pills appended.
		set_current_screen( 'dashboard' );
		$result_dashboard = $experiment->add_dashboard_pills( $original_excerpt, $comment_id, $comment );
		$this->assertStringContainsString( 'ai-dashboard-pills', $result_dashboard );
		$this->assertStringContainsString( 'Positive', $result_dashboard );

		// Test filter opt-out.
		add_filter( 'wpai_comment_moderation_show_dashboard_pills', '__return_false' );
		$result_filtered = $experiment->add_dashboard_pills( $original_excerpt, $comment_id, $comment );
		$this->assertSame( $original_excerpt, $result_filtered );
		remove_filter( 'wpai_comment_moderation_show_dashboard_pills', '__return_false' );

		set_current_screen( 'front' );
	}
}
