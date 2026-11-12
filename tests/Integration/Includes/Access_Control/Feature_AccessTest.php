<?php
/**
 * Integration tests for Feature_Access.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Access_Control
 */

namespace WordPress\AI\Tests\Integration\Includes\Access_Control;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use WP_UnitTest_Factory;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI\Access_Control\Feature_Access;

/**
 * Tagged fixture ability.
 *
 * Extends Abstract_Ability so the `wp_ability_permission_result` polyfill
 * applies on WordPress versions that predate the core filter.
 *
 * @since x.x.x
 */
class Access_Fixture_Ability extends Abstract_Ability {

	/**
	 * Permission results keyed by ability name. Unlisted abilities are allowed.
	 *
	 * @since x.x.x
	 * @var array<string, bool>
	 */
	public static array $permissions = array();

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array( 'value' => array( 'type' => 'string' ) ),
			'required'   => array( 'value' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array( 'echo' => array( 'type' => 'string' ) ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function execute_callback( $input ) {
		return array( 'echo' => $input['value'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function permission_callback( $input ) {
		return self::$permissions[ $this->get_name() ] ?? true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function meta(): array {
		return array();
	}
}

/**
 * Feature_Access test case.
 *
 * @since x.x.x
 */
class Feature_AccessTest extends WP_UnitTestCase {

	private const FEATURE = 'access-test-feature';

	private static int $admin_id;
	private static int $author_id;
	private static int $other_author_id;

	/**
	 * Creates users once for the class.
	 *
	 * @param WP_UnitTest_Factory $factory The factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		self::$admin_id        = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$author_id       = $factory->user->create( array( 'role' => 'author' ) );
		self::$other_author_id = $factory->user->create( array( 'role' => 'author' ) );
	}

	/**
	 * Registers fixture abilities and resets the REST server.
	 */
	public function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resetting the REST server for the test.
		do_action( 'rest_api_init', $wp_rest_server );

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			$this->register_tagged_fixture( 'ai/access-gated', true );
			$this->register_tagged_fixture( 'ai/access-gated-denied', false );
			$this->register_ungated_fixture( 'ai/access-ungated' );
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Cleans up abilities, options and the REST server.
	 */
	public function tearDown(): void {
		foreach ( array( 'ai/access-gated', 'ai/access-ungated', 'ai/access-gated-denied' ) as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}

		delete_option( Feature_Access::enabled_option( self::FEATURE ) );
		delete_option( Feature_Access::roles_option( self::FEATURE ) );
		delete_option( Feature_Access::users_option( self::FEATURE ) );

		Access_Fixture_Ability::$permissions = array();

		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resetting the REST server after the test.

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Backwards compatibility: enabling access control without saving a role list changes nothing.
	 */
	public function test_enabled_without_saved_roles_keeps_todays_access(): void {
		update_option( Feature_Access::enabled_option( self::FEATURE ), true );

		$this->assertTrue( user_can( self::$author_id, Feature_Access::CAPABILITY, self::FEATURE ) );
		$this->assertTrue( user_can( 0, Feature_Access::CAPABILITY, self::FEATURE ), 'Logged-out visitors keep UI hooks as today.' );

		wp_set_current_user( self::$author_id );
		$this->assertTrue( wp_get_ability( 'ai/access-gated' )->check_permissions( array( 'value' => 'x' ) ) );
	}

	/**
	 * Backwards compatibility: with access control off, a saved restrictive list is ignored.
	 */
	public function test_saved_lists_ignored_while_access_control_disabled(): void {
		update_option( Feature_Access::roles_option( self::FEATURE ), array() );

		$this->assertTrue( user_can( self::$author_id, Feature_Access::CAPABILITY, self::FEATURE ) );
		$this->assertTrue( user_can( 0, Feature_Access::CAPABILITY, self::FEATURE ) );
	}

	public function test_listed_user_has_access_without_a_listed_role(): void {
		$this->restrict( array( 'administrator' ), array( self::$author_id ) );

		$this->assertTrue( user_can( self::$author_id, Feature_Access::CAPABILITY, self::FEATURE ) );
		$this->assertFalse( user_can( self::$other_author_id, Feature_Access::CAPABILITY, self::FEATURE ) );
	}

	public function test_empty_roles_and_users_deny_everyone(): void {
		$this->restrict( array(), array() );

		$this->assertFalse( user_can( self::$admin_id, Feature_Access::CAPABILITY, self::FEATURE ) );
		$this->assertFalse( user_can( 0, Feature_Access::CAPABILITY, self::FEATURE ) );
	}

	public function test_restricted_user_cannot_run_tagged_ability_directly(): void {
		$this->restrict( array( 'administrator' ), array() );

		wp_set_current_user( self::$author_id );
		$denied = wp_get_ability( 'ai/access-gated' )->check_permissions( array( 'value' => 'x' ) );
		$this->assertWPError( $denied );
		$this->assertSame( 'wpai_feature_access_denied', $denied->get_error_code() );

		// Core reports a WP_Error permission result from execute() as incorrect usage.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$this->assertWPError( wp_get_ability( 'ai/access-gated' )->execute( array( 'value' => 'x' ) ) );

		wp_set_current_user( self::$admin_id );
		$this->assertTrue( wp_get_ability( 'ai/access-gated' )->check_permissions( array( 'value' => 'x' ) ) );
	}

	public function test_rest_run_endpoint_denies_restricted_user(): void {
		$this->restrict( array( 'administrator' ), array() );
		wp_set_current_user( self::$author_id );

		$request = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/ai/access-gated/run' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'input' => array( 'value' => 'x' ) ) ) );
		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wpai_feature_access_denied', $response->get_data()['code'] );
	}

	public function test_untagged_ability_is_not_gated(): void {
		$this->restrict( array( 'administrator' ), array() );
		wp_set_current_user( self::$author_id );

		$this->assertTrue( wp_get_ability( 'ai/access-ungated' )->check_permissions( array( 'value' => 'x' ) ) );
	}

	public function test_access_never_grants_what_permission_callback_denies(): void {
		$this->restrict( array( 'administrator' ), array() );
		wp_set_current_user( self::$admin_id );

		$this->assertFalse( wp_get_ability( 'ai/access-gated-denied' )->check_permissions( array( 'value' => 'x' ) ) );
	}

	/**
	 * Enables access control for the fixture feature with the given lists.
	 *
	 * @param list<string> $roles Allowed roles.
	 * @param list<int>    $users Allowed user IDs.
	 */
	private function restrict( array $roles, array $users ): void {
		update_option( Feature_Access::enabled_option( self::FEATURE ), true );
		update_option( Feature_Access::roles_option( self::FEATURE ), $roles );
		update_option( Feature_Access::users_option( self::FEATURE ), $users );
	}

	/**
	 * Registers one fixture ability tagged with the fixture feature.
	 *
	 * Uses an Abstract_Ability subclass so the permission filter polyfill
	 * applies on WordPress 7.0.x.
	 *
	 * @param string $name       Ability name.
	 * @param bool   $permission Result of the ability's own permission callback.
	 */
	private function register_tagged_fixture( string $name, bool $permission ): void {
		Access_Fixture_Ability::$permissions[ $name ] = $permission;

		wp_register_ability(
			$name,
			array(
				'label'         => $name,
				'description'   => 'Access control fixture.',
				'ability_class' => Access_Fixture_Ability::class,
				'meta'          => array(
					'show_in_rest'                   => true,
					Feature_Access::ABILITY_META_KEY => self::FEATURE,
				),
			)
		);
	}

	/**
	 * Registers one untagged fixture ability.
	 *
	 * @param string $name Ability name.
	 */
	private function register_ungated_fixture( string $name ): void {
		wp_register_ability(
			$name,
			array(
				'label'               => $name,
				'description'         => 'Access control fixture.',
				'category'            => WPAI_DEFAULT_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'value' => array( 'type' => 'string' ) ),
					'required'   => array( 'value' ),
				),
				'execute_callback'    => static fn( array $input ): array => array( 'echo' => $input['value'] ),
				'permission_callback' => static fn(): bool => true,
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}
}
