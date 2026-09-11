<?php
/**
 * Shared base for the content abilities integration tests.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Content
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Content;

use WP_UnitTestCase;
use WordPress\AI\Abilities\Content\Content;
use WordPress\AI\Abilities\Show_In_Abilities;

/**
 * Base test case for the content abilities.
 *
 * Provides the shared users, the ability registration and category set-up, and the
 * assertion helpers used by the query, create, update, and delete test cases.
 *
 * @since x.x.x
 */
abstract class Content_Ability_TestCase extends WP_UnitTestCase {

	/**
	 * The ability names registered by the Content class.
	 *
	 * @since x.x.x
	 *
	 * @var string[]
	 */
	protected const CONTENT_ABILITIES = array(
		'core/content-query',
		'core/read-content',
		'core/content-create',
		'core/content-update',
		'core/content-delete',
	);

	/**
	 * Shared user IDs keyed by role or fixture name.
	 *
	 * @since 1.2.0
	 *
	 * @var array<string, int>
	 */
	protected static $user_ids = array();

	/**
	 * Creates the shared users for the content ability tests.
	 *
	 * @since 1.2.0
	 *
	 * @param \WP_UnitTest_Factory $factory The unit test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$user_ids = array(
			'administrator'    => $factory->user->create( array( 'role' => 'administrator' ) ),
			'editor'           => $factory->user->create( array( 'role' => 'editor' ) ),
			'subscriber'       => $factory->user->create( array( 'role' => 'subscriber' ) ),
			'contributor'      => $factory->user->create( array( 'role' => 'contributor' ) ),
			'author'           => $factory->user->create( array( 'role' => 'author' ) ),
			'author_secondary' => $factory->user->create( array( 'role' => 'author' ) ),
		);
	}

	/**
	 * Set up test case.
	 *
	 * @since 1.2.0
	 */
	public function setUp(): void {
		parent::setUp();

		// Mark the curated core post types (post, page) as exposed to abilities.
		( new Show_In_Abilities() )->register();

		$this->ensure_ability_category( 'content' );

		/*
		 * The plugin registers its other abilities on the same abilities-init hook, so
		 * booting the registry here also registers `core/read-settings` (the `site`
		 * category) and `core/users-query` (the `user` category). Make sure those
		 * categories exist too; otherwise their registration emits an "incorrect usage"
		 * notice that fails these tests.
		 */
		$this->ensure_ability_category( 'site' );
		$this->ensure_ability_category( 'user' );
	}

	/**
	 * Tear down test case.
	 *
	 * @since 1.2.0
	 */
	public function tearDown(): void {
		foreach ( self::CONTENT_ABILITIES as $ability_name ) {
			if ( ! wp_has_ability( $ability_name ) ) {
				continue;
			}

			wp_unregister_ability( $ability_name );
		}

		// Restore the curated post types to their unmarked state to avoid leaking into other tests.
		foreach ( array( 'post', 'page' ) as $post_type ) {
			$object = get_post_type_object( $post_type );
			if ( ! $object ) {
				continue;
			}

			unset( $object->show_in_abilities );
		}

		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Ensures an ability category exists for an ability to attach to.
	 *
	 * @since 1.2.0
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
	 * Registers the plugin's content abilities inside a faked init action.
	 *
	 * @since 1.2.0
	 */
	protected function register_ability(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Content() )->register();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Logs in as a user with the given role and returns the user ID.
	 *
	 * @param string $role The role to log in as.
	 * @return int The user ID.
	 */
	protected function login_as( string $role ): int {
		$user_id = self::$user_ids[ $role ] ?? self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );
		return $user_id;
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
	 * Asserts that a result is a WP_Error with the given code.
	 *
	 * @since x.x.x
	 *
	 * @param mixed  $result  The ability result.
	 * @param string $code    The expected error code.
	 * @param string $message The assertion message.
	 */
	protected function assertAbilityError( $result, string $code, string $message ): void {
		$this->assertWPError( $result, $message );
		$this->assertSame( $code, $result->get_error_code(), $message );
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
	 * Removes a capability from the current user and flushes the capability cache.
	 *
	 * @since x.x.x
	 *
	 * @param string $capability The capability to remove.
	 */
	protected function revoke_current_user_capability( string $capability ): void {
		$user = wp_get_current_user();
		$user->add_cap( $capability, false );
		// Flush capabilities, https://core.trac.wordpress.org/ticket/28374
		$user->get_role_caps();
		$user->update_user_level_from_caps();
	}

	/**
	 * Provides date inputs for the create and update tests.
	 *
	 * Each case sets the site timezone to America/New_York and expects the stored local
	 * and GMT dates, whether the date is given as local, as GMT, or with an offset.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: string, 1: array<string, string>, 2: array<string, string>}> Status, inputs, and expected stored dates.
	 */
	public function data_post_dates(): array {
		$all_statuses = array( 'draft', 'publish', 'future', 'pending', 'private' );

		$cases_short = array(
			'set date without timezone'     => array(
				'statuses' => $all_statuses,
				'params'   => array(
					'timezone_string' => 'America/New_York',
					'date'            => '2016-12-12T14:00:00',
				),
				'results'  => array(
					'date'     => '2016-12-12 14:00:00',
					'date_gmt' => '2016-12-12 19:00:00',
				),
			),
			'set date_gmt without timezone' => array(
				'statuses' => $all_statuses,
				'params'   => array(
					'timezone_string' => 'America/New_York',
					'date_gmt'        => '2016-12-12T19:00:00',
				),
				'results'  => array(
					'date'     => '2016-12-12 14:00:00',
					'date_gmt' => '2016-12-12 19:00:00',
				),
			),
			'set date with timezone'        => array(
				'statuses' => array( 'draft', 'publish' ),
				'params'   => array(
					'timezone_string' => 'America/New_York',
					'date'            => '2016-12-12T18:00:00-01:00',
				),
				'results'  => array(
					'date'     => '2016-12-12 14:00:00',
					'date_gmt' => '2016-12-12 19:00:00',
				),
			),
			'set date_gmt with timezone'    => array(
				'statuses' => array( 'draft', 'publish' ),
				'params'   => array(
					'timezone_string' => 'America/New_York',
					'date_gmt'        => '2016-12-12T18:00:00-01:00',
				),
				'results'  => array(
					'date'     => '2016-12-12 14:00:00',
					'date_gmt' => '2016-12-12 19:00:00',
				),
			),
		);

		$cases = array();
		foreach ( $cases_short as $description => $case ) {
			foreach ( $case['statuses'] as $status ) {
				$cases[ $description . ', status=' . $status ] = array( $status, $case['params'], $case['results'] );
			}
		}

		return $cases;
	}

	/**
	 * Returns roles that can read public posts but cannot edit another user's post.
	 *
	 * @return array<string, array{role: string}> Role test cases.
	 */
	public function data_roles_without_edit_access_to_other_users_posts(): array {
		return array(
			'subscriber'  => array(
				'role' => 'subscriber',
			),
			'contributor' => array(
				'role' => 'contributor',
			),
			'author'      => array(
				'role' => 'author',
			),
		);
	}
}
