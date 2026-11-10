<?php
/**
 * Integration tests for the ai/get-site-info Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Site
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Site;

use WP_UnitTestCase;
use WordPress\AI\Abilities\Site\Get_Site_Info;
use WordPress\AI\Experiments\AI_Workspace\Tool_Policy;
use WordPress\AI\Experiments\AI_Workspace\Tool_Selector;
use WordPress\AI\Experiments\MCP_Adapter\Exposure_Overrides;

/**
 * Get_Site_Info ability test case.
 *
 * The ability answers questions about the site from a fixed set of fields. These
 * tests pin which fields each role gets, that nothing outside that set leaks, and
 * that the ability reaches the agent and MCP on a default install.
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
		update_option( 'admin_email', 'owner-secret@example.org' );
		update_option( 'siteurl', home_url( '/wp-core-secret' ) );

		$this->register_ability();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		remove_filter( 'wp_register_ability_args', array( Exposure_Overrides::class, 'filter_ability_args' ), 100 );

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
			( new Get_Site_Info() )->register();
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
	 * A user with `manage_options` but not `view_site_health_checks`, such as a sub-site
	 * administrator on multisite, gets no versions.
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
	 * A site set to a UTC offset rather than a named zone reports the offset.
	 *
	 * @since x.x.x
	 */
	public function test_timezone_falls_back_to_the_utc_offset(): void {
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', -5 );

		$result = $this->run_as( 'editor' );

		$this->assertSame( '-05:00', $result['timezone'] );
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
	 * No response carries the admin email, the WordPress address, the database version or plugin names.
	 *
	 * @since x.x.x
	 */
	public function test_no_response_carries_admin_email_siteurl_database_or_plugins(): void {
		global $wpdb;

		$plugins = (array) get_option( 'active_plugins', array() );

		foreach ( array( 'administrator', 'editor' ) as $role ) {
			$encoded = (string) wp_json_encode( $this->run_as( $role ) );

			$this->assertStringNotContainsString( 'owner-secret@example.org', $encoded, $role );
			$this->assertStringNotContainsString( 'wp-core-secret', $encoded, $role );
			$this->assertStringNotContainsString( (string) $wpdb->db_server_info(), $encoded, $role );
			$this->assertStringNotContainsString( 'db_version', $encoded, $role );
			$this->assertStringNotContainsString( 'plugin', $encoded, $role );

			foreach ( $plugins as $plugin ) {
				$this->assertStringNotContainsString( (string) wp_json_encode( (string) $plugin ), $encoded, $role );
			}
		}
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
	 * The input schema, as declared to the model, holds no empty list where
	 * JSON Schema expects an object. OpenAI refused every request that offered
	 * this ability while it sent `"properties": []`.
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

	/**
	 * Input is still refused, with no properties declared.
	 *
	 * @since x.x.x
	 */
	public function test_refuses_any_input(): void {
		wp_set_current_user( self::$user_ids['editor'] );

		$result = wp_get_ability( Get_Site_Info::ABILITY )->execute( array( 'fields' => array( 'name' ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * The output schema requires the shared fields and leaves the versions optional.
	 *
	 * @since x.x.x
	 */
	public function test_output_schema_marks_the_versions_optional(): void {
		$schema = wp_get_ability( Get_Site_Info::ABILITY )->get_output_schema();

		$this->assertSame( self::SHARED_FIELDS, $schema['required'] );
		$this->assertFalse( $schema['additionalProperties'] );

		foreach ( self::VERSION_FIELDS as $field ) {
			$this->assertArrayHasKey( $field, $schema['properties'] );
			$this->assertNotContains( $field, $schema['required'] );
		}
	}

	/**
	 * Registration carries a category and the read-only, closed-world annotations.
	 *
	 * WordPress trunk refuses an ability without a registered category, so this
	 * also stands for "registers on trunk".
	 *
	 * @since x.x.x
	 */
	public function test_registration_has_a_category_and_read_only_annotations(): void {
		$ability = wp_get_ability( Get_Site_Info::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( WPAI_DEFAULT_ABILITY_CATEGORY, $ability->get_category() );
		$this->assertTrue( wp_has_ability_category( WPAI_DEFAULT_ABILITY_CATEGORY ) );

		$meta = $ability->get_meta();

		$this->assertTrue( $meta['annotations']['readonly'] );
		$this->assertFalse( $meta['annotations']['destructive'] );
		$this->assertTrue( $meta['annotations']['idempotent'] );
		$this->assertFalse( $meta['annotations']['open_world'] );
		$this->assertTrue( $meta['show_in_rest'] );
	}

	/**
	 * With policy admission off, as it ships, the ability is declared to the model for an administrator and an editor.
	 *
	 * @since x.x.x
	 */
	public function test_declared_to_the_model_for_administrator_and_editor_with_admission_off(): void {
		$this->assertFalse( ( new Tool_Policy() )->admission_is_enabled(), 'Admission must be off, as it ships.' );

		foreach ( array( 'administrator', 'editor' ) as $role ) {
			wp_set_current_user( self::$user_ids[ $role ] );

			$selector = new Tool_Selector();

			$this->assertArrayHasKey( Get_Site_Info::ABILITY, $selector->get_candidates() );
			$this->assertSame( '', $selector->get_candidates()[ Get_Site_Info::ABILITY ] );
			$this->assertContains(
				Get_Site_Info::ABILITY,
				$selector->get_tool_names( Tool_Selector::SCOPE_SITE ),
				sprintf( 'An %s must be offered the site-info read.', $role )
			);
		}
	}

	/**
	 * Under the MCP Adapter experiment's exposure filter, the ability is listed for MCP.
	 *
	 * @since x.x.x
	 */
	public function test_listed_for_mcp(): void {
		wp_unregister_ability( Get_Site_Info::ABILITY );
		delete_option( Exposure_Overrides::OPTION_NAME );
		add_filter( 'wp_register_ability_args', array( Exposure_Overrides::class, 'filter_ability_args' ), 100, 2 );

		$this->register_ability();

		$meta = wp_get_ability( Get_Site_Info::ABILITY )->get_meta();

		$this->assertTrue( $meta['mcp']['public'] );
	}
}
