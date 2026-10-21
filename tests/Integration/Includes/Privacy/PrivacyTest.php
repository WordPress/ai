<?php
/**
 * Integration tests for the privacy integration.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Privacy
 */

namespace WordPress\AI\Tests\Integration\Includes\Privacy;

use WP_Privacy_Policy_Content;
use WP_UnitTestCase;
use WordPress\AI\Privacy\Privacy;

/**
 * Privacy test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Privacy\Privacy
 */
class PrivacyTest extends WP_UnitTestCase {

	/**
	 * Loads the admin privacy policy helpers.
	 *
	 * @since x.x.x
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	protected function tearDown(): void {
		$this->reset_policy_content();
		set_current_screen( 'front' );

		parent::tearDown();
	}

	/**
	 * Clears suggested text registered during a test.
	 *
	 * @since x.x.x
	 */
	private function reset_policy_content(): void {
		$property = new \ReflectionProperty( WP_Privacy_Policy_Content::class, 'policy_content' );
		$property->setAccessible( true );
		$property->setValue( null, array() );
	}

	/**
	 * Returns the suggested text registered under the plugin's name.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> Registered policy texts.
	 */
	private function get_registered_policy_texts(): array {
		$property = new \ReflectionProperty( WP_Privacy_Policy_Content::class, 'policy_content' );
		$property->setAccessible( true );

		$texts = array();
		foreach ( (array) $property->getValue() as $entry ) {
			if ( 'AI' !== $entry['plugin_name'] ) {
				continue;
			}

			$texts[] = $entry['policy_text'];
		}

		return $texts;
	}

	/**
	 * Tests that the plugin hooks the suggested text onto admin_init.
	 *
	 * @since x.x.x
	 */
	public function test_plugin_hooks_policy_content_on_admin_init(): void {
		global $wp_filter;

		$found = false;
		foreach ( $wp_filter['admin_init']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( ! is_array( $callback['function'] ) || ! ( $callback['function'][0] instanceof Privacy ) || 'add_privacy_policy_content' !== $callback['function'][1] ) {
					continue;
				}

				$found = true;
			}
		}

		$this->assertTrue( $found, 'The suggested privacy policy text should be added on admin_init.' );
	}

	/**
	 * Tests that the suggested text is registered when admin_init runs.
	 *
	 * @since x.x.x
	 */
	public function test_registers_policy_content_on_admin_init(): void {
		set_current_screen( 'dashboard' );
		$this->reset_policy_content();

		$privacy = new Privacy();
		$privacy->init();

		$this->assertNotFalse( has_action( 'admin_init', array( $privacy, 'add_privacy_policy_content' ) ) );

		$this->run_admin_init_callback_only( $privacy );

		$texts = $this->get_registered_policy_texts();

		$this->assertCount( 1, $texts );
		$this->assertSame( $privacy->get_privacy_policy_content(), $texts[0] );
	}

	/**
	 * Runs Privacy::add_privacy_policy_content() as the admin_init action.
	 *
	 * Other admin_init callbacks are removed for the duration of the call so the
	 * test does not depend on unrelated admin behavior.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Privacy\Privacy $privacy Instance under test.
	 */
	private function run_admin_init_callback_only( Privacy $privacy ): void {
		global $wp_filter;

		$saved = $wp_filter['admin_init'] ?? null;

		$wp_filter['admin_init'] = new \WP_Hook(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		add_action( 'admin_init', array( $privacy, 'add_privacy_policy_content' ) );

		do_action( 'admin_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		if ( null === $saved ) {
			unset( $wp_filter['admin_init'] );
		} else {
			$wp_filter['admin_init'] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	/**
	 * Tests that the suggested text does not depend on which experiments are enabled.
	 *
	 * @since x.x.x
	 */
	public function test_policy_content_is_independent_of_experiment_state(): void {
		$privacy = new Privacy();
		$before  = $privacy->get_privacy_policy_content();

		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_ai-request-logging_enabled', true );
		update_option( 'wpai_feature_comment-moderation_enabled', true );

		$this->assertSame( $before, $privacy->get_privacy_policy_content() );
	}

	/**
	 * Tests the structure of the suggested text.
	 *
	 * @since x.x.x
	 */
	public function test_policy_content_structure(): void {
		$content = ( new Privacy() )->get_privacy_policy_content();

		// Some WordPress versions return `&rarr;` from kses as the literal character, so compare decoded text.
		$text = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );

		$this->assertStringContainsString( 'class="privacy-policy-tutorial"', $content );
		$this->assertStringContainsString( 'Settings → Connectors', $text );
		$this->assertStringContainsString( 'Tools → AI Request Logs', $text );
		$this->assertStringContainsString( 'wpai_request_log_retention_days', $content );
		$this->assertSame( wp_kses_post( $content ), $content );
	}
}
