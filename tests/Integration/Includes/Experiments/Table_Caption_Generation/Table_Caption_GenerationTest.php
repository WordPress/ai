<?php
/**
 * Integration tests for the Table_Caption_Generation experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Table_Caption_Generation
 */

namespace WordPress\AI\Tests\Integration\Experiments\Table_Caption_Generation;

use WP_UnitTestCase;
use WordPress\AI\Abilities\Table_Caption_Generation\Table_Caption_Generation as Ability;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Experiments\Table_Caption_Generation\Table_Caption_Generation;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Table_Caption_Generation experiment test case.
 *
 * @since x.x.x
 */
class Table_Caption_GenerationTest extends WP_UnitTestCase {

	/**
	 * Sets up the enabled experiment and an administrator.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		add_filter( 'wpai_has_ai_credentials', '__return_true' );
		update_option( 'wpai_feature_table-caption-generation_enabled', true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->init();

		$this->assertInstanceOf( Table_Caption_Generation::class, $registry->get_feature( 'table-caption-generation' ) );
	}

	/**
	 * Restores user, screen, and asset state.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		set_current_screen( 'front' );
		unset( $GLOBALS['current_screen'] );
		wp_dequeue_script( 'ai_table-caption-generation' );
		wp_deregister_script( 'ai_table-caption-generation' );
		wp_dequeue_style( 'ai_table-caption-generation' );
		wp_deregister_style( 'ai_table-caption-generation' );
		delete_option( 'wpai_feature_table-caption-generation_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		remove_filter( 'wpai_has_ai_credentials', '__return_true' );

		parent::tearDown();
	}

	/**
	 * Tests the experiment metadata and enabled state.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_registration(): void {
		$experiment = new Table_Caption_Generation();

		$this->assertSame( 'table-caption-generation', $experiment->get_id() );
		$this->assertSame( 'Table Caption Generation', $experiment->get_label() );
		$this->assertSame( Experiment_Category::EDITOR, $experiment->get_category() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Tests that the experiment respects its disabled setting.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_not_enabled_when_individually_disabled(): void {
		update_option( 'wpai_feature_table-caption-generation_enabled', false );

		$experiment = new Table_Caption_Generation();
		$this->assertFalse( $experiment->is_enabled() );
	}

	/**
	 * Tests that registration adds the ability and asset hooks.
	 *
	 * @since x.x.x
	 */
	public function test_register_adds_expected_hooks(): void {
		$experiment = new Table_Caption_Generation();
		$experiment->register();

		$this->assertIsInt( has_action( 'wp_abilities_api_init', array( $experiment, 'register_ability' ) ) );
		$this->assertIsInt( has_action( 'admin_enqueue_scripts', array( $experiment, 'enqueue_assets' ) ) );
	}

	/**
	 * Tests that registration exposes the caption ability.
	 *
	 * @since x.x.x
	 */
	public function test_register_ability(): void {
		if ( wp_has_ability( 'ai/table-caption-generation' ) ) {
			wp_unregister_ability( 'ai/table-caption-generation' );
		}
		remove_all_actions( 'wp_abilities_api_init' );
		add_action( 'wp_abilities_api_init', array( new Table_Caption_Generation(), 'register_ability' ) );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core Abilities API hook.
		do_action( 'wp_abilities_api_init' );

		$ability = wp_get_ability( 'ai/table-caption-generation' );
		$this->assertInstanceOf( Ability::class, $ability );
		$this->assertSame( 'Table Caption Generation', $ability->get_label() );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest' ) );
	}

	/**
	 * Tests that assets and enabled settings load in the post block editor.
	 *
	 * @since x.x.x
	 * @dataProvider editor_hooks
	 *
	 * @param string $hook_suffix Admin page hook.
	 */
	public function test_enqueue_assets_in_block_editor( string $hook_suffix ): void {
		set_current_screen( 'post' );
		get_current_screen()->is_block_editor( true );

		$experiment = new Table_Caption_Generation();
		$experiment->enqueue_assets( $hook_suffix );

		$this->assertTrue( wp_script_is( 'ai_table-caption-generation', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'ai_table-caption-generation', 'enqueued' ) );
		$data = (string) wp_scripts()->get_data( 'ai_table-caption-generation', 'data' );
		$this->assertStringContainsString( 'aiTableCaptionGenerationData', $data );
		$this->assertStringContainsString( '"enabled":"1"', $data );
	}

	/**
	 * Provides existing and new post editor hooks.
	 *
	 * @return array<string, array<string>> Editor hooks.
	 */
	public function editor_hooks(): array {
		return array(
			'existing post' => array( 'post.php' ),
			'new post'      => array( 'post-new.php' ),
		);
	}

	/**
	 * Tests that unrelated screens and the classic editor do not load assets.
	 *
	 * @since x.x.x
	 * @dataProvider non_block_editor_screens
	 *
	 * @param string $screen_id   Screen ID.
	 * @param string $hook_suffix Admin page hook.
	 */
	public function test_enqueue_assets_skips_non_block_editor_screens( string $screen_id, string $hook_suffix ): void {
		set_current_screen( $screen_id );
		get_current_screen()->is_block_editor( false );

		$experiment = new Table_Caption_Generation();
		$experiment->enqueue_assets( $hook_suffix );

		$this->assertFalse( wp_script_is( 'ai_table-caption-generation', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'ai_table-caption-generation', 'enqueued' ) );
	}

	/**
	 * Provides screens outside the post block editor.
	 *
	 * @return array<string, array<string>> Non-block-editor screens.
	 */
	public function non_block_editor_screens(): array {
		return array(
			'settings'       => array( 'options-general', 'options-general.php' ),
			'classic editor' => array( 'post', 'post.php' ),
		);
	}

	/**
	 * Tests that unsupported post types do not load assets.
	 *
	 * @since x.x.x
	 * @dataProvider unsupported_post_types
	 *
	 * @param bool  $show_in_rest Whether the post type is REST-enabled.
	 * @param array $supports     Supported post type features.
	 */
	public function test_enqueue_assets_skips_unsupported_post_types( bool $show_in_rest, array $supports ): void {
		register_post_type(
			'caption_test',
			array(
				'show_in_rest' => $show_in_rest,
				'supports'     => $supports,
			)
		);
		try {
			set_current_screen( 'caption_test' );
			get_current_screen()->is_block_editor( true );

			$experiment = new Table_Caption_Generation();
			$experiment->enqueue_assets( 'post.php' );

			$this->assertFalse( wp_script_is( 'ai_table-caption-generation', 'enqueued' ) );
			$this->assertFalse( wp_style_is( 'ai_table-caption-generation', 'enqueued' ) );
		} finally {
			unregister_post_type( 'caption_test' );
		}
	}

	/**
	 * Provides post types without REST or editor support.
	 *
	 * @return array<string, array> Unsupported post type settings.
	 */
	public function unsupported_post_types(): array {
		return array(
			'no REST support'   => array( false, array( 'editor' ) ),
			'no editor support' => array( true, array( 'title' ) ),
		);
	}
}
