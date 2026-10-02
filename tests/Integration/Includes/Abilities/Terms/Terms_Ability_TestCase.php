<?php
/**
 * Shared base for the terms abilities integration tests.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Terms
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Terms;

use WP_REST_Request;
use WP_REST_Response;
use WP_Term;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Show_In_Abilities;
use WordPress\AI\Abilities\Terms\Terms;

/**
 * Base test case for the terms abilities.
 *
 * Provides the shared users, the ability registration and category set-up, and the
 * assertion helpers used by the ported REST controller tests and the ability tests.
 *
 * @since x.x.x
 */
abstract class Terms_Ability_TestCase extends WP_UnitTestCase {

	/**
	 * Every field a term can return, in the order the ability returns them.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	protected const ALL_FIELDS = array( 'id', 'count', 'description', 'link', 'name', 'slug', 'taxonomy', 'parent' ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is used as an array const.

	/**
	 * Shared user IDs keyed by role.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, int>
	 */
	protected static array $user_ids = array();

	/**
	 * Taxonomies registered for one test.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	private array $registered_taxonomies = array();

	/**
	 * Creates the shared users for the terms ability tests.
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

		// Mark the curated core taxonomies (category, post_tag) as exposed to abilities.
		( new Show_In_Abilities() )->register();

		// Register the ability's category through its own fallback.
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Terms() )->register_category();
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

		$this->registered_taxonomies = array();

		foreach ( array( 'core/terms-query', 'core/term-create', 'core/term-update', 'core/term-delete' ) as $ability_name ) {
			if ( ! wp_has_ability( $ability_name ) ) {
				continue;
			}

			wp_unregister_ability( $ability_name );
		}

		// Restore the taxonomies and post types Show_In_Abilities marks to their unmarked state to avoid leaking into other tests.
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );
			if ( ! $object ) {
				continue;
			}

			unset( $object->show_in_abilities );
		}

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
	 * Registers the plugin's terms abilities inside a faked init action.
	 *
	 * Registering again replaces the abilities, so a test that registers a taxonomy calls
	 * this afterwards to add the taxonomy to the input schemas.
	 *
	 * @since x.x.x
	 */
	protected function register_ability(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Terms() )->register();
		} finally {
			array_pop( $wp_current_filter );
		}
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
	 * Registers the custom taxonomies of the write tests, unregistered again in tearDown().
	 *
	 * `wpai_genre` is exposed and hierarchical, `wpai_mood` is exposed and flat, and
	 * `wpai_secret` is shown in REST but not exposed to abilities. All three use the default
	 * term capabilities.
	 *
	 * @since x.x.x
	 */
	protected function register_write_test_taxonomies(): void {
		$this->register_test_taxonomy(
			'wpai_genre',
			'post',
			array(
				'hierarchical'      => true,
				'show_in_rest'      => true,
				'show_in_abilities' => true,
			)
		);
		$this->register_test_taxonomy(
			'wpai_mood',
			'post',
			array(
				'show_in_rest'      => true,
				'show_in_abilities' => true,
			)
		);
		$this->register_test_taxonomy(
			'wpai_secret',
			'post',
			array(
				'hierarchical' => true,
				'show_in_rest' => true,
			)
		);
	}

	/**
	 * Returns the curated taxonomies, for the tests both REST terms controllers have.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: string}> The taxonomy.
	 */
	public function data_core_taxonomies(): array {
		return array(
			'categories' => array( 'category' ),
			'tags'       => array( 'post_tag' ),
		);
	}

	/**
	 * Returns the roles from administrator to a logged-out visitor, with the term
	 * capabilities they have under the default taxonomy capabilities.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: string, 1: bool, 2: bool}> The role, or an empty string for a logged-out visitor, whether it can manage terms, and whether it can assign them.
	 */
	public function data_term_roles(): array {
		return array(
			'administrator' => array( 'administrator', true, true ),
			'editor'        => array( 'editor', true, true ),
			'author'        => array( 'author', false, true ),
			'contributor'   => array( 'contributor', false, true ),
			'subscriber'    => array( 'subscriber', false, false ),
			'logged out'    => array( '', false, false ),
		);
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
	 * Runs the registered core/terms-query ability through WP_Ability::execute().
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return mixed The ability result.
	 */
	protected function query_terms( array $input ) {
		$ability = wp_get_ability( 'core/terms-query' );
		$this->assertNotNull( $ability, 'The core/terms-query ability should be registered.' );

		return $ability->execute( $input );
	}

	/**
	 * Runs a registered ability through WP_Ability::execute().
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
	 * Runs an ability through the run endpoint, with the input in a JSON body for a POST
	 * request and in the query string otherwise.
	 *
	 * @since x.x.x
	 *
	 * @param string       $method       The request method.
	 * @param string       $ability_name The ability name.
	 * @param array<mixed> $input        The ability input.
	 * @return \WP_REST_Response The response.
	 */
	protected function run_ability( string $method, string $ability_name, array $input ): WP_REST_Response {
		$request = new WP_REST_Request( $method, "/wp-abilities/v1/abilities/{$ability_name}/run" );

		if ( 'POST' === $method ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( array( 'input' => $input ) ) );
		} else {
			$request->set_query_params( array( 'input' => $input ) );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Returns the arguments a REST route takes for a request method.
	 *
	 * @since x.x.x
	 *
	 * @param string $route  The route pattern.
	 * @param string $method The request method.
	 * @return array<string, array<string, mixed>> The arguments.
	 */
	protected function get_rest_route_args( string $route, string $method ): array {
		foreach ( rest_get_server()->get_routes()[ $route ] ?? array() as $handler ) {
			if ( ! empty( $handler['methods'][ $method ] ) ) {
				return $handler['args'];
			}
		}

		return array();
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
	 * Asserts that a collection result lists every term of a taxonomy, starting with the
	 * first term `get_terms()` returns.
	 *
	 * @since x.x.x
	 *
	 * @param mixed  $result   The ability result.
	 * @param string $taxonomy The taxonomy that was queried.
	 */
	protected function check_get_taxonomy_terms_response( $result, string $taxonomy ): void {
		$this->assertIsArray( $result, 'Querying the terms should succeed.' );

		$data  = $result['terms'];
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);
		$this->assertCount( count( $terms ), $data, 'Every term should be returned.' );
		$this->assertSame( $terms[0]->term_id, $data[0]['id'], 'The first term ID should match.' );
		$this->assertSame( $terms[0]->name, $data[0]['name'], 'The first term name should match.' );
		$this->assertSame( $terms[0]->slug, $data[0]['slug'], 'The first term slug should match.' );
		$this->assertSame( $terms[0]->taxonomy, $data[0]['taxonomy'], 'The first term taxonomy should match.' );
		$this->assertSame( $terms[0]->description, $data[0]['description'], 'The first term description should match.' );
		$this->assertSame( $terms[0]->count, $data[0]['count'], 'The first term count should match.' );
	}

	/**
	 * Asserts that returned term data matches a term.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Term $term The term.
	 * @param mixed    $data The returned term data.
	 */
	protected function check_taxonomy_term( WP_Term $term, $data ): void {
		$this->assertIsArray( $data, 'The term should be returned.' );
		$this->assertSame( $term->term_id, $data['id'], 'The term ID should match.' );
		$this->assertSame( $term->name, $data['name'], 'The term name should match.' );
		$this->assertSame( $term->slug, $data['slug'], 'The term slug should match.' );
		$this->assertSame( $term->description, $data['description'], 'The term description should match.' );
		$this->assertSame( get_term_link( $term ), $data['link'], 'The term link should match.' );
		$this->assertSame( $term->count, $data['count'], 'The term count should match.' );
		$taxonomy = get_taxonomy( $term->taxonomy );
		if ( $taxonomy->hierarchical ) {
			$this->assertSame( $term->parent, $data['parent'], 'The term parent should match.' );
		} else {
			$this->assertArrayNotHasKey( 'parent', $data, 'Terms of a non-hierarchical taxonomy should have no parent.' );
		}
	}
}
