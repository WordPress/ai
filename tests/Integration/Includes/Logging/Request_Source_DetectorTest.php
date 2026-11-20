<?php
/**
 * Integration tests for Request_Source_Detector.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Logging
 */

namespace WordPress\AI\Tests\Integration\Includes\Logging;

use ReflectionMethod;
use WP_UnitTestCase;
use WordPress\AI\Logging\Request_Source_Detector;

/**
 * Request_Source_Detector test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Logging\Request_Source_Detector
 */
class Request_Source_DetectorTest extends WP_UnitTestCase {

	/**
	 * Filter used to run the detector through the WordPress hook dispatch.
	 *
	 * @var string
	 */
	private const DETECT_FILTER = 'wpai_test_request_source_detect';

	/**
	 * Detector instance under test.
	 *
	 * @var \WordPress\AI\Logging\Request_Source_Detector
	 */
	private Request_Source_Detector $detector;

	/**
	 * Set up test case.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->detector = new Request_Source_Detector();
	}

	/**
	 * Tear down test case.
	 */
	protected function tearDown(): void {
		remove_all_filters( self::DETECT_FILTER );
		parent::tearDown();
	}

	/**
	 * Invokes a private detector method via reflection.
	 *
	 * @param string $method_name Method name.
	 * @param mixed  ...$args     Method arguments.
	 * @return mixed
	 */
	private function invoke( string $method_name, ...$args ) {
		$method = new ReflectionMethod( Request_Source_Detector::class, $method_name );
		$method->setAccessible( true );

		return $method->invoke( $this->detector, ...$args );
	}

	/**
	 * Returns the normalized plugins directory with a trailing slash.
	 *
	 * @return string
	 */
	private function plugins_dir(): string {
		return trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
	}

	/**
	 * Detection skips the logging code and attributes the call to the test file's plugin.
	 */
	public function test_detect_attributes_direct_call_to_calling_plugin(): void {
		if ( 0 !== strpos( wp_normalize_path( __FILE__ ), $this->plugins_dir() ) ) {
			$this->markTestSkipped( 'The plugin is not installed inside WP_PLUGIN_DIR.' );
		}

		$source = $this->detector->detect();

		$this->assertIsArray( $source );
		$this->assertSame( 'plugin', $source['type'] );
		$this->assertSame( 'ai', $source['slug'] );
		$this->assertSame( 'AI', $source['name'] );
		$this->assertStringStartsWith( 'ai/tests/', $source['file'] );
	}

	/**
	 * Without skipping the hook dispatch, a call made through a filter is attributed to core.
	 */
	public function test_detect_attributes_hook_dispatch_to_core_by_default(): void {
		add_filter( self::DETECT_FILTER, array( $this->detector, 'detect' ) );

		$source = apply_filters( 'wpai_test_request_source_detect', false );

		$this->assertIsArray( $source );
		$this->assertSame( 'core', $source['type'] );
		$this->assertSame( 'wordpress', $source['slug'] ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Source slug.
		$this->assertSame( 'wp-includes/class-wp-hook.php', $source['file'] );
	}

	/**
	 * When skipping the hook dispatch, the code that fired the filter is attributed instead.
	 */
	public function test_detect_skips_hook_dispatch_when_requested(): void {
		if ( 0 !== strpos( wp_normalize_path( __FILE__ ), $this->plugins_dir() ) ) {
			$this->markTestSkipped( 'The plugin is not installed inside WP_PLUGIN_DIR.' );
		}

		add_filter( self::DETECT_FILTER, array( $this->detector, 'detect' ) );

		$source = apply_filters( 'wpai_test_request_source_detect', true );

		$this->assertIsArray( $source );
		$this->assertSame( 'plugin', $source['type'] );
		$this->assertSame( 'ai', $source['slug'] );
	}

	/**
	 * Only the hook dispatch files count as hook dispatch.
	 */
	public function test_is_hook_dispatch_file(): void {
		$includes = trailingslashit( wp_normalize_path( ABSPATH . 'wp-includes' ) );

		$this->assertTrue( $this->invoke( 'is_hook_dispatch_file', $includes . 'class-wp-hook.php' ) );
		$this->assertTrue( $this->invoke( 'is_hook_dispatch_file', $includes . 'plugin.php' ) );
		$this->assertFalse( $this->invoke( 'is_hook_dispatch_file', $includes . 'post.php' ) );
	}

	/**
	 * Logging, vendor and core AI client files are infrastructure; plugin code is not.
	 */
	public function test_is_infrastructure_file(): void {
		$logging_dir = trailingslashit( wp_normalize_path( dirname( ( new \ReflectionClass( Request_Source_Detector::class ) )->getFileName() ) ) );

		$this->assertTrue( $this->invoke( 'is_infrastructure_file', $logging_dir . 'Logging_Http_Transporter.php' ) );
		$this->assertTrue( $this->invoke( 'is_infrastructure_file', $this->plugins_dir() . 'some-plugin/vendor/lib/Client.php' ) );
		$this->assertTrue( $this->invoke( 'is_infrastructure_file', wp_normalize_path( ABSPATH . 'wp-includes/php-ai-client/src/Client.php' ) ) );
		$this->assertTrue( $this->invoke( 'is_infrastructure_file', wp_normalize_path( ABSPATH . 'wp-includes/ai-client/class-wp-ai-client.php' ) ) );
		$this->assertFalse( $this->invoke( 'is_infrastructure_file', $this->plugins_dir() . 'some-plugin/some-plugin.php' ) );
	}

	/**
	 * The infrastructure directories include the core AI client directories.
	 */
	public function test_get_infrastructure_dirs_includes_core_ai_client(): void {
		$dirs = $this->invoke( 'get_infrastructure_dirs' );

		$this->assertContains( wp_normalize_path( ABSPATH . 'wp-includes/php-ai-client' ) . '/', $dirs );
		$this->assertContains( wp_normalize_path( ABSPATH . 'wp-includes/ai-client' ) . '/', $dirs );
	}

	/**
	 * The connector plugin slug is the first segment of the plugin file.
	 */
	public function test_resolve_connector_plugin_slug(): void {
		$this->assertSame( 'ai-provider-for-openai', $this->invoke( 'resolve_connector_plugin_slug', array( 'plugin' => array( 'file' => 'ai-provider-for-openai/plugin.php' ) ) ) );
		$this->assertSame( 'single-file.php', $this->invoke( 'resolve_connector_plugin_slug', array( 'plugin' => array( 'file' => 'single-file.php' ) ) ) );
		$this->assertSame( '', $this->invoke( 'resolve_connector_plugin_slug', array() ) );
		$this->assertSame( '', $this->invoke( 'resolve_connector_plugin_slug', array( 'plugin' => array( 'file' => '' ) ) ) );
		$this->assertSame( '', $this->invoke( 'resolve_connector_plugin_slug', array( 'plugin' => array( 'file' => array( 'not-a-string' ) ) ) ) );
	}

	/**
	 * Plugin files are attributed to their plugin directory.
	 */
	public function test_match_request_source_plugin(): void {
		$source = $this->invoke( 'match_request_source', $this->plugins_dir() . 'my-plugin/includes/class-foo.php' );

		$this->assertSame(
			array(
				'type' => 'plugin',
				'slug' => 'my-plugin',
				'name' => 'my-plugin',
				'file' => 'my-plugin/includes/class-foo.php',
			),
			$source
		);
	}

	/**
	 * The AI plugin gets its translated display name.
	 */
	public function test_match_request_source_ai_plugin_uses_display_name(): void {
		$source = $this->invoke( 'match_request_source', $this->plugins_dir() . 'ai/ai.php' );

		$this->assertSame( 'ai', $source['slug'] );
		$this->assertSame( 'AI', $source['name'] );
	}

	/**
	 * Mu-plugin files are attributed to the mu-plugin, without the .php extension.
	 */
	public function test_match_request_source_mu_plugin(): void {
		$mu_dir = trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) );

		$single = $this->invoke( 'match_request_source', $mu_dir . 'site-tweaks.php' );
		$this->assertSame(
			array(
				'type' => 'mu-plugin',
				'slug' => 'site-tweaks',
				'name' => 'site-tweaks',
				'file' => 'site-tweaks.php',
			),
			$single
		);

		$nested = $this->invoke( 'match_request_source', $mu_dir . 'loader/inc/run.php' );
		$this->assertSame( 'loader', $nested['slug'] );
		$this->assertSame( 'loader/inc/run.php', $nested['file'] );
	}

	/**
	 * Theme files use the theme's name when the theme is installed.
	 */
	public function test_match_request_source_installed_theme(): void {
		$theme = wp_get_theme();

		if ( ! $theme->exists() ) {
			$this->markTestSkipped( 'No active theme is installed.' );
		}

		$stylesheet = $theme->get_stylesheet();
		$source     = $this->invoke( 'match_request_source', trailingslashit( wp_normalize_path( get_theme_root() ) ) . $stylesheet . '/functions.php' );

		$this->assertSame( 'theme', $source['type'] );
		$this->assertSame( $stylesheet, $source['slug'] );
		$this->assertSame( $theme->get( 'Name' ), $source['name'] );
		$this->assertSame( $stylesheet . '/functions.php', $source['file'] );
	}

	/**
	 * Theme files fall back to the directory name when the theme is not installed.
	 */
	public function test_match_request_source_unknown_theme_uses_slug(): void {
		$source = $this->invoke( 'match_request_source', trailingslashit( wp_normalize_path( get_theme_root() ) ) . 'wpai-missing-theme/functions.php' );

		$this->assertSame( 'theme', $source['type'] );
		$this->assertSame( 'wpai-missing-theme', $source['slug'] );
		$this->assertSame( 'wpai-missing-theme', $source['name'] );
	}

	/**
	 * Core admin and includes files are attributed to WordPress core.
	 */
	public function test_match_request_source_core(): void {
		$admin = $this->invoke( 'match_request_source', wp_normalize_path( ABSPATH . 'wp-admin/includes/post.php' ) );
		$this->assertSame(
			array(
				'type' => 'core',
				'slug' => 'wordpress',
				'name' => 'WordPress Core',
				'file' => 'wp-admin/includes/post.php',
			),
			$admin
		);

		$includes = $this->invoke( 'match_request_source', wp_normalize_path( ABSPATH . 'wp-includes/post.php' ) );
		$this->assertSame( 'wp-includes/post.php', $includes['file'] );
	}

	/**
	 * Files outside plugins, mu-plugins, themes and core are not attributed.
	 */
	public function test_match_request_source_returns_null_for_unknown_paths(): void {
		$this->assertNull( $this->invoke( 'match_request_source', '/tmp/wpai-elsewhere/script.php' ) );
		$this->assertNull( $this->invoke( 'match_request_source', wp_normalize_path( ABSPATH . 'index.php' ) ) );
	}

	/**
	 * A path that is only the plugins directory itself has no slug.
	 */
	public function test_match_helpers_return_null_without_slug(): void {
		$this->assertNull( $this->invoke( 'match_plugin_source', $this->plugins_dir() ) );
		$this->assertNull( $this->invoke( 'match_mu_plugin_source', trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) ) ) );
		$this->assertNull( $this->invoke( 'match_theme_source', trailingslashit( wp_normalize_path( get_theme_root() ) ) ) );
	}
}
