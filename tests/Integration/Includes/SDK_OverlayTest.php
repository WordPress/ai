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
		$this->assertTrue(
			class_exists( 'WordPress\\AiClient\\Builders\\EmbeddingBuilder' ),
			'EmbeddingBuilder should be loadable after the plugin bootstraps.'
		);
	}

	/**
	 * The required new members on the override-race class are present (our copy won, or env has it).
	 */
	public function test_model_requirements_has_embedding_factory(): void {
		$this->assertTrue(
			method_exists(
				'WordPress\\AiClient\\Providers\\Models\\DTO\\ModelRequirements',
				'fromEmbeddingData'
			),
			'ModelRequirements::fromEmbeddingData() must be available to derive embedding requirements.'
		);

		$this->assertTrue(
			method_exists(
				'WordPress\\AiClient\\Providers\\Models\\DTO\\ModelRequirements',
				'getUnmetRequirements'
			),
			'ModelRequirements::getUnmetRequirements() must be available to explain why a model is unsuitable.'
		);
	}

	/**
	 * The builder exposes the model-required API, not the superseded model-resolution API.
	 *
	 * Embedding vectors are only comparable within a single model, so the builder must make the
	 * caller name one. A builder that still accepted a preference list would silently pick a
	 * different model as connectors change, invalidating any stored corpus.
	 */
	public function test_embedding_builder_requires_an_explicit_model(): void {
		$builder = 'WordPress\\AiClient\\Builders\\EmbeddingBuilder';

		$this->assertTrue(
			method_exists( $builder, 'usingProviderModel' ),
			'EmbeddingBuilder::usingProviderModel() must be available to name a model explicitly.'
		);
		$this->assertTrue(
			method_exists( $builder, 'usingModel' ),
			'EmbeddingBuilder::usingModel() must be available to pass a model instance.'
		);
		$this->assertFalse(
			method_exists( $builder, 'usingModelPreference' ),
			'EmbeddingBuilder must no longer resolve a model from a preference list.'
		);
		$this->assertFalse(
			method_exists( $builder, 'usingProvider' ),
			'EmbeddingBuilder must no longer resolve a model from a provider alone.'
		);
	}

	/**
	 * The configuration trait the builder composes is served, and the superseded one is not shipped.
	 */
	public function test_overlay_ships_the_configuration_trait_not_the_resolution_trait(): void {
		$this->assertNotNull(
			SDK_Overlay::class_to_file( 'WordPress\\AiClient\\Builders\\Traits\\ModelConfigurationTrait' ),
			'ModelConfigurationTrait must be vendored; EmbeddingBuilder composes it.'
		);
		$this->assertNull(
			SDK_Overlay::class_to_file( 'WordPress\\AiClient\\Builders\\Traits\\ModelResolutionTrait' ),
			'ModelResolutionTrait must not be vendored; nothing on the embedding path uses it.'
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

	/**
	 * Every feature sentinel is detectable by the exact probe resolve() performs.
	 *
	 * resolve() calls class_exists() on the sentinel. class_exists() returns false for an
	 * interface or a trait, so a sentinel that is not a real class would make its feature
	 * activate unconditionally, even in an environment that already ships the feature.
	 */
	public function test_each_feature_sentinel_is_a_real_class(): void {
		foreach ( SDK_Overlay::features() as $feature => $config ) {
			$this->assertTrue(
				class_exists( $config['sentinel'] ),
				sprintf(
					'Feature "%s": sentinel %s must be a class (class_exists() is the probe resolve() uses).',
					$feature,
					$config['sentinel']
				)
			);
		}
	}

	/**
	 * Every guard names a method that the overlay's own copy of the guarded class really declares.
	 *
	 * A guard method that does not exist in our copy would make the conflict probe fire on every
	 * request, permanently skipping the feature. This reads the vendored file rather than the
	 * loaded class, so it fails even when the environment supplied a newer copy of its own.
	 */
	public function test_every_guard_method_exists_in_the_vendored_copy(): void {
		foreach ( SDK_Overlay::features() as $feature => $config ) {
			foreach ( $config['guards'] as $class_name => $method_name ) {
				$this->assertContains(
					$class_name,
					$config['classes'],
					sprintf( 'Feature "%s": guarded class %s must be one this feature overlays.', $feature, $class_name )
				);

				$file = SDK_Overlay::class_to_file( $class_name );
				$this->assertIsString(
					$file,
					sprintf( 'Feature "%s": guarded class %s must be vendored.', $feature, $class_name )
				);

				$this->assertMatchesRegularExpression(
					'/function\s+' . preg_quote( $method_name, '/' ) . '\s*\(/',
					(string) file_get_contents( (string) $file ),
					sprintf(
						'Feature "%s": guard method %s::%s() must be declared by the vendored file.',
						$feature,
						$class_name,
						$method_name
					)
				);
			}
		}
	}

	/**
	 * The streaming feature's guard methods are the ones that distinguish the newer copy.
	 *
	 * Both guarded classes exist in every environment; the guard only works if the named method
	 * is absent from the older bundled copy. If a future SDK adds these to the base classes, the
	 * guards stop discriminating and must be re-picked.
	 */
	public function test_streaming_guards_are_the_stream_aware_methods(): void {
		$features = SDK_Overlay::features();
		$this->assertArrayHasKey( 'streaming', $features );

		$this->assertSame(
			array(
				'WordPress\\AiClient\\Providers\\Http\\DTO\\Response'       => 'getStream',
				'WordPress\\AiClient\\Providers\\Http\\DTO\\RequestOptions' => 'isStream',
			),
			$features['streaming']['guards']
		);
	}

	/**
	 * The streaming types are loadable after bootstrap (from the overlay or the environment).
	 */
	public function test_streaming_classes_are_available_after_bootstrap(): void {
		$this->assertTrue(
			class_exists( 'WordPress\\AiClient\\Results\\StreamedGenerativeAiResult' ),
			'StreamedGenerativeAiResult should be loadable after the plugin bootstraps.'
		);
		$this->assertTrue(
			class_exists( 'WordPress\\AiClient\\Results\\ChunkAccumulator' ),
			'ChunkAccumulator should be loadable after the plugin bootstraps.'
		);
		$this->assertTrue(
			interface_exists(
				'WordPress\\AiClient\\Providers\\Models\\TextGeneration\\Contracts\\StreamingTextGenerationModelInterface'
			),
			'StreamingTextGenerationModelInterface is the contract a streaming model must implement.'
		);
		$this->assertTrue(
			interface_exists( 'WordPress\\AiClient\\Providers\\Http\\Streaming\\Contracts\\EventStreamParserInterface' ),
			'EventStreamParserInterface must be available for a transport to decode an event stream.'
		);
	}

	/**
	 * The HTTP DTOs on the hot path carry the stream-aware API.
	 *
	 * These two classes exist in every environment, so this is the assertion that proves the
	 * overlay actually won the override (or that the environment already shipped a newer copy).
	 */
	public function test_http_dtos_expose_the_stream_api(): void {
		$this->assertTrue(
			method_exists( 'WordPress\\AiClient\\Providers\\Http\\DTO\\Response', 'getStream' ),
			'Response::getStream() must be available to read a streamed body.'
		);
		$this->assertTrue(
			method_exists( 'WordPress\\AiClient\\Providers\\Http\\DTO\\RequestOptions', 'isStream' ),
			'RequestOptions::isStream() must be available for a transport to know to stream.'
		);
		$this->assertTrue(
			method_exists( 'WordPress\\AiClient\\Providers\\Http\\DTO\\RequestOptions', 'setStream' ),
			'RequestOptions::setStream() must be available for a caller to request streaming.'
		);
	}

	/**
	 * The stream-aware Response stays backwards compatible with the buffered contract.
	 *
	 * Every AI call in the plugin goes through this class, so a widened constructor must not
	 * change what a plain string body does.
	 */
	public function test_response_string_body_behaviour_is_unchanged(): void {
		$response_class = 'WordPress\\AiClient\\Providers\\Http\\DTO\\Response';
		$response       = new $response_class( 200, array( 'Content-Type' => 'application/json' ), '{"a":1}' );

		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertTrue( $response->isSuccessful() );
		$this->assertSame( '{"a":1}', $response->getBody() );
		$this->assertSame( array( 'a' => 1 ), $response->getData() );
		$this->assertSame( 'application/json', $response->getHeaderAsString( 'content-type' ) );

		// getBody() must stay repeatable for a string body, and getStream() must not consume it.
		$this->assertSame( '{"a":1}', $response->getStream()->getContents() );
		$this->assertSame( '{"a":1}', $response->getBody() );
	}

	/**
	 * A streamed body is readable as a stream and collapses to a string on demand.
	 */
	public function test_response_accepts_a_psr_stream_body(): void {
		$stream_class = 'WordPress\\AiClientDependencies\\Nyholm\\Psr7\\Stream';

		if ( ! class_exists( $stream_class ) ) {
			$this->markTestSkipped( 'Environment does not expose the prefixed PSR-7 stream implementation.' );
		}

		$response_class = 'WordPress\\AiClient\\Providers\\Http\\DTO\\Response';
		$response       = new $response_class( 200, array(), $stream_class::create( 'hello' ) );

		$this->assertSame( 'hello', $response->getStream()->getContents() );
		$this->assertSame( 'hello', $response->getBody() );
	}

	/**
	 * The SSE parser decodes a `text/event-stream` body into discrete events.
	 */
	public function test_sse_parser_decodes_events(): void {
		$stream_class = 'WordPress\\AiClientDependencies\\Nyholm\\Psr7\\Stream';
		$parser_class = 'WordPress\\AiClient\\Providers\\Http\\Streaming\\SseEventStreamParser';

		if ( ! class_exists( $stream_class ) ) {
			$this->markTestSkipped( 'Environment does not expose the prefixed PSR-7 stream implementation.' );
		}

		$body = ": comment\n"
			. "event: delta\ndata: one\nid: 1\n\n"
			. "data: two\ndata: three\n\n";

		$parser = new $parser_class();
		$events = array();
		foreach ( $parser->parse( $stream_class::create( $body ) ) as $event ) {
			$events[] = $event;
		}

		$this->assertCount( 2, $events );
		$this->assertSame( 'delta', $events[0]->getEvent() );
		$this->assertSame( 'one', $events[0]->getData() );
		$this->assertSame( '1', $events[0]->getId() );

		// Unspecified event name defaults to "message"; multi-line data joins with a newline.
		$this->assertSame( 'message', $events[1]->getEvent() );
		$this->assertSame( "two\nthree", $events[1]->getData() );
	}

	/**
	 * The vendored trunk streaming types assemble a result using the environment's own DTOs.
	 *
	 * This is the real compatibility check for the feature: ChunkAccumulator is forward-ported
	 * from upstream trunk, but Message, MessagePart, Candidate, TokenUsage and GenerativeAiResult
	 * all come from whatever SDK version the environment bundles.
	 */
	public function test_streamed_result_accumulates_chunks_into_a_result(): void {
		$provider_metadata = new \WordPress\AiClient\Providers\DTO\ProviderMetadata(
			'test-provider',
			'Test Provider',
			\WordPress\AiClient\Providers\Enums\ProviderTypeEnum::cloud()
		);
		$model_metadata    = new \WordPress\AiClient\Providers\Models\DTO\ModelMetadata(
			'test-model',
			'Test Model',
			array( \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration() ),
			array()
		);

		$chunk_class     = 'WordPress\\AiClient\\Results\\ValueObjects\\GenerativeAiResultChunk';
		$delta_class     = 'WordPress\\AiClient\\Results\\ValueObjects\\CandidateDelta';
		$streamed_class  = 'WordPress\\AiClient\\Results\\StreamedGenerativeAiResult';
		$part_class      = 'WordPress\\AiClient\\Messages\\DTO\\MessagePart';

		$chunks = new \ArrayIterator(
			array(
				new $chunk_class( 'res-1', null, array(), array( new $delta_class( 0, array( new $part_class( 'Hello' ) ) ) ) ),
				new $chunk_class(
					null,
					new \WordPress\AiClient\Results\DTO\TokenUsage( 3, 2, 5 ),
					array(),
					array(
						new $delta_class(
							0,
							array( new $part_class( ' world' ) ),
							\WordPress\AiClient\Results\Enums\FinishReasonEnum::stop()
						),
					)
				),
			)
		);

		$streamed = new $streamed_class( $chunks, $provider_metadata, $model_metadata );

		$seen = array();
		$streamed->onComplete(
			static function ( $result ) use ( &$seen ): void {
				$seen[] = $result;
			}
		);

		$text = '';
		foreach ( $streamed as $chunk ) {
			$text .= $chunk->getDeltaText();
		}

		$this->assertSame( 'Hello world', $text );
		$this->assertCount( 1, $seen, 'The completion callback must fire once the stream is drained.' );

		$result = $streamed->getFinalResult();
		$this->assertInstanceOf( \WordPress\AiClient\Results\DTO\GenerativeAiResult::class, $result );
		$this->assertSame( 'res-1', $result->getId() );
		$this->assertSame( 'Hello world', $result->toText() );
		$this->assertSame( 5, $result->getTokenUsage()->getTotalTokens() );
		$this->assertTrue( $result->getCandidates()[0]->getFinishReason()->isStop() );
	}

	/**
	 * A streamed result may only be consumed once.
	 */
	public function test_streamed_result_cannot_be_iterated_twice(): void {
		$streamed_class = 'WordPress\\AiClient\\Results\\StreamedGenerativeAiResult';

		$streamed = new $streamed_class(
			new \ArrayIterator( array() ),
			new \WordPress\AiClient\Providers\DTO\ProviderMetadata(
				'test-provider',
				'Test Provider',
				\WordPress\AiClient\Providers\Enums\ProviderTypeEnum::cloud()
			),
			new \WordPress\AiClient\Providers\Models\DTO\ModelMetadata( 'test-model', 'Test Model', array(), array() )
		);

		foreach ( $streamed as $ignored ) {
			$this->fail( 'An empty chunk stream must yield nothing.' );
		}

		$this->expectException( \WordPress\AiClient\Common\Exception\RuntimeException::class );
		foreach ( $streamed as $ignored ) {
			$this->fail( 'A second iteration must not yield.' );
		}
	}

	/**
	 * The streaming feature deliberately does not vendor the upstream classes it does not need.
	 *
	 * Each overlaid class is one we override everywhere the feature activates, so an unreferenced
	 * file is pure blast radius.
	 */
	public function test_streaming_feature_does_not_vendor_unreferenced_upstream_files(): void {
		foreach (
			array(
				'WordPress\\AiClient\\Events\\GenerateResultErrorEvent',
				'WordPress\\AiClient\\Providers\\Http\\Exception\\ResponseException',
				'WordPress\\AiClient\\Builders\\PromptBuilder',
				'WordPress\\AiClient\\Providers\\Http\\HttpTransporter',
			) as $class_name
		) {
			$this->assertNull(
				SDK_Overlay::class_to_file( $class_name ),
				sprintf( '%s must not be vendored; nothing the overlay ships references it.', $class_name )
			);
		}
	}

	/**
	 * Vendored files import core's prefixed PSR/Nyholm dependencies, never the bare names.
	 *
	 * WordPress core scopes its PSR dependencies under `WordPress\AiClientDependencies\`. An
	 * unprefixed import resolves to nothing and fatals at runtime.
	 *
	 * The match covers every `Psr\` namespace rather than `Psr\Http\` alone. Core scopes more
	 * than PSR-7 -- `Psr\EventDispatcher\` and `Psr\SimpleCache\` are prefixed the same way --
	 * and a narrower pattern let an unprefixed `Psr\EventDispatcher\` import sit in the vendored
	 * tree undetected. It stayed latent only because the symbol appeared solely as a nullable type,
	 * which PHP never resolves while the value is null; passing a real dispatcher would have raised
	 * a TypeError, because the prefixed interface does not satisfy the unprefixed name.
	 */
	public function test_vendored_files_use_the_prefixed_psr_dependencies(): void {
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( SDK_Overlay::src_dir(), \FilesystemIterator::SKIP_DOTS )
		);

		$checked = 0;

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			++$checked;

			$this->assertDoesNotMatchRegularExpression(
				'/^use (Nyholm|Psr)\\\\/m',
				(string) file_get_contents( $file->getPathname() ),
				sprintf(
					'%s imports an unprefixed PSR dependency; core scopes these under WordPress\\AiClientDependencies\\.',
					$file->getPathname()
				)
			);
		}

		$this->assertGreaterThan( 0, $checked, 'The vendored tree must contain PHP files.' );
	}
}
