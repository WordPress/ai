<?php
/**
 * Tests for the Featured_Connectors class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Connectors
 */

namespace WordPress\AI\Tests\Integration\Includes\Connectors;

use WP_Connector_Registry;
use WP_UnitTestCase;
use WordPress\AI\Connectors\Featured_Connectors;
use function WordPress\AI\get_ai_connectors;

/**
 * Featured_Connectors test case.
 *
 * @since x.x.x
 */
class Featured_ConnectorsTest extends WP_UnitTestCase {

	/**
	 * Skips the test when the connector registry is not available.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		if ( class_exists( WP_Connector_Registry::class ) && function_exists( 'wp_supports_ai' ) && wp_supports_ai() ) {
			return;
		}

		$this->markTestSkipped( 'Connectors with AI support are not available.' );
	}

	/**
	 * Removes test filters.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		remove_all_filters( 'wpai_featured_connectors' );
		parent::tearDown();
	}

	/**
	 * Tests that the default list features Mistral and Vercel AI Gateway.
	 *
	 * @since x.x.x
	 */
	public function test_default_featured_connectors() {
		$connectors = ( new Featured_Connectors() )->get_featured_connectors();

		$this->assertSame( array( 'mistral', 'ai_gateway' ), array_keys( $connectors ) );
		$this->assertSame( 'vercel-ai-gateway-provider/plugin.php', $connectors['ai_gateway']['plugin_file'] );
		$this->assertSame( 'ai-provider-for-mistral/ai-provider-for-mistral.php', $connectors['mistral']['plugin_file'] );
		$this->assertStringEndsWith( 'assets/images/connectors/vercel-ai-gateway.svg', $connectors['ai_gateway']['logo_url'] );
		$this->assertStringEndsWith( 'assets/images/connectors/mistral.svg', $connectors['mistral']['logo_url'] );
	}

	/**
	 * Tests that the bundled logos exist.
	 *
	 * @since x.x.x
	 */
	public function test_bundled_logos_exist() {
		$this->assertFileExists( WPAI_PLUGIN_DIR . 'assets/images/connectors/vercel-ai-gateway.svg' );
		$this->assertFileExists( WPAI_PLUGIN_DIR . 'assets/images/connectors/mistral.svg' );
	}

	/**
	 * Tests that featured connectors are registered as installable AI provider connectors.
	 *
	 * @since x.x.x
	 */
	public function test_register_connectors_adds_placeholders() {
		$registry = new WP_Connector_Registry();

		( new Featured_Connectors() )->register_connectors( $registry );

		$this->assertTrue( $registry->is_registered( 'ai_gateway' ) );
		$this->assertTrue( $registry->is_registered( 'mistral' ) );

		$connector = $registry->get_registered( 'ai_gateway' );

		$this->assertSame( 'Vercel AI Gateway', $connector['name'] );
		$this->assertSame( 'ai_provider', $connector['type'] );
		$this->assertSame( 'vercel-ai-gateway-provider/plugin.php', $connector['plugin']['file'] );
		$this->assertSame( 'api_key', $connector['authentication']['method'] );
		$this->assertSame( 'connectors_ai_ai_gateway_api_key', $connector['authentication']['setting_name'] );
		$this->assertSame( 'AI_GATEWAY_API_KEY', $connector['authentication']['constant_name'] );
		$this->assertSame( 'AI_GATEWAY_API_KEY', $connector['authentication']['env_var_name'] );
		$this->assertNotEmpty( $connector['authentication']['credentials_url'] );
		$this->assertNotEmpty( $connector['logo_url'] );
	}

	/**
	 * Tests that the placeholder reports the plugin as inactive while its provider is not registered.
	 *
	 * @since x.x.x
	 */
	public function test_placeholder_is_inactive_without_provider() {
		$registry = new WP_Connector_Registry();

		( new Featured_Connectors() )->register_connectors( $registry );

		$connector = $registry->get_registered( 'mistral' );

		$this->assertFalse( call_user_func( $connector['plugin']['is_active'] ) );
	}

	/**
	 * Tests that a connector core already registered is left as it is.
	 *
	 * @since x.x.x
	 */
	public function test_register_connectors_skips_registered_connectors() {
		$registry = new WP_Connector_Registry();
		$registry->register(
			'mistral',
			array(
				'name'           => 'Mistral from plugin',
				'type'           => 'ai_provider',
				'authentication' => array( 'method' => 'api_key' ),
			)
		);

		( new Featured_Connectors() )->register_connectors( $registry );

		$this->assertSame( 'Mistral from plugin', $registry->get_registered( 'mistral' )['name'] );
		$this->assertTrue( $registry->is_registered( 'ai_gateway' ) );
	}

	/**
	 * Tests that the filter can remove all featured connectors.
	 *
	 * @since x.x.x
	 */
	public function test_filter_can_remove_all_connectors() {
		add_filter( 'wpai_featured_connectors', '__return_empty_array' );

		$registry = new WP_Connector_Registry();
		( new Featured_Connectors() )->register_connectors( $registry );

		$this->assertFalse( $registry->is_registered( 'ai_gateway' ) );
		$this->assertFalse( $registry->is_registered( 'mistral' ) );
	}

	/**
	 * Tests that the filter can add a connector and that invalid entries are skipped.
	 *
	 * @since x.x.x
	 */
	public function test_filter_can_add_connectors_and_skips_invalid_entries() {
		$this->setExpectedIncorrectUsage( Featured_Connectors::class . '::get_featured_connectors' );

		add_filter(
			'wpai_featured_connectors',
			static function ( array $connectors ): array {
				$connectors['host-provider'] = array(
					'name'        => 'Host Provider',
					'plugin_file' => 'host-provider/host-provider.php',
				);
				$connectors['Bad ID']        = array(
					'name'        => 'Bad ID',
					'plugin_file' => 'bad/bad.php',
				);
				$connectors['no_name']       = array(
					'plugin_file' => 'no-name/no-name.php',
				);
				$connectors['no_file']       = array(
					'name' => 'No file',
				);
				$connectors['not_php']       = array(
					'name'        => 'Not PHP',
					'plugin_file' => 'not-php/readme.txt',
				);
				$connectors['not_array']     = 'not-array';
				$connectors[]                = array(
					'name'        => 'Numeric key',
					'plugin_file' => 'numeric/numeric.php',
				);

				return $connectors;
			}
		);

		$connectors = ( new Featured_Connectors() )->get_featured_connectors();

		$this->assertSame( array( 'mistral', 'ai_gateway', 'host-provider' ), array_keys( $connectors ) );
		$this->assertSame( '', $connectors['host-provider']['description'] );

		$registry = new WP_Connector_Registry();
		( new Featured_Connectors() )->register_connectors( $registry );

		$connector = $registry->get_registered( 'host-provider' );

		$this->assertSame( 'connectors_ai_host_provider_api_key', $connector['authentication']['setting_name'] );
		$this->assertSame( 'HOST_PROVIDER_API_KEY', $connector['authentication']['constant_name'] );
		$this->assertArrayNotHasKey( 'logo_url', $connector );
	}

	/**
	 * Tests that a non-array filter result features nothing.
	 *
	 * @since x.x.x
	 */
	public function test_non_array_filter_result_features_nothing() {
		$this->setExpectedIncorrectUsage( Featured_Connectors::class . '::get_featured_connectors' );

		add_filter( 'wpai_featured_connectors', '__return_false' );

		$this->assertSame( array(), ( new Featured_Connectors() )->get_featured_connectors() );
	}

	/**
	 * Tests that the API key names match the ones core gives its default AI providers.
	 *
	 * @since x.x.x
	 */
	public function test_api_key_names_match_core() {
		foreach ( array( 'anthropic', 'google', 'openai' ) as $connector_id ) {
			$connector = wp_get_connector( $connector_id );

			if ( null === $connector ) {
				continue;
			}

			$names = Featured_Connectors::get_api_key_names( $connector_id );

			$this->assertSame( $connector['authentication']['setting_name'], $names['setting_name'] );
			$this->assertSame( $connector['authentication']['constant_name'], $names['constant_name'] );
			$this->assertSame( $connector['authentication']['env_var_name'], $names['env_var_name'] );
		}
	}

	/**
	 * Tests that the Connectors screen data is sorted by connector name.
	 *
	 * @since x.x.x
	 */
	public function test_sort_connectors_by_name() {
		$data = array(
			'connectors' => array(
				'ai_gateway' => array( 'name' => 'Vercel AI Gateway' ),
				'anthropic'  => array( 'name' => 'Anthropic' ),
				'google'     => array( 'name' => 'Google' ),
				'mistral'    => array( 'name' => 'Mistral' ),
				'openai'     => array( 'name' => 'OpenAI' ),
				'no_name'    => array(),
			),
			'other'      => true,
		);

		$sorted = ( new Featured_Connectors() )->sort_connectors_by_name( $data );

		$this->assertSame( array( 'anthropic', 'google', 'mistral', 'no_name', 'openai', 'ai_gateway' ), array_keys( $sorted['connectors'] ) );
		$this->assertTrue( $sorted['other'] );
		$this->assertSame( array( 'other' => true ), ( new Featured_Connectors() )->sort_connectors_by_name( array( 'other' => true ) ) );
	}

	/**
	 * Tests that the Connectors screen sorts the live connectors by name.
	 *
	 * @since x.x.x
	 */
	public function test_connectors_screen_data_is_sorted_by_name() {
		$data  = apply_filters( 'script_module_data_options-connectors-wp-admin', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- Core hook.
		$names = array_column( $data['connectors'], 'name' );

		$sorted = $names;
		usort( $sorted, 'strnatcasecmp' );

		$this->assertSame( $sorted, $names );
		$this->assertSame( 'Vercel AI Gateway', end( $names ) );
	}

	/**
	 * Tests that the featured connectors are registered on the live registry.
	 *
	 * @since x.x.x
	 */
	public function test_featured_connectors_registered_on_init() {
		$this->assertTrue( wp_is_connector_registered( 'ai_gateway' ) );
		$this->assertTrue( wp_is_connector_registered( 'mistral' ) );
	}

	/**
	 * Tests that placeholders are not counted as usable AI connectors.
	 *
	 * @since x.x.x
	 */
	public function test_placeholders_are_not_active_ai_connectors() {
		$active = get_ai_connectors();

		$this->assertArrayNotHasKey( 'ai_gateway', $active );
		$this->assertArrayNotHasKey( 'mistral', $active );
		$this->assertArrayHasKey( 'ai_gateway', get_ai_connectors( false ) );
	}
}
