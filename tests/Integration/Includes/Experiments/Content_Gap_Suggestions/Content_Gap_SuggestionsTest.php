<?php
/**
 * Integration tests for the Content_Gap_Suggestions experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Content_Gap_Suggestions
 */

namespace WordPress\AI\Tests\Integration\Experiments\Content_Gap_Suggestions;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Content_Gap_Suggestions\Content_Gap_Suggestions;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Content_Gap_Suggestions experiment test case.
 *
 * @since x.x.x
 */
class Content_Gap_SuggestionsTest extends WP_UnitTestCase {

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_content-gap-suggestions_enabled', true );

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->init();

		$experiment = $registry->get_feature( 'content-gap-suggestions' );
		$this->assertInstanceOf(
			Content_Gap_Suggestions::class,
			$experiment,
			'Content gap suggestions experiment should be registered in the registry.'
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
		delete_option( 'wpai_feature_content-gap-suggestions_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Tests that the experiment reports correct metadata.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_registration(): void {
		$experiment = new Content_Gap_Suggestions();

		$this->assertEquals( 'content-gap-suggestions', $experiment->get_id() );
		$this->assertEquals( 'Content Gap Suggestions', $experiment->get_label() );
		$this->assertEquals( Experiment_Category::ADMIN, $experiment->get_category() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Tests that the experiment can be disabled via the filter.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_can_be_disabled_via_filter(): void {
		add_filter( 'wpai_feature_content-gap-suggestions_enabled', '__return_false' );

		$experiment = new Content_Gap_Suggestions();
		$this->assertFalse( $experiment->is_enabled() );

		remove_all_filters( 'wpai_feature_content-gap-suggestions_enabled' );
	}

	/**
	 * Tests that the ability is registered under the expected name.
	 *
	 * @since x.x.x
	 */
	public function test_registers_ability(): void {
		do_action( 'wp_abilities_api_init' );

		$ability = wp_get_ability( 'ai/content-gap-suggestions' );
		$this->assertNotNull( $ability );
	}

	/**
	 * Tests that enqueue_assets() skips non-dashboard admin screens.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_skips_non_dashboard_screens(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$experiment = new Content_Gap_Suggestions();
		$experiment->enqueue_assets( 'post.php' );

		$this->assertFalse( wp_script_is( 'ai_content_gap_suggestions', 'enqueued' ) );
	}

	/**
	 * Tests that enqueue_assets() skips users without edit_posts.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_skips_users_without_edit_posts(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$experiment = new Content_Gap_Suggestions();
		$experiment->enqueue_assets( 'index.php' );

		$this->assertFalse( wp_script_is( 'ai_content_gap_suggestions', 'enqueued' ) );
	}

	/**
	 * Tests that enqueue_assets() enqueues and localizes data on the dashboard.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_localizes_dashboard_data(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		set_current_screen( 'dashboard' );

		$experiment = new Content_Gap_Suggestions();
		$experiment->enqueue_assets( 'index.php' );

		$this->assertTrue( wp_script_is( 'ai_content_gap_suggestions', 'enqueued' ) );

		$localized = (string) wp_scripts()->get_data( 'ai_content_gap_suggestions', 'data' );

		$this->assertStringContainsString( '"enabled":"1"', $localized );
		$this->assertStringContainsString(
			'"widgetRoot":"ai-content-gap-suggestions-root"',
			$localized
		);
		$this->assertStringContainsString( '"postEditBaseUrl"', $localized );
		$this->assertStringContainsString( admin_url( 'post.php' ), $localized );
	}
}
