<?php
/**
 * Shared base for the core/post-types-query ability integration tests.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Post_Types
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Post_Types;

use WP_Post_Type;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Post_Types\Post_Types;
use WordPress\AI\Abilities\Show_In_Abilities;

/**
 * Base test case for the core/post-types-query ability.
 *
 * Provides the shared users, the ability registration and category set-up, and the
 * assertion helpers used by the ported REST controller tests and the ability tests.
 *
 * @since x.x.x
 */
abstract class Post_Types_Ability_TestCase extends WP_UnitTestCase {

	/**
	 * Every field a post type can return, in the order the ability returns them.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	protected const ALL_FIELDS = array( 'capabilities', 'description', 'hierarchical', 'has_archive', 'visibility', 'viewable', 'labels', 'name', 'slug', 'icon', 'supports', 'taxonomies', 'template', 'template_lock' ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is used as an array const.

	/**
	 * Shared user IDs keyed by role.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, int>
	 */
	protected static array $user_ids = array();

	/**
	 * Post types registered for one test.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	private array $registered_post_types = array();

	/**
	 * Taxonomies registered for one test.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	private array $registered_taxonomies = array();

	/**
	 * Creates the shared users for the post types ability tests.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_UnitTest_Factory $factory The unit test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$user_ids = array(
			'administrator' => $factory->user->create( array( 'role' => 'administrator' ) ),
			'editor'        => $factory->user->create( array( 'role' => 'editor' ) ),
			'author'        => $factory->user->create( array( 'role' => 'author' ) ),
			'contributor'   => $factory->user->create( array( 'role' => 'contributor' ) ),
			'subscriber'    => $factory->user->create( array( 'role' => 'subscriber' ) ),
		);
	}

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		// Mark the curated core post types (post, page) as exposed to abilities.
		( new Show_In_Abilities() )->register();

		// Register the ability's category through its own fallback.
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Post_Types() )->register_category();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		foreach ( $this->registered_taxonomies as $taxonomy ) {
			unregister_taxonomy( $taxonomy );
		}

		foreach ( $this->registered_post_types as $post_type ) {
			unregister_post_type( $post_type );
		}

		$this->registered_taxonomies = array();
		$this->registered_post_types = array();

		if ( wp_has_ability( 'core/post-types-query' ) ) {
			wp_unregister_ability( 'core/post-types-query' );
		}

		// Restore the post types Show_In_Abilities marks to their unmarked state to avoid leaking into other tests.
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
	 * Registers the plugin's core/post-types-query ability inside a faked init action.
	 *
	 * Registering again replaces the ability, so a test that registers a post type calls
	 * this afterwards to add the post type to the input schema.
	 *
	 * @since x.x.x
	 */
	protected function register_ability(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Post_Types() )->register();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Registers a post type for one test, unregistered again in tearDown().
	 *
	 * @since x.x.x
	 *
	 * @param string               $name The post type name.
	 * @param array<string, mixed> $args The post type registration arguments.
	 */
	protected function register_test_post_type( string $name, array $args = array() ): void {
		register_post_type( $name, $args ); // phpcs:ignore WordPress.NamingConventions.ValidPostTypeSlug.NotStringLiteral -- The slug is passed by each test.

		$this->registered_post_types[] = $name;
	}

	/**
	 * Registers a taxonomy for one test, unregistered again in tearDown().
	 *
	 * @since x.x.x
	 *
	 * @param string               $name        The taxonomy name.
	 * @param string|list<string>  $object_type The object types the taxonomy applies to.
	 * @param array<string, mixed> $args        The taxonomy registration arguments.
	 */
	protected function register_test_taxonomy( string $name, $object_type, array $args = array() ): void {
		register_taxonomy( $name, $object_type, $args );

		$this->registered_taxonomies[] = $name;
	}

	/**
	 * Logs in as a user with the given role and returns the user ID.
	 *
	 * @since x.x.x
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
	 * Runs the registered core/post-types-query ability through WP_Ability::execute().
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The ability input, or null for none.
	 * @return mixed The ability result.
	 */
	protected function query_post_types( $input = array() ) {
		$ability = wp_get_ability( 'core/post-types-query' );
		$this->assertNotNull( $ability, 'The core/post-types-query ability should be registered.' );

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
	 * Asserts that returned post type data matches a post type in a context.
	 *
	 * @since x.x.x
	 *
	 * @param string        $context       The context, `view` or `edit`.
	 * @param \WP_Post_Type $post_type_obj The post type.
	 * @param mixed         $data          The returned post type data.
	 */
	protected function check_post_type_obj( string $context, WP_Post_Type $post_type_obj, $data ): void {
		$this->assertIsArray( $data, 'The post type should be returned.' );
		$this->assertSame( $post_type_obj->label, $data['name'], 'The name should be the post type label.' );
		$this->assertSame( $post_type_obj->name, $data['slug'], 'The slug should be the post type name.' );
		$this->assertSame( $post_type_obj->description, $data['description'], 'The description should match.' );
		$this->assertSame( $post_type_obj->hierarchical, $data['hierarchical'], 'The hierarchical flag should match.' );
		$this->assertSame( $post_type_obj->has_archive, $data['has_archive'], 'The archive setting should match.' );
		$this->assertSame( $post_type_obj->template ?? array(), $data['template'], 'The template should match.' );
		$this->assertSame( ! empty( $post_type_obj->template_lock ) ? $post_type_obj->template_lock : false, $data['template_lock'], 'The template lock should match.' );

		if ( 'edit' === $context ) {
			$this->assertSame( $post_type_obj->cap, $data['capabilities'], 'The capabilities should match.' );
			$this->assertSame( $post_type_obj->labels, $data['labels'], 'The labels should match.' );
			if ( in_array( $post_type_obj->name, array( 'post', 'page' ), true ) ) {
				$viewable = true;
			} else {
				$viewable = is_post_type_viewable( $post_type_obj );
			}
			$this->assertSame( $viewable, $data['viewable'], 'The viewable flag should match.' );
			$visibility = array(
				'show_in_nav_menus' => (bool) $post_type_obj->show_in_nav_menus,
				'show_ui'           => (bool) $post_type_obj->show_ui,
			);
			$this->assertSame( $visibility, $data['visibility'], 'The visibility settings should match.' );
			$this->assertSame( get_all_post_type_supports( $post_type_obj->name ), $data['supports'], 'The supported features should match.' );
		} else {
			$this->assertArrayNotHasKey( 'capabilities', $data, 'The capabilities should need the edit context.' );
			$this->assertArrayNotHasKey( 'viewable', $data, 'The viewable flag should need the edit context.' );
			$this->assertArrayNotHasKey( 'labels', $data, 'The labels should need the edit context.' );
			$this->assertArrayNotHasKey( 'supports', $data, 'The supported features should need the edit context.' );
		}
	}
}
