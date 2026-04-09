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
	 * @since x.y.z
	 */
	public function test_are_requirements_met_returns_true_in_test_environment() {
		$this->assertTrue( ( new Requirements() )->are_requirements_met() );
	}

	/**
	 * @since x.y.z
	 */
	public function test_are_requirements_met_returns_false_when_check_fails() {
		$requirements = new Requirements();
		$reflection   = new \ReflectionClass( Requirements::class );

		$checks = $reflection->getMethod( 'get_requirements' );
		$checks->setAccessible( true );

		$property = $reflection->getProperty( 'requirements' );
		$property->setAccessible( true );
		$property->setValue(
			$requirements,
			array(
				'php' => static fn() => 'PHP version check failed.',
			)
		);

		$this->assertFalse( $requirements->are_requirements_met() );

		// Check that the error message is output.
		ob_start();
		do_action( 'admin_notices' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		$message = ob_get_clean();
		$this->assertStringContainsString( 'PHP version check failed.', $message );
	}

	/**
	 * @since x.y.z
	 */
	public function test_are_requirements_met_registers_admin_notice_hooks_on_failure() {
		$requirements = new Requirements();

		$reflection = new \ReflectionClass( Requirements::class );
		$property   = $reflection->getProperty( 'requirements' );
		$property->setAccessible( true );
		$property->setValue(
			$requirements,
			array(
				'php' => static fn() => 'PHP version check failed.',
			)
		);

		$requirements->are_requirements_met();

		$this->assertNotFalse( has_action( 'admin_notices' ) );
		$this->assertNotFalse( has_action( 'network_admin_notices' ) );
	}

	/**
	 * @since x.y.z
	 */
	public function test_individual_requirement_checks_pass_in_test_environment() {
		$requirements = new Requirements();
		$reflection   = new \ReflectionClass( Requirements::class );
		$method       = $reflection->getMethod( 'get_requirements' );
		$method->setAccessible( true );

		$checks = $method->invoke( $requirements );

		$this->assertArrayHasKey( 'php', $checks );
		$this->assertArrayHasKey( 'wp', $checks );
		$this->assertArrayHasKey( 'assets', $checks );

		foreach ( $checks as $slug => $check ) {
			$this->assertTrue( $check['check'](), "Requirement '{$slug}' should pass in the test environment." );
		}
	}

	/**
	 * @since x.y.z
	 */
	public function test_error_messages_return_non_empty_strings() {
		$requirements = new Requirements();
		$reflection   = new \ReflectionClass( Requirements::class );
		$method       = $reflection->getMethod( 'get_requirements' );
		$method->setAccessible( true );

		$checks = $method->invoke( $requirements );

		foreach ( $checks as $slug => $check ) {
			$message = $check['error_message']();
			$this->assertIsString( $message );
			$this->assertNotEmpty( $message, "Requirement '{$slug}' should have a non-empty error message." );
		}
	}

	/**
	 * @since x.y.z
	 */
	public function test_prepare_admin_notice_message_with_single_failure() {
		$requirements = new Requirements();
		$reflection   = new \ReflectionClass( Requirements::class );

		$property = $reflection->getProperty( 'requirements' );
		$property->setAccessible( true );
		$property->setValue(
			$requirements,
			array(
				'php' => static fn() => 'PHP check failed.',
			)
		);

		$method = $reflection->getMethod( 'prepare_admin_notice_message' );
		$method->setAccessible( true );
		$message = $method->invoke( $requirements );

		$this->assertStringContainsString( 'PHP check failed.', $message );
		$this->assertStringNotContainsString( '<ul>', $message, 'Single failure should not produce a list.' );
	}

	/**
	 * @since x.y.z
	 */
	public function test_prepare_admin_notice_message_with_multiple_failures() {
		$requirements = new Requirements();
		$reflection   = new \ReflectionClass( Requirements::class );

		$property = $reflection->getProperty( 'requirements' );
		$property->setAccessible( true );
		$property->setValue(
			$requirements,
			array(
				'php'       => static fn() => 'PHP check failed.',
				'wordpress' => static fn() => 'WordPress check failed.',
			)
		);

		$method = $reflection->getMethod( 'prepare_admin_notice_message' );
		$method->setAccessible( true );
		$message = $method->invoke( $requirements );

		$this->assertStringContainsString( '<ul>', $message );
		$this->assertStringContainsString( 'PHP check failed.', $message );
		$this->assertStringContainsString( 'WordPress check failed.', $message );
	}
}
