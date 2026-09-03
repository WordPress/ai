<?php
/**
 * Integration tests for audit attribution in the vendored secrets SDK.
 *
 * The `plugin` entry in an audit context is asserted by the caller, so on its own it would record
 * whatever slug the caller chose. These tests cover the `detected_plugin` entry added alongside it,
 * which lets an audit consumer see the backtrace-derived caller and flag a mismatch.
 *
 * Neither value is an authenticated identity — hostile in-process code can steer both, and would
 * more likely bypass the SDK altogether. This is attribution for well-behaved callers and a signal
 * for auditors, not an enforcement mechanism. See the Key Encryption threat model.
 *
 * @package WordPress\AI\Tests\Integration\Vendor
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Vendor\Secrets;

use WP_UnitTestCase;

/**
 * Test case for Secrets_Audit caller attribution.
 *
 * @since 1.3.0
 */
class Secrets_AuditTest extends WP_UnitTestCase {

	use Fixture_Plugin_Trait;

	/**
	 * PHP run inside the fixture plugin: reads an `ai/` secret while claiming to be the AI plugin,
	 * exactly as the proof-of-concept in the security reports did.
	 *
	 * @since 1.3.0
	 */
	private const SPOOFED_READ = <<<'PHP'
use WordPress\AI\Vendor\Secrets\Secrets;

return Secrets::get( 'ai/fixture_key', array( 'plugin' => 'ai', 'user_id' => 0, 'is_cli' => false ) );
PHP;

	/**
	 * Contexts captured from the audit hooks during a test.
	 *
	 * @since 1.3.0
	 * @var array<int, array<string, mixed>>
	 */
	private array $captured = array();

	/**
	 * @since 1.3.0
	 */
	public function setUp(): void {
		parent::setUp();

		$this->captured = array();

		// Short-circuit the read so these tests need no provider, no keyring, and no stored value:
		// Secrets_Manager::get() logs the operation and returns as soon as this filter answers.
		add_filter( 'secrets_pre_get', array( $this, 'short_circuit_get' ) );
	}

	/**
	 * @since 1.3.0
	 */
	public function tearDown(): void {
		remove_filter( 'secrets_pre_get', array( $this, 'short_circuit_get' ) );
		remove_action( 'secrets_accessed', array( $this, 'capture' ) );
		remove_action( 'secrets_get', array( $this, 'capture' ) );

		$this->delete_fixture_plugin();

		parent::tearDown();
	}

	/**
	 * Supplies a value for any secret read so no provider is required.
	 *
	 * @since 1.3.0
	 *
	 * @return string The stand-in secret value.
	 */
	public function short_circuit_get(): string {
		return 'stand-in-value';
	}

	/**
	 * Records an audit context.
	 *
	 * @since 1.3.0
	 *
	 * @param string               $key     The secret key. Unused.
	 * @param string|array<mixed>  $second  Operation name (`secrets_accessed`) or context.
	 * @param array<string, mixed> $context Context, when the hook passes an operation first.
	 */
	public function capture( string $key, $second, array $context = array() ): void {
		unset( $key );

		$this->captured[] = is_array( $second ) ? $second : $context;
	}

	/**
	 * A forged `plugin` value is still recorded as given — but the audit context also carries the
	 * real calling plugin, so the two can be compared.
	 *
	 * @since 1.3.0
	 */
	public function test_records_the_detected_caller_next_to_a_forged_plugin_value() {
		add_action( 'secrets_accessed', array( $this, 'capture' ), 10, 3 );

		$this->run_in_fixture_plugin( 'unrelated-fixture-plugin', self::SPOOFED_READ );

		$this->assertCount( 1, $this->captured );
		$this->assertSame( 'ai', $this->captured[0]['plugin'], 'The caller-asserted value should be recorded verbatim.' );
		$this->assertSame(
			'unrelated-fixture-plugin',
			$this->captured[0]['detected_plugin'],
			'The audit context should expose the real calling plugin.'
		);
	}

	/**
	 * The operation-specific hook gets the same attribution as the catch-all hook.
	 *
	 * @since 1.3.0
	 */
	public function test_operation_specific_hook_also_receives_the_detected_caller() {
		add_action( 'secrets_get', array( $this, 'capture' ), 10, 2 );

		$this->run_in_fixture_plugin( 'unrelated-fixture-plugin', self::SPOOFED_READ );

		$this->assertCount( 1, $this->captured );
		$this->assertSame( 'unrelated-fixture-plugin', $this->captured[0]['detected_plugin'] );
	}

	/**
	 * A caller that asserts nothing is still attributed.
	 *
	 * Audit contexts are the caller's own array rather than the resolved Secrets_Context, so
	 * `plugin` is absent entirely when the caller supplies no context — meaning these records
	 * previously carried no attribution at all. `detected_plugin` closes that gap.
	 *
	 * @since 1.3.0
	 */
	public function test_detected_caller_is_recorded_when_the_caller_asserts_nothing() {
		add_action( 'secrets_accessed', array( $this, 'capture' ), 10, 3 );

		$body = <<<'PHP'
use WordPress\AI\Vendor\Secrets\Secrets;

return Secrets::get( 'unrelated-fixture-plugin/key', array( 'user_id' => 0, 'is_cli' => false ) );
PHP;

		$this->run_in_fixture_plugin( 'unrelated-fixture-plugin', $body );

		$this->assertCount( 1, $this->captured );
		$this->assertArrayNotHasKey( 'plugin', $this->captured[0] );
		$this->assertSame( 'unrelated-fixture-plugin', $this->captured[0]['detected_plugin'] );
	}
}
