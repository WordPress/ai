<?php
/**
 * Integration tests for the plugin consuming the `wordpress/abilities` package.
 *
 * Adapts the reference consumption test from the MCP-adapter PoC
 * (WordPress/mcp-adapter #430) to this plugin's Custom Abilities experiment
 * gating: the core-facing abilities register through the vendored package's
 * `Registrar::register_all()` only while the experiment is enabled.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use ReflectionProperty;
use WP_Abilities_Registry;
use WP_Ability_Categories_Registry;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use WP_UnitTest_Factory;
use WordPress\AI\Abstracts\Abstract_Gated_Ability;
use WordPress\AI\Experiments\Custom_Abilities\Custom_Abilities;
use WordPress\Abilities\Registrar;
use WordPress\Abilities\Support\Features;

/**
 * A third-party gated ability, added through the `wpai_gated_abilities`
 * filter, whose ability needs core objects exposed to the Abilities API.
 *
 * @since x.x.x
 */
final class Test_Consumption_Exposure_Gated_Ability extends Abstract_Gated_Ability {
	/**
	 * {@inheritDoc}
	 */
	public function requires_core_object_exposure(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action(
			'wp_abilities_api_init',
			static function (): void {
				if ( wp_has_ability( 'my-plugin/gated-fixture' ) ) {
					return;
				}

				wp_register_ability(
					'my-plugin/gated-fixture',
					array(
						'label'               => 'Third-party gated fixture',
						'description'         => 'Added through the wpai_gated_abilities filter.',
						'category'            => 'content',
						'execute_callback'    => static function () {
							return array( 'ok' => true );
						},
						'permission_callback' => static function (): bool {
							return current_user_can( 'read' );
						},
						'meta'                => array(
							'my-plugin' => true,
						),
					)
				);
			}
		);
	}
}

/**
 * A third-party gated ability, added through the `wpai_gated_abilities`
 * filter, that does not need core-object exposure.
 *
 * @since x.x.x
 */
final class Test_Consumption_Simple_Gated_Ability extends Abstract_Gated_Ability {
	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action(
			'wp_abilities_api_init',
			static function (): void {
				if ( wp_has_ability( 'my-plugin/simple-fixture' ) ) {
					return;
				}

				wp_register_ability(
					'my-plugin/simple-fixture',
					array(
						'label'               => 'Third-party simple fixture',
						'description'         => 'Added through the wpai_gated_abilities filter.',
						'category'            => 'site',
						'execute_callback'    => static function () {
							return array( 'ok' => true );
						},
						'permission_callback' => static function (): bool {
							return current_user_can( 'read' );
						},
						'meta'                => array(
							'my-plugin' => true,
						),
					)
				);
			}
		);
	}
}

/**
 * Consumption test case for the vendored `wordpress/abilities` package.
 *
 * The abilities registry boots exactly once per process and does not expose a
 * reset, so each test drives its own fresh registration window (mirroring the
 * package's own RegistrarTest): the registry singletons and fired-action
 * counters are reset through reflection, the experiment's register() runs in
 * the production-like `init` context, and the boot is triggered through the
 * registry itself. Tests must never leave hooks or registrations of their
 * own behind.
 *
 * @since x.x.x
 */
class WordpressAbilitiesConsumptionTest extends WP_UnitTestCase {

	/**
	 * Every ability the package manifest ships.
	 *
	 * @var list<string>
	 */
	private const PACKAGE_ABILITIES = array(
		'core/content-query',
		'core/content-create',
		'core/content-update',
		'core/content-delete',
		'core/settings-get',
		'core/users-query',
		'wp/media-query',
		'wp/media-upload',
		'wp/media-update',
		'wp/media-delete',
		'wp/settings-update',
		'wp/terms-query',
		'wp/term-create',
		'wp/term-update',
		'wp/term-delete',
		'wp/user-create',
		'wp/user-update',
		'wp/user-delete',
	);

	/**
	 * The plugin-owned abilities the gated registry keeps after the migration.
	 *
	 * @var list<string>
	 */
	private const PLUGIN_GATED_ABILITIES = array(
		'ai/get-post-terms',
		'ai/get-post-details',
	);

	/**
	 * The REST run route prefix for the core abilities routes shipped with the
	 * Abilities API.
	 *
	 * @var string
	 */
	private const ROUTE_PREFIX = '/wp-abilities/v1/abilities';

	/**
	 * The REST server instance for the current test.
	 *
	 * @var WP_REST_Server|null
	 */
	private ?WP_REST_Server $server = null;

	/**
	 * Abilities (and their owners) this test registered and must clean up.
	 *
	 * @var list<string>
	 */
	private array $registered = array();

	/**
	 * Fixture categories registered by the current test.
	 *
	 * @var list<string>
	 */
	private array $categories = array();

	/**
	 * The `doing_it_wrong_run` collector of the current test.
	 *
	 * @var callable|null
	 */
	private $wrong_usage_listener = null;

	/**
	 * The hooks' callback lists at setUp, restored at tearDown.
	 *
	 * The Registrar defers registration onto the abilities actions via
	 * closure hooks that cannot be removed by callback id, so each test
	 * snapshots and restores the two hook queues it exercises, keeping its
	 * queued closures out of the rest of the suite.
	 *
	 * @var array<array<string, array<string, array<string, mixed>>>>
	 */
	private array $hook_snapshots = array();

	/**
	 * Sets up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->snapshot_hooks();
		$this->start_watching_incorrect_usage();
	}

	/**
	 * Tears down test case.
	 *
	 * Unregisters everything the test registered and restores the hook queues
	 * and registration windows it exercised.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		foreach ( self::PACKAGE_ABILITIES as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}

		foreach ( self::PLUGIN_GATED_ABILITIES as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}

		foreach ( $this->registered as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}

		foreach ( $this->categories as $slug ) {
			if ( ! wp_has_ability_category( $slug ) ) {
				continue;
			}

			wp_unregister_ability_category( $slug );
		}

		remove_all_filters( 'wpai_register_wordpress_abilities' );
		remove_all_filters( 'wpai_wordpress_abilities_args' );
		remove_all_filters( 'wpai_gated_abilities' );

		if ( null !== $this->wrong_usage_listener ) {
			remove_filter( 'doing_it_wrong_run', $this->wrong_usage_listener, 10 );
			$this->wrong_usage_listener = null;
			$GLOBALS['__test_wrong_usage'] = array();
		}

		global $wp_rest_server;
		$this->server = null;
		unset( $wp_rest_server, $GLOBALS['__test_wrong_usage'] );

		$this->restore_hooks();

		// A closed window must leave the process reusable: open a fresh one
		// for whichever test class runs next.
		$this->open_fresh_windows();

		wp_set_current_user( 0 );
		delete_option( 'wpai_feature_custom-abilities_enabled' );
		parent::tearDown();
	}

	/*
	 * ---------------------------------------------------------------------
	 * The gating contract.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Tests the experiment enabled registers all 18 package abilities with
	 * provenance meta.
	 *
	 * Samples one `core/*` tier and one `wp/*` tier ability in depth: the
	 * package source, package version, stability tier, and REST visibility
	 * pin the vendored snapshot's meta shape in the plugin.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_enabled_registers_all_package_abilities_with_provenance(): void {
		$this->boot_with_experiment();

		foreach ( self::PACKAGE_ABILITIES as $name ) {
			$this->assertTrue( wp_has_ability( $name ), "$name is not registered." );
		}

		$query = wp_get_ability( 'core/content-query' );
		$this->assertNotNull( $query );
		$this->assertSame( 'content', $query->get_category() );

		$meta = $query->get_meta();
		$this->assertSame( 'wordpress/abilities', $meta['source'] ?? null, 'Package abilities must be stamped with the package source.' );
		$this->assertSame( '0.1.0', $meta['package_version'] ?? null );
		$this->assertSame( 'core', $meta['stability'] ?? null );
		$this->assertTrue( $meta['public'] ?? false );
		// The pinned snapshot ships the REST flag explicitly (package PR #15),
		// so the trio is exposed over REST on every supported WordPress.
		$this->assertTrue( $meta['show_in_rest'] ?? false, 'Pinned snapshot: the trio carries an explicit show_in_rest meta flag.' );

		$upload = wp_get_ability( 'wp/media-upload' );
		$this->assertNotNull( $upload );
		$this->assertSame( 'wordpress/abilities', $upload->get_meta()['source'] ?? null );
		$this->assertSame( 'experimental', $upload->get_meta()['stability'] ?? null );
		$this->assertSame( true, $upload->get_meta()['mcp']['public'] ?? null );
		$this->assertTrue( $upload->get_meta()['show_in_rest'] ?? false );

		$create = wp_get_ability( 'core/content-create' );
		$this->assertNotNull( $create );
		$this->assertSame( 'stable', $create->get_meta()['stability'] ?? null );
	}

	/**
	 * Tests the package abilities are gone when the experiment is disabled.
	 *
	 * Register() is never called by the feature Loader for a disabled
	 * experiment, so nothing of the package can reach the registry: no
	 * abilities, and no hooks of any kind.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_disabled_registers_nothing(): void {
		$this->reset_registrations();
		$this->open_fresh_windows();

		global $wp_current_filter;
		$wp_current_filter[] = 'init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the production feature-loader context.

		try {
			// The feature Loader never calls register() while the experiment
			// is disabled (the gate the documented UX rests on, P3).
			$this->assertFalse( ( new Custom_Abilities() )->is_enabled() );
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->boot_registry();

		foreach ( self::PACKAGE_ABILITIES as $name ) {
			$this->assertFalse( wp_has_ability( $name ), "$name must not register while the experiment is disabled." );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Conflict semantics (#1240/#1251 acceptance criteria).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Tests a pre-registered core/content-query is kept, not overridden.
	 *
	 * A third-party polyfill (or trunk core itself) registering the name
	 * first wins: the Registrar's Gate-2 stand-down — the #1240/#1251
	 * semantics — means the pre-registering copy's meta survives the boot
	 * unchanged, for the first time pinned inside this plugin.
	 *
	 * @since x.x.x
	 */
	public function test_pre_registered_core_ability_is_kept_not_overridden(): void {
		$this->reset_registrations();
		$this->open_fresh_windows();
		$this->register_fixture_category( 'content' );
		$this->register_fixture_category( 'media' );
		$this->register_third_party_core_content_query();

		// The third-party registration booted the registry, so the
		// experiment's Registrar call takes its immediate path — inside the
		// abilities action doing-context, as a registration needs.
		$this->register_experiment_only( true );

		$this->assertTrue( wp_has_ability( 'core/content-query' ) );
		$this->assertSame(
			'inhouse/polyfill',
			wp_get_ability( 'core/content-query' )->get_meta()['source'] ?? null,
			'The pre-registered copy must be kept, not overridden by the package.'
		);

		// Every other package ability still registers around it.
		$others = array_diff( self::PACKAGE_ABILITIES, array( 'core/content-query' ) );
		foreach ( $others as $name ) {
			$this->assertTrue( wp_has_ability( $name ), "$name is not registered." );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Categories.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Tests the package declares the `content` and `media` categories itself,
	 * with no plugin backfill left in the code.
	 *
	 * @since x.x.x
	 */
	public function test_package_declares_its_categories(): void {
		$this->boot_with_experiment();

		$this->assertTrue( wp_has_ability_category( 'content' ), 'The content category must exist without any plugin backfill.' );
		$this->assertTrue( wp_has_ability_category( 'media' ), 'The package-authored media category must be declared by the Registrar.' );
		$this->assertTrue( wp_has_ability_category( 'site' ) );
		$this->assertTrue( wp_has_ability_category( 'user' ) );
	}

	/**
	 * Tests the `wordpress_abilities_declare_categories` opt-out leaves the
	 * requiring abilities loudly unregistered.
	 *
	 * With the declarations declined AND the categories action closed, the
	 * package abilities whose categories never register are rejected loudly
	 * by Gate 8, naming the missing category and the opt-out filter.
	 *
	 * @since x.x.x
	 */
	public function test_category_opt_out_leaves_abilities_loudly_unregistered(): void {
		$this->reset_registrations();
		$this->open_fresh_windows();

		$callback = static function (): bool {
			return false;
		};
		add_filter( 'wordpress_abilities_declare_categories', $callback );

		// The rejections are the test's subject: core refuses the registration
		// (unknown category) and the Registrar's Gate 8 makes its rejection
		// loud, naming the category and the consumer-owned filter.
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::register' );
		$this->setExpectedIncorrectUsage( 'WordPress\Abilities\Registrar::try_register' );

		$this->register_experiment_only();

		try {
			$this->boot_registry();
		} finally {
			remove_filter( 'wordpress_abilities_declare_categories', $callback );
		}

		// Gate 8 rejects the abilities whose categories are only package-declared
		// (on this host: `content` and `media`); abilities whose categories the
		// host itself ships (`site`, `user`) still register.
		foreach ( self::PACKAGE_ABILITIES as $name ) {
			$category = \WordPress\Abilities\Manifest::get( $name )['category'];
			if ( in_array( $category, array( 'content', 'media' ), true ) ) {
				$this->assertFalse( wp_has_ability( $name ), "$name must not register while the package category declarations are opted out." );
			} else {
				$this->assertTrue( wp_has_ability( $name ), "$name's category is host-provided and must register." );
			}
		}

		$messages = implode( "\n", $this->wrong_usage_messages( Registrar::class . '::try_register' ) );
		$this->assertStringContainsStringIgnoringCase( 'core/content-query', $messages, 'The rejection must name an ability.' );
		$this->assertStringContainsStringIgnoringCase( '<code>content</code>', $messages, 'The rejection must name the missing category.' );
		$this->assertStringContainsStringIgnoringCase( 'wordpress_abilities_declare_categories', $messages, 'The rejection must name the consumer-owned opt-out filter.' );
	}

	/*
	 * ---------------------------------------------------------------------
	 * REST parity.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Tests the core abilities REST list route serves the package abilities,
	 * with their provenance meta.
	 *
	 * @since x.x.x
	 */
	public function test_rest_list_route_serves_package_abilities_with_provenance(): void {
		$this->boot_with_experiment();

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$response = $this->rest_dispatch( 'GET', self::ROUTE_PREFIX );

		$this->assertSame( 200, $response->get_status() );
		$names = array_column( $response->get_data(), 'name' );
		foreach ( self::PACKAGE_ABILITIES as $name ) {
			$this->assertContains( $name, $names, "$name must be listed over REST." );
		}

		$query_row = null;
		foreach ( $response->get_data() as $row ) {
			if ( 'core/content-query' === $row['name'] ) {
				$query_row = $row;
			}
		}
		$this->assertNotNull( $query_row );
		$this->assertSame( 'wordpress/abilities', $query_row['meta']['source'] ?? null );
		$this->assertSame( '0.1.0', $query_row['meta']['package_version'] ?? null );
		$this->assertSame( 'core', $query_row['meta']['stability'] ?? null );
	}

	/**
	 * Tests a REST run of `core/content-query` round-trips a post created
	 * through `core/content-create`.
	 *
	 * @since x.x.x
	 */
	public function test_rest_run_roundtrips_content_create_and_query(): void {
		$this->boot_with_experiment();

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$create = $this->rest_dispatch(
			'POST',
			self::ROUTE_PREFIX . '/core/content-create/run',
			array(
				'title.raw'   => 'PoC roundtrip post',
				'content.raw' => 'The body.',
				'type'        => 'post',
				'status'      => 'publish',
			)
		);

		$this->assertSame( 200, $create->get_status(), 'Creating the post over REST failed: ' . wp_json_encode( $create->get_data() ) );

		$created = $create->get_data();
		$post_id = $created['id'] ?? null;
		$this->assertNotEmpty( $post_id, 'The create run must answer the created post.' );

		$run = $this->rest_dispatch(
			'GET',
			self::ROUTE_PREFIX . '/core/content-query/run',
			array(),
			array(
				'where' => array(
					'type' => 'post',
					'slugs' => array( $created['slug'] ?? '' ),
				),
			)
		);

		$this->assertSame( 200, $run->get_status(), 'The query run failed: ' . wp_json_encode( $run->get_data() ) );
		$data = $run->get_data();
		$ids  = array_column( $data['items'] ?? array(), 'id' );
		$this->assertContains( $post_id, $ids, 'The query must find the post the create run made.' );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Filters.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Tests the wpai_register_wordpress_abilities opt-out skips the package
	 * while the plugin's gated abilities still register.
	 *
	 * @since x.x.x
	 */
	public function test_register_filter_opt_out_skips_package_keeps_plugin_abilities(): void {
		$this->reset_registrations();
		$this->open_fresh_windows();

		$callback = static function (): bool {
			return false;
		};
		add_filter( 'wpai_register_wordpress_abilities', $callback );

		try {
			$this->register_experiment_only();
			$this->boot_registry();
		} finally {
			remove_filter( 'wpai_register_wordpress_abilities', $callback );
		}

		foreach ( self::PACKAGE_ABILITIES as $name ) {
			$this->assertFalse( wp_has_ability( $name ), "$name must not register with the package registration filtered off." );
		}

		foreach ( self::PLUGIN_GATED_ABILITIES as $name ) {
			$this->assertTrue( wp_has_ability( $name ), "$name must still register while the package registration is skipped." );
		}
	}

	/**
	 * Tests the wpai_wordpress_abilities_args filter's Registrar selection
	 * policy removes a chosen package ability.
	 *
	 * @since x.x.x
	 */
	public function test_args_filter_removes_a_chosen_ability(): void {
		$this->reset_registrations();
		$this->open_fresh_windows();

		$callback = static function (): array {
			return array(
				'filter' => static function ( string $name, array $entry ): bool {
					return 'wp/media-query' !== $name;
				},
			);
		};
		add_filter( 'wpai_wordpress_abilities_args', $callback );

		try {
			$this->boot_with_experiment();
		} finally {
			remove_filter( 'wpai_wordpress_abilities_args', $callback );
		}

		$this->assertFalse( wp_has_ability( 'wp/media-query' ), 'The filtered-out ability must not register.' );
		$this->assertTrue( wp_has_ability( 'wp/media-upload' ), 'Its siblings must register.' );
		$this->assertTrue( wp_has_ability( 'core/content-query' ), 'The core trio must register.' );
	}

	/**
	 * Tests a third-party gated ability added via wpai_gated_abilities still
	 * registers alongside the package set.
	 *
	 * @since x.x.x
	 */
	public function test_third_party_gated_ability_registers_alongside_the_package(): void {
		$this->reset_registrations();
		$this->open_fresh_windows();

		$callback = static function ( array $classes ): array {
			$classes[] = Test_Consumption_Simple_Gated_Ability::class;
			return $classes;
		};
		add_filter( 'wpai_gated_abilities', $callback );

		try {
			$this->boot_with_experiment();
		} finally {
			remove_filter( 'wpai_gated_abilities', $callback );
		}

		$this->assertTrue( wp_has_ability( 'my-plugin/simple-fixture' ), 'The third-party gated ability must register.' );
		$this->assertSame( true, wp_get_ability( 'my-plugin/simple-fixture' )->get_meta()['my-plugin'] ?? null );
		foreach ( self::PACKAGE_ABILITIES as $name ) {
			$this->assertTrue( wp_has_ability( $name ), "$name is not registered." );
		}
	}

	/**
	 * Tests the core-object-exposure promise for third-party gated abilities
	 * now goes through the package's support polyfills.
	 *
	 * A gated ability reporting `requires_core_object_exposure()` loads the
	 * package's `show_in_abilities` polyfill on hosts that lack the flag —
	 * the migration's replacement for the deleted Show_In_Abilities class.
	 *
	 * @since x.x.x
	 */
	public function test_third_party_exposure_need_loads_the_package_polyfill(): void {
		$this->reset_registrations();
		$this->open_fresh_windows();

		// The polyfill is a process-wide singleton load: only when the host
		// lacks the flag AND it was not already loaded can the +1 be observed.
		$already = Features::is_available( 'show_in_abilities' );
		$before  = $this->count_post_type_args_callbacks();

		$callback = static function ( array $classes ): array {
			$classes[] = Test_Consumption_Exposure_Gated_Ability::class;
			return $classes;
		};
		add_filter( 'wpai_gated_abilities', $callback );

		try {
			$this->boot_with_experiment();
		} finally {
			remove_filter( 'wpai_gated_abilities', $callback );
		}

		$this->assertTrue( wp_has_ability( 'my-plugin/gated-fixture' ), 'The third-party gated ability must register.' );
		$this->assertTrue( Features::is_available( 'show_in_abilities' ), 'The show_in_abilities feature must be available after the exposure.' );

		if ( $already ) {
			// Already satisfied earlier in the process (native 7.2+ host, or a
			// previous boot loaded the polyfill): the count assertion cannot
			// attribute a load, so only availability is pinned here.
			return;
		}

		$this->assertSame(
			$before + 1,
			$this->count_post_type_args_callbacks(),
			'The package show_in_abilities polyfill must load for the third-party gated ability.'
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Idempotence.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Tests repeated experiment registration and a second Registrar call from
	 * a simulated second consumer produce no duplicates and no notices.
	 *
	 * @since x.x.x
	 */
	public function test_repeated_registration_is_idempotent(): void {
		$this->boot_with_experiment();

		$before = count( wp_get_abilities() );

		// The feature Loader runs the experiment again (a later boot), and a
		// second consumer (e.g. the MCP adapter copy) calls the Registrar too.
		$this->register_experiment_only();
		Registrar::register_all();

		$this->assertSame(
			array(),
			$this->wrong_usage_messages(),
			'Re-registration must not trigger any doing-it-wrong notices.'
		);
		$this->assertSame( $before, count( wp_get_abilities() ), 'Re-registration must not duplicate abilities.' );
		$this->assertTrue( wp_has_ability( 'core/content-query' ) );
		$this->assertSame( 'wordpress/abilities', wp_get_ability( 'core/content-query' )->get_meta()['source'] ?? null );

		// A second registrar call for a single name is equally quiet.
		$this->assertTrue( Registrar::register( 'core/content-query' ) );
		$this->assertSame( $before, count( wp_get_abilities() ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Helpers.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Enables the Custom Abilities experiment and runs its registration flow
	 * in a fresh window: experiment gates on, hooks queued, the registry
	 * booted, everything settled.
	 *
	 * @since x.x.x
	 */
	private function boot_with_experiment(): void {
		$this->reset_registrations();
		$this->open_fresh_windows();
		$this->register_experiment_only();
		$this->boot_registry();
	}

	/**
	 * Enables the experiment and calls its register() in the production-like
	 * `init` context (the feature Loader's init:15 window), only.
	 *
	 * The registry is not booted here; the queued hooks register when a boot
	 * fires the actions. With `$immediate`, the context also fakes the
	 * abilities action itself, so the Registrar's post-boot immediate path
	 * may register synchronously (mirroring a boot that already happened).
	 *
	 * @since x.x.x
	 *
	 * @param bool $immediate Whether the abilities action context is faked too.
	 */
	private function register_experiment_only( bool $immediate = false ): void {
		update_option( 'wpai_feature_custom-abilities_enabled', true );

		global $wp_current_filter;
		$wp_current_filter[] = 'init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the production feature-loader context.
		if ( $immediate ) {
			$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The Registrar's immediate path needs the registration context.
		}

		try {
			( new Custom_Abilities() )->register();
		} finally {
			array_pop( $wp_current_filter );
			if ( $immediate ) {
				array_pop( $wp_current_filter );
			}
		}
	}

	/**
	 * Boots the abilities registry, which fires the categories action and then
	 * the abilities action exactly once — the production boot the queued
	 * registrations ride on.
	 *
	 * @since x.x.x
	 */
	private function boot_registry(): void {
		WP_Abilities_Registry::get_instance();
	}

	/**
	 * Resets the Registrar's static registration bookkeeping and the current
	 * test's fixture bookkeeping.
	 *
	 * The Registrar's statics are process-wide: a fresh registration window
	 * needs them cleared, as the package's own RegistrarTest does. Only test
	 * code may reopen a registration window.
	 *
	 * @since x.x.x
	 */
	private function reset_registrations(): void {
		$hooked                  = new ReflectionProperty( Registrar::class, 'hooked' );
		$categories_hooked       = new ReflectionProperty( Registrar::class, 'categories_hooked' );
		$preload_hooked          = new ReflectionProperty( Registrar::class, 'preload_hooked' );
		$registered              = new ReflectionProperty( Registrar::class, 'registered' );
		$deferred                = new ReflectionProperty( Registrar::class, 'deferred' );
		$preloads                = new ReflectionProperty( Registrar::class, 'preloads' );
		$hooked->setValue( null, false );
		$categories_hooked->setValue( null, false );
		$preload_hooked->setValue( null, false );
		$registered->setValue( null, array() );
		$deferred->setValue( null, array() );
		$preloads->setValue( null, array() );

		$this->registered = array();
		$this->categories = array();
	}

	/**
	 * Opens fresh registration windows: registry singletons reset, fired-action
	 * counters cleared.
	 *
	 * Mirrors the package's own RegistrarTest window replay; only test code may
	 * reopen a registration window.
	 *
	 * @since x.x.x
	 */
	private function open_fresh_windows(): void {
		$instance = new ReflectionProperty( WP_Abilities_Registry::class, 'instance' );
		$instance->setValue( null, null );

		$categories_instance = new ReflectionProperty( WP_Ability_Categories_Registry::class, 'instance' );
		$categories_instance->setValue( null, null );

		$GLOBALS['wp_actions']['wp_abilities_api_init']            = 0;
		$GLOBALS['wp_actions']['wp_abilities_api_categories_init'] = 0;
	}

	/**
	 * Snapshots the callback queues of the abilities actions.
	 *
	 * @since x.x.x
	 */
	private function snapshot_hooks(): void {
		$this->hook_snapshots = array();

		foreach ( array( 'wp_abilities_api_init', 'wp_abilities_api_categories_init' ) as $hook ) {
			$this->hook_snapshots[ $hook ] = isset( $GLOBALS['wp_filter'][ $hook ] )
				? $GLOBALS['wp_filter'][ $hook ]->callbacks
				: array();
		}
	}

	/**
	 * Restores the callback queues of the abilities actions.
	 *
	 * The Registrar (and the fixture wrappers) queue registration onto those
	 * actions through closures that cannot be removed by callback id; the
	 * snapshot round-trip keeps the suite's hook queues as it found them.
	 *
	 * @since x.x.x
	 */
	private function restore_hooks(): void {
		foreach ( $this->hook_snapshots as $hook => $callbacks ) {
			if ( ! isset( $GLOBALS['wp_filter'][ $hook ] ) ) {
				continue;
			}

			$GLOBALS['wp_filter'][ $hook ]->callbacks = $callbacks; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test-local hook hygiene.
		}
	}

	/**
	 * Registers a fixture `content` category as a third-party would.
	 *
	 * @since x.x.x
	 *
	 * @param string $slug The category slug.
	 */
	private function register_fixture_category( string $slug ): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the registration context as the Explorer tests do.

		try {
			wp_register_ability_category(
				$slug,
				array(
					'label'       => 'Fixtures',
					'description' => 'A fixture category.',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->categories[] = $slug;
	}

	/**
	 * Registers a fixture `core/content-query` as a third-party polyfill
	 * would, before the package boot.
	 *
	 * @since x.x.x
	 */
	private function register_third_party_core_content_query(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the registration context.

		try {
			wp_register_ability(
				'core/content-query',
				array(
					'label'               => 'Third-party polyfill copy',
					'description'         => 'Registered before the package boot.',
					'category'            => 'content',
					'execute_callback'    => static function () {
						return array( 'items' => array(), 'pagination' => array( 'total' => 0, 'total_pages' => 0 ) );
					},
					'permission_callback' => static function (): bool {
						return is_user_logged_in();
					},
					'meta'                => array(
						'annotations'  => array(
							'readonly'    => true,
							'destructive' => false,
							'idempotent'  => true,
						),
						'public'       => true,
						'show_in_rest' => true,
						'source'       => 'inhouse/polyfill',
					),
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->registered[] = 'core/content-query';
	}

	/**
	 * Counts the callbacks currently attached to `register_post_type_args`.
	 *
	 * The package's show_in_abilities polyfill loads exactly one more closure
	 * onto that filter; a repeat load is a guarded no-op.
	 *
	 * @since x.x.x
	 *
	 * @return int The total number of attached callbacks.
	 */
	private function count_post_type_args_callbacks(): int {
		global $wp_filter;

		if ( ! isset( $wp_filter['register_post_type_args'] ) ) {
			return 0;
		}

		$total = 0;
		foreach ( $wp_filter['register_post_type_args']->callbacks as $priority_callbacks ) {
			$total += count( $priority_callbacks );
		}

		return $total;
	}

	/**
	 * Starts collecting doing-it-wrong traffic for the current test.
	 *
	 * @since x.x.x
	 */
	private function start_watching_incorrect_usage(): void {
		$this->wrong_usage_listener = static function ( string $function_name, string $message ): void {
			$GLOBALS['__test_wrong_usage'][ $function_name ][] = $message;
		};
		add_filter( 'doing_it_wrong_run', $this->wrong_usage_listener, 10, 2 );
	}

	/**
	 * Returns (and clears) the collected doing-it-wrong messages.
	 *
	 * @since x.x.x
	 *
	 * @param string|null $function_name Optional. Only messages from this function.
	 * @return list<string> The collected messages (in trigger order).
	 */
	private function wrong_usage_messages( ?string $function_name = null ): array {
		$all = $GLOBALS['__test_wrong_usage'] ?? array();

		$GLOBALS['__test_wrong_usage'] = array();

		if ( null !== $function_name ) {
			return $all[ $function_name ] ?? array();
		}

		$messages = array();
		foreach ( $all as $batch ) {
			$messages = array_merge( $messages, $batch );
		}

		return $messages;
	}

	/**
	 * Dispatches a REST request against the current REST server.
	 *
	 * Mirrors the existing REST ability tests: dispatch() skips the
	 * authentication pass serve_request() runs, so a current user plus the
	 * route's own permission callback decide. POST run requests carry the
	 * input through a JSON body, the way the run controller reads it
	 * (get_json_params()'s `input`).
	 *
	 * @since x.x.x
	 *
	 * @param string               $method The HTTP method.
	 * @param string               $route  The route.
	 * @param array<string, mixed> $input  The body input for POST run requests.
	 * @param array<string, mixed> $query  The query parameters for GET run requests.
	 * @return \WP_REST_Response The response.
	 */
	private function rest_dispatch( string $method, string $route, array $input = array(), array $query = array() ) {
		if ( null === $this->server ) {
			global $wp_rest_server;
			$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resetting the REST server like the existing REST tests do.
			$this->server   = $wp_rest_server;
			do_action( 'rest_api_init' );
		}

		$request = new WP_REST_Request( $method, $route );
		if ( ! empty( $input ) ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( array( 'input' => $input ) ) );
		}
		if ( ! empty( $query ) ) {
			$request->set_query_params( array( 'input' => $query ) );
		}

		return $this->server->dispatch( $request );
	}
}
