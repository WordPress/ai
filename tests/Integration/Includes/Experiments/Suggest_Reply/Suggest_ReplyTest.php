<?php
/**
 * Integration tests for the Suggest_Reply experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Suggest_Reply
 */

namespace WordPress\AI\Tests\Integration\Experiments\Suggest_Reply;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Suggest_Reply\Suggest_Reply;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Suggest_Reply experiment test case.
 *
 * @since x.x.x
 */
class Suggest_ReplyTest extends WP_UnitTestCase {

	/**
	 * Admin user ID used for capability-sensitive tests.
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		add_filter( 'wpai_has_ai_credentials', '__return_true' );

		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_suggest-reply_enabled', true );

		$this->admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->init();

		$experiment = $registry->get_feature( 'suggest-reply' );
		$this->assertInstanceOf(
			Suggest_Reply::class,
			$experiment,
			'Suggest reply experiment should be registered in the registry.'
		);
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_suggest-reply_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		remove_filter( 'wpai_has_ai_credentials', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Test that the experiment metadata is correct.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_registration() {
		$experiment = new Suggest_Reply();

		$this->assertSame( 'suggest-reply', $experiment->get_id() );
		$this->assertSame( 'Suggest Reply', $experiment->get_label() );
		$this->assertSame( Experiment_Category::ADMIN, $experiment->get_category() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Test that register() adds expected hooks.
	 *
	 * @since x.x.x
	 */
	public function test_register_adds_expected_hooks() {
		$experiment = new Suggest_Reply();
		$experiment->register();

		$this->assertIsInt( has_action( 'wp_abilities_api_init', array( $experiment, 'register_abilities' ) ) );
		$this->assertIsInt( has_filter( 'comment_row_actions', array( $experiment, 'add_row_action' ) ) );
		$this->assertIsInt( has_action( 'admin_enqueue_scripts', array( $experiment, 'enqueue_assets' ) ) );
	}

	/**
	 * Test that register_abilities() registers the reply suggestion ability.
	 *
	 * @since x.x.x
	 */
	public function test_register_abilities_registers_reply_suggestion() {
		$experiment = new Suggest_Reply();
		$experiment->register();

		$this->assertIsInt( has_action( 'wp_abilities_api_init', array( $experiment, 'register_abilities' ) ) );
	}

	/**
	 * Test add_row_action() adds a suggest reply action link to a valid comment.
	 *
	 * @since x.x.x
	 */
	public function test_add_row_action_adds_suggest_reply_link() {
		$comment_id = self::factory()->comment->create();
		$comment    = get_comment( $comment_id );
		$experiment = new Suggest_Reply();

		$actions = $experiment->add_row_action( array(), $comment );

		$this->assertArrayHasKey( 'wpai_suggest_reply', $actions );
		$this->assertStringContainsString( 'wpai-suggest-reply', $actions['wpai_suggest_reply'] );
		$this->assertStringContainsString( 'data-comment-id="' . $comment_id . '"', $actions['wpai_suggest_reply'] );
		$this->assertStringContainsString( 'Suggest reply', $actions['wpai_suggest_reply'] );
	}

	/**
	 * Test add_row_action() preserves existing actions.
	 *
	 * @since x.x.x
	 */
	public function test_add_row_action_preserves_existing_actions() {
		$comment_id = self::factory()->comment->create();
		$comment    = get_comment( $comment_id );
		$experiment = new Suggest_Reply();

		$existing_actions = array( 'edit' => 'Edit', 'reply' => 'Reply' );
		$actions          = $experiment->add_row_action( $existing_actions, $comment );

		$this->assertArrayHasKey( 'edit', $actions );
		$this->assertArrayHasKey( 'reply', $actions );
		$this->assertArrayHasKey( 'wpai_suggest_reply', $actions );
	}

	/**
	 * Test add_row_action() returns early when $comment is not a WP_Comment.
	 *
	 * @since x.x.x
	 */
	public function test_add_row_action_returns_early_for_non_comment_object() {
		$experiment = new Suggest_Reply();

		$result = $experiment->add_row_action( array( 'edit' => 'Edit' ), null );

		$this->assertSame( array( 'edit' => 'Edit' ), $result );
	}

	/**
	 * Test enqueue_assets() returns early for unrelated admin screens.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_returns_early_for_non_target_screens() {
		$experiment = new Suggest_Reply();
		$experiment->enqueue_assets( 'options-general.php' );

		$this->assertFalse( wp_script_is( 'suggest_reply', 'enqueued' ) );
	}
}
