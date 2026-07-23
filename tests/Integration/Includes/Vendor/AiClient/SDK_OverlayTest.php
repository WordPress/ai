<?php
/**
 * Tests for the PHP AI Client SDK overlay loader.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Vendor\AiClient
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Includes\Vendor\AiClient;

use WP_UnitTestCase;
use WordPress\AI\Vendor\AiClient\SDK_Overlay;

/**
 * @coversDefaultClass \WordPress\AI\Vendor\AiClient\SDK_Overlay
 */
class SDK_OverlayTest extends WP_UnitTestCase {

	/**
	 * Environment already has embeddings -> defer, regardless of conflict.
	 */
	public function test_decide_defers_when_environment_capable(): void {
		$this->assertSame( 'defer', SDK_Overlay::decide( true, false ) );
		$this->assertSame( 'defer', SDK_Overlay::decide( true, true ) );
	}

	/**
	 * Environment lacks embeddings but an old override-race class is already loaded -> skip.
	 */
	public function test_decide_skips_on_conflict(): void {
		$this->assertSame( 'skip', SDK_Overlay::decide( false, true ) );
	}

	/**
	 * Environment lacks embeddings and no conflict -> activate.
	 */
	public function test_decide_activates_when_absent_and_no_conflict(): void {
		$this->assertSame( 'activate', SDK_Overlay::decide( false, false ) );
	}

	/**
	 * A class we ship maps to its file under src/.
	 */
	public function test_class_to_file_maps_shipped_class(): void {
		$file = SDK_Overlay::class_to_file( 'WordPress\\AiClient\\Builders\\EmbeddingBuilder' );
		$this->assertIsString( $file );
		$this->assertStringEndsWith(
			'includes/Vendor/AiClient/src/Builders/EmbeddingBuilder.php',
			str_replace( '\\', '/', (string) $file )
		);
		$this->assertFileExists( (string) $file );
	}

	/**
	 * A WordPress\AiClient class we deliberately do NOT ship returns null (falls through to env).
	 */
	public function test_class_to_file_returns_null_for_unshipped_sdk_class(): void {
		$this->assertNull( SDK_Overlay::class_to_file( 'WordPress\\AiClient\\AiClient' ) );
	}

	/**
	 * A class outside the SDK prefix returns null.
	 */
	public function test_class_to_file_ignores_foreign_prefix(): void {
		$this->assertNull( SDK_Overlay::class_to_file( 'WordPress\\AI\\Main' ) );
	}

	/**
	 * After bootstrap, the sentinel embedding class is loadable (from overlay or environment).
	 */
	public function test_embedding_classes_are_available_after_bootstrap(): void {
		$this->assertTrue(
			class_exists( 'WordPress\\AiClient\\Builders\\EmbeddingBuilder' ),
			'EmbeddingBuilder should be loadable after the plugin bootstraps.'
		);
	}

	/**
	 * The required new member on the override-race class is present (our copy won, or env has it).
	 */
	public function test_model_requirements_has_embedding_factory(): void {
		$this->assertTrue(
			method_exists(
				'WordPress\\AiClient\\Providers\\Models\\DTO\\ModelRequirements',
				'fromEmbeddingData'
			),
			'ModelRequirements::fromEmbeddingData() must be available for embedding model resolution.'
		);
	}

	/**
	 * A non-vendored SDK class still resolves via the environment (fall-through works).
	 */
	public function test_unshipped_sdk_class_still_resolves_from_environment(): void {
		// AiClient is deliberately NOT vendored; if the base SDK is present it must still load.
		if ( ! class_exists( 'WordPress\\AiClient\\AiClient' ) ) {
			$this->markTestSkipped( 'Base PHP AI Client SDK not present in this environment.' );
		}
		$this->assertNull(
			SDK_Overlay::class_to_file( 'WordPress\\AiClient\\AiClient' ),
			'AiClient must be served by the environment, never by the overlay.'
		);
	}
}
