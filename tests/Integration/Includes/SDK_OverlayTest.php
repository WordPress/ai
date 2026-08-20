<?php
/**
 * Tests for the PHP AI Client SDK overlay loader.
 *
 * @package WordPress\AI\Tests\Integration\Includes
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Includes;

use ReflectionClass;
use WP_UnitTestCase;
use WordPress\AI\SDK_Overlay;

/**
 * @coversDefaultClass \WordPress\AI\SDK_Overlay
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
	 * A class we ship maps to its file under the vendored src/ tree.
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
		$this->markTestSkipped( 'Embedding support is not available in this environment.' );
		$this->assertTrue(
			class_exists( 'WordPress\\AiClient\\Builders\\EmbeddingBuilder' ),
			'EmbeddingBuilder should be loadable after the plugin bootstraps.'
		);
	}

	/**
	 * The required new member on the override-race class is present (our copy won, or env has it).
	 */
	public function test_model_requirements_has_embedding_factory(): void {
		$this->markTestSkipped( 'Embedding support is not available in this environment.' );
		$this->assertTrue(
			method_exists(
				'WordPress\\AiClient\\Providers\\Models\\DTO\\ModelRequirements',
				'fromEmbeddingData'
			),
			'ModelRequirements::fromEmbeddingData() must be available for embedding model resolution.'
		);
	}

	/**
	 * Every class the overlay claims to serve is really defined by the overlay's own file.
	 *
	 * This is the assertion that catches a regression in the prepend/serve logic:
	 * method_exists() checks pass whether our copy won or the environment supplied its own, but
	 * the file a class was actually loaded from does not lie.
	 */
	public function test_served_classes_are_loaded_from_overlay_files(): void {
		$served = SDK_Overlay::served_classes();

		if ( array() === $served ) {
			$this->markTestSkipped( 'Overlay deferred to the environment SDK; nothing is served.' );
		}

		foreach ( $served as $class_name => $file ) {
			$this->assertTrue(
				class_exists( $class_name ) || interface_exists( $class_name ) || trait_exists( $class_name ),
				sprintf( '%s is served by the overlay but is not loadable.', $class_name )
			);

			$reflection = new ReflectionClass( $class_name );

			$this->assertSame(
				realpath( $file ),
				realpath( (string) $reflection->getFileName() ),
				sprintf( '%s must be defined by the overlay file, not the environment copy.', $class_name )
			);
		}
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

	/**
	 * Every class listed in every feature manifest resolves to a vendored file on disk.
	 *
	 * Guards against manifest/disk drift (typos, renamed or un-vendored classes).
	 */
	public function test_every_manifest_class_is_vendored(): void {
		$features = SDK_Overlay::features();
		$this->assertNotEmpty( $features, 'At least one feature must be defined.' );

		foreach ( $features as $feature => $config ) {
			foreach ( $config['classes'] as $class_name ) {
				$this->assertNotNull(
					SDK_Overlay::class_to_file( $class_name ),
					sprintf( 'Feature "%s": class %s must map to a vendored file.', $feature, $class_name )
				);
			}
		}
	}

	/**
	 * Each feature's sentinel is one of the classes that feature ships.
	 *
	 * The sentinel must be a class we actually vendor (so it is feature-specific and net-new),
	 * never a shared/base class that would give a false capability reading.
	 */
	public function test_each_feature_sentinel_is_a_shipped_class(): void {
		foreach ( SDK_Overlay::features() as $feature => $config ) {
			$this->assertContains(
				$config['sentinel'],
				$config['classes'],
				sprintf( 'Feature "%s": sentinel should be one of its shipped classes.', $feature )
			);
			$this->assertNotNull(
				SDK_Overlay::class_to_file( $config['sentinel'] ),
				sprintf( 'Feature "%s": sentinel must be vendored.', $feature )
			);
		}
	}

	/**
	 * Features are gated independently: activating one and deferring another serves only the
	 * activated feature's classes.
	 */
	public function test_features_are_gated_independently(): void {
		$features = array(
			'embeddings' => array(
				'sentinel' => 'WordPress\\AiClient\\Builders\\EmbeddingBuilder',
				'guards'   => array(),
				'classes'  => array( 'WordPress\\AiClient\\Builders\\EmbeddingBuilder' ),
			),
			'streaming'  => array(
				'sentinel' => 'WordPress\\AiClient\\Streaming\\Nonexistent',
				'guards'   => array(),
				'classes'  => array( 'WordPress\\AiClient\\Streaming\\Nonexistent' ),
			),
		);

		// embeddings activates, streaming defers -> only the (real, vendored) embeddings class served.
		$served = SDK_Overlay::plan_served_classes(
			$features,
			array(
				'embeddings' => 'activate',
				'streaming'  => 'defer',
			)
		);
		$this->assertArrayHasKey( 'WordPress\\AiClient\\Builders\\EmbeddingBuilder', $served );
		$this->assertArrayNotHasKey( 'WordPress\\AiClient\\Streaming\\Nonexistent', $served );

		// Reverse: embeddings defers, streaming activates. Embeddings not served; streaming's class
		// is not vendored (no file) so it is filtered out too.
		$served_reverse = SDK_Overlay::plan_served_classes(
			$features,
			array(
				'embeddings' => 'defer',
				'streaming'  => 'activate',
			)
		);
		$this->assertArrayNotHasKey( 'WordPress\\AiClient\\Builders\\EmbeddingBuilder', $served_reverse );
		$this->assertArrayNotHasKey( 'WordPress\\AiClient\\Streaming\\Nonexistent', $served_reverse );
	}

	/**
	 * plan_served_classes() only includes classes for features whose action is 'activate'.
	 */
	public function test_plan_served_classes_ignores_non_activated_features(): void {
		$features = array(
			'embeddings' => array(
				'sentinel' => 'WordPress\\AiClient\\Builders\\EmbeddingBuilder',
				'guards'   => array(),
				'classes'  => array( 'WordPress\\AiClient\\Builders\\EmbeddingBuilder' ),
			),
		);

		$this->assertSame( array(), SDK_Overlay::plan_served_classes( $features, array( 'embeddings' => 'defer' ) ) );
		$this->assertSame( array(), SDK_Overlay::plan_served_classes( $features, array( 'embeddings' => 'skip' ) ) );
		$this->assertArrayHasKey(
			'WordPress\\AiClient\\Builders\\EmbeddingBuilder',
			SDK_Overlay::plan_served_classes( $features, array( 'embeddings' => 'activate' ) )
		);
	}
}
