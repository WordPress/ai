<?php
/**
 * Shared base for the user write abilities integration tests.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Users
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Users;

use WP_UnitTestCase;
use WordPress\AI\Abilities\Users\Users;

/**
 * Base test case for the user write abilities.
 *
 * Provides the shared users, the ability registration, and the assertion helpers used by
 * the test cases of the user write abilities.
 *
 * @since x.x.x
 */
abstract class Users_Ability_TestCase extends WP_UnitTestCase {

	/**
	 * The ability names registered by the Users class.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	protected const USER_ABILITIES = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is used as an array const.
		'core/users-query',
		'core/read-users',
		'core/user-create',
	);

	/**
	 * A role that can create users but not promote them.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	protected const USER_CREATOR_ROLE = 'wpai_user_creator';

	/**
	 * Shared user IDs keyed by role or fixture name.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, int>
	 */
	protected static array $user_ids = array();

	/**
	 * A second site on multisite.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	protected static int $site = 0;

	/**
	 * Creates the shared users, and a second site on multisite.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_UnitTest_Factory $factory The unit test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		add_role( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.custom_role_add_role -- Registering a throwaway role in an integration test.
			self::USER_CREATOR_ROLE,
			'User Creator',
			array(
				'read'         => true,
				'create_users' => true,
			)
		);

		self::$user_ids = array(
			'superadmin'    => $factory->user->create(
				array(
					'role'       => 'administrator',
					'user_login' => 'superadmin',
				)
			),
			'administrator' => $factory->user->create( array( 'role' => 'administrator' ) ),
			'editor'        => $factory->user->create(
				array(
					'role'       => 'editor',
					'user_email' => 'editor@example.com',
				)
			),
			'author'        => $factory->user->create( array( 'role' => 'author' ) ),
			'contributor'   => $factory->user->create( array( 'role' => 'contributor' ) ),
			'subscriber'    => $factory->user->create(
				array(
					'role'         => 'subscriber',
					'display_name' => 'subscriber',
					'user_email'   => 'subscriber@example.com',
				)
			),
			'user_creator'  => $factory->user->create( array( 'role' => self::USER_CREATOR_ROLE ) ),
		);

		if ( ! is_multisite() ) {
			return;
		}

		self::$site = $factory->blog->create(
			array(
				'domain' => 'rest.wordpress.org',
				'path'   => '/',
			)
		);
		update_site_option( 'site_admins', array( 'superadmin' ) );
	}

	/**
	 * Removes the second site and the custom role.
	 *
	 * @since x.x.x
	 */
	public static function wpTearDownAfterClass(): void {
		if ( is_multisite() ) {
			wp_delete_site( self::$site );
		}

		remove_role( self::USER_CREATOR_ROLE );
	}

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->ensure_ability_category( 'user' );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		foreach ( self::USER_ABILITIES as $ability_name ) {
			if ( ! wp_has_ability( $ability_name ) ) {
				continue;
			}

			wp_unregister_ability( $ability_name );
		}

		parent::tearDown();
	}

	/**
	 * Ensures an ability category exists for an ability to attach to.
	 *
	 * @since x.x.x
	 *
	 * @param string $slug The ability category slug.
	 */
	protected function ensure_ability_category( string $slug ): void {
		if ( wp_has_ability_category( $slug ) ) {
			return;
		}

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			wp_register_ability_category(
				$slug,
				array(
					'label'       => ucfirst( $slug ),
					'description' => ucfirst( $slug ) . '.',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Registers the plugin's user abilities inside a faked init action.
	 *
	 * @since x.x.x
	 */
	protected function register_ability(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Users() )->register();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Logs in as a shared user and returns the user ID.
	 *
	 * @since x.x.x
	 *
	 * @param string $name The fixture name, such as a role.
	 * @return int The user ID.
	 */
	protected function login_as( string $name ): int {
		wp_set_current_user( self::$user_ids[ $name ] );

		return self::$user_ids[ $name ];
	}

	/**
	 * Logs in as the administrator, and makes them the only super admin on multisite.
	 *
	 * @since x.x.x
	 */
	protected function allow_user_to_manage_multisite(): void {
		wp_set_current_user( self::$user_ids['administrator'] );

		if ( ! is_multisite() ) {
			return;
		}

		update_site_option( 'site_admins', array( wp_get_current_user()->user_login ) );
	}

	/**
	 * Executes a registered ability through WP_Ability::execute().
	 *
	 * @since x.x.x
	 *
	 * @param string       $ability_name The ability name.
	 * @param array<mixed> $input        The ability input.
	 * @return mixed The ability result.
	 */
	protected function execute_ability( string $ability_name, array $input ) {
		$ability = wp_get_ability( $ability_name );
		$this->assertNotNull( $ability, sprintf( 'The %s ability should be registered.', $ability_name ) );

		return $ability->execute( $input );
	}

	/**
	 * Asserts that a result is a WP_Error with the given code and, optionally, status.
	 *
	 * @since x.x.x
	 *
	 * @param mixed    $result  The ability result.
	 * @param string   $code    The expected error code.
	 * @param string   $message The assertion message.
	 * @param int|null $status  Optional. The expected status. Default null.
	 */
	protected function assertAbilityError( $result, string $code, string $message, ?int $status = null ): void {
		$this->assertWPError( $result, $message );
		$this->assertSame( $code, $result->get_error_code(), $message );

		if ( null === $status ) {
			return;
		}

		$this->assertSame( $status, $result->get_error_data()['status'] ?? null, $message );
	}

	/**
	 * Asserts that a result is the generic permission denial.
	 *
	 * @since x.x.x
	 *
	 * @param mixed  $result  The ability result.
	 * @param string $message The assertion message.
	 */
	protected function assertAbilityDenied( $result, string $message ): void {
		$this->assertAbilityError( $result, 'ability_invalid_permissions', $message );
	}

	/**
	 * Asserts that a result is an input validation failure.
	 *
	 * @since x.x.x
	 *
	 * @param mixed  $result  The ability result.
	 * @param string $message The assertion message.
	 */
	protected function assertAbilityInvalidInput( $result, string $message ): void {
		$this->assertAbilityError( $result, 'ability_invalid_input', $message );
	}

	/**
	 * Asserts that a written user is returned with every field of the edit context.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $data The ability result.
	 */
	protected function check_user_data( $data ): void {
		$this->assertIsArray( $data, 'The write should return the user.' );

		$user = get_userdata( $data['id'] );
		$this->assertInstanceOf( \WP_User::class, $user, 'The returned user should exist.' );

		$this->assertSame( $user->ID, $data['id'] );
		$this->assertSame( $user->display_name, $data['name'] );
		$this->assertSame( $user->user_url, $data['url'] );
		$this->assertSame( $user->description, $data['description'] );
		$this->assertSame( get_author_posts_url( $user->ID ), $data['link'] );
		$this->assertArrayHasKey( 'avatar_urls', $data );
		$this->assertSame( $user->user_nicename, $data['slug'] );
		$this->assertSame( $user->first_name, $data['first_name'] );
		$this->assertSame( $user->last_name, $data['last_name'] );
		$this->assertSame( $user->nickname, $data['nickname'] );
		$this->assertSame( $user->user_email, $data['email'] );
		$this->assertSame( gmdate( 'c', strtotime( $user->user_registered ) ), $data['registered_date'] );
		$this->assertSame( $user->user_login, $data['username'] );
		$this->assertSame( array_values( $user->roles ), $data['roles'] );
		$this->assertSame( get_user_locale( $user ), $data['locale'] );
		$this->assertArrayNotHasKey( 'password', $data );
	}

	/**
	 * Returns every field of a user, for writes that should return them all.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> The user field names.
	 */
	protected function all_fields(): array {
		return array( 'id', 'name', 'description', 'url', 'link', 'slug', 'avatar_urls', 'username', 'email', 'first_name', 'last_name', 'nickname', 'locale', 'registered_date', 'roles' );
	}
}
