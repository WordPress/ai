<?php
/**
 * Integration tests for AI Workspace template proposals.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace;

use ReflectionProperty;
use WP_REST_Request;
use WP_UnitTestCase;
use WP_User;
use WordPress\AI\Abilities\Show_In_Abilities;
use WordPress\AI\Experiments\AI_Workspace\Proposal_Store;
use WordPress\AI\Experiments\AI_Workspace\Propose_Template;
use WordPress\AI\Experiments\AI_Workspace\REST\Proposal_Controller;
use WordPress\AI\Experiments\AI_Workspace\Template_Writer;
use WordPress\AI\Experiments\AI_Workspace\Turn_Context;
use WordPress\AI\Logging\AI_Request_Log_Manager;
use WordPress\AI\Logging\Logging_Integration;

/**
 * Template proposal test case.
 *
 * A template is live for every visitor as soon as it is approved, so these tests
 * pin what may be proposed, what the card is shown, and that approval creates
 * exactly the template that was shown, once, without touching one someone has
 * already edited.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Experiments\AI_Workspace\Template_Writer
 * @covers \WordPress\AI\Experiments\AI_Workspace\Propose_Template
 */
class Template_ProposalTest extends WP_UnitTestCase {

	/**
	 * The conversation every proposal in these tests belongs to.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const CONVERSATION = 'template-conversation';

	/**
	 * A block theme that ships `404.html`, `search.html`, `archive.html` and `single.html`.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const BLOCK_THEME = 'twentytwentyfive';

	/**
	 * Minimal valid template markup.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const MARKUP = '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Page not found</h1><!-- /wp:heading -->';

	/**
	 * Shared user IDs keyed by label.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, int>
	 */
	private static $user_ids = array();

	/**
	 * Manager the logging integration held before the test replaced it.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Manager|null
	 */
	private $original_shared_manager = null;

	/**
	 * Creates the shared users.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_UnitTest_Factory $factory The unit test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$user_ids = array(
			'owner'  => $factory->user->create( array( 'role' => 'administrator' ) ),
			'editor' => $factory->user->create( array( 'role' => 'editor' ) ),
		);
	}

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		( new Show_In_Abilities() )->register();
		switch_theme( self::BLOCK_THEME );

		// Writes are logged; with no log table in these tests, nothing is shared to log to.
		$this->original_shared_manager = Logging_Integration::get_log_manager();
		$this->set_shared_manager( null );

		global $wp_rest_server, $wp_current_filter;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Rebuilding the REST server between dispatches.
		rest_get_server();
		( new Proposal_Controller() )->register_routes();

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
			( new Propose_Template() )->register();
		} finally {
			array_pop( $wp_current_filter );
		}

		wp_set_current_user( self::$user_ids['owner'] );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		Turn_Context::leave();

		$this->set_shared_manager( $this->original_shared_manager );

		if ( wp_has_ability( Propose_Template::ABILITY ) ) {
			wp_unregister_ability( Propose_Template::ABILITY );
		}

		( new WP_User( self::$user_ids['owner'] ) )->remove_cap( Template_Writer::CAPABILITY );

		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Replaces the manager the logging integration shares.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Logging\AI_Request_Log_Manager|null $manager Manager, or null for a disabled experiment.
	 */
	private function set_shared_manager( ?AI_Request_Log_Manager $manager ): void {
		$property = new ReflectionProperty( Logging_Integration::class, 'log_manager' );
		$property->setAccessible( true );
		$property->setValue( null, $manager );
	}

	/**
	 * Calls the propose ability inside a workspace turn.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $input The ability input.
	 * @return mixed The ability result.
	 */
	private function propose( array $input ) {
		Turn_Context::enter( self::CONVERSATION, get_current_user_id() );

		try {
			return wp_get_ability( Propose_Template::ABILITY )->execute( $input );
		} finally {
			Turn_Context::leave();
		}
	}

	/**
	 * Proposes a template and returns the stored proposal.
	 *
	 * @since x.x.x
	 *
	 * @param string $slug    The template slug.
	 * @param string $content The block markup.
	 * @return array<string, mixed> The stored proposal.
	 */
	private function store_template( string $slug = '404', string $content = self::MARKUP ): array {
		$result = $this->propose(
			array(
				'slug'    => $slug,
				'title'   => 'Page not found',
				'content' => $content,
			)
		);

		$this->assertIsArray( $result, 'The fixture template should be proposed cleanly.' );

		$proposal = ( new Proposal_Store() )->get( $result['proposal_id'], get_current_user_id() );

		$this->assertIsArray( $proposal );

		return $proposal;
	}

	/**
	 * Returns the item keys of a proposal.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $proposal The proposal.
	 * @return list<string> The keys.
	 */
	private function keys( array $proposal ): array {
		return array_column( $proposal['items'], 'key' );
	}

	/**
	 * Dispatches a GET for a proposal.
	 *
	 * @since x.x.x
	 *
	 * @param string $proposal_id The proposal ID.
	 * @return array<string, mixed> The response data.
	 */
	private function present( string $proposal_id ): array {
		return rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', '/ai/v1/workspace/proposals/' . $proposal_id )
		)->get_data();
	}

	/**
	 * Dispatches an execute request for a proposal.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $proposal The proposal.
	 * @return \WP_REST_Response The response.
	 */
	private function execute( array $proposal ) {
		$request = new WP_REST_Request( 'POST', '/ai/v1/workspace/proposals/' . $proposal['id'] . '/execute' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'conversation_id' => self::CONVERSATION,
					'selected'        => $this->keys( $proposal ),
				)
			)
		);

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Returns the user templates stored for the active theme with a slug.
	 *
	 * @since x.x.x
	 *
	 * @param string $slug The template slug.
	 * @return list<\WP_Post> The template posts.
	 */
	private function stored_templates( string $slug ): array {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.get_posts_get_posts -- Test lookup that must see the live rows.
		return get_posts(
			array(
				'post_type'      => 'wp_template',
				'post_status'    => 'any',
				'name'           => $slug,
				'posts_per_page' => 10,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Test lookup.
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'name',
						'terms'    => get_stylesheet(),
					),
				),
			)
		);
	}

	/**
	 * Counts the stored proposals of any user.
	 *
	 * @since x.x.x
	 *
	 * @return int The number of proposal transients.
	 */
	private function stored_proposal_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . Proposal_Store::TRANSIENT_PREFIX ) . '%'
			)
		);
	}

	/**
	 * Approving a 404 template creates one template for the active theme, opened in the Site Editor.
	 *
	 * @since x.x.x
	 */
	public function test_approving_a_404_template_creates_it_for_the_active_theme(): void {
		$proposal = $this->store_template();
		$response = $this->execute( $proposal );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $data['created'] );

		$templates = $this->stored_templates( '404' );

		$this->assertCount( 1, $templates );
		$this->assertSame( 'publish', $templates[0]->post_status );
		$this->assertSame( 'Page not found', $templates[0]->post_title );
		$this->assertSame( $templates[0]->ID, $data['items'][0]['post_id'] );
		$this->assertSame( 'wp_template', $data['items'][0]['post_type'] );

		$template = get_block_template( get_stylesheet() . '//404' );
		$this->assertNotNull( $template );
		$this->assertSame( 'custom', $template->source, 'The new template is the one the theme now uses.' );

		$this->assertStringContainsString( 'site-editor.php', $data['items'][0]['edit_link'] );
		$this->assertStringContainsString( rawurlencode( get_stylesheet() . '//404' ), $data['items'][0]['edit_link'] );
	}

	/**
	 * On a classic theme the call explains why, and nothing is stored.
	 *
	 * @since x.x.x
	 */
	public function test_classic_theme_refuses_with_a_reason_and_stores_nothing(): void {
		switch_theme( 'twentytwentyone' );

		$result = $this->propose(
			array(
				'slug'    => '404',
				'title'   => 'Page not found',
				'content' => self::MARKUP,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'workspace_template_classic_theme', $result->get_error_code() );
		$this->assertStringContainsString( 'classic theme', $result->get_error_message() );
		$this->assertSame( 0, $this->stored_proposal_count() );
	}

	/**
	 * Losing the theme capability before approval writes nothing.
	 *
	 * @since x.x.x
	 */
	public function test_approval_without_edit_theme_options_is_refused(): void {
		$proposal = $this->store_template();

		// The current user object caches its capabilities, so the change is made on it.
		wp_get_current_user()->add_cap( Template_Writer::CAPABILITY, false );

		$data = $this->execute( $proposal )->get_data();

		$this->assertSame( 0, $data['created'] );
		$this->assertSame( 1, $data['denied'] );
		$this->assertSame( 'cannot_edit_theme_options', $data['items'][0]['error_code'] );
		$this->assertCount( 0, $this->stored_templates( '404' ) );
	}

	/**
	 * A template someone already edited is never overwritten.
	 *
	 * @since x.x.x
	 */
	public function test_an_edited_template_is_left_unchanged(): void {
		$proposal = $this->store_template();

		$edited_id = self::factory()->post->create(
			array(
				'post_type'    => 'wp_template',
				'post_status'  => 'publish',
				'post_name'    => '404',
				'post_title'   => 'My own 404',
				'post_content' => '<!-- wp:paragraph --><p>Mine.</p><!-- /wp:paragraph -->',
			)
		);
		wp_set_post_terms( $edited_id, array( get_stylesheet() ), 'wp_theme' );

		$data = $this->execute( $proposal )->get_data();

		$this->assertSame( 0, $data['created'] );
		$this->assertSame( 1, $data['failed'] );
		$this->assertSame( 'template_exists', $data['items'][0]['error_code'] );
		$this->assertNotSame( '', $data['items'][0]['error_message'] );

		$templates = $this->stored_templates( '404' );
		$this->assertCount( 1, $templates );
		$this->assertSame( $edited_id, $templates[0]->ID );
		$this->assertSame( '<!-- wp:paragraph --><p>Mine.</p><!-- /wp:paragraph -->', $templates[0]->post_content );

		$again = $this->propose(
			array(
				'slug'    => '404',
				'title'   => 'Page not found',
				'content' => self::MARKUP,
			)
		);

		$this->assertWPError( $again, 'Proposing over an edited template is refused up front, too.' );
		$this->assertSame( 'workspace_template_exists', $again->get_error_code() );
	}

	/**
	 * Replacing a template file the theme ships is allowed, and the card says so.
	 *
	 * @since x.x.x
	 */
	public function test_replacing_a_theme_file_template_is_announced_then_created(): void {
		$this->assertNotNull( get_block_file_template( get_stylesheet() . '//404' ), 'The fixture theme ships 404.html.' );

		$proposal  = $this->store_template();
		$presented = $this->present( $proposal['id'] );

		$this->assertSame( 'template', $presented['kind'] );
		$this->assertSame( 1, $presented['max_items'] );
		$this->assertTrue( $presented['items'][0]['replaces_theme_template'] );
		$this->assertSame( '404', $presented['items'][0]['slug'] );
		$this->assertSame( wp_get_theme()->get( 'Name' ), $presented['items'][0]['theme'] );

		$this->assertSame( 1, $this->execute( $proposal )->get_data()['created'] );
		$this->assertCount( 1, $this->stored_templates( '404' ) );

		$page = $this->store_template( 'page-about' );
		$this->assertFalse( $this->present( $page['id'] )['items'][0]['replaces_theme_template'], 'The theme ships no page-about template.' );
	}

	/**
	 * Markup with no blocks, or with HTML outside the blocks, is refused.
	 *
	 * @since x.x.x
	 */
	public function test_markup_that_is_not_only_blocks_is_refused(): void {
		$cases = array(
			'no blocks'          => '<p>Just a paragraph.</p>',
			'empty'              => '',
			'script outside'     => self::MARKUP . '<script>alert(1)</script>',
			'html before blocks' => '<div>Loose</div>' . self::MARKUP,
		);

		foreach ( $cases as $label => $content ) {
			$result = $this->propose(
				array(
					'slug'    => '404',
					'title'   => 'Page not found',
					'content' => $content,
				)
			);

			$this->assertWPError( $result, $label . ' must be refused.' );
			$this->assertSame( 'workspace_template_invalid_markup', $result->get_error_code(), $label );
		}

		$this->assertSame( 0, $this->stored_proposal_count() );
	}

	/**
	 * A block the site does not register is refused, at the top level or nested.
	 *
	 * @since x.x.x
	 */
	public function test_an_unregistered_block_is_refused(): void {
		$cases = array(
			'top level' => '<!-- wp:core/hero {"title":"Not found"} /-->',
			'nested'    => '<!-- wp:group --><div class="wp-block-group"><!-- wp:theme/banner /--></div><!-- /wp:group -->',
		);

		foreach ( $cases as $label => $content ) {
			$result = $this->propose(
				array(
					'slug'    => '404',
					'title'   => 'Page not found',
					'content' => $content,
				)
			);

			$this->assertWPError( $result, $label . ' must be refused.' );
			$this->assertSame( 'workspace_template_unknown_block', $result->get_error_code(), $label );
		}

		$this->assertStringContainsString( 'theme/banner', $result->get_error_message(), 'The error names the block, so the model can fix it.' );
		$this->assertSame( 0, $this->stored_proposal_count() );
	}

	/**
	 * A template for one page may be proposed and created.
	 *
	 * @since x.x.x
	 */
	public function test_a_page_template_is_created(): void {
		$data = $this->execute( $this->store_template( 'page-about-us' ) )->get_data();

		$this->assertSame( 1, $data['created'] );
		$this->assertCount( 1, $this->stored_templates( 'page-about-us' ) );
	}

	/**
	 * A switch to a classic theme before approval writes nothing.
	 *
	 * @since x.x.x
	 */
	public function test_a_classic_theme_at_approval_writes_nothing(): void {
		$proposal = $this->store_template();

		switch_theme( 'twentytwentyone' );

		$data = $this->execute( $proposal )->get_data();

		$this->assertSame( 0, $data['created'] );
		$this->assertSame( 1, $data['failed'] );
		$this->assertSame( 'classic_theme', $data['items'][0]['error_code'] );
		$this->assertSame( 0, (int) wp_count_posts( 'wp_template' )->publish, 'No template row was written for any theme.' );
	}

	/**
	 * A slug outside the template hierarchy is refused.
	 *
	 * @since x.x.x
	 */
	public function test_a_slug_outside_the_hierarchy_is_refused(): void {
		foreach ( array( 'header', 'page-', '../404', 'page-About Us' ) as $slug ) {
			$result = $this->propose(
				array(
					'slug'    => $slug,
					'title'   => 'A template',
					'content' => self::MARKUP,
				)
			);

			$this->assertWPError( $result, $slug . ' must be refused.' );
			$this->assertSame( 'workspace_template_invalid_slug', $result->get_error_code(), $slug );
		}
	}

	/**
	 * Unsafe markup inside blocks is stripped when stored, and written exactly as shown.
	 *
	 * @since x.x.x
	 */
	public function test_unsafe_markup_is_stripped_and_written_as_shown(): void {
		$markup = '<!-- wp:html --><img src="x.png" onerror="alert(1)"><!-- /wp:html -->'
			. '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="javascript:alert(1)">Go</a></div><!-- /wp:button --></div><!-- /wp:buttons -->'
			. '<!-- wp:heading {"placeholder":"a -- b"} --><h2 class="wp-block-heading">Tail</h2><!-- /wp:heading -->';

		$proposal = $this->store_template( '404', $markup );
		$stored   = $proposal['items'][0]['content'];

		$this->assertStringNotContainsString( 'onerror', $stored );
		$this->assertStringNotContainsString( 'javascript:', $stored );
		$this->assertStringContainsString( '<!-- wp:html -->', $stored, 'The blocks themselves survive.' );
		// Attribute JSON is re-serialized with `--` escaped, so the markup carries backslashes `wp_insert_post()` would strip.
		$this->assertStringContainsString( '\\u002d\\u002d', $stored );

		$this->assertSame( $stored, $this->present( $proposal['id'] )['items'][0]['content'], 'The card shows the stored markup.' );

		$this->execute( $proposal );

		$templates = $this->stored_templates( '404' );
		$this->assertCount( 1, $templates );
		$this->assertSame( $stored, $templates[0]->post_content, 'The template written is byte for byte the one shown.' );
	}

	/**
	 * Approving the same template twice creates it once.
	 *
	 * @since x.x.x
	 */
	public function test_approving_twice_creates_one_template(): void {
		$proposal = $this->store_template();

		$first  = $this->execute( $proposal )->get_data();
		$second = $this->execute( $proposal )->get_data();

		$this->assertSame( 1, $first['created'] );
		$this->assertSame( 0, $second['created'] );
		$this->assertSame( 1, $second['duplicate'] );
		$this->assertSame( $first['items'][0]['post_id'], $second['items'][0]['post_id'] );
		$this->assertCount( 1, $this->stored_templates( '404' ) );
	}

	/**
	 * Proposing needs the theme capability, and a workspace turn.
	 *
	 * @since x.x.x
	 */
	public function test_proposing_needs_the_theme_capability_and_a_turn(): void {
		$ability = wp_get_ability( Propose_Template::ABILITY );

		$this->assertFalse( $ability->get_meta()['show_in_rest'] );

		$outside = $ability->execute(
			array(
				'slug'    => '404',
				'title'   => 'Page not found',
				'content' => self::MARKUP,
			)
		);
		$this->assertWPError( $outside );
		$this->assertSame( 'workspace_context_required', $outside->get_error_code() );

		wp_set_current_user( self::$user_ids['editor'] );

		$this->assertWPError(
			$this->propose(
				array(
					'slug'    => '404',
					'title'   => 'Page not found',
					'content' => self::MARKUP,
				)
			),
			'An editor cannot edit the theme, so cannot propose a template.'
		);
		$this->assertSame( 0, $this->stored_proposal_count() );
	}
}
