<?php
/**
 * Integration tests for the ai/find-admin-screens Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Site
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Site;

use WP_UnitTestCase;
use WordPress\AI\Abilities\Site\Find_Admin_Screens;
use WordPress\AI\Experiments\AI_Workspace\Tool_Policy;
use WordPress\AI\Experiments\AI_Workspace\Tool_Selector;
use WordPress\AI\Experiments\MCP_Adapter\Exposure_Overrides;

/**
 * Find_Admin_Screens ability test case.
 *
 * The ability turns a short description of a task into links to the admin
 * screens that handle it. These tests pin the ranking on the queries the
 * Command Palette fallback (#1250) was built around, that a user is only ever
 * sent to a screen they can open, and that every link stays on the admin host.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Abilities\Site\Find_Admin_Screens
 */
class Find_Admin_ScreensTest extends WP_UnitTestCase {

	/**
	 * A capability no default role has, for the filter-added entry.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const CUSTOM_CAP = 'wpai_test_manage_widgets';

	/**
	 * Shared user IDs keyed by role.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, int>
	 */
	private static $user_ids = array();

	/**
	 * The stylesheet active before a test switched themes.
	 *
	 * @since x.x.x
	 *
	 * @var string|null
	 */
	private $original_theme = null;

	/**
	 * Creates the shared users.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_UnitTest_Factory $factory The unit test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$user_ids = array(
			'administrator' => $factory->user->create( array( 'role' => 'administrator' ) ),
			'editor'        => $factory->user->create( array( 'role' => 'editor' ) ),
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

		$this->register_ability();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		remove_all_filters( 'wpai_admin_screens' );

		if ( null !== $this->original_theme && get_stylesheet() !== $this->original_theme ) {
			switch_theme( $this->original_theme );
		}

		remove_filter( 'wp_register_ability_args', array( Exposure_Overrides::class, 'filter_ability_args' ), 100 );

		if ( wp_has_ability( Find_Admin_Screens::ABILITY ) ) {
			wp_unregister_ability( Find_Admin_Screens::ABILITY );
		}

		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Registers the ability inside a faked `wp_abilities_api_init` action.
	 *
	 * @since x.x.x
	 */
	private function register_ability(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Find_Admin_Screens() )->register();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Runs the ability as a user with the given role.
	 *
	 * @since x.x.x
	 *
	 * @param string $role  The role to run as.
	 * @param string $query The query.
	 * @return mixed The ability result.
	 */
	private function find_as( string $role, string $query ) {
		wp_set_current_user( self::$user_ids[ $role ] );

		return wp_get_ability( Find_Admin_Screens::ABILITY )->execute( array( 'query' => $query ) );
	}

	/**
	 * Returns the URLs of a successful result.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $result The ability result.
	 * @return list<string> The URLs, best match first.
	 */
	private function urls( $result ): array {
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'screens', $result );

		return array_values( wp_list_pluck( $result['screens'], 'url' ) );
	}

	/**
	 * "hide my site from google" sends an administrator to Settings, Reading first.
	 *
	 * @since x.x.x
	 */
	public function test_hiding_the_site_from_google_finds_reading_settings_first(): void {
		$result = $this->find_as( 'administrator', 'How do I hide my site from Google?' );
		$urls   = $this->urls( $result );

		$this->assertNotEmpty( $urls );
		$this->assertSame( admin_url( 'options-reading.php' ), $urls[0] );
		$this->assertSame( 'Reading', $result['screens'][0]['title'] );
		$this->assertSame( 'Settings', $result['screens'][0]['section'] );
	}

	/**
	 * "stop spam comments" sends an administrator to Settings, Discussion first.
	 *
	 * @since x.x.x
	 */
	public function test_stopping_spam_comments_finds_discussion_settings_first(): void {
		$urls = $this->urls( $this->find_as( 'administrator', 'stop spam comments' ) );

		$this->assertSame( admin_url( 'options-discussion.php' ), $urls[0] );
	}

	/**
	 * Asking where the timezone is set finds Settings, General first.
	 *
	 * @since x.x.x
	 */
	public function test_changing_the_timezone_finds_general_settings_first(): void {
		$urls = $this->urls( $this->find_as( 'administrator', 'Where do I change the timezone?' ) );

		$this->assertSame( admin_url( 'options-general.php' ), $urls[0] );
	}

	/**
	 * "setting" and "settings" are the same word, so both find Settings, General first for "general setting(s)".
	 *
	 * @since x.x.x
	 */
	public function test_singular_and_plural_settings_match_alike(): void {
		$general = admin_url( 'options-general.php' );
		$plural  = $this->urls( $this->find_as( 'administrator', 'general settings' ) );

		$this->assertSame( $general, $plural[0] );
		$this->assertSame( $plural, $this->urls( $this->find_as( 'administrator', 'general setting' ) ) );
		$this->assertContains( $general, $this->urls( $this->find_as( 'administrator', 'setting' ) ) );
	}

	/**
	 * Stemming is idempotent for every word in the screen map, so a stemmed word always meets its own stem.
	 *
	 * @since x.x.x
	 */
	public function test_stem_is_idempotent_for_every_word_in_the_map(): void {
		$finder = new Find_Admin_Screens();
		$stem   = new \ReflectionMethod( $finder, 'stem' );
		$stem->setAccessible( true );

		$words = array();

		foreach ( require WPAI_PLUGIN_DIR . 'includes/Abilities/Site/admin-screens.php' as $screen ) {
			$text = implode( ' ', array_merge( array( $screen['title'], $screen['section'] ?? '' ), $screen['keywords'] ) );
			$text = strtolower( $text );

			foreach ( (array) preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) as $word ) {
				$words[] = (string) $word;
				$words[] = $word . 's';
			}
		}

		$this->assertNotEmpty( $words );

		foreach ( array_unique( $words ) as $word ) {
			$once = $stem->invoke( $finder, $word );

			$this->assertSame( $once, $stem->invoke( $finder, $once ), $word );
		}
	}

	/**
	 * On a block theme, "customize colors" finds the Site Editor and never the Customizer.
	 *
	 * @since x.x.x
	 */
	public function test_block_theme_offers_the_site_editor_not_the_customizer(): void {
		$this->switch_to( 'twentytwentyfive' );
		$this->assertTrue( wp_is_block_theme() );

		$urls = $this->urls( $this->find_as( 'administrator', 'customize colors' ) );

		$this->assertContains( admin_url( 'site-editor.php' ), $urls );
		$this->assertNotContains( admin_url( 'customize.php' ), $urls );
	}

	/**
	 * On a classic theme, "customize colors" finds the Customizer and never the Site Editor.
	 *
	 * @since x.x.x
	 */
	public function test_classic_theme_offers_the_customizer_not_the_site_editor(): void {
		$this->switch_to( 'twentytwentyone' );
		$this->assertFalse( wp_is_block_theme() );

		$urls = $this->urls( $this->find_as( 'administrator', 'customize colors' ) );

		$this->assertContains( admin_url( 'customize.php' ), $urls );
		$this->assertNotContains( admin_url( 'site-editor.php' ), $urls );
	}

	/**
	 * Switches to a theme for one test, remembering the original for tearDown().
	 *
	 * @since x.x.x
	 *
	 * @param string $stylesheet The theme to activate.
	 */
	private function switch_to( string $stylesheet ): void {
		$this->assertTrue( wp_get_theme( $stylesheet )->exists(), $stylesheet . ' must be installed.' );

		$this->original_theme = get_stylesheet();
		switch_theme( $stylesheet );
	}

	/**
	 * No more than five screens come back.
	 *
	 * @since x.x.x
	 */
	public function test_returns_at_most_five_screens(): void {
		$urls = $this->urls( $this->find_as( 'administrator', 'settings posts pages comments users plugins themes media' ) );

		$this->assertCount( 5, $urls );
	}

	/**
	 * An editor asking for plugins gets no Plugins screen, and no Settings screen either.
	 *
	 * @since x.x.x
	 */
	public function test_editor_asking_for_plugins_gets_no_plugins_or_settings_screen(): void {
		foreach ( array( 'plugins', 'install a plugin', 'change site settings', 'discourage search engines' ) as $query ) {
			foreach ( $this->urls( $this->find_as( 'editor', $query ) ) as $url ) {
				$this->assertStringNotContainsString( 'plugin', $url, $query );
				$this->assertStringNotContainsString( 'options-', $url, $query );
			}
		}

		// The same query does reach the Plugins screen for an administrator.
		$this->assertContains( admin_url( 'plugins.php' ), $this->urls( $this->find_as( 'administrator', 'plugins' ) ) );
	}

	/**
	 * A screen added through `wpai_admin_screens` with its own capability is offered only to a user who has it.
	 *
	 * @since x.x.x
	 */
	public function test_filter_added_screen_appears_only_for_users_with_its_capability(): void {
		add_filter(
			'wpai_admin_screens',
			static function ( array $screens ): array {
				$screens[] = array(
					'title'      => 'Gizmo Dashboard',
					'path'       => 'admin.php?page=gizmo',
					'keywords'   => array( 'gizmo', 'widgets' ),
					'capability' => self::CUSTOM_CAP,
				);

				return $screens;
			}
		);

		$gizmo = admin_url( 'admin.php?page=gizmo' );

		$this->assertNotContains( $gizmo, $this->urls( $this->find_as( 'administrator', 'gizmo' ) ) );

		$editor = get_user_by( 'id', self::$user_ids['editor'] );
		$editor->add_cap( self::CUSTOM_CAP );

		try {
			$this->assertSame( $gizmo, $this->urls( $this->find_as( 'editor', 'gizmo' ) )[0] );
			$this->assertNotContains( $gizmo, $this->urls( $this->find_as( 'administrator', 'gizmo' ) ) );
		} finally {
			$editor->remove_cap( self::CUSTOM_CAP );
		}
	}

	/**
	 * The filter can remove a core screen.
	 *
	 * @since x.x.x
	 */
	public function test_filter_can_remove_a_core_screen(): void {
		add_filter(
			'wpai_admin_screens',
			static function ( array $screens ): array {
				return array_values(
					array_filter(
						$screens,
						static function ( $screen ): bool {
							return 'options-reading.php' !== $screen['path'];
						}
					)
				);
			}
		);

		$this->assertNotContains( admin_url( 'options-reading.php' ), $this->urls( $this->find_as( 'administrator', 'hide my site from google' ) ) );
	}

	/**
	 * Every URL is on the admin host, including for a filter-added entry that names another site.
	 *
	 * @since x.x.x
	 */
	public function test_every_url_is_on_the_admin_host(): void {
		add_filter(
			'wpai_admin_screens',
			static function ( array $screens ): array {
				foreach ( array( 'https://evil.example/steal', '//evil.example/steal', '/\\evil.example/steal', 'javascript:alert(1)' ) as $path ) {
					$screens[] = array(
						'title'      => 'Evil escape',
						'path'       => $path,
						'keywords'   => array( 'escape' ),
						'capability' => 'read',
					);
				}

				return $screens;
			}
		);

		$admin_host = wp_parse_url( admin_url(), PHP_URL_HOST );
		$admin_base = admin_url();

		foreach ( array( 'escape', 'hide my site from google', 'stop spam comments', 'users profile password', 'export import tools', 'theme editor customize' ) as $query ) {
			foreach ( $this->urls( $this->find_as( 'administrator', $query ) ) as $url ) {
				$this->assertSame( $admin_host, wp_parse_url( $url, PHP_URL_HOST ), $url );
				$this->assertStringStartsWith( $admin_base, $url );
				$this->assertStringNotContainsString( 'evil', $url );
			}
		}
	}

	/**
	 * A query made only of stopwords or punctuation finds nothing, as an empty list rather than an error.
	 *
	 * @since x.x.x
	 */
	public function test_stopword_only_query_returns_an_empty_list(): void {
		foreach ( array( 'how do I', '   ', '?!', 'where is the' ) as $query ) {
			$result = $this->find_as( 'administrator', $query );

			$this->assertIsArray( $result, $query );
			$this->assertSame( array(), $result['screens'], $query );
		}
	}

	/**
	 * A visitor who is not logged in is refused.
	 *
	 * @since x.x.x
	 */
	public function test_logged_out_visitor_is_refused(): void {
		wp_set_current_user( 0 );

		$this->assertWPError( wp_get_ability( Find_Admin_Screens::ABILITY )->execute( array( 'query' => 'plugins' ) ) );
	}

	/**
	 * Registration carries a category and the read-only, closed-world annotations.
	 *
	 * @since x.x.x
	 */
	public function test_registration_has_a_category_and_read_only_annotations(): void {
		$ability = wp_get_ability( Find_Admin_Screens::ABILITY );

		$this->assertNotNull( $ability );
		$this->assertSame( WPAI_DEFAULT_ABILITY_CATEGORY, $ability->get_category() );

		$meta = $ability->get_meta();

		$this->assertTrue( $meta['annotations']['readonly'] );
		$this->assertFalse( $meta['annotations']['destructive'] );
		$this->assertTrue( $meta['annotations']['idempotent'] );
		$this->assertFalse( $meta['annotations']['open_world'] );
		$this->assertTrue( $meta['show_in_rest'] );

		$input = $ability->get_input_schema();

		$this->assertSame( array( 'query' ), $input['required'] );
		$this->assertSame( 200, $input['properties']['query']['maxLength'] );
	}

	/**
	 * With policy admission off, as it ships, the ability is declared to the model for an administrator and an editor.
	 *
	 * @since x.x.x
	 */
	public function test_declared_to_the_model_for_administrator_and_editor_with_admission_off(): void {
		$this->assertFalse( ( new Tool_Policy() )->admission_is_enabled(), 'Admission must be off, as it ships.' );

		foreach ( array( 'administrator', 'editor' ) as $role ) {
			wp_set_current_user( self::$user_ids[ $role ] );

			$selector = new Tool_Selector();

			$this->assertArrayHasKey( Find_Admin_Screens::ABILITY, $selector->get_candidates() );
			$this->assertSame( '', $selector->get_candidates()[ Find_Admin_Screens::ABILITY ] );
			$this->assertContains(
				Find_Admin_Screens::ABILITY,
				$selector->get_tool_names( Tool_Selector::SCOPE_SITE ),
				sprintf( 'An %s must be offered the screen finder.', $role )
			);
		}
	}

	/**
	 * Under the MCP Adapter experiment's exposure filter, the ability is listed for MCP.
	 *
	 * @since x.x.x
	 */
	public function test_listed_for_mcp(): void {
		wp_unregister_ability( Find_Admin_Screens::ABILITY );
		delete_option( Exposure_Overrides::OPTION_NAME );
		add_filter( 'wp_register_ability_args', array( Exposure_Overrides::class, 'filter_ability_args' ), 100, 2 );

		$this->register_ability();

		$meta = wp_get_ability( Find_Admin_Screens::ABILITY )->get_meta();

		$this->assertTrue( $meta['mcp']['public'] );
	}
}
