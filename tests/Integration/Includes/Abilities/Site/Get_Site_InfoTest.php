<?php
/**
 * Integration tests for the ai/get-site-info Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Site
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Site;

use WP_UnitTestCase;
use WordPress\AI\Abilities\Site\Get_Site_Info;
use WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace\Fixtures\Workspace_Tools;

/**
 * Get_Site_Info ability test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Abilities\Site\Get_Site_Info
 */
class Get_Site_InfoTest extends WP_UnitTestCase {

	/**
	 * The fields every caller gets.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	private const SHARED_FIELDS = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.
		'name',
		'tagline',
		'home_url',
		'language',
		'timezone',
		'date_format',
		'time_format',
		'theme',
		'is_block_theme',
	);

	/**
	 * The fields only a user who can `view_site_health_checks` gets.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	private const VERSION_FIELDS = array( 'wp_version', 'php_version' ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.

	/**
	 * Shared user IDs keyed by role.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, int>
	 */
	private static $user_ids = array();

	/**
	 * Creates the shared users.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_UnitTest_Factory $factory The unit test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$user_ids = array(
			'administrator' => $factory->user->create( array( 'role' => 'administrator' ) ),
			'editor'        => $factory->user->create( array( 'role' => 'editor' ) ),
			'subscriber'    => $factory->user->create( array( 'role' => 'subscriber' ) ),
		);

		// On multisite only a super admin can open Plugins or Site Health, as in core.
		if ( ! is_multisite() ) {
			return;
		}

		grant_super_admin( self::$user_ids['administrator'] );
	}

	/**
	 * Revokes the super admin granted on multisite.
	 *
	 * @since x.x.x
	 */
	public static function wpTearDownAfterClass(): void {
		if ( ! is_multisite() ) {
			return;
		}

		revoke_super_admin( self::$user_ids['administrator'] );
	}

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'blogname', 'Field Notes' );
		update_option( 'blogdescription', 'Notes from the field' );
		update_option( 'timezone_string', 'America/Denver' );
		update_option( 'date_format', 'j F Y' );
		update_option( 'time_format', 'H:i' );

		$this->register_ability();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		if ( wp_has_ability( Get_Site_Info::ABILITY ) ) {
			wp_unregister_ability( Get_Site_Info::ABILITY );
		}

		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Registers the ability inside a faked `wp_abilities_api_init` action.
	 *
	 * @since x.x.x
	 */
	private function register_ability(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			Workspace_Tools::register( new Get_Site_Info() );
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Runs the ability as a user with the given role.
	 *
	 * @since x.x.x
	 *
	 * @param string $role The role to run as.
	 * @return mixed The ability result.
	 */
	private function run_as( string $role ) {
		wp_set_current_user( self::$user_ids[ $role ] );

		return wp_get_ability( Get_Site_Info::ABILITY )->execute( array() );
	}

	/**
	 * An administrator gets every field, including the WordPress and PHP versions.
	 *
	 * @since x.x.x
	 */
	public function test_administrator_gets_every_field_including_versions(): void {
		$result = $this->run_as( 'administrator' );

		$this->assertIsArray( $result );
		$this->assertSame( array_merge( self::SHARED_FIELDS, self::VERSION_FIELDS ), array_keys( $result ) );
		$this->assertSame( 'Field Notes', $result['name'] );
		$this->assertSame( 'Notes from the field', $result['tagline'] );
		$this->assertSame( home_url( '/' ), $result['home_url'] );
		$this->assertSame( get_locale(), $result['language'] );
		$this->assertSame( 'America/Denver', $result['timezone'] );
		$this->assertSame( 'j F Y', $result['date_format'] );
		$this->assertSame( 'H:i', $result['time_format'] );
		$this->assertSame( wp_get_theme()->get( 'Name' ), $result['theme'] );
		$this->assertSame( wp_is_block_theme(), $result['is_block_theme'] );
		$this->assertSame( get_bloginfo( 'version' ), $result['wp_version'] );
		$this->assertSame( PHP_VERSION, $result['php_version'] );
	}

	/**
	 * The ability runs when called with no input at all, as core's own `core/get-site-info` does.
	 *
	 * @since x.x.x
	 */
	public function test_executes_without_any_input(): void {
		wp_set_current_user( self::$user_ids['editor'] );

		$result = wp_get_ability( Get_Site_Info::ABILITY )->execute();

		$this->assertIsArray( $result );
		$this->assertSame( self::SHARED_FIELDS, array_keys( $result ) );
	}

	/**
	 * The name and tagline come back as plain text, not with the HTML entities they are stored with.
	 *
	 * @since x.x.x
	 */
	public function test_name_and_tagline_are_decoded(): void {
		update_option( 'blogname', "Tom & Jerry's" );
		update_option( 'blogdescription', "Cats & mice, \"chased\" since '40" );

		$result = $this->run_as( 'editor' );

		$this->assertSame( "Tom & Jerry's", $result['name'] );
		$this->assertSame( "Cats & mice, \"chased\" since '40", $result['tagline'] );
	}

	/**
	 * A user with `manage_options` but not `view_site_health_checks` gets no versions.
	 *
	 * @since x.x.x
	 */
	public function test_manage_options_without_site_health_access_gets_no_versions(): void {
		$deny = static function ( array $caps, string $cap ): array {
			return 'view_site_health_checks' === $cap ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $deny, 10, 2 );

		try {
			wp_set_current_user( self::$user_ids['administrator'] );
			$this->assertTrue( current_user_can( 'manage_options' ) );
			$this->assertFalse( current_user_can( 'view_site_health_checks' ) );

			$result = wp_get_ability( Get_Site_Info::ABILITY )->execute( array() );
		} finally {
			remove_filter( 'map_meta_cap', $deny, 10 );
		}

		$this->assertIsArray( $result );
		$this->assertSame( self::SHARED_FIELDS, array_keys( $result ) );
	}

	/**
	 * An editor gets the same fields without the versions.
	 *
	 * @since x.x.x
	 */
	public function test_editor_gets_the_fields_without_versions(): void {
		$result = $this->run_as( 'editor' );

		$this->assertIsArray( $result );
		$this->assertSame( self::SHARED_FIELDS, array_keys( $result ) );
		$this->assertSame( 'Field Notes', $result['name'] );
		$this->assertSame( 'Notes from the field', $result['tagline'] );
		$this->assertArrayNotHasKey( 'wp_version', $result );
		$this->assertArrayNotHasKey( 'php_version', $result );
	}

	/**
	 * A visitor who is not logged in is refused.
	 *
	 * @since x.x.x
	 */
	public function test_logged_out_visitor_is_refused(): void {
		wp_set_current_user( 0 );

		$this->assertWPError( wp_get_ability( Get_Site_Info::ABILITY )->execute( array() ) );
	}

	/**
	 * The declared input schema never sends `properties` as an empty list, which OpenAI refuses.
	 *
	 * @since x.x.x
	 */
	public function test_input_schema_declared_to_the_model_has_no_empty_list(): void {
		$schema = wp_get_ability( Get_Site_Info::ABILITY )->get_input_schema();

		if ( function_exists( 'wp_prepare_json_schema_for_client' ) ) {
			$schema = wp_prepare_json_schema_for_client( $schema );
		}

		$this->assertStringNotContainsString( '"properties":[]', (string) wp_json_encode( $schema ) );
	}
}
