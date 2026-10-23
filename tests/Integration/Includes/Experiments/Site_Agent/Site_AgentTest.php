<?php
/**
 * Integration tests for the Site Agent experiment.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\Site_Agent
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\Site_Agent;

use WP_UnitTestCase;
use WordPress\AI\Abilities\Site_Agent\Create_Draft;
use WordPress\AI\Abilities\Site_Agent\Read_Site;
use WordPress\AI\Abilities\Site_Agent\Update_Post;
use WordPress\AI\Abilities\Site_Agent\Update_Setting;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Experiments\Site_Agent\Catalog;
use WordPress\AI\Experiments\Site_Agent\REST\Controller;
use WordPress\AI\Experiments\Site_Agent\Site_Agent;

/**
 * Site Agent experiment test case.
 *
 * @since x.x.x
 */
class Site_AgentTest extends WP_UnitTestCase {

	/**
	 * Set up test case.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->ensure_category();
		$this->register_abilities();

		delete_option( 'wpai_feature_site-agent_enabled' );
		delete_option( 'wpai_feature_site-agent_field_allow_writes' );
	}

	/**
	 * Tear down test case.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wpai_feature_site-agent_enabled' );
		remove_all_filters( 'wpai_site_agent_allow_writes' );
		remove_all_filters( Catalog::FILTER );

		foreach ( array( Read_Site::ABILITY, Create_Draft::ABILITY, Update_Post::ABILITY, Update_Setting::ABILITY ) as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}

		delete_option( 'wpai_feature_site-agent_enabled' );
		delete_option( 'wpai_feature_site-agent_field_allow_writes' );

		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * The experiment exposes the expected metadata.
	 */
	public function test_experiment_metadata(): void {
		$experiment = new Site_Agent();

		$this->assertSame( 'site-agent', $experiment->get_id() );
		$this->assertSame( 'Site Agent', $experiment->get_label() );
		$this->assertNotEmpty( $experiment->get_description() );
		$this->assertSame( Experiment_Category::ADMIN, $experiment->get_category() );
		$this->assertSame( 'text_generation', $experiment->get_capability() );
	}

	/**
	 * The experiment is off by default.
	 */
	public function test_experiment_is_disabled_by_default(): void {
		$this->assertFalse( Site_Agent::is_active() );

		add_filter( 'wpai_feature_site-agent_enabled', '__return_true' );
		$this->assertTrue( Site_Agent::is_active() );
	}

	/**
	 * The write gate is off by default.
	 */
	public function test_write_gate_defaults_to_false(): void {
		delete_option( 'wpai_feature_site-agent_field_allow_writes' );

		$this->assertFalse( Site_Agent::writes_allowed() );

		update_option( 'wpai_feature_site-agent_field_allow_writes', true );
		$this->assertTrue( Site_Agent::writes_allowed() );

		add_filter( 'wpai_site_agent_allow_writes', '__return_false' );
		$this->assertFalse( Site_Agent::writes_allowed() );
	}

	/**
	 * Reads are in the catalog and writes are not until the gate is on.
	 */
	public function test_catalog_adds_writes_only_when_allowed(): void {
		$names = Catalog::names();

		$this->assertContains( Read_Site::ABILITY, $names );
		$this->assertNotContains( Create_Draft::ABILITY, $names );
		$this->assertNotContains( Update_Post::ABILITY, $names );
		$this->assertNotContains( Update_Setting::ABILITY, $names );

		update_option( 'wpai_feature_site-agent_field_allow_writes', true );

		$names = Catalog::names();

		$this->assertContains( Create_Draft::ABILITY, $names );
		$this->assertContains( Update_Post::ABILITY, $names );
		$this->assertContains( Update_Setting::ABILITY, $names );
	}

	/**
	 * The catalog filter can remove an ability.
	 */
	public function test_catalog_filter_can_remove_abilities(): void {
		add_filter(
			Catalog::FILTER,
			static function (): array {
				return array( 'ai/not-an-ability' );
			}
		);

		$this->assertSame( array( 'ai/not-an-ability' ), Catalog::names() );
	}

	/**
	 * Only abilities the current user could invoke are declared.
	 */
	public function test_available_names_filters_by_capability(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->assertContains( Read_Site::ABILITY, Catalog::available_names() );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$this->assertSame( array(), Catalog::available_names() );
	}

	/**
	 * The REST routes refuse every request while the experiment is off.
	 */
	public function test_rest_permission_refuses_while_disabled(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = ( new Controller() )->check_permission();

		$this->assertWPError( $result );
		$this->assertSame( 'site_agent_disabled', $result->get_error_code() );
	}

	/**
	 * The REST routes require the screen capability.
	 */
	public function test_rest_permission_requires_capability(): void {
		update_option( 'wpai_feature_site-agent_enabled', true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = ( new Controller() )->check_permission();

		$this->assertWPError( $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * An administrator may use the routes when the experiment is on.
	 */
	public function test_rest_permission_allows_administrator(): void {
		update_option( 'wpai_feature_site-agent_enabled', true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertTrue( ( new Controller() )->check_permission() );
	}

	/**
	 * Ensures the plugin's ability category exists.
	 */
	private function ensure_category(): void {
		if ( wp_has_ability_category( WPAI_DEFAULT_ABILITY_CATEGORY ) ) {
			return;
		}

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context.
		try {
			wp_register_ability_category(
				WPAI_DEFAULT_ABILITY_CATEGORY,
				array(
					'label'       => 'AI',
					'description' => 'AI abilities.',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Registers the experiment's abilities outside the normal init order.
	 */
	private function register_abilities(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context.
		try {
			( new Read_Site() )->register();
			( new Create_Draft() )->register();
			( new Update_Post() )->register();
			( new Update_Setting() )->register();
		} finally {
			array_pop( $wp_current_filter );
		}
	}
}
