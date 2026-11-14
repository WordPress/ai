<?php
/**
 * Integration tests for the core/templates-query Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Templates
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Templates;

use WP_Ability;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Templates\Templates;

/**
 * Templates ability test case.
 *
 * Runs against two fixture themes in `tests/data/themes`: a block theme that ships
 * `index.html` and `404.html`, and a classic theme.
 *
 * @since x.x.x
 */
class TemplatesTest extends WP_UnitTestCase {

	/**
	 * The ability name.
	 *
	 * @var string
	 */
	private const ABILITY = 'core/templates-query';

	/**
	 * The fixture block theme.
	 *
	 * @var string
	 */
	private const BLOCK_THEME = 'ai-test-block-theme';

	/**
	 * The fixture classic theme.
	 *
	 * @var string
	 */
	private const CLASSIC_THEME = 'ai-test-classic-theme';

	/**
	 * The theme directories before the fixture directory was registered.
	 *
	 * @var string[]
	 */
	private $original_theme_directories = array();

	/**
	 * The active theme before the test switched it.
	 *
	 * @var string
	 */
	private $original_stylesheet = '';

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_theme_directories = $GLOBALS['wp_theme_directories'];
		$this->original_stylesheet        = get_stylesheet();
		register_theme_directory( dirname( __DIR__, 4 ) . '/data/themes' );
		wp_clean_themes_cache();

		$this->register_ability();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
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

		switch_theme( $this->original_stylesheet );
		$GLOBALS['wp_theme_directories'] = $this->original_theme_directories; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the WordPress test global.
		wp_clean_themes_cache();

		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resetting the REST server after the test.

		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Registers the plugin's core/templates-query ability inside a faked init action.
	 *
	 * @since x.x.x
	 */
	private function register_ability(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Templates() )->register();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Returns the registered ability.
	 *
	 * @since x.x.x
	 *
	 * @return \WP_Ability The ability.
	 */
	private function get_ability(): WP_Ability {
		$ability = wp_get_ability( self::ABILITY );
		$this->assertInstanceOf( WP_Ability::class, $ability );

		return $ability;
	}

	/**
	 * Runs the ability and returns its result, failing the test on an error.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default null.
	 * @return array<string, mixed> The ability result.
	 */
	private function run_ability( $input = null ): array {
		$result = $this->get_ability()->execute( $input );
		$this->assertNotWPError( $result );

		return $result;
	}

	/**
	 * Returns the result's templates keyed by slug.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $result The ability result.
	 * @return array<string, array<string, mixed>> The templates keyed by slug.
	 */
	private function templates_by_slug( array $result ): array {
		return array_column( $result['templates'], null, 'slug' );
	}

	/**
	 * Creates a published template for the active theme, as the Site Editor stores one.
	 *
	 * @since x.x.x
	 *
	 * @param string $slug  The template slug.
	 * @param string $title The template title.
	 */
	private function create_user_template( string $slug, string $title ): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'wp_template',
				'post_name'    => $slug,
				'post_title'   => $title,
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>Edited</p><!-- /wp:paragraph -->',
			)
		);
		wp_set_post_terms( $post_id, get_stylesheet(), 'wp_theme' );
	}

	/**
	 * The ability is registered as a read-only, public ability in the site category.
	 *
	 * @since x.x.x
	 */
	public function test_registers_a_read_only_public_ability(): void {
		$ability = $this->get_ability();
		$meta    = $ability->get_meta();

		$this->assertSame( 'site', $ability->get_category() );
		$this->assertTrue( $meta['annotations']['readonly'] );
		$this->assertFalse( $meta['annotations']['destructive'] );
		$this->assertTrue( $meta['annotations']['idempotent'] );
		$this->assertTrue( $meta['public'] );
		$this->assertTrue( $meta['show_in_rest'] );
	}

	/**
	 * An ability already registered under the name, such as one core provides, is kept.
	 *
	 * @since x.x.x
	 */
	public function test_keeps_an_ability_already_registered_under_the_name(): void {
		wp_unregister_ability( self::ABILITY );

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			wp_register_ability(
				self::ABILITY,
				array(
					'label'               => 'Existing',
					'description'         => 'An ability registered first.',
					'category'            => 'site',
					'execute_callback'    => '__return_true',
					'permission_callback' => '__return_true',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->register_ability();

		$this->assertSame( 'Existing', $this->get_ability()->get_label() );
	}

	/**
	 * Only users who can edit theme options may run the ability.
	 *
	 * @since x.x.x
	 */
	public function test_requires_edit_theme_options(): void {
		$this->assertTrue( $this->get_ability()->check_permissions() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertFalse( $this->get_ability()->check_permissions() );

		wp_set_current_user( 0 );
		$this->assertFalse( $this->get_ability()->check_permissions() );
	}

	/**
	 * A user without the capability gets an error instead of the templates.
	 *
	 * @since x.x.x
	 */
	public function test_execute_refuses_a_user_without_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$result = $this->get_ability()->execute();

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * A block theme is reported with the templates it ships as files.
	 *
	 * @since x.x.x
	 */
	public function test_reports_a_block_theme_and_its_template_files(): void {
		switch_theme( self::BLOCK_THEME );

		$result    = $this->run_ability();
		$templates = $this->templates_by_slug( $result );

		$this->assertTrue( $result['block_theme'] );
		$this->assertSame( 'AI Test Block Theme', $result['theme'] );
		$this->assertArrayHasKey( '404', $templates );
		$this->assertArrayHasKey( 'index', $templates );
		$this->assertSame( 'theme', $templates['404']['source'] );
		$this->assertTrue( $templates['404']['has_theme_file'] );
		$this->assertNotSame( '', $templates['404']['title'] );
	}

	/**
	 * A template the theme ships that a user edited is reported as custom, with its theme file.
	 *
	 * @since x.x.x
	 */
	public function test_reports_an_edited_theme_template_as_custom_with_a_theme_file(): void {
		switch_theme( self::BLOCK_THEME );
		$this->create_user_template( '404', 'Edited 404' );

		$templates = $this->templates_by_slug( $this->run_ability() );

		$this->assertSame( 'custom', $templates['404']['source'] );
		$this->assertTrue( $templates['404']['has_theme_file'] );
		$this->assertSame( 'Edited 404', $templates['404']['title'] );
		$this->assertSame( 'theme', $templates['index']['source'] );
	}

	/**
	 * A template a user created, which the theme does not ship, has no theme file.
	 *
	 * @since x.x.x
	 */
	public function test_reports_a_user_created_template_without_a_theme_file(): void {
		switch_theme( self::BLOCK_THEME );
		$this->create_user_template( 'page-about', 'About' );

		$templates = $this->templates_by_slug( $this->run_ability() );

		$this->assertSame( 'custom', $templates['page-about']['source'] );
		$this->assertFalse( $templates['page-about']['has_theme_file'] );
		$this->assertSame( 'About', $templates['page-about']['title'] );
	}

	/**
	 * Templates are sorted by slug, whether or not a user edited them.
	 *
	 * @since x.x.x
	 */
	public function test_sorts_templates_by_slug(): void {
		switch_theme( self::BLOCK_THEME );
		$this->create_user_template( 'single', 'Single' );

		$slugs  = wp_list_pluck( $this->run_ability()['templates'], 'slug' );
		$sorted = $slugs;
		sort( $sorted, SORT_STRING );

		$this->assertSame( $sorted, $slugs );
	}

	/**
	 * A classic theme is reported as one, without block templates.
	 *
	 * @since x.x.x
	 */
	public function test_reports_a_classic_theme_without_templates(): void {
		switch_theme( self::CLASSIC_THEME );

		$result = $this->run_ability();

		$this->assertFalse( $result['block_theme'] );
		$this->assertSame( 'AI Test Classic Theme', $result['theme'] );
		$this->assertSame( array(), $result['templates'] );
	}

	/**
	 * An empty object is accepted as input, as clients that always send one do.
	 *
	 * @since x.x.x
	 */
	public function test_accepts_an_empty_object_as_input(): void {
		switch_theme( self::BLOCK_THEME );

		$result = $this->run_ability( array() );

		$this->assertTrue( $result['block_theme'] );
	}

	/**
	 * Input the ability does not take is rejected.
	 *
	 * @since x.x.x
	 */
	public function test_rejects_unknown_input(): void {
		$result = $this->get_ability()->execute( array( 'slug' => '404' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * The schemas the REST API serves are valid JSON Schema: no `properties` is encoded as a
	 * JSON array, which client-side validators reject before running the ability.
	 *
	 * @since x.x.x
	 */
	public function test_rest_schemas_never_encode_properties_as_an_array(): void {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resetting the REST server for the test.
		do_action( 'rest_api_init' );

		$data = rest_do_request( new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/' . self::ABILITY ) )->get_data();

		$this->assertStringNotContainsString( '"properties":[]', (string) wp_json_encode( $data['input_schema'] ) );
		$this->assertStringNotContainsString( '"properties":[]', (string) wp_json_encode( $data['output_schema'] ) );
	}

	/**
	 * The ability runs over the REST API with GET, as a read-only ability does.
	 *
	 * @since x.x.x
	 */
	public function test_runs_over_rest_with_get(): void {
		switch_theme( self::BLOCK_THEME );

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resetting the REST server for the test.
		do_action( 'rest_api_init' );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/' . self::ABILITY . '/run' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['block_theme'] );
		$this->assertArrayHasKey( '404', $this->templates_by_slug( $data ) );
	}
}
