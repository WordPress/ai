<?php
/**
 * Integration tests for Activation.
 *
 * @package WordPress\AI\Tests\Integration\Admin
 */

namespace WordPress\AI\Tests\Integration\Admin;

use WP_UnitTestCase;
use WordPress\AI\Admin\Activation;
use WordPress\AI\Experiments\Key_Encryption\Key_Encryption;

/**
 * Activation test case.
 *
 * @covers \WordPress\AI\Admin\Activation
 * @since 0.6.0
 */
class ActivationTest extends WP_UnitTestCase {

	/**
	 * Cleans up options before each test.
	 *
	 * @since 0.6.0
	 */
	public function setUp(): void {
		parent::setUp();

		delete_option( 'wpai_version' );
	}

	/**
	 * Cleans up options after each test.
	 *
	 * @since 0.6.0
	 */
	public function tearDown(): void {
		delete_option( 'wpai_version' );
		delete_option( Key_Encryption::RESUME_MIGRATION_OPTION );

		parent::tearDown();
	}

	/**
	 * Tests that activation_callback() triggers upgrades.
	 *
	 * @since 0.6.0
	 */
	public function test_activation_callback_triggers_upgrades() {
		Activation::activation_callback();

		$this->assertEquals( WPAI_VERSION, get_option( 'wpai_version' ) );
	}

	/**
	 * Tests that activation_callback() flags the current site for key re-encryption.
	 *
	 * @since x.x.x
	 */
	public function test_activation_callback_flags_resume_migration() {
		Activation::activation_callback();

		$this->assertSame( '1', get_option( Key_Encryption::RESUME_MIGRATION_OPTION ) );
	}

	/**
	 * Tests that a network-wide activation flags every site for key re-encryption.
	 *
	 * @group ms-required
	 *
	 * @since x.x.x
	 */
	public function test_network_wide_activation_flags_every_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$second_blog_id = self::factory()->blog->create();

		Activation::activation_callback( true );

		$this->assertSame( '1', get_option( Key_Encryption::RESUME_MIGRATION_OPTION ), 'Main site should be flagged.' );
		$this->assertSame( '1', get_blog_option( $second_blog_id, Key_Encryption::RESUME_MIGRATION_OPTION ), 'Second site should be flagged.' );

		wp_delete_site( get_site( $second_blog_id ) );
	}

	/**
	 * Tests that a single-site activation on multisite flags only the current site.
	 *
	 * @group ms-required
	 *
	 * @since x.x.x
	 */
	public function test_single_site_activation_flags_only_current_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$second_blog_id = self::factory()->blog->create();

		Activation::activation_callback( false );

		$this->assertSame( '1', get_option( Key_Encryption::RESUME_MIGRATION_OPTION ), 'Current site should be flagged.' );
		$this->assertFalse( get_blog_option( $second_blog_id, Key_Encryption::RESUME_MIGRATION_OPTION ), 'Other sites should not be flagged.' );

		wp_delete_site( get_site( $second_blog_id ) );
	}
}
