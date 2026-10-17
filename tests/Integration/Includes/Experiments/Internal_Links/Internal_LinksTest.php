<?php
/**
 * Integration tests for the Internal_Links experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Internal_Links
 */

namespace WordPress\AI\Tests\Integration\Experiments\Internal_Links;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Experiments\Internal_Links\Internal_Links;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Internal_Links experiment test case.
 *
 * @since x.x.x
 */
class Internal_LinksTest extends WP_UnitTestCase {
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
		update_option( 'wpai_feature_internal-links_enabled', true );

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->init();

		$experiment = $registry->get_feature( 'internal-links' );
		$this->assertInstanceOf(
			Internal_Links::class,
			$experiment,
			'Internal links experiment should be registered in the registry.'
		);
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		wp_dequeue_style( 'ai_internal_links' );
		wp_deregister_style( 'ai_internal_links' );
		wp_dequeue_script( 'ai_internal_links' );
		wp_deregister_script( 'ai_internal_links' );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_internal-links_enabled' );
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
		$experiment = new Internal_Links();

		$this->assertSame( 'internal-links', $experiment->get_id() );
		$this->assertSame( 'Internal Link Suggestions', $experiment->get_label() );
		$this->assertSame( Experiment_Category::EDITOR, $experiment->get_category() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Tests that the experiment can be disabled via the filter.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_can_be_disabled_via_filter(): void {
		add_filter( 'wpai_feature_internal-links_enabled', '__return_false' );

		$experiment = new Internal_Links();
		$this->assertFalse( $experiment->is_enabled() );

		remove_all_filters( 'wpai_feature_internal-links_enabled' );
	}

	/**
	 * Tests that register() hooks the expected actions.
	 *
	 * @since x.x.x
	 */
	public function test_register_hooks_expected_actions(): void {
		$experiment = new Internal_Links();
		$experiment->register();

		$this->assertNotFalse(
			has_action( 'wp_abilities_api_init', array( $experiment, 'register_abilities' ) ),
			'register_abilities should be hooked to wp_abilities_api_init'
		);
		$this->assertNotFalse(
			has_action( 'enqueue_block_editor_assets', array( $experiment, 'enqueue_assets' ) ),
			'enqueue_assets should be hooked to enqueue_block_editor_assets'
		);
	}

	/**
	 * Tests that enqueue_assets() enqueues the script and localizes data.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_enqueues_script_and_localizes_data(): void {
		$experiment = new Internal_Links();
		$experiment->enqueue_assets();

		$this->assertTrue( wp_script_is( 'ai_internal_links', 'enqueued' ) );

		$localized = (string) wp_scripts()->get_data( 'ai_internal_links', 'data' );
		$this->assertStringContainsString( 'enabled', $localized );
		$this->assertStringContainsString( 'minContentLength', $localized );
		$this->assertStringContainsString( 'maxSuggestions', $localized );
	}

	/**
	 * Tests that enqueue_assets() localizes the default max suggestions value.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_localizes_default_max_suggestions(): void {
		$experiment = new Internal_Links();
		$experiment->enqueue_assets();

		$localized = (string) wp_scripts()->get_data( 'ai_internal_links', 'data' );
		$this->assertStringContainsString( '"maxSuggestions":"5"', $localized );
	}

	/**
	 * Tests that the wpai_internal_links_max_suggestions filter overrides the default.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_respects_max_suggestions_filter(): void {
		add_filter(
			'wpai_internal_links_max_suggestions',
			static function () {
				return 3;
			}
		);

		$experiment = new Internal_Links();
		$experiment->enqueue_assets();

		remove_all_filters( 'wpai_internal_links_max_suggestions' );

		$localized = (string) wp_scripts()->get_data( 'ai_internal_links', 'data' );
		$this->assertStringContainsString( '"maxSuggestions":"3"', $localized );
	}
}
