<?php
/**
 * Integration tests for the Requirements class.
 *
 * @package WordPress\AI\Tests\Integration\Includes
 */

namespace WordPress\AI\Tests\Integration\Includes;

use WP_UnitTestCase;
use WordPress\AI\Requirements;

/**
 * Requirements test case.
 *
 * @covers \WordPress\AI\Requirements
 */
class RequirementsTest extends WP_UnitTestCase {

	/**
	 * @since x.x.x
	 */
	public function test_are_requirements_met_returns_true_in_test_environment() {
		$this->assertTrue( ( new Requirements() )->are_requirements_met() );
	}

	/**
	 * @since x.x.x
	 */
	public function test_are_requirements_met_returns_false_when_check_fails() {
		$requirements = new Requirements();
		$reflection   = new \ReflectionClass( Requirements::class );

		$checks = $reflection->getMethod( 'get_requirements' );
		$checks->setAccessible( true );

		// Test a failing requirement check.
		$property = $reflection->getProperty( 'requirements' );
		$property->setAccessible( true );
		$property->setValue(
			$requirements,
			array_merge(
				$property->getValue( $requirements ),
				array(
					'testfail' => static fn() => esc_html__( 'Test check failed.', 'ai' ),
				)
			)
		);

		$this->assertFalse( $requirements->are_requirements_met() );

		// Check that the error message is output.
		ob_start();
		do_action( 'admin_notices' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		$message = ob_get_clean();
		$this->assertStringContainsString( 'Test check failed.', $message );
		$this->assertStringNotContainsString( '<ul>', $message, 'Single failure should not produce a list.' );

		// Check it is also output in network admin notices.
		ob_start();
		do_action( 'network_admin_notices' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		$message = ob_get_clean();
		$this->assertStringContainsString( 'Test check failed.', $message );
		$this->assertStringNotContainsString( '<ul>', $message, 'Single failure should not produce a list.' );
	}

	/**
	 * @since x.x.x
	 */
	public function test_individual_requirements_are_valid() {
		$requirements = new Requirements();
		$reflection   = new \ReflectionClass( Requirements::class );
		$method       = $reflection->getMethod( 'get_requirements' );
		$method->setAccessible( true );

		$checks = $method->invoke( $requirements );

		$this->assertNotEmpty( $checks, 'There should be at least one requirement check defined.' );

		foreach ( $checks as $slug => $check ) {
			$this->assertTrue( $check['check'](), "Requirement '{$slug}' should pass in the test environment." );

			// Manually invoke the error message to test it.
			$message = $check['error_message']();
			$this->assertIsString( $message );
			$this->assertNotEmpty( $message, "Requirement '{$slug}' should have a non-empty error message." );
		}
	}

	/**
	 * Tests that multiple failed requirements render as a list via the admin_notices hook.
	 *
	 * @since x.x.x
	 */
	public function test_admin_notice_outputs_multiple_failure_messages() {
		$requirements = new Requirements();
		$reflection   = new \ReflectionClass( Requirements::class );

		$property = $reflection->getProperty( 'requirements' );
		$property->setAccessible( true );
		$property->setValue(
			$requirements,
			array(
				'php'       => static fn() => esc_html__( 'PHP check failed.', 'ai' ),
				'wordpress' => static fn() => esc_html__( 'WordPress check failed.', 'ai' ),
			)
		);

		$requirements->are_requirements_met();

		ob_start();
		do_action( 'admin_notices' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		$output = ob_get_clean();

		$this->assertStringContainsString( '<ul>', $output );
		$this->assertEquals( 2, substr_count( $output, '<li>' ), 'There should be exactly two list items for the two failed checks.' );
		$this->assertStringContainsString( 'PHP check failed.', $output );
		$this->assertStringContainsString( 'WordPress check failed.', $output );
	}

	/**
	 * Used to mimic translation functions being called before the plugin's text domain is loaded.
	 * This is needed since we're manually invoking admin_notices. and not actually loading the plugin.
	 *
	 * @since x.x.x
	 */
	public function test_error_message_callbacks_are_deferred_until_admin_notice_render() {
		$callback_invoked = false;

		$callback = static function () use ( &$callback_invoked ) {
			$callback_invoked = true;
			return esc_html__( 'Deferred callback was invoked.', 'ai' );
		};

		$requirements = new Requirements();
		$reflection   = new \ReflectionClass( Requirements::class );

		$property = $reflection->getProperty( 'requirements' );
		$property->setAccessible( true );
		$property->setValue(
			$requirements,
			array(
				'test' => $callback,
			)
		);

		$this->assertFalse( $requirements->are_requirements_met() );
		$this->assertFalse( $callback_invoked, 'Callback should not be invoked during are_requirements_met().' );

		ob_start();
		do_action( 'admin_notices' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		$output = ob_get_clean();

		$this->assertTrue( $callback_invoked, 'Callback should be invoked when admin notice is rendered.' );
		$this->assertStringContainsString( 'Deferred callback was invoked.', $output );
	}
}
