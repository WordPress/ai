<?php
/**
 * Integration tests for the Image_Generation class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace WordPress\AI\Tests\Integration\Experiments\Image_Generation;

use WP_UnitTestCase;
use WordPress\AI\Experiment_Loader;
use WordPress\AI\Experiment_Category;
use WordPress\AI\Experiment_Registry;
use WordPress\AI\Experiments\Image_Generation\Image_Generation;

/**
 * Image_Generation test case.
 *
 * @since 0.2.0
 */
class Image_GenerationTest extends WP_UnitTestCase {
	/**
	 * Set up test case.
	 *
	 * @since 0.2.0
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up mock AI credentials so has_ai_credentials() returns true.
		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );

		// Mock has_valid_ai_credentials to return true for tests.
		add_filter( 'ai_experiments_pre_has_valid_credentials_check', '__return_true' );

		// Enable experiments globally and individually.
		update_option( 'ai_experiments_enabled', true );
		update_option( 'ai_experiment_image-generation_enabled', true );

		$registry = new Experiment_Registry();
		$loader   = new Experiment_Loader( $registry );
		$loader->register_default_experiments();
		$loader->initialize_experiments();

		$experiment = $registry->get_experiment( 'image-generation' );
		$this->assertInstanceOf( Image_Generation::class, $experiment, 'Image generation experiment should be registered in the registry.' );
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.2.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'ai_experiments_enabled' );
		delete_option( 'ai_experiment_image-generation_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'ai_experiments_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Test that the experiment is registered correctly.
	 *
	 * @since 0.2.0
	 */
	public function test_experiment_registration() {
		$experiment = new Image_Generation();

		$this->assertEquals( 'image-generation', $experiment->get_id() );
		$this->assertEquals( 'Image Generation', $experiment->get_label() );
		$this->assertEquals( Experiment_Category::EDITOR, $experiment->get_category() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Test that the experiment registers all abilities.
	 *
	 * @since 0.3.0
	 */
	public function test_experiment_registers_abilities() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WP_Ability class not available' );
			return;
		}

		// Expect warnings about already registered abilities from other tests.
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::register' );

		// Trigger the hook to register abilities.
		do_action( 'wp_abilities_api_init' );

		// Verify image generation ability is registered.
		$image_generation_ability = wp_get_ability( 'ai/image-generation' );
		$this->assertNotNull( $image_generation_ability, 'Image generation ability should be registered' );
		$this->assertInstanceOf( \WP_Ability::class, $image_generation_ability, 'Should be a WP_Ability instance' );

		// Verify image import ability is registered.
		$image_import_ability = wp_get_ability( 'ai/image-import' );
		$this->assertNotNull( $image_import_ability, 'Image import ability should be registered' );
		$this->assertInstanceOf( \WP_Ability::class, $image_import_ability, 'Should be a WP_Ability instance' );

		// Verify image prompt generation ability is registered.
		$image_prompt_ability = wp_get_ability( 'ai/image-prompt-generation' );
		$this->assertNotNull( $image_prompt_ability, 'Image prompt generation ability should be registered' );
		$this->assertInstanceOf( \WP_Ability::class, $image_prompt_ability, 'Should be a WP_Ability instance' );
	}

	/**
	 * Test that register() hooks enqueue_inline_assets to enqueue_block_editor_assets.
	 *
	 * @since 0.4.0
	 */
	public function test_register_hooks_enqueue_block_editor_assets(): void {
		$experiment = new Image_Generation();
		$experiment->register();

		$this->assertNotFalse(
			has_action( 'enqueue_block_editor_assets', array( $experiment, 'enqueue_inline_assets' ) ),
			'enqueue_inline_assets should be hooked to enqueue_block_editor_assets'
		);
	}

	/**
	 * Test that the experiment registers post meta.
	 *
	 * @since 0.3.0
	 */
	public function test_experiment_registers_post_meta() {
		$experiment = new Image_Generation();
		$experiment->register();

		// Verify post meta is registered for attachment post type.
		$meta = get_registered_meta_keys( 'post', 'attachment' );
		$this->assertArrayHasKey( 'ai_generated', $meta, 'ai_generated meta should be registered for attachment post type' );
		$this->assertEquals( 'integer', $meta['ai_generated']['type'], 'ai_generated meta type should be integer' );
		$this->assertTrue( $meta['ai_generated']['show_in_rest'], 'ai_generated meta should be available in REST API' );
	}

	/**
	 * Test that register_admin_menu hooks are added.
	 *
	 * @since 0.4.0
	 */
	public function test_register_hooks_admin_menu() {
		$experiment = new Image_Generation();
		$experiment->register();

		$this->assertNotFalse( has_action( 'admin_menu', array( $experiment, 'register_admin_menu' ) ), 'admin_menu hook should be registered' );
		$this->assertNotFalse( has_action( 'admin_footer-upload.php', array( $experiment, 'inject_generate_image_button' ) ), 'admin_footer-upload.php hook should be registered' );
	}

	/**
	 * Test that render_admin_page outputs expected HTML.
	 *
	 * @since 0.4.0
	 */
	public function test_render_admin_page() {
		$experiment = new Image_Generation();

		ob_start();
		$experiment->render_admin_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<div class="wrap">', $output, 'Output should contain wrap div' );
		$this->assertStringContainsString( 'Generate Image', $output, 'Output should contain page title' );
		$this->assertStringContainsString( 'ai-image-generation-root', $output, 'Output should contain React root element' );
	}

	/**
	 * Test that inject_generate_image_button outputs a script tag.
	 *
	 * @since 0.4.0
	 */
	public function test_inject_generate_image_button() {
		$experiment = new Image_Generation();

		ob_start();
		$experiment->inject_generate_image_button();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<script type="text/javascript">', $output, 'Output should contain script tag' );
		$this->assertStringContainsString( 'ai-generate-image-btn', $output, 'Output should contain button class name' );
		$this->assertStringContainsString( 'page=generate-image', $output, 'Output should contain link to admin page' );
		$this->assertStringContainsString( 'stopImmediatePropagation', $output, 'Output should contain click handler that stops propagation' );
	}

	/**
	 * Test that enqueue_assets does not load on irrelevant screens.
	 *
	 * @since 0.4.0
	 */
	public function test_enqueue_assets_skips_irrelevant_screens() {
		$experiment = new Image_Generation();
		$experiment->register();

		// Calling with an irrelevant hook suffix should not enqueue anything.
		$experiment->enqueue_assets( 'options-general.php' );
		$this->assertFalse( wp_script_is( 'ai_image_generation', 'enqueued' ), 'Script should not be enqueued on options-general.php' );
	}
}
