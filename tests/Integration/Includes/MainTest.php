<?php
/**
 * Integration tests for the Main class.
 *
 * @package WordPress\AI\Tests\Integration\Includes
 */

namespace WordPress\AI\Tests\Integration\Includes;

use WP_UnitTestCase;
use WordPress\AI\Main;



/**
 * Main test case.
 *
 * @covers \WordPress\AI\Main
 * @since 0.8.0
 */
class MainTest extends WP_UnitTestCase {

	public function tearDown(): void {
		$reflection = new \ReflectionClass( Main::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );

		parent::tearDown();
	}

	/**
	 * @since 0.8.0
	 */
	public function test_get_instance() {
		$this->assertInstanceOf( Main::class, Main::get_instance() );

		$this->assertSame( Main::get_instance(), Main::get_instance() );
	}

	/**
	 * @since 0.8.0
	 */
	public function test_feature_registration_and_loading() {
		$main = Main::get_instance();
		$main->initialize_features();

		// If we got here without error, the test passes.
		$this->assertTrue( true );
	}

	/**
	 * @since 0.8.0
	 */
	public function test_bad_feature_does_not_cause_plugin_failure() {
		add_filter(
			'wpai_register_features',
			static function ( $registry ) {
				$registry->register_feature(
					new class() {
						public function get_name() {
							return 'bad-feature';
						}

						public function is_experiment() {
							return false;
						}

						public function initialize() {
							throw new \Exception( 'Initialization failed.' );
						}
					}
				);
			}
		);

		$this->setExpectedIncorrectUsage( Main::class . '::initialize_features' );
		$main = Main::get_instance();
		$main->initialize_features();
	}

	/**
	 * @since 0.8.0
	 */
	public function test_plugin_action_links_prepends_connectors_and_settings() {
		$main   = Main::get_instance();
		$method = new \ReflectionMethod( Main::class, 'plugin_action_links' );
		$links  = $method->invoke( $main, array( 'existing-link' ) );

		$this->assertCount( 3, $links );
		$this->assertStringContainsString( 'options-connectors.php', $links[0] );
		$this->assertStringContainsString( 'options-general.php', $links[1] );
		$this->assertSame( 'existing-link', $links[2] );
	}

	/**
	 * @since 0.8.0
	 */
	public function test_clone_triggers_doing_it_wrong() {
		$this->setExpectedIncorrectUsage( '__clone' );

		clone Main::get_instance();
	}

	/**
	 * @since 0.8.0
	 */
	public function test_wakeup_triggers_doing_it_wrong() {
		$this->setExpectedIncorrectUsage( '__wakeup' );

		unserialize( serialize( Main::get_instance() ) );
	}
}
