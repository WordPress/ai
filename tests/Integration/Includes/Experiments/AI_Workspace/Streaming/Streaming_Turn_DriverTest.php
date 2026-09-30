<?php
/**
 * Integration tests for the streaming turn driver.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace\Streaming
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace\Streaming;

use ReflectionMethod;
use WP_UnitTestCase;
use WordPress\AI\Experiments\AI_Workspace\Streaming\Streaming_Exception;
use WordPress\AI\Experiments\AI_Workspace\Streaming\Streaming_Turn_Driver;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

/**
 * Streaming_Turn_Driver test case.
 *
 * Model selection is exercised directly. The provider-availability guard is
 * exercised in a subprocess, because the state that matters — the SDK present, the
 * Anthropic provider plugin absent — cannot be reached inside the test run: other
 * tests in this directory register the provider plugin's autoloader, and once that
 * has happened the class under guard is loadable for the rest of the process.
 * Everything else on the driver needs a configured registry and a live connector.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Experiments\AI_Workspace\Streaming\Streaming_Turn_Driver
 */
class Streaming_Turn_DriverTest extends WP_UnitTestCase {

	/**
	 * Builds candidate model metadata from a list of model IDs.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, string> $ids The model IDs, in registry order.
	 * @return array<int, \WordPress\AiClient\Providers\Models\DTO\ModelMetadata> The candidates.
	 */
	private function candidates( array $ids ): array {
		$candidates = array();

		foreach ( $ids as $id ) {
			$candidates[] = new ModelMetadata( $id, $id, array( CapabilityEnum::textGeneration() ), array() );
		}

		return $candidates;
	}

	/**
	 * Runs the driver's candidate selection.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, string> $ids The candidate model IDs, in registry order.
	 * @return string The selected model ID.
	 */
	private function select( array $ids ): string {
		$method = new ReflectionMethod( Streaming_Turn_Driver::class, 'preferred_candidate' );
		$method->setAccessible( true );

		/** @var \WordPress\AiClient\Providers\Models\DTO\ModelMetadata $selected */
		$selected = $method->invoke( null, $this->candidates( $ids ) );

		return $selected->getId();
	}

	/**
	 * The availability guard falls back instead of fatally autoloading the model.
	 *
	 * The streaming model extends a class that ships in the separate
	 * `ai-provider-for-anthropic` plugin. Any reference PHP has to resolve — a static
	 * call to the model's own `is_available()` included — autoloads the model, which
	 * resolves its parent, which raises an `Error` where that plugin is inactive. That
	 * happens before the driver's own try block, and nothing above it catches, so the
	 * turn dies rather than answering with a buffered request.
	 *
	 * The subprocess reproduces exactly that host: the SDK is present (WordPress ships
	 * it), the provider plugin is not. `create_model()` must exit cleanly with null and
	 * announce the fallback.
	 *
	 * @since x.x.x
	 */
	public function test_create_model_falls_back_when_the_provider_plugin_is_absent(): void {
		$script = sprintf(
			'<?php
namespace WordPress\\AiClient {
	// Stands in for the SDK WordPress core ships, so the driver reaches the check under test.
	class AiClient {}
}

namespace {
	define( \'ABSPATH\', \'/\' );

	$GLOBALS[\'fired\'] = array();

	function do_action( $hook_name, ...$args ) {
		$GLOBALS[\'fired\'][] = $hook_name . \'|\' . $args[0] . \'|\' . $args[1];
	}

	function apply_filters( $hook_name, $value, ...$args ) {
		return $value;
	}

	function esc_html__( $text, $domain = \'default\' ) {
		return $text;
	}

	require %s;

	$driver = new \\WordPress\\AI\\Experiments\\AI_Workspace\\Streaming\\Streaming_Turn_Driver();
	$method = new \\ReflectionMethod( $driver, \'create_model\' );
	$method->setAccessible( true );

	echo null === $method->invoke( $driver ) ? "RESULT:NULL\n" : "RESULT:MODEL\n";

	foreach ( $GLOBALS[\'fired\'] as $line ) {
		echo \'HOOK:\', $line, "\n";
	}
}
',
			var_export( TESTS_REPO_ROOT_DIR . '/includes/autoload.php', true )
		);

		$file = (string) tempnam( sys_get_temp_dir(), 'wpai' );

		file_put_contents( $file, $script ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a throwaway file outside the WordPress install.

		$output = array();
		$status = 0;

		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' 2>&1', $output, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec

		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- a throwaway file outside the WordPress install.

		$printed = implode( "\n", $output );

		$this->assertSame( 0, $status, 'The availability guard must not fatal where the provider plugin is absent. Output: ' . $printed );
		$this->assertStringContainsString( 'RESULT:NULL', $printed );
		$this->assertStringContainsString(
			'HOOK:wpai_workspace_streaming_fallback|' . Streaming_Exception::CODE_TRANSPORT . '|The Anthropic provider plugin does not supply a streamable model.',
			$printed,
			'The fallback must be announced, not swallowed.'
		);
	}

	/**
	 * The preferred model wins over the registry's array order.
	 *
	 * @since x.x.x
	 */
	public function test_preferred_model_wins_over_array_order(): void {
		$this->assertSame(
			'claude-sonnet-5',
			$this->select( array( 'claude-fable-5-1', 'claude-opus-5', 'claude-sonnet-5' ) )
		);
	}

	/**
	 * The preference list is consulted in order, not by the registry's order.
	 *
	 * @since x.x.x
	 */
	public function test_preference_list_order_decides_between_preferred_models(): void {
		$this->assertSame(
			'claude-sonnet-5',
			$this->select( array( 'claude-opus-5', 'claude-sonnet-5' ) )
		);
	}

	/**
	 * An absent preferred model falls through to the next preference.
	 *
	 * @since x.x.x
	 */
	public function test_absent_preferred_model_falls_through_to_the_next(): void {
		$this->assertSame(
			'claude-opus-5',
			$this->select( array( 'claude-fable-5-1', 'claude-opus-5' ) )
		);
	}

	/**
	 * With no preferred model available the first candidate is still used.
	 *
	 * @since x.x.x
	 */
	public function test_no_preferred_model_available_uses_the_first_candidate(): void {
		$this->assertSame(
			'claude-fable-5-1',
			$this->select( array( 'claude-fable-5-1', 'claude-haiku-4-5' ) )
		);
	}

	/**
	 * The preference list is filterable.
	 *
	 * @since x.x.x
	 */
	public function test_preference_list_is_filterable(): void {
		$filter = static function (): array {
			return array( 'claude-haiku-4-5' );
		};

		add_filter( 'wpai_workspace_preferred_streaming_models', $filter );

		$selected = $this->select( array( 'claude-fable-5-1', 'claude-sonnet-5', 'claude-haiku-4-5' ) );

		remove_filter( 'wpai_workspace_preferred_streaming_models', $filter );

		$this->assertSame( 'claude-haiku-4-5', $selected );
	}

	/**
	 * A filter naming only unavailable models falls back to the first candidate.
	 *
	 * @since x.x.x
	 */
	public function test_filtered_preference_naming_unavailable_models_falls_back(): void {
		$filter = static function (): array {
			return array( 'not-a-real-model' );
		};

		add_filter( 'wpai_workspace_preferred_streaming_models', $filter );

		$selected = $this->select( array( 'claude-fable-5-1', 'claude-sonnet-5' ) );

		remove_filter( 'wpai_workspace_preferred_streaming_models', $filter );

		$this->assertSame( 'claude-fable-5-1', $selected );
	}
}
