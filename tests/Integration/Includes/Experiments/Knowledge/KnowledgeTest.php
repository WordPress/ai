<?php
/**
 * Integration tests for the Knowledge experiment.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Knowledge
 */

namespace WordPress\AI\Tests\Integration\Experiments\Knowledge;

use WP_REST_Request;
use WP_UnitTestCase;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Experiments\Knowledge\Knowledge;
use WordPress\AI\Experiments\Knowledge\Knowledge_Post_Type;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Knowledge experiment test case.
 *
 * @since x.x.x
 */
class KnowledgeTest extends WP_UnitTestCase {

	/**
	 * The experiment instance under test.
	 *
	 * @var \WordPress\AI\Experiments\Knowledge\Knowledge
	 */
	private Knowledge $experiment;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_knowledge_enabled', true );

		$registry = new Registry();
		( new Loader( $registry ) )->init();

		$experiment = $registry->get_feature( 'knowledge' );
		$this->assertInstanceOf( Knowledge::class, $experiment );

		$this->experiment = $experiment;

		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_knowledge_enabled' );
		remove_all_filters( 'wp_guideline_scopes' );
		remove_all_filters( 'wp_guideline_max_length' );

		if ( post_type_exists( Knowledge_Post_Type::POST_TYPE ) ) {
			unregister_post_type( Knowledge_Post_Type::POST_TYPE );
		}
		if ( taxonomy_exists( Knowledge_Post_Type::TAXONOMY ) ) {
			unregister_taxonomy( Knowledge_Post_Type::TAXONOMY );
		}

		parent::tearDown();
	}

	/**
	 * Tests that the experiment reports the expected metadata.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_metadata(): void {
		$this->assertSame( 'knowledge', Knowledge::get_id() );
		$this->assertSame( Experiment_Category::ADMIN, $this->experiment->get_category() );
		$this->assertSame( 'none', $this->experiment->get_capability() );
	}

	/**
	 * Tests that enabling the experiment registers the post type and taxonomy.
	 *
	 * @since x.x.x
	 */
	public function test_register_adds_post_type_and_taxonomy(): void {
		$this->assertTrue( post_type_exists( Knowledge_Post_Type::POST_TYPE ) );
		$this->assertTrue( taxonomy_exists( Knowledge_Post_Type::TAXONOMY ) );
		$this->assertTrue( $this->experiment->owns_knowledge() );
	}

	/**
	 * Tests that the shared `wp_*` contract is available once the experiment runs.
	 *
	 * @since x.x.x
	 */
	public function test_register_declares_shared_functions(): void {
		$this->assertTrue( function_exists( 'wp_knowledge_types' ) );
		$this->assertTrue( function_exists( 'wp_guideline_scopes' ) );
		$this->assertTrue( function_exists( 'wp_guideline_max_length' ) );
		$this->assertTrue( function_exists( 'wp_guideline_scope_from_slug' ) );

		$this->assertArrayHasKey( 'guideline', wp_knowledge_types() );
		$this->assertArrayHasKey( 'site', wp_guideline_scopes() );
		$this->assertSame( 5000, wp_guideline_max_length() );
	}

	/**
	 * Tests that a second registration stands down instead of registering twice.
	 *
	 * This is what happens when another plugin, such as Gutenberg, declared the
	 * same post type first.
	 *
	 * @since x.x.x
	 */
	public function test_register_stands_down_when_post_type_already_exists(): void {
		$this->assertFalse(
			Knowledge_Post_Type::register(),
			'Should report that it did not register the post type'
		);
	}

	/**
	 * Tests that guideline slugs resolve to the scope that owns them.
	 *
	 * @since x.x.x
	 */
	public function test_guideline_scope_from_slug(): void {
		$this->assertSame( 'copy', wp_guideline_scope_from_slug( 'guideline-copy' ) );
		$this->assertSame( 'blocks', wp_guideline_scope_from_slug( 'guideline-block-core_paragraph' ) );
		$this->assertNull( wp_guideline_scope_from_slug( 'guideline-block-' ) );
		$this->assertNull( wp_guideline_scope_from_slug( 'guideline-unknown' ) );
		$this->assertNull( wp_guideline_scope_from_slug( 'something-else' ) );
	}

	/**
	 * Tests that a scope key wins over the per-block namespace.
	 *
	 * @since x.x.x
	 */
	public function test_guideline_scope_from_slug_prefers_registered_scope(): void {
		add_filter(
			'wp_guideline_scopes',
			static function ( array $scopes ): array {
				$scopes['block-foo'] = array(
					'title'       => 'Block foo',
					'description' => '',
					'order'       => 60,
				);
				return $scopes;
			}
		);

		$this->assertSame( 'block-foo', wp_guideline_scope_from_slug( 'guideline-block-foo' ) );
	}

	/**
	 * Tests that the REST insert guard sanitizes and truncates guideline content.
	 *
	 * @since x.x.x
	 */
	public function test_guard_sanitizes_and_truncates_guideline_content(): void {
		add_filter(
			'wp_guideline_max_length',
			static function (): int {
				return 20;
			}
		);

		$prepared               = new \stdClass();
		$prepared->post_name    = 'guideline-copy';
		$prepared->post_content = '<b>' . str_repeat( 'a', 50 ) . '</b>';

		$result = wp_knowledge_guard_guideline_row( $prepared );

		$this->assertSame( 20, mb_strlen( $result->post_content, 'UTF-8' ) );
		$this->assertStringNotContainsString( '<b>', $result->post_content );
		$this->assertSame( 'Copy', $result->post_title );
	}

	/**
	 * Tests that a row outside the guideline namespace is left untouched.
	 *
	 * @since x.x.x
	 */
	public function test_guard_leaves_other_rows_untouched(): void {
		$prepared               = new \stdClass();
		$prepared->post_name    = 'my-note';
		$prepared->post_content = '<b>Keep me</b>';
		$prepared->post_title   = 'My note';

		$result = wp_knowledge_guard_guideline_row( $prepared );

		$this->assertSame( '<b>Keep me</b>', $result->post_content );
		$this->assertSame( 'My note', $result->post_title );
	}

	/**
	 * Tests that saving a guideline row assigns the `guideline` type term.
	 *
	 * @since x.x.x
	 */
	public function test_saving_a_guideline_row_assigns_the_guideline_term(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => Knowledge_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_name'   => 'guideline-site',
			)
		);

		$terms = wp_get_object_terms( $post_id, Knowledge_Post_Type::TAXONOMY, array( 'fields' => 'slugs' ) );

		$this->assertIsArray( $terms );
		$this->assertContains( Knowledge_Post_Type::TERM_GUIDELINE, $terms );
	}

	/**
	 * Tests that the scopes registry route is registered and readable by an admin.
	 *
	 * @since x.x.x
	 */
	public function test_guideline_scopes_route_returns_registry(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/knowledge/guideline-scopes' ) );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );

		$slugs = wp_list_pluck( $data, 'slug' );
		$this->assertContains( 'site', $slugs );
		$this->assertContains( 'blocks', $slugs );
	}

	/**
	 * Tests that the scopes registry route is closed to logged-out visitors.
	 *
	 * @since x.x.x
	 */
	public function test_guideline_scopes_route_denies_anonymous_requests(): void {
		wp_set_current_user( 0 );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/knowledge/guideline-scopes' ) );

		$this->assertSame( 401, $response->get_status() );
	}
}
