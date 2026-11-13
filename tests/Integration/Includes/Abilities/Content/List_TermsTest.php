<?php
/**
 * Integration tests for the ai/list-terms Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Content
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Content;

use WP_UnitTestCase;
use WordPress\AI\Abilities\Content\List_Terms;
use WordPress\AI\Abilities\Show_In_Abilities;

/**
 * List_Terms ability test case.
 *
 * The ability lists the categories and tags a site uses, with how many posts each
 * holds, so the assistant can see what has been covered. These tests pin what it
 * lists, how it orders, and what it refuses.
 *
 * @since x.x.x
 */
class List_TermsTest extends WP_UnitTestCase {

	/**
	 * The registered ability name.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const ABILITY = 'ai/list-terms';

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		( new Show_In_Abilities() )->register();

		global $wp_current_filter;

		if ( ! wp_has_ability_category( 'content' ) ) {
			$wp_current_filter[] = 'wp_abilities_api_categories_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
			try {
				wp_register_ability_category(
					'content',
					array(
						'label'       => 'Content',
						'description' => 'Content.',
					)
				);
			} finally {
				array_pop( $wp_current_filter );
			}
		}

		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new List_Terms() )->register();
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
		if ( wp_has_ability( self::ABILITY ) ) {
			wp_unregister_ability( self::ABILITY );
		}

		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Runs the ability as an editor.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $input The ability input.
	 * @return mixed The ability result.
	 */
	private function list_terms( array $input ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		return wp_get_ability( self::ABILITY )->execute( $input );
	}

	/**
	 * Creates a category holding the given number of published posts.
	 *
	 * @since x.x.x
	 *
	 * @param string $name  The category name.
	 * @param int    $posts How many posts to put in it.
	 * @return int The term ID.
	 */
	private function category_with_posts( string $name, int $posts ): int {
		$term_id = self::factory()->category->create( array( 'name' => $name ) );

		for ( $i = 0; $i < $posts; $i++ ) {
			$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
			wp_set_post_categories( $post_id, array( $term_id ) );
		}

		return $term_id;
	}

	/**
	 * Returns the rows of one taxonomy from a result, in order.
	 *
	 * @since x.x.x
	 *
	 * @param mixed  $result   The ability result.
	 * @param string $taxonomy The taxonomy.
	 * @return list<array<string, mixed>> The rows.
	 */
	private function rows( $result, string $taxonomy ): array {
		$this->assertIsArray( $result );

		return array_values(
			array_filter(
				$result['terms'],
				static function ( array $row ) use ( $taxonomy ): bool {
					return $taxonomy === $row['taxonomy'];
				}
			)
		);
	}

	/**
	 * The ability reads only, and says it never reaches outside the site.
	 *
	 * @since x.x.x
	 */
	public function test_registers_as_a_read_only_local_ability(): void {
		$ability = wp_get_ability( self::ABILITY );

		$this->assertNotNull( $ability );

		$annotations = $ability->get_meta()['annotations'];

		$this->assertTrue( $annotations['readonly'] );
		$this->assertFalse( $annotations['destructive'] );
		$this->assertFalse( $annotations['open_world'] );
	}

	/**
	 * Sorted by count, categories come back with the fullest first.
	 *
	 * @since x.x.x
	 */
	public function test_orders_categories_by_count(): void {
		$this->category_with_posts( 'Two posts', 2 );
		$this->category_with_posts( 'Five posts', 5 );
		$this->category_with_posts( 'One post', 1 );

		$rows = $this->rows(
			$this->list_terms(
				array(
					'taxonomy' => array( 'category' ),
					'orderby'  => 'count',
				)
			),
			'category'
		);

		$this->assertSame( array( 'Five posts', 'Two posts', 'One post' ), array_column( $rows, 'name' ) );
		$this->assertSame( array( 5, 2, 1 ), array_column( $rows, 'count' ) );
		$this->assertSame( 'five-posts', $rows[0]['slug'] );
	}

	/**
	 * Terms that hold no posts are left out unless asked for.
	 *
	 * @since x.x.x
	 */
	public function test_leaves_out_empty_terms_by_default(): void {
		$this->category_with_posts( 'Used', 1 );
		self::factory()->category->create( array( 'name' => 'Unused' ) );

		$names = array_column(
			$this->rows( $this->list_terms( array( 'taxonomy' => array( 'category' ) ) ), 'category' ),
			'name'
		);

		$this->assertContains( 'Used', $names );
		$this->assertNotContains( 'Unused', $names );

		$all = array_column(
			$this->rows(
				$this->list_terms(
					array(
						'taxonomy'   => array( 'category' ),
						'hide_empty' => false,
					)
				),
				'category'
			),
			'name'
		);

		$this->assertContains( 'Unused', $all );
	}

	/**
	 * With no taxonomy named, categories and tags are both listed.
	 *
	 * @since x.x.x
	 */
	public function test_lists_categories_and_tags_by_default(): void {
		$this->category_with_posts( 'News', 1 );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		wp_set_post_tags( $post_id, array( 'roadmap' ) );

		$result = $this->list_terms( array() );

		$this->assertContains( 'News', array_column( $this->rows( $result, 'category' ), 'name' ) );
		$this->assertContains( 'roadmap', array_column( $this->rows( $result, 'post_tag' ), 'name' ) );
	}

	/**
	 * An unknown or private taxonomy is a validation error.
	 *
	 * @since x.x.x
	 */
	public function test_refuses_an_unknown_or_private_taxonomy(): void {
		foreach ( array( 'no_such_taxonomy', 'nav_menu' ) as $taxonomy ) {
			$result = $this->list_terms( array( 'taxonomy' => array( $taxonomy ) ) );

			$this->assertWPError( $result, $taxonomy . ' must be refused.' );
			$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		}
	}

	/**
	 * Called without schema validation, an unknown taxonomy is still refused.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_refuses_an_unknown_taxonomy_without_schema_validation(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$result = ( new List_Terms() )->execute_list_terms( array( 'taxonomy' => array( 'nav_menu' ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ai_list_terms_invalid_taxonomy', $result->get_error_code() );
	}

	/**
	 * The list is capped, and says how many terms there are in all.
	 *
	 * @since x.x.x
	 */
	public function test_caps_the_list_and_reports_the_total(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->category_with_posts( 'Category ' . $i, 1 );
		}

		$result = $this->list_terms(
			array(
				'taxonomy' => array( 'category' ),
				'per_page' => 3,
			)
		);

		$this->assertCount( 3, $this->rows( $result, 'category' ) );
		$this->assertGreaterThanOrEqual( 5, $result['total'] );

		$schema = wp_get_ability( self::ABILITY )->get_input_schema();
		$this->assertSame( 100, $schema['properties']['per_page']['maximum'] );
	}

	/**
	 * A logged-out request is refused.
	 *
	 * @since x.x.x
	 */
	public function test_logged_out_request_is_refused(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( self::ABILITY )->execute( array() );

		$this->assertWPError( $result );
	}
}
