<?php
/**
 * Integration tests for the Site_Health class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Admin
 */

namespace WordPress\AI\Tests\Integration\Includes\Admin;

use WP_UnitTestCase;
use WordPress\AI\Admin\Site_Health;
use WordPress\AI\Settings\Settings_Registration;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Features\Registry;

/**
 * Stub feature used to populate the feature registry during tests.
 */
class Site_Health_Test_Feature extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'site-health-test-feature';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Site Health Test Feature',
			'description' => 'A feature for Site Health testing.',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {}
}

/**
 * Site_Health test case.
 *
 * @since x.x.x
 */
class Site_HealthTest extends WP_UnitTestCase {

	/**
	 * Instance under test.
	 *
	 * @var \WordPress\AI\Admin\Site_Health
	 */
	private Site_Health $site_health;

	/**
	 * {@inheritDoc}
	 */
	public function setUp(): void {
		parent::setUp();

		$this->site_health = new Site_Health();

		// Register test feature settings.
		$registry     = new Registry();
		$registry->register_feature( new Site_Health_Test_Feature() );
		$registration = new Settings_Registration( $registry );
		$registration->register_settings();
	}

	/**
	 * {@inheritDoc}
	 */
	public function tearDown(): void {
		remove_all_filters( 'debug_information' );
		remove_all_filters( 'site_status_tests' );
		remove_all_filters( 'wpai_has_ai_credentials' );
		delete_option( Settings_Registration::GLOBAL_OPTION );
		delete_option( 'wpai_feature_site-health-test-feature_enabled' );
		unregister_setting( Settings_Registration::OPTION_GROUP, Settings_Registration::GLOBAL_OPTION );
		unregister_setting( Settings_Registration::OPTION_GROUP, 'wpai_feature_site-health-test-feature_enabled' );
		unregister_setting( Settings_Registration::OPTION_GROUP, 'wpai_feature_site-health-test-feature_field_developer' );
		parent::tearDown();
	}

	// Hook registration

	/**
	 * Tests that init() registers the debug_information filter.
	 *
	 * @since x.x.x
	 */
	public function test_init_registers_debug_information_filter(): void {
		$this->site_health->init();

		$this->assertNotFalse(
			has_filter( 'debug_information', array( $this->site_health, 'add_debug_information' ) )
		);
	}

	/**
	 * Tests that init() registers the site_status_tests filter.
	 *
	 * @since x.x.x
	 */
	public function test_init_registers_site_status_tests_filter(): void {
		$this->site_health->init();

		$this->assertNotFalse(
			has_filter( 'site_status_tests', array( $this->site_health, 'add_status_tests' ) )
		);
	}

	// Debug information

	/**
	 * Tests that the debug information section contains the expected keys.
	 *
	 * @since x.x.x
	 */
	public function test_add_debug_information_adds_ai_plugin_section(): void {
		$info   = array();
		$result = $this->site_health->add_debug_information( $info );

		$this->assertArrayHasKey( 'ai-plugin', $result );
		$this->assertArrayHasKey( 'label', $result['ai-plugin'] );
		$this->assertArrayHasKey( 'fields', $result['ai-plugin'] );
	}

	/**
	 * Tests that the debug section contains required fields.
	 *
	 * @since x.x.x
	 */
	public function test_debug_information_contains_required_fields(): void {
		$result = $this->site_health->add_debug_information( array() );
		$fields = $result['ai-plugin']['fields'];

		$this->assertArrayHasKey( 'ai_enabled', $fields );
		$this->assertArrayHasKey( 'plugin_version', $fields );
		$this->assertArrayHasKey( 'credentials_configured', $fields );
		$this->assertArrayHasKey( 'configured_providers', $fields );
		$this->assertArrayHasKey( 'enabled_features', $fields );
	}

	/**
	 * Tests that the ai_enabled field reflects the global toggle option.
	 *
	 * @since x.x.x
	 */
	public function test_debug_information_reflects_global_enabled_state(): void {
		update_option( Settings_Registration::GLOBAL_OPTION, true );

		$result = $this->site_health->add_debug_information( array() );
		$fields = $result['ai-plugin']['fields'];

		$this->assertSame( 'yes', $fields['ai_enabled']['debug'] );
	}

	/**
	 * Tests that the debug section shows the correct features-enabled count.
	 *
	 * @since x.x.x
	 */
	public function test_debug_information_counts_enabled_features(): void {
		update_option( 'wpai_feature_site-health-test-feature_enabled', true );

		$result = $this->site_health->add_debug_information( array() );
		$fields = $result['ai-plugin']['fields'];

		$this->assertSame( 1, $fields['enabled_features']['value'] );
	}

	/**
	 * Tests that the debug information does NOT contain any API key or token
	 * values even when they might be present in the environment.
	 *
	 * @since x.x.x
	 */
	public function test_debug_information_does_not_expose_credentials(): void {
		$result      = $this->site_health->add_debug_information( array() );
		$fields      = $result['ai-plugin']['fields'];
		$all_values  = array_column( $fields, 'value' );
		$all_debug   = array_column( $fields, 'debug' );
		$all_strings = array_merge( $all_values, array_filter( $all_debug ) );

		foreach ( $all_strings as $value ) {
			// No field value should contain something that looks like a credential.
			if ( ! is_string( $value ) ) {
				continue;
			}
			$this->assertDoesNotMatchRegularExpression(
				'/sk-[a-zA-Z0-9]{10,}|Bearer [a-zA-Z0-9]{10,}/',
				$value,
				'Debug information must not expose API keys or bearer tokens.'
			);
		}
	}

	// Status tests

	/**
	 * Tests that add_status_tests() injects the credentials test.
	 *
	 * @since x.x.x
	 */
	public function test_add_status_tests_injects_credentials_test(): void {
		$tests  = array( 'direct' => array() );
		$result = $this->site_health->add_status_tests( $tests );

		$this->assertArrayHasKey( 'wpai_credentials', $result['direct'] );
	}

	/**
	 * Tests that the credentials test returns a "good" status when credentials
	 * are configured.
	 *
	 * @since x.x.x
	 */
	public function test_credentials_test_returns_good_when_credentials_present(): void {
		add_filter( 'wpai_has_ai_credentials', '__return_true' );

		$result = $this->site_health->run_credentials_test();

		$this->assertSame( 'good', $result['status'] );
	}

	/**
	 * Tests that the credentials test returns a "recommended" status when no
	 * credentials are configured.
	 *
	 * @since x.x.x
	 */
	public function test_credentials_test_returns_recommended_when_no_credentials(): void {
		add_filter( 'wpai_has_ai_credentials', '__return_false' );

		$result = $this->site_health->run_credentials_test();

		$this->assertSame( 'recommended', $result['status'] );
	}

	/**
	 * Tests that the credentials test result contains the required structure.
	 *
	 * @since x.x.x
	 */
	public function test_credentials_test_result_has_required_structure(): void {
		$result = $this->site_health->run_credentials_test();

		$this->assertArrayHasKey( 'label', $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertArrayHasKey( 'badge', $result );
		$this->assertArrayHasKey( 'description', $result );
		$this->assertArrayHasKey( 'test', $result );
		$this->assertSame( 'wpai_credentials', $result['test'] );
	}

	/**
	 * Tests that the credentials test result badge is labelled "AI".
	 *
	 * @since x.x.x
	 */
	public function test_credentials_test_badge_label_is_ai(): void {
		$result = $this->site_health->run_credentials_test();

		$this->assertSame( 'AI', $result['badge']['label'] );
	}
}

