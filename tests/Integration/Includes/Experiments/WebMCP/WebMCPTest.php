<?php
/**
 * Integration tests for the WebMCP experiment.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\WebMCP
 */

namespace WordPress\AI\Tests\Integration\Experiments\WebMCP;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Experiments\WebMCP\WebMCP;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * WebMCP experiment test case.
 *
 * @since x.x.x
 */
class WebMCPTest extends WP_UnitTestCase {

	/**
	 * Enables the experiment.
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( 'wpai_feature_webmcp_enabled', true );
	}

	/**
	 * Cleans up.
	 */
	public function tearDown(): void {
		delete_option( 'wpai_feature_webmcp_enabled' );
		remove_all_filters( 'wpai_webmcp_screens' );
		remove_all_filters( 'wpai_webmcp_max_tools' );
		remove_all_actions( 'wpai_register_features' );
		wp_dequeue_script( 'ai_webmcp' );
		wp_deregister_script( 'ai_webmcp' );
		parent::tearDown();
	}

	/**
	 * Skips a test that needs the built script when the build has not run.
	 */
	private function require_built_script(): void {
		if ( file_exists( WPAI_PLUGIN_DIR . 'build-scripts/experiments/webmcp.asset.php' ) ) {
			return;
		}

		$this->markTestSkipped( 'Scripts are not built.' );
	}

	/**
	 * Tests the metadata.
	 */
	public function test_experiment_metadata(): void {
		$experiment = new WebMCP();

		$this->assertSame( 'webmcp', $experiment->get_id() );
		$this->assertSame( 'WebMCP', $experiment->get_label() );
		$this->assertSame( Experiment_Category::EDITOR, $experiment->get_category() );
		$this->assertSame( 'experimental', $experiment->get_stability() );
		$this->assertSame( 'none', $experiment->get_capability() );
	}

	/**
	 * Tests that the experiment is off unless its option is set.
	 */
	public function test_experiment_is_disabled_by_default(): void {
		delete_option( 'wpai_feature_webmcp_enabled' );

		$this->assertFalse( ( new WebMCP() )->is_enabled() );
	}

	/**
	 * Tests that the experiment is on when its option is set.
	 */
	public function test_experiment_is_enabled_when_option_set(): void {
		$this->assertTrue( ( new WebMCP() )->is_enabled() );
	}

	/**
	 * Tests registration through the plugin's loader.
	 */
	public function test_experiment_registers_through_loader(): void {
		$registry = new Registry();
		$loader   = new Loader( $registry );

		add_action(
			'wpai_register_features',
			static function ( $reg ) {
				$reg->register_feature( new WebMCP() );
			}
		);

		$loader->init();

		$this->assertInstanceOf( WebMCP::class, $registry->get_feature( 'webmcp' ) );
	}

	/**
	 * Tests the default screens and the filter.
	 */
	public function test_screens_default_to_the_post_editor_and_are_filterable(): void {
		$experiment = new WebMCP();

		$this->assertSame( array( 'post.php', 'post-new.php' ), $experiment->get_screens() );

		add_filter(
			'wpai_webmcp_screens',
			static function ( array $screens ) {
				$screens[] = 'site-editor.php';
				$screens[] = 42;
				return $screens;
			}
		);

		$this->assertSame( array( 'post.php', 'post-new.php', 'site-editor.php' ), $experiment->get_screens() );
	}

	/**
	 * Tests that a filter returning a non-array keeps the default screens.
	 */
	public function test_screens_fall_back_to_the_default_when_a_filter_returns_a_non_array(): void {
		$experiment = new WebMCP();

		add_filter( 'wpai_webmcp_screens', static fn() => 'post.php' );

		$this->assertSame( array( 'post.php', 'post-new.php' ), $experiment->get_screens() );
	}

	/**
	 * Tests the cap and its filter.
	 */
	public function test_max_tools_default_and_filter(): void {
		$experiment = new WebMCP();

		$this->assertSame( 30, $experiment->get_max_tools() );

		add_filter( 'wpai_webmcp_max_tools', static fn() => 0 );
		$this->assertSame( 1, $experiment->get_max_tools(), 'The cap never drops below one.' );
	}

	/**
	 * Tests that the bridge is not loaded on screens without an editor.
	 */
	public function test_bridge_not_enqueued_on_other_screens(): void {
		$experiment = new WebMCP();
		$experiment->register();
		$experiment->enqueue_assets( 'index.php' );

		$this->assertFalse( wp_script_is( 'ai_webmcp', 'enqueued' ) );
	}

	/**
	 * Tests that the editor screen gets the bridge and its data.
	 *
	 * CI builds the scripts before the PHP tests run, so the asset file exists there.
	 */
	public function test_bridge_enqueued_on_the_editor_with_data(): void {
		$this->require_built_script();

		$experiment = new WebMCP();
		$experiment->register();
		$experiment->enqueue_assets( 'post.php' );

		$this->assertTrue( wp_script_is( 'ai_webmcp', 'enqueued' ) );

		$localized = wp_scripts()->get_data( 'ai_webmcp', 'data' );
		$this->assertIsString( $localized );
		$printed = str_replace( '\\/', '/', $localized );
		$this->assertStringContainsString( 'var aiWebMCP = ', $printed );
		$this->assertStringContainsString( '"screen":"post.php"', $printed );
		$this->assertStringContainsString( '"maxTools":"30"', $printed );
	}

	/**
	 * Tests that the built bridge declares the editor stores it dispatches into.
	 */
	public function test_built_bridge_depends_on_the_editor_stores(): void {
		$this->require_built_script();

		$asset = include WPAI_PLUGIN_DIR . 'build-scripts/experiments/webmcp.asset.php';

		$this->assertContains( 'wp-data', $asset['dependencies'] );
		$this->assertContains( 'wp-blocks', $asset['dependencies'] );
		$this->assertContains( 'wp-hooks', $asset['dependencies'] );
	}
}
