<?php
/**
 * Integration tests for caller detection in the vendored secrets context.
 *
 * These lock in the one behaviour that vendoring broke. Upstream's caller detection skips its own
 * frames by matching the `displace-secrets-manager/` plugin directory, which never matches a copy
 * vendored inside a different plugin — so before the fix the nearest frame was always an SDK file
 * and every lookup attributed the call to the host plugin (`ai`), whoever the real caller was.
 *
 * Caller detection is diagnostic metadata, not an authenticated identity: see
 * {@see \WordPress\AI\Vendor\Secrets\Secrets_Context::can_access_namespace()}. These tests assert
 * that it reports the truth, not that it constitutes a boundary against hostile in-process code.
 *
 * @package WordPress\AI\Tests\Integration\Vendor
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Vendor\Secrets;

use WP_UnitTestCase;
use WordPress\AI\Vendor\Secrets\Secrets_Context;

/**
 * Test case for Secrets_Context caller detection.
 *
 * @since 1.3.0
 */
class Secrets_ContextTest extends WP_UnitTestCase {

	use Fixture_Plugin_Trait;

	/**
	 * PHP run inside the fixture plugin: reports how the SDK identifies it.
	 *
	 * @since 1.3.0
	 */
	private const PROBE = <<<'PHP'
use WordPress\AI\Vendor\Secrets\Secrets_Context;

$context = new Secrets_Context( array( 'user_id' => 0, 'is_cli' => false ) );

return array(
	'detected'             => Secrets_Context::detect_calling_plugin(),
	'slug_via_constructor' => $context->get_plugin_slug(),
	'can_access_ai'        => $context->can_access_namespace( 'ai' ),
);
PHP;

	/**
	 * @since 1.3.0
	 */
	public function tearDown(): void {
		$this->delete_fixture_plugin();

		parent::tearDown();
	}

	/**
	 * A caller in another plugin directory must be reported as that plugin, not as the host plugin
	 * whose tree the SDK happens to be vendored into.
	 *
	 * Asserted through the constructor rather than the bare helper on purpose: that is the path
	 * every real caller takes (`Secrets_Manager::check_access()` builds a context per operation),
	 * and it is the path the broken frame-skipping affected. A direct call to the helper was never
	 * affected, because then the caller's own file is already the nearest frame.
	 *
	 * @since 1.3.0
	 */
	public function test_detects_the_calling_plugin_not_the_host_plugin() {
		$result = $this->run_in_fixture_plugin( 'unrelated-fixture-plugin', self::PROBE );

		$this->assertSame( 'unrelated-fixture-plugin', $result['slug_via_constructor'] );
		$this->assertSame( 'unrelated-fixture-plugin', $result['detected'] );
	}

	/**
	 * With no explicit context supplied, a caller from an unrelated plugin does not get
	 * self-namespace access to `ai/`.
	 *
	 * @since 1.3.0
	 */
	public function test_foreign_caller_does_not_get_the_ai_namespace_by_default() {
		$result = $this->run_in_fixture_plugin( 'unrelated-fixture-plugin', self::PROBE );

		$this->assertFalse( $result['can_access_ai'] );
	}

	/**
	 * SDK frames are skipped, but not the real caller above them: a context built from this
	 * plugin's own code still resolves to this plugin's directory, so Secrets_Bridge keeps its
	 * self-namespace access even on the automatic path.
	 *
	 * Cannot distinguish the fixed code from the broken code — for callers inside this plugin both
	 * answer `ai`. It guards the opposite failure: skipping too far and reporting `unknown` or some
	 * unrelated directory.
	 *
	 * @since 1.3.0
	 */
	public function test_detects_this_plugin_when_called_from_plugin_code() {
		$expected = basename( untrailingslashit( WPAI_PLUGIN_DIR ) );

		$context = new Secrets_Context(
			array(
				'user_id' => 0,
				'is_cli'  => false,
			)
		);

		$this->assertSame( $expected, $context->get_plugin_slug() );
		$this->assertSame( $expected, Secrets_Context::detect_calling_plugin() );
	}

	/**
	 * An explicitly supplied `plugin` context is still honoured.
	 *
	 * This is deliberate, not an oversight. Secrets_Bridge passes `[ 'plugin' => 'ai' ]` because
	 * its option filters run in cron, front-end, and REST contexts where no user holds
	 * `manage_secrets` and backtrace detection is unreliable. Removing caller-supplied context
	 * would lock the site out of its own credentials in exactly those contexts without denying a
	 * hostile in-process caller anything, so this test exists to stop a future refactor from
	 * "hardening" it away.
	 *
	 * @since 1.3.0
	 */
	public function test_explicit_context_overrides_detection_and_grants_self_namespace() {
		$context = new Secrets_Context(
			array(
				'plugin'  => 'ai',
				'user_id' => 0,
				'is_cli'  => false,
			)
		);

		$this->assertSame( 'ai', $context->get_plugin_slug() );
		$this->assertTrue( $context->can_access_namespace( 'ai' ) );
	}
}
