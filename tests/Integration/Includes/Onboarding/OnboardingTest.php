<?php
/**
 * Integration tests for the Onboarding class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Onboarding
 */

namespace WordPress\AI\Tests\Integration\Includes\Onboarding;

use WP_UnitTestCase;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Features\Registry;
use WordPress\AI\Onboarding\Onboarding;

/**
 * Stub for Title Generation.
 */
class Onboarding_Test_Title_Generation extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'title-generation';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Title Generation',
			'description' => 'A stub feature for the onboarding tests.',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {}
}

/**
 * Stub for Type-ahead Text.
 */
class Onboarding_Test_Type_Ahead extends Onboarding_Test_Title_Generation {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'type-ahead';
	}
}

/**
 * Onboarding test case.
 *
 * @covers \WordPress\AI\Onboarding\Onboarding
 * @since x.x.x
 */
class OnboardingTest extends WP_UnitTestCase {

	/**
	 * Instance under test.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Onboarding\Onboarding
	 */
	private Onboarding $onboarding;

	/**
	 * Administrator used by the tests.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		/*
		 * Start from a site with no tools turned on. Tests elsewhere in the suite
		 * that force an implicit commit can leave a feature option behind, and a
		 * delete in tearDown() is rolled back with the test's transaction.
		 */
		delete_option( 'wpai_feature_title-generation_enabled' );
		delete_option( 'wpai_feature_type-ahead_enabled' );

		$registry = new Registry();
		$registry->register_feature( new Onboarding_Test_Title_Generation() );
		$registry->register_feature( new Onboarding_Test_Type_Ahead() );

		$this->onboarding = new Onboarding( $registry );
		$this->onboarding->register_settings();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( Onboarding::OPTION_USE_CASES );
		delete_option( Onboarding::OPTION_COMPLETED );
		delete_option( Onboarding::OPTION_WELCOME );
		delete_option( 'wpai_feature_title-generation_enabled' );
		delete_option( 'wpai_feature_type-ahead_enabled' );

		parent::tearDown();
	}

	/**
	 * Tests that the guide's settings and user meta are exposed over REST.
	 *
	 * @since x.x.x
	 */
	public function test_register_settings_exposes_state_over_rest(): void {
		$settings = get_registered_settings();

		$this->assertArrayHasKey( Onboarding::OPTION_USE_CASES, $settings );
		$this->assertArrayHasKey( Onboarding::OPTION_COMPLETED, $settings );
		$this->assertNotEmpty( $settings[ Onboarding::OPTION_USE_CASES ]['show_in_rest'] );
		$this->assertTrue( $settings[ Onboarding::OPTION_COMPLETED ]['show_in_rest'] );
		$this->assertTrue( registered_meta_key_exists( 'user', Onboarding::META_HIDDEN ) );
	}

	/**
	 * Tests that only known, unique use case and add-on IDs are stored.
	 *
	 * @since x.x.x
	 */
	public function test_sanitize_use_case_ids_keeps_known_unique_ids(): void {
		$this->assertSame(
			array( 'write-publish', 'type-ahead' ),
			$this->onboarding->sanitize_use_case_ids( array( 'write-publish', 'unknown', 'type-ahead', 'write-publish', 12 ) )
		);
		$this->assertSame( array(), $this->onboarding->sanitize_use_case_ids( 'write-publish' ) );
	}

	/**
	 * Tests that the stored choices are sanitized when saved and when read.
	 *
	 * @since x.x.x
	 */
	public function test_get_selected_ids_returns_sanitized_option(): void {
		update_option( Onboarding::OPTION_USE_CASES, array( 'write-publish', 'images-audio' ) );

		$this->assertSame( array( 'write-publish' ), $this->onboarding->get_selected_ids() );
	}

	/**
	 * Tests that setup is pending for an administrator on a new site.
	 *
	 * @since x.x.x
	 */
	public function test_setup_is_pending_on_a_new_site(): void {
		$this->assertTrue( $this->onboarding->is_setup_pending_for_current_user() );
	}

	/**
	 * Tests that setup is never pending for users who cannot manage options.
	 *
	 * @since x.x.x
	 */
	public function test_setup_is_not_pending_without_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertFalse( $this->onboarding->is_setup_pending_for_current_user() );
	}

	/**
	 * Tests that a site with tools already on is treated as set up.
	 *
	 * @since x.x.x
	 */
	public function test_setup_is_not_pending_when_a_feature_is_enabled(): void {
		update_option( 'wpai_feature_title-generation_enabled', true );

		$this->assertSame( 1, $this->onboarding->count_enabled_features() );
		$this->assertFalse( $this->onboarding->is_setup_pending_for_current_user() );
	}

	/**
	 * Tests that setup stops being pending once use cases are chosen.
	 *
	 * @since x.x.x
	 */
	public function test_setup_is_not_pending_after_choosing_use_cases(): void {
		update_option( Onboarding::OPTION_USE_CASES, array( 'write-publish' ) );

		$this->assertFalse( $this->onboarding->is_setup_pending_for_current_user() );
	}

	/**
	 * Tests that hiding the guide only affects the user who hid it.
	 *
	 * @since x.x.x
	 */
	public function test_hiding_the_guide_is_per_user(): void {
		update_user_meta( $this->admin_id, Onboarding::META_HIDDEN, true );

		$this->assertTrue( $this->onboarding->is_hidden_for_current_user() );
		$this->assertFalse( $this->onboarding->is_setup_pending_for_current_user() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertFalse( $this->onboarding->is_hidden_for_current_user() );
		$this->assertTrue( $this->onboarding->is_setup_pending_for_current_user() );
	}

	/**
	 * Tests that setup is not pending once the guide is complete.
	 *
	 * @since x.x.x
	 */
	public function test_setup_is_not_pending_when_completed(): void {
		update_option( Onboarding::OPTION_COMPLETED, true );

		$this->assertTrue( $this->onboarding->is_completed() );
		$this->assertFalse( $this->onboarding->is_setup_pending_for_current_user() );
	}

	/**
	 * Tests that activation flags the welcome notice, but not on a set-up site.
	 *
	 * @since x.x.x
	 */
	public function test_flag_welcome_skips_completed_sites(): void {
		Onboarding::flag_welcome();
		$this->assertSame( '1', get_option( Onboarding::OPTION_WELCOME ) );

		delete_option( Onboarding::OPTION_WELCOME );
		update_option( Onboarding::OPTION_COMPLETED, true );

		Onboarding::flag_welcome();
		$this->assertFalse( get_option( Onboarding::OPTION_WELCOME ) );
	}

	/**
	 * Tests that opening Settings > AI clears the welcome flag.
	 *
	 * @since x.x.x
	 */
	public function test_clear_welcome_flag_removes_the_option(): void {
		Onboarding::flag_welcome();

		$this->onboarding->clear_welcome_flag();

		$this->assertFalse( get_option( Onboarding::OPTION_WELCOME ) );
	}

	/**
	 * Tests the data passed to the Settings > AI page.
	 *
	 * @since x.x.x
	 */
	public function test_add_page_data_adds_the_guide_state(): void {
		update_option( Onboarding::OPTION_USE_CASES, array( 'write-publish' ) );
		update_option( 'wpai_feature_title-generation_enabled', true );

		$data = $this->onboarding->add_page_data( array( 'existing' => 'value' ) );

		$this->assertSame( 'value', $data['existing'] );
		$this->assertArrayHasKey( 'onboarding', $data );

		$onboarding = $data['onboarding'];

		$this->assertSame( array( 'write-publish' ), $onboarding['selected'] );
		$this->assertFalse( $onboarding['completed'] );
		$this->assertFalse( $onboarding['hidden'] );
		$this->assertSame( 1, $onboarding['enabledCount'] );
		$this->assertSame( array( 'write-publish' ), wp_list_pluck( $onboarding['useCases'], 'id' ) );
		$this->assertSame( array(), $onboarding['useCases'][0]['unsupported'] );
		$this->assertSame( 'type-ahead', $onboarding['useCases'][0]['addon']['id'] );
		$this->assertStringEndsWith( 'assets/images/wapuu/wapuu-navigating.svg', $onboarding['sprites']['navigating'] );
	}

	/**
	 * Tests that every sprite the guide references ships with the plugin.
	 *
	 * @since x.x.x
	 */
	public function test_sprites_exist_on_disk(): void {
		$data = $this->onboarding->add_page_data( array() );

		$this->assertNotEmpty( $data['onboarding']['sprites'] );

		foreach ( array_keys( $data['onboarding']['sprites'] ) as $pose ) {
			$this->assertFileExists( WPAI_PLUGIN_DIR . 'assets/images/wapuu/wapuu-' . $pose . '.svg' );
		}

		foreach ( $data['onboarding']['useCases'] as $use_case ) {
			$this->assertArrayHasKey( $use_case['sprite'], $data['onboarding']['sprites'] );
		}

		$this->assertSame( '', Onboarding::get_sprite_url( 'not-a-pose' ) );
	}
}
