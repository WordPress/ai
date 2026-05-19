<?php
/**
 * Integration tests for the Approvals_Store class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Connector_Approval
 */

namespace WordPress\AI\Tests\Integration\Includes\Connector_Approval;

use WP_UnitTestCase;
use WordPress\AI\Connector_Approval\Approvals_Store;

/**
 * Approvals_Store test case.
 *
 * @since 1.0.0
 */
class Approvals_StoreTest extends WP_UnitTestCase {
	/**
	 * Store instance under test.
	 *
	 * @since 1.0.0
	 *
	 * @var \WordPress\AI\Connector_Approval\Approvals_Store
	 */
	private Approvals_Store $store;

	/**
	 * Set up test case.
	 *
	 * @since 1.0.0
	 */
	public function setUp(): void {
		parent::setUp();
		$this->store = new Approvals_Store();
	}

	/**
	 * Tear down test case.
	 *
	 * @since 1.0.0
	 */
	public function tearDown(): void {
		delete_option( Approvals_Store::OPTION_APPROVALS );
		delete_option( Approvals_Store::OPTION_PENDING );
		parent::tearDown();
	}

	/**
	 * Test setting and revoking approval state.
	 *
	 * @since 1.0.0
	 */
	public function test_set_and_revoke_approval() {
		$this->store->set_approval( 'my-plugin/my-plugin.php', 'openai', true );
		$this->assertTrue( $this->store->is_approved( 'my-plugin/my-plugin.php', 'openai' ) );

		$this->store->set_approval( 'my-plugin/my-plugin.php', 'openai', false );
		$this->assertFalse( $this->store->is_approved( 'my-plugin/my-plugin.php', 'openai' ) );
		$this->assertSame( array(), $this->store->get_approvals() );
	}

	/**
	 * Test that pending entries are recorded and attempt count is incremented.
	 *
	 * @since 1.0.0
	 */
	public function test_record_pending_tracks_attempts() {
		$caller = array(
			'type'     => 'plugin',
			'basename' => 'my-plugin/my-plugin.php',
			'name'     => 'My Plugin',
		);

		$this->store->record_pending( $caller, 'openai' );
		$this->store->record_pending( $caller, 'openai' );

		$key     = $this->store->pending_key( 'my-plugin/my-plugin.php', 'openai' );
		$pending = $this->store->get_pending();

		$this->assertArrayHasKey( $key, $pending );
		$this->assertSame( 2, $pending[ $key ]['attempts'] );
		$this->assertGreaterThan( 0, $pending[ $key ]['first_seen'] );
		$this->assertGreaterThan( 0, $pending[ $key ]['last_seen'] );
	}

	/**
	 * Test removing pending entries.
	 *
	 * @since 1.0.0
	 */
	public function test_remove_pending() {
		$caller = array(
			'type'     => 'plugin',
			'basename' => 'my-plugin/my-plugin.php',
			'name'     => 'My Plugin',
		);

		$this->store->record_pending( $caller, 'openai' );
		$key = $this->store->pending_key( 'my-plugin/my-plugin.php', 'openai' );

		$this->assertTrue( $this->store->remove_pending( $key ) );
		$this->assertFalse( $this->store->remove_pending( $key ) );
		$this->assertSame( array(), $this->store->get_pending() );
	}

	/**
	 * Test that pending queue respects the configured hard limit.
	 *
	 * @since 1.0.0
	 */
	public function test_pending_queue_hard_limit() {
		for ( $i = 0; $i < Approvals_Store::PENDING_LIMIT; $i++ ) {
			$this->store->record_pending(
				array(
					'type'     => 'plugin',
					'basename' => "plugin-{$i}/plugin.php",
					'name'     => "Plugin {$i}",
				),
				'openai'
			);
		}

		$this->store->record_pending(
			array(
				'type'     => 'plugin',
				'basename' => 'overflow/overflow.php',
				'name'     => 'Overflow',
			),
			'openai'
		);

		$this->assertCount( Approvals_Store::PENDING_LIMIT, $this->store->get_pending() );
	}

	/**
	 * Test that bare plugin slugs are canonicalized where possible.
	 *
	 * @since 1.0.0
	 */
	public function test_get_approvals_canonicalizes_bare_plugin_slug() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$expected = null;
		foreach ( array_keys( get_plugins() ) as $basename ) {
			if ( str_starts_with( (string) $basename, 'ai/' ) ) {
				$expected = (string) $basename;
				break;
			}
		}

		if ( null === $expected ) {
			$this->markTestSkipped( 'Could not find plugin basename for ai plugin in get_plugins().' );
		}

		update_option(
			Approvals_Store::OPTION_APPROVALS,
			array(
				'ai' => array(
					'openai' => true,
				),
			)
		);

		$approvals = $this->store->get_approvals();
		$this->assertArrayHasKey( $expected, $approvals );
		$this->assertArrayNotHasKey( 'ai', $approvals );
		$this->assertTrue( $approvals[ $expected ]['openai'] );
	}
}
