<?php
/**
 * Integration tests for the streaming turn driver.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace\Streaming
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace\Streaming;

use ReflectionMethod;
use WP_UnitTestCase;
use WordPress\AI\Experiments\AI_Workspace\Streaming\Streaming_Turn_Driver;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

/**
 * Streaming_Turn_Driver test case.
 *
 * Only model selection is exercised: everything else on the driver needs a
 * configured provider registry and a live connector.
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
