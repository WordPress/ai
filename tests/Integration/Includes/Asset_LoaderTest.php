<?php
/**
 * Integration tests for Asset_Loader.
 *
 * @package WordPress\AI\Tests\Integration\Includes
 */

namespace WordPress\AI\Tests\Integration\Includes;

use WP_UnitTestCase;
use WordPress\AI\Asset_Loader;

/**
 * Asset_Loader test case.
 *
 * @since 0.8.0
 */
class Asset_LoaderTest extends WP_UnitTestCase {

	/**
	 * Map of fixture base names to their .asset.php paths.
	 *
	 * Key is also the handle used in enqueue calls.
	 *
	 * @var array<string, string>
	 */
	private $created_files = array();

	/**
	 * {@inheritDoc}
	 */
	public function tearDown(): void {
		$dir = WPAI_PLUGIN_DIR . 'build-scripts/';

		foreach ( $this->created_files as $name => $path ) {
			$handle = 'ai_' . $name;
			wp_deregister_style( $handle );
			wp_dequeue_style( $handle );
			wp_deregister_script( $handle );
			wp_dequeue_script( $handle );

			if ( file_exists( $path ) ) {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}

			$rtl = $dir . $name . '-rtl.css';
			if ( ! file_exists( $rtl ) ) {
				continue;
			}

			unlink( $rtl ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		$this->created_files = array();

		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// enqueue_script()
	// -----------------------------------------------------------------------

	/**
	 * @since 0.8.0
	 */
	public function test_enqueue_script_enqueues_with_full_asset_data(): void {
		$asset_handle   = 'test-image-gen';
		$asset_filename = 'features/image-generation';
		Asset_Loader::enqueue_script( $asset_handle, $asset_filename );

		$this->assertTrue( wp_script_is( 'ai_' . $asset_handle, 'enqueued' ), 'Script should be enqueued with prefix.' );

		$actual_deps = require WPAI_PLUGIN_DIR . 'build-scripts/' . $asset_filename . '.asset.php';
		$this->assertNotEmpty( $actual_deps['version'], 'Asset file should contain a version.' );
		$this->assertNotEmpty( $actual_deps['dependencies'], 'Asset file should contain dependencies.' );

		$registered = wp_scripts()->registered[ 'ai_' . $asset_handle ];
		$this->assertSame( $actual_deps['version'], $registered->ver, 'Registered script version should match asset file.' );
		$this->assertContains( 'wp-element', $registered->deps );
		$this->assertContains( 'wp-components', $registered->deps );
	}

	/**
	 * @since 0.8.0
	 */
	public function test_enqueue_script_bails_when_asset_file_missing(): void {
		$this->setExpectedIncorrectUsage( Asset_Loader::class );

		Asset_Loader::enqueue_script( 'missing', 'nonexistent-script-xyz' );

		$this->assertFalse( wp_script_is( 'ai_missing', 'enqueued' ) );

		ob_start();
		do_action( 'admin_notices' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'is missing and cannot be registered', $output );
		$this->assertStringContainsString( 'nonexistent-script-xyz', $output );
	}

	/**
	 * @since 0.8.0
	 */
	public function test_enqueue_script_bails_when_asset_file_returns_non_array(): void {
		$this->setExpectedIncorrectUsage( Asset_Loader::class );

		$this->create_raw_asset_file( 'bad-script', '<?php return "not an array";' );

		Asset_Loader::enqueue_script( 'bad-script', 'bad-script' );

		$this->assertFalse( wp_script_is( 'ai_bad-script', 'enqueued' ) );

		ob_start();
		do_action( 'admin_notices' );
		$output = ob_get_clean();
		$this->assertStringContainsString( 'is invalid and cannot be registered', $output );
		$this->assertStringContainsString( 'bad-script', $output );
	}

	/**
	 * @since 0.8.0
	 */
	public function test_enqueue_script_falls_back_to_filemtime_when_version_missing(): void {
		$this->create_asset_file(
			'no-ver',
			array( 'dependencies' => array() )
		);

		Asset_Loader::enqueue_script( 'no-ver', 'no-ver' );

		$this->assertTrue( wp_script_is( 'ai_no-ver', 'enqueued' ) );
		$this->assertIsNumeric( wp_scripts()->registered['ai_no-ver']->ver );
	}

	/**
	 * @since 0.8.0
	 */
	public function test_enqueue_script_succeeds_when_asset_file_omits_dependencies(): void {
		$this->create_asset_file(
			'no-deps',
			array( 'version' => '1.0.0' )
		);

		Asset_Loader::enqueue_script( 'no-deps', 'no-deps' );

		$this->assertTrue( wp_script_is( 'ai_no-deps', 'enqueued' ) );
		// wp_set_script_translations() appends wp-i18n to the otherwise empty deps.
		$this->assertSame( array( 'wp-i18n' ), wp_scripts()->registered['ai_no-deps']->deps );
	}

	/**
	 * @since 0.8.0
	 */
	public function test_enqueue_style_enqueues_with_full_asset_data(): void {
		$asset_handle   = 'test-image-gen-style';
		$asset_filename = 'features/image-generation';
		Asset_Loader::enqueue_style( $asset_handle, $asset_filename );

		$this->assertTrue( wp_style_is( 'ai_' . $asset_handle, 'enqueued' ), 'Style should be enqueued with prefix.' );

		$actual_deps = require WPAI_PLUGIN_DIR . 'build-scripts/' . $asset_filename . '.asset.php';
		$registered  = wp_styles()->registered[ 'ai_' . $asset_handle ];

		$this->assertSame( $actual_deps['version'], $registered->ver, 'Registered style version should match asset file.' );
		$this->assertStringContainsString( $asset_filename . '.css', wp_styles()->get_data( 'ai_' . $asset_handle, 'path' ), 'Style path data should be registered.' );
		$this->assertEquals( 'replace', wp_styles()->get_data( 'ai_' . $asset_handle, 'rtl' ), 'RTL context should be set to replace.' );
	}

	/**
	 * @since 0.8.0
	 */
	public function test_enqueue_styles_enqueues_with_rtl_context(): void {
		// Preserve global locale to restore later.
		$locale = $GLOBALS['wp_locale'];

		$asset_handle   = 'test-image-gen-style';
		$asset_filename = 'features/image-generation';

		$GLOBALS['wp_locale']->text_direction = 'rtl';

		Asset_Loader::enqueue_style( $asset_handle, $asset_filename );

		$this->assertStringContainsString( '-rtl.css', wp_styles()->get_data( 'ai_' . $asset_handle, 'path' ) );

		// Restore global locale.
		$GLOBALS['wp_locale'] = $locale;
	}

	/**
	 * @since 0.8.0
	 */
	public function test_enqueue_style_bails_when_asset_file_missing(): void {
		$this->setExpectedIncorrectUsage( Asset_Loader::class );

		Asset_Loader::enqueue_style( 'missing-style', 'nonexistent-style-xyz' );

		$this->assertFalse( wp_style_is( 'ai_missing-style', 'enqueued' ) );

		ob_start();
		do_action( 'admin_notices' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'is missing and cannot be registered', $output );
		$this->assertStringContainsString( 'nonexistent-style-xyz', $output );
	}

	/**
	 * @since 0.8.0
	 */
	public function test_enqueue_style_bails_when_asset_file_returns_non_array(): void {
		$this->setExpectedIncorrectUsage( Asset_Loader::class );

		$this->create_raw_asset_file( 'bad-style', '<?php return 42;' );

		Asset_Loader::enqueue_style( 'bad-style', 'bad-style' );

		$this->assertFalse( wp_style_is( 'ai_bad-style', 'enqueued' ) );

		ob_start();
		do_action( 'admin_notices' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'is invalid and cannot be registered', $output );
		$this->assertStringContainsString( 'bad-style', $output );
	}

	/**
	 * @since 0.8.0
	 */
	public function test_enqueue_style_works_when_rtl_file_missing(): void {
		$this->setExpectedIncorrectUsage( Asset_Loader::class );

		$this->create_asset_file(
			'no-rtl',
			array(
				'dependencies' => array(),
				'version'      => '1.0.0',
			)
		);

		Asset_Loader::enqueue_style( 'no-rtl', 'no-rtl' );

		$this->assertTrue( wp_style_is( 'ai_no-rtl', 'enqueued' ) );
		$this->assertFalse( wp_styles()->get_data( 'ai_no-rtl', 'rtl' ) );
	}

	/**
	 * @since 0.8.0
	 */
	public function test_localize_script_prefixes_correctly(): void {
		$this->create_asset_file(
			'test-localized',
			array(
				'dependencies' => array(),
				'version'      => '1.0.0',
			)
		);

		Asset_Loader::enqueue_script( 'test-localized', 'test-localized' );
		Asset_Loader::localize_script( 'test-localized', 'MyObject', array( 'key' => 'value' ) );

		$extra = wp_scripts()->registered['ai_test-localized']->extra;
		$this->assertArrayHasKey( 'data', $extra );
		$this->assertStringContainsString( 'aiMyObject', $extra['data'] );
		$this->assertStringContainsString( '"key"', $extra['data'] );
		$this->assertStringContainsString( '"value"', $extra['data'] );
	}

	/**
	 * @since 1.0.0
	 */
	public function test_add_global_data_is_output_as_inline_script(): void {
		$this->create_asset_file(
			'test-global-data',
			array(
				'dependencies' => array(),
				'version'      => '1.0.0',
			)
		);

		Asset_Loader::add_global_data( 'ProviderData', array( 'hasProvider' => true, 'connectorsUrl' => 'https://example.com' ) );
		Asset_Loader::enqueue_script( 'test-global-data', 'test-global-data' );

		$registered = wp_scripts()->registered['ai_test-global-data'];
		$before     = $registered->extra['before'] ?? array();

		$inline = implode( "\n", $before );
		$this->assertStringContainsString( 'window.aiProviderData=', $inline );
		$this->assertStringContainsString( '"hasProvider":true', $inline );
		$this->assertStringContainsString( 'connectorsUrl', $inline );
	}

	/**
	 * @since 1.0.0
	 */
	public function test_global_data_is_flushed_after_first_enqueue(): void {
		$this->create_asset_file(
			'test-flush-first',
			array(
				'dependencies' => array(),
				'version'      => '1.0.0',
			)
		);
		$this->create_asset_file(
			'test-flush-second',
			array(
				'dependencies' => array(),
				'version'      => '1.0.0',
			)
		);

		Asset_Loader::add_global_data( 'TestData', array( 'key' => 'value' ) );

		Asset_Loader::enqueue_script( 'test-flush-first', 'test-flush-first' );
		Asset_Loader::enqueue_script( 'test-flush-second', 'test-flush-second' );

		$first_before  = wp_scripts()->registered['ai_test-flush-first']->extra['before'] ?? array();
		$second_before = wp_scripts()->registered['ai_test-flush-second']->extra['before'] ?? array();

		$this->assertStringContainsString( 'window.aiTestData=', implode( "\n", $first_before ) );
		$this->assertEmpty( array_filter( $second_before ), 'Global data should not appear on the second script.' );
	}

	/**
	 * @since 1.0.0
	 */
	public function test_enqueue_script_without_global_data_has_no_inline_script(): void {
		$this->create_asset_file(
			'test-no-global',
			array(
				'dependencies' => array(),
				'version'      => '1.0.0',
			)
		);

		Asset_Loader::enqueue_script( 'test-no-global', 'test-no-global' );

		$before = wp_scripts()->registered['ai_test-no-global']->extra['before'] ?? array();
		$this->assertEmpty( array_filter( $before ), 'No inline script should be added when no global data is set.' );
	}

	/**
	 * @param string $name File name without extension.
	 * @param array  $data Asset data to return.
	 */
	private function create_asset_file( string $name, array $data ): void {
		$dir  = WPAI_PLUGIN_DIR . 'build-scripts/';
		$path = $dir . $name . '.asset.php';

		wp_mkdir_p( $dir );
		file_put_contents( $path, '<?php return ' . var_export( $data, true ) . ';' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->created_files[ $name ] = $path;
	}

	/**
	 * @param string $name    File name without extension.
	 * @param string $content Raw PHP content.
	 */
	private function create_raw_asset_file( string $name, string $content ): void {
		$dir  = WPAI_PLUGIN_DIR . 'build-scripts/';
		$path = $dir . $name . '.asset.php';

		wp_mkdir_p( $dir );
		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->created_files[ $name ] = $path;
	}
}
