<?php
/**
 * Integration tests for the Guide_Notices class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Onboarding
 */

namespace WordPress\AI\Tests\Integration\Includes\Onboarding;

use WPDieException;
use WP_Admin_Bar;
use WP_UnitTestCase;
use WordPress\AI\Features\Registry;
use WordPress\AI\Onboarding\Guide_Notices;
use WordPress\AI\Onboarding\Onboarding;

/**
 * Guide_Notices test case.
 *
 * @covers \WordPress\AI\Onboarding\Guide_Notices
 * @since x.x.x
 */
class Guide_NoticesTest extends WP_UnitTestCase {

	/**
	 * Instance under test.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Onboarding\Guide_Notices
	 */
	private Guide_Notices $notices;

	/**
	 * Administrator used by the tests.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->notices  = new Guide_Notices( new Onboarding( new Registry() ) );
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $this->admin_id );
		update_option( Onboarding::OPTION_WELCOME, '1' );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		unset( $_GET['wpai_guide_dismiss'], $_GET['_wpnonce'] );
		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'wpai_has_ai_credentials' );
		remove_all_filters( 'wpai_pre_has_valid_credentials_check' );
		delete_option( Onboarding::OPTION_WELCOME );
		delete_option( Onboarding::OPTION_USE_CASES );
		delete_option( Onboarding::OPTION_COMPLETED );
		set_current_screen( 'front' );

		parent::tearDown();
	}

	/**
	 * Makes the plugin report a connected provider that can generate text.
	 *
	 * @since x.x.x
	 */
	private function connect_provider(): void {
		add_filter( 'wpai_has_ai_credentials', '__return_true' );
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
	}

	/**
	 * Returns the output of the welcome notice.
	 *
	 * @since x.x.x
	 *
	 * @return string The rendered output.
	 */
	private function render_welcome(): string {
		ob_start();
		$this->notices->render_welcome();

		return (string) ob_get_clean();
	}

	/**
	 * Returns the toolbar item added on the current screen, if any.
	 *
	 * @since x.x.x
	 *
	 * @return object|null The toolbar node, or null when none was added.
	 */
	private function get_toolbar_item(): ?object {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';

		$admin_bar = new WP_Admin_Bar();
		$this->notices->add_connectors_toolbar_item( $admin_bar );

		return $admin_bar->get_node( Guide_Notices::TOOLBAR_NODE_ID );
	}

	/**
	 * Tests that the welcome notice shows on the Plugins screen after activation.
	 *
	 * @since x.x.x
	 */
	public function test_welcome_renders_on_plugins_screen_after_activation(): void {
		set_current_screen( 'plugins' );

		$output = $this->render_welcome();

		$this->assertStringContainsString( 'AI is installed.', $output );
		$this->assertStringContainsString( 'options-general.php?page=ai-wp-admin', $output );
		$this->assertStringContainsString( 'wapuu-experimenting.svg', $output );
		$this->assertStringContainsString( 'alt=""', $output );
	}

	/**
	 * Tests that the welcome notice needs the activation flag.
	 *
	 * @since x.x.x
	 */
	public function test_welcome_skips_without_activation_flag(): void {
		delete_option( Onboarding::OPTION_WELCOME );
		set_current_screen( 'plugins' );

		$this->assertSame( '', $this->render_welcome() );
	}

	/**
	 * Tests that the welcome notice only shows on the Plugins screen.
	 *
	 * @since x.x.x
	 */
	public function test_welcome_skips_other_screens(): void {
		set_current_screen( 'dashboard' );

		$this->assertSame( '', $this->render_welcome() );
	}

	/**
	 * Tests that the welcome notice is not shown to users who cannot manage options.
	 *
	 * @since x.x.x
	 */
	public function test_welcome_skips_users_without_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		set_current_screen( 'plugins' );

		$this->assertSame( '', $this->render_welcome() );
	}

	/**
	 * Tests that the welcome notice stops once use cases are chosen.
	 *
	 * @since x.x.x
	 */
	public function test_welcome_skips_once_setup_is_complete(): void {
		update_option( Onboarding::OPTION_COMPLETED, true );
		set_current_screen( 'plugins' );

		$this->assertSame( '', $this->render_welcome() );
	}

	/**
	 * Tests that the Connectors toolbar item points to step two once a provider is connected.
	 *
	 * @since x.x.x
	 */
	public function test_toolbar_item_is_added_once_a_provider_is_connected(): void {
		$this->connect_provider();
		set_current_screen( 'options-connectors' );

		$node = $this->get_toolbar_item();

		$this->assertNotNull( $node );
		$this->assertStringContainsString( 'options-general.php?page=ai-wp-admin', $node->href );
		$this->assertStringContainsString( 'Next: choose what AI helps with', $node->title );
		$this->assertStringContainsString( 'wapuu-working.svg', $node->title );
	}

	/**
	 * Tests that the toolbar item waits until a provider is connected.
	 *
	 * The Connectors screen has its own message about the AI plugin until then.
	 *
	 * @since x.x.x
	 */
	public function test_toolbar_item_skips_before_a_provider_is_connected(): void {
		set_current_screen( 'options-connectors' );

		$this->assertNull( $this->get_toolbar_item() );
	}

	/**
	 * Tests that the toolbar item only shows on the Connectors screen.
	 *
	 * @since x.x.x
	 */
	public function test_toolbar_item_skips_other_screens(): void {
		$this->connect_provider();
		set_current_screen( 'plugins' );

		$this->assertNull( $this->get_toolbar_item() );
	}

	/**
	 * Tests that the toolbar item is not shown once the guide is hidden.
	 *
	 * @since x.x.x
	 */
	public function test_toolbar_item_skips_when_guide_is_hidden(): void {
		$this->connect_provider();
		update_user_meta( $this->admin_id, Onboarding::META_HIDDEN, true );
		set_current_screen( 'options-connectors' );

		$this->assertNull( $this->get_toolbar_item() );
	}

	/**
	 * Tests that the toolbar item stops once use cases are chosen.
	 *
	 * @since x.x.x
	 */
	public function test_toolbar_item_skips_once_setup_is_complete(): void {
		$this->connect_provider();
		update_option( Onboarding::OPTION_COMPLETED, true );
		set_current_screen( 'options-connectors' );

		$this->assertNull( $this->get_toolbar_item() );
	}

	/**
	 * Tests that an invalid nonce does not dismiss anything.
	 *
	 * @since x.x.x
	 */
	public function test_dismiss_ignores_invalid_nonce(): void {
		$_GET['wpai_guide_dismiss'] = '1';
		$_GET['_wpnonce']           = 'invalid-nonce';

		$this->notices->maybe_handle_dismiss();

		$this->assertSame( '', get_user_meta( $this->admin_id, Onboarding::META_WELCOME_DISMISSED, true ) );
	}

	/**
	 * Tests that users who cannot manage options cannot dismiss.
	 *
	 * @since x.x.x
	 */
	public function test_dismiss_ignores_users_without_manage_options(): void {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$_GET['wpai_guide_dismiss'] = '1';
		$_GET['_wpnonce']           = wp_create_nonce( 'wpai_guide_dismiss' );

		$this->notices->maybe_handle_dismiss();

		$this->assertSame( '', get_user_meta( $editor_id, Onboarding::META_WELCOME_DISMISSED, true ) );
	}

	/**
	 * Tests that dismissing the welcome notice stores the choice and hides it.
	 *
	 * @since x.x.x
	 */
	public function test_dismissing_welcome_hides_only_the_welcome_notice(): void {
		$_GET['wpai_guide_dismiss'] = '1';
		$_GET['_wpnonce']           = wp_create_nonce( 'wpai_guide_dismiss' );

		add_filter(
			'wp_redirect',
			static function ( string $location ): string {
				throw new WPDieException( esc_html( $location ) );
			}
		);

		try {
			$this->notices->maybe_handle_dismiss();
			$this->fail( 'Expected wp_safe_redirect to be intercepted.' );
		} catch ( WPDieException $e ) {
			$this->assertStringNotContainsString( 'wpai_guide_dismiss', $e->getMessage() );
		}

		$this->assertSame( '1', get_user_meta( $this->admin_id, Onboarding::META_WELCOME_DISMISSED, true ) );
		$this->assertEmpty( get_user_meta( $this->admin_id, Onboarding::META_HIDDEN, true ) );

		unset( $_GET['wpai_guide_dismiss'], $_GET['_wpnonce'] );

		set_current_screen( 'plugins' );
		$this->assertSame( '', $this->render_welcome() );

		$this->connect_provider();
		set_current_screen( 'options-connectors' );
		$this->assertNotNull( $this->get_toolbar_item(), 'Dismissing the welcome notice should not hide the rest of the guide.' );
	}
}
