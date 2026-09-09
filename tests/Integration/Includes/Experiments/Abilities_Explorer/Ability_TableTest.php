<?php
/**
 * Integration tests for the Ability_Table class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Abilities_Explorer
 */

namespace WordPress\AI\Tests\Integration\Experiments\Abilities_Explorer;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Abilities_Explorer\Ability_Handler;
use WordPress\AI\Experiments\Abilities_Explorer\Ability_Table;
use WordPress\AI\Experiments\Abilities_Explorer\Admin_Page;
use WordPress\AI\Experiments\AI_Workspace\Streaming\Streaming_Turn_Driver;
use WordPress\AI\Experiments\AI_Workspace\Tool_Policy;
use WordPress\AI\Experiments\AI_Workspace\Tool_Selector;

/**
 * Ability_Table test case.
 *
 * @since 1.3.0
 */
class Ability_TableTest extends WP_UnitTestCase {
	/**
	 * Set up test case.
	 *
	 * @since 1.3.0
	 */
	public function setUp(): void {
		parent::setUp();

		/*
		 * Production registers this from the experiment bootstrap
		 * (`AI_Workspace::register()`), which does not run here.
		 */
		Tool_Policy::register_owner_exclusions();

		// Create admin user for tests.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		set_current_screen( 'tools_page_ai-abilities-explorer' );
	}

	/**
	 * Tear down test case.
	 *
	 * @since 1.3.0
	 */
	public function tearDown(): void {
		remove_filter( 'wpai_workspace_tool_candidates', array( Tool_Policy::class, 'filter_owner_exclusions' ), 100 );

		foreach ( $this->registered as $ability_name ) {
			if ( wp_has_ability( $ability_name ) ) {
				wp_unregister_ability( $ability_name );
			}
		}

		$this->registered = array();

		unset( $_REQUEST['_wpnonce'], $_REQUEST['surface'], $_REQUEST['ability'] );

		delete_option( Tool_Policy::OWNER_EXCLUSIONS_OPTION );
		delete_option( Tool_Selector::POLICY_DISABLED_OPTION );

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Names of the fixture abilities registered by the current test.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	private $registered = array();

	/**
	 * Test get_unique_providers returns only the known origins by default.
	 *
	 * @since 1.3.0
	 */
	public function test_get_unique_providers_returns_known_origins() {
		$table = new Ability_Table();
		$table->prepare_items();

		$this->assertSame( array( 'Core', 'Plugin', 'Theme' ), $table->get_unique_providers() );
	}

	/**
	 * Test get_unique_providers appends custom providers after the known origins.
	 *
	 * @since 1.3.0
	 */
	public function test_get_unique_providers_includes_custom_providers() {
		global $wp_current_filter;

		$slug = 'custom-provider-plugin/table-ability';

		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.

		try {
			wp_register_ability(
				$slug,
				array(
					'label'               => 'Custom Provider Table Ability',
					'description'         => 'Test ability with a custom provider label.',
					'category'            => WPAI_DEFAULT_ABILITY_CATEGORY,
					'meta'                => array( 'provider' => 'My Custom Plugin' ),
					'execute_callback'    => '__return_true',
					'permission_callback' => '__return_true',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$table = new Ability_Table();
		$table->prepare_items();

		wp_unregister_ability( $slug );

		$this->assertSame( array( 'Core', 'Plugin', 'Theme', 'My Custom Plugin' ), $table->get_unique_providers() );
	}

	/**
	 * Test the provider filter matches by origin for known providers and by label for custom ones.
	 *
	 * Filtering by "Plugin" must include abilities that carry a custom provider
	 * label but originate from a plugin (consistent with the statistics), while
	 * filtering by the custom label must return only those abilities.
	 *
	 * @since 1.3.0
	 */
	public function test_provider_filter_matches_origin_for_known_providers() {
		global $wp_current_filter;

		$slug = 'custom-provider-plugin/filter-ability';

		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.

		try {
			wp_register_ability(
				$slug,
				array(
					'label'               => 'AAA Custom Provider Filter Ability',
					'description'         => 'Test ability with a custom provider label for filtering.',
					'category'            => WPAI_DEFAULT_ABILITY_CATEGORY,
					'meta'                => array( 'provider' => 'My Custom Plugin' ),
					'execute_callback'    => '__return_true',
					'permission_callback' => '__return_true',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		// Known origin: the custom-labeled ability still appears under "Plugin".
		$_REQUEST['provider'] = 'Plugin';
		$table                = new Ability_Table();
		$table->prepare_items();
		$plugin_slugs = wp_list_pluck( $table->items, 'slug' );
		$this->assertContains( $slug, $plugin_slugs );

		// Custom label: filtering by it returns exactly the custom-labeled ability.
		$_REQUEST['provider'] = 'My Custom Plugin';
		$table                = new Ability_Table();
		$table->prepare_items();
		$custom_slugs = wp_list_pluck( $table->items, 'slug' );

		unset( $_REQUEST['provider'] );
		wp_unregister_ability( $slug );

		$this->assertSame( array( $slug ), $custom_slugs );
	}

	/**
	 * A declared, admitted ability is marked as on the conversational surface.
	 *
	 * @since x.x.x
	 */
	public function test_column_marks_a_declared_admitted_ability_as_on_the_surface(): void {
		$this->require_filtered_discovery();

		$slug = $this->register_declared_fixture( 'wpai-test/table-admitted' );

		$item = $this->row_for( $slug );

		$this->assertTrue(
			$item['conversational_surface'],
			'A declared, read-only, closed-world ability the workspace admits must be marked as on the assistant surface.'
		);
		$this->assertNull(
			$item['surface_reason'],
			'An ability on the surface must carry no exclusion reason.'
		);
		$this->assertStringContainsString(
			'On the assistant surface',
			( new Ability_Table() )->column_conversational_surface( $item ),
			'The rendered column must say that the assistant holds the ability.'
		);
	}

	/**
	 * An excluded ability shows the reason it is not offered.
	 *
	 * @since x.x.x
	 */
	public function test_column_shows_the_exclusion_reason_for_an_ability_not_on_the_surface(): void {
		$this->require_filtered_discovery();

		$slug = $this->register_fixture(
			'wpai-test/table-undeclared',
			array(
				'readonly'    => true,
				'destructive' => false,
				'open_world'  => false,
			),
			false
		);

		$item = $this->row_for( $slug );

		$this->assertFalse(
			$item['conversational_surface'],
			'An ability carrying no declaration must not be marked as on the assistant surface.'
		);
		$this->assertSame(
			Tool_Policy::REASON_NOT_DECLARED,
			$item['surface_reason'],
			'An ability carrying no declaration must report that as its reason.'
		);
		$this->assertStringContainsString(
			'Not declared for the assistant',
			( new Ability_Table() )->column_conversational_surface( $item ),
			'The rendered column must tell the owner why the assistant does not hold the ability.'
		);
	}

	/**
	 * The column and the model read the same description source string.
	 *
	 * Compared before escaping on purpose. The column escapes and
	 * `Streaming_Turn_Driver::build_config()` does not, so a rendered-cell
	 * comparison would be false by construction for any description containing
	 * `&`, `'` or `<` — which is exactly what this fixture's description holds,
	 * so that a comparison done the wrong way could not pass by accident.
	 *
	 * @since x.x.x
	 */
	public function test_column_and_function_declaration_share_one_description_source(): void {
		$this->require_filtered_discovery();

		$description = "Reads drafts & notes with 'quotes' and a <tag>.";
		$slug        = $this->register_declared_fixture( 'wpai-test/table-description', $description );

		$item = $this->row_for( $slug );

		$this->assertSame(
			$description,
			$item['description'],
			'The Explorer row must carry the ability description unmodified.'
		);

		$config = ( new \ReflectionMethod( Streaming_Turn_Driver::class, 'build_config' ) );
		$config->setAccessible( true );

		$declarations = $config->invoke( new Streaming_Turn_Driver(), array( $slug ), 'instruction' )
			->getFunctionDeclarations();

		$this->assertCount(
			1,
			$declarations,
			'The fixture must produce exactly one function declaration for the comparison to be meaningful.'
		);
		$this->assertSame(
			$item['description'],
			$declarations[0]->getDescription(),
			'The Explorer column and the model must be shown the same description string, not two renderings of it.'
		);
		$this->assertStringContainsString(
			'&amp;',
			( new Ability_Table() )->column_conversational_surface( $item ),
			'The column must escape the description on output, even though the source string it reads is unmodified.'
		);
	}

	/**
	 * A surface change without a valid nonce leaves the surface unchanged.
	 *
	 * @since x.x.x
	 */
	public function test_surface_change_without_a_valid_nonce_is_refused(): void {
		$slug = $this->register_declared_fixture( 'wpai-test/table-nonce' );

		$_REQUEST['_wpnonce'] = 'not-a-nonce';
		$_REQUEST['surface']  = 'remove';
		$_REQUEST['ability']  = $slug;

		$this->assertHandlerRefuses(
			'A request carrying no valid nonce must not reshape the assistant surface.'
		);
	}

	/**
	 * A surface change from a user without `manage_options` is refused.
	 *
	 * The nonce alone is not the guard: a nonce is what a CSRF against a
	 * logged-in user rides on, and a subscriber holding one must still be
	 * unable to change what the assistant may call.
	 *
	 * @since x.x.x
	 */
	public function test_surface_change_without_manage_options_is_refused(): void {
		$slug = $this->register_declared_fixture( 'wpai-test/table-capability' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$_REQUEST['_wpnonce'] = wp_create_nonce( Admin_Page::SURFACE_NONCE_ACTION );
		$_REQUEST['surface']  = 'remove';
		$_REQUEST['ability']  = $slug;

		$this->assertHandlerRefuses(
			'A user without manage_options must not be able to reshape the assistant surface, even with a valid nonce.'
		);
	}

	/**
	 * An administrator with a valid nonce removes the ability from the surface.
	 *
	 * @since x.x.x
	 */
	public function test_surface_change_by_an_administrator_removes_the_ability(): void {
		$this->require_filtered_discovery();

		$slug = $this->register_declared_fixture( 'wpai-test/table-removed' );

		$this->assertContains(
			$slug,
			( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE ),
			'The fixture must reach the surface before its removal can prove anything.'
		);

		$_REQUEST['_wpnonce'] = wp_create_nonce( Admin_Page::SURFACE_NONCE_ACTION );
		$_REQUEST['surface']  = 'remove';
		$_REQUEST['ability']  = $slug;

		add_filter( 'wp_redirect', '__return_false' );
		( new Admin_Page() )->ajax_set_surface_membership();
		remove_filter( 'wp_redirect', '__return_false' );

		$this->assertTrue(
			( new Tool_Policy() )->is_owner_excluded( $slug ),
			'The administrator’s removal must persist.'
		);
		$this->assertNotContains(
			$slug,
			( new Tool_Selector() )->get_tool_names( Tool_Selector::SCOPE_SITE ),
			'An ability the owner removed must not be declared to the model on the next turn.'
		);
	}

	/**
	 * Runs the surface handler and asserts it changed nothing.
	 *
	 * @since x.x.x
	 *
	 * @param string $message The assertion message.
	 */
	private function assertHandlerRefuses( string $message ): void {
		$before = get_option( Tool_Policy::OWNER_EXCLUSIONS_OPTION, array() );

		/*
		 * Both guards end the request. Outside an AJAX context
		 * `check_ajax_referer()` reaches a bare `die()` that would take the test
		 * runner with it, so the request is presented as the AJAX request it
		 * really is and the AJAX die handler is swapped for one that throws —
		 * the same substitution `WP_Ajax_UnitTestCase` makes.
		 */
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'throwing_die_handler' ) );
		add_filter( 'wp_redirect', '__return_false' );

		$refused = false;

		ob_start();
		try {
			( new Admin_Page() )->ajax_set_surface_membership();
		} catch ( \WPDieException $exception ) {
			$refused = true;
		} finally {
			ob_end_clean();
			remove_filter( 'wp_redirect', '__return_false' );
			remove_filter( 'wp_die_ajax_handler', array( $this, 'throwing_die_handler' ) );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		$this->assertTrue( $refused, $message );
		$this->assertSame(
			$before,
			get_option( Tool_Policy::OWNER_EXCLUSIONS_OPTION, array() ),
			$message
		);
	}

	/**
	 * Returns a `wp_die()` handler that throws instead of ending the process.
	 *
	 * @since x.x.x
	 *
	 * @return callable The die handler.
	 */
	public function throwing_die_handler(): callable {
		return static function ( $message, $title = '', $args = array() ): void {
			unset( $title, $args );

			throw new \WPDieException( is_scalar( $message ) ? (string) $message : '' );
		};
	}

	/**
	 * Returns the Explorer row for one ability slug.
	 *
	 * @since x.x.x
	 *
	 * @param string $slug The ability name.
	 * @return array<string, mixed> The row.
	 */
	private function row_for( string $slug ): array {
		$rows = array_column( Ability_Handler::get_all_abilities(), null, 'slug' );

		$this->assertArrayHasKey(
			$slug,
			$rows,
			sprintf( 'The Explorer must list the fixture ability %s.', $slug )
		);

		return $rows[ $slug ];
	}

	/**
	 * Registers a fixture that declares itself fit for the assistant.
	 *
	 * @since x.x.x
	 *
	 * @param string $slug        The ability name.
	 * @param string $description Optional. The ability description. Default a plain sentence.
	 * @return string The ability name.
	 */
	private function register_declared_fixture( string $slug, string $description = 'A fixture ability for the surface column.' ): string {
		return $this->register_fixture(
			$slug,
			array(
				'readonly'    => true,
				'destructive' => false,
				'open_world'  => false,
			),
			true,
			$description
		);
	}

	/**
	 * Registers a fixture ability.
	 *
	 * @since x.x.x
	 *
	 * @param string               $slug        The ability name.
	 * @param array<string, mixed> $annotations The effect-class annotations.
	 * @param bool                 $declared    Whether to carry the conversational-surface declaration.
	 * @param string               $description Optional. The ability description. Default a plain sentence.
	 * @return string The ability name.
	 */
	private function register_fixture( string $slug, array $annotations, bool $declared, string $description = 'A fixture ability for the surface column.' ): string {
		$meta = array( 'annotations' => $annotations );

		if ( $declared ) {
			$meta['wpai_conversational_surface'] = true;
		}

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.

		try {
			wp_register_ability(
				$slug,
				array(
					'label'               => 'Surface fixture',
					'description'         => $description,
					'category'            => WPAI_DEFAULT_ABILITY_CATEGORY,
					'meta'                => $meta,
					'execute_callback'    => '__return_true',
					'permission_callback' => '__return_true',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->registered[] = $slug;

		return $slug;
	}

	/**
	 * Skips a test on a WordPress that cannot filter ability discovery.
	 *
	 * @since x.x.x
	 */
	private function require_filtered_discovery(): void {
		if ( ! ( new Tool_Policy() )->supports_filtered_discovery() ) {
			$this->markTestSkipped( 'This WordPress does not support filtered ability discovery.' );
		}
	}
}
