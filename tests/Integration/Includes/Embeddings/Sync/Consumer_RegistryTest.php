<?php
/**
 * Tests for the embedding consumer registry.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embeddings\Sync
 */

namespace WordPress\AI\Tests\Integration\Includes\Embeddings\Sync;

use WP_UnitTestCase;
use WordPress\AI\Embeddings\Sync\Consumer_Registry;
use WordPress\AI\Embeddings\Sync\Embedding_Target;

/**
 * Consumer_Registry test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Embeddings\Sync\Consumer_Registry
 * @covers \WordPress\AI\Embeddings\Sync\Embedding_Target
 */
class Consumer_RegistryTest extends WP_UnitTestCase {

	/**
	 * Registry under test.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Consumer_Registry
	 */
	private Consumer_Registry $registry;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->registry = new Consumer_Registry();
	}

	/**
	 * Returns valid consumer arguments.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $overrides Values to override.
	 * @return array<string, mixed> Arguments.
	 */
	private function args( array $overrides = array() ): array {
		return array_merge(
			array(
				'provider' => 'openai',
				'model'    => 'text-embedding-3-small',
				'objects'  => array( 'post' => array( 'post' ) ),
			),
			$overrides
		);
	}

	/**
	 * Tests the empty state.
	 *
	 * @since x.x.x
	 */
	public function test_starts_empty(): void {
		$this->assertFalse( $this->registry->has_consumers() );
		$this->assertSame( array(), $this->registry->get_targets() );
	}

	/**
	 * Tests that consumers on one model share a target covering the union of their subtypes.
	 *
	 * @since x.x.x
	 */
	public function test_consumers_on_one_model_share_a_target(): void {
		$this->assertTrue( $this->registry->register( 'related', $this->args() ) );
		$this->assertTrue(
			$this->registry->register(
				'search',
				$this->args(
					array(
						'objects' => array(
							'post' => array( 'page', 'post' ),
							'term' => array( 'post_tag' ),
						),
					)
				)
			)
		);

		$targets = $this->registry->get_targets();

		$this->assertCount( 1, $targets );

		$target = reset( $targets );

		$this->assertSame( array( 'page', 'post' ), $target->get_subtypes_for( 'post' ) );
		$this->assertSame( array( 'post_tag' ), $target->get_subtypes_for( 'term' ) );
		$this->assertSame( $target->get_key(), Embedding_Target::key_for( 'openai', 'text-embedding-3-small' ) );
		$this->assertSame( $target->get_key(), $this->registry->get_target_for_consumer( 'related' )->get_key() );
	}

	/**
	 * Tests that different models produce different targets.
	 *
	 * @since x.x.x
	 */
	public function test_different_models_produce_different_targets(): void {
		$this->registry->register( 'a', $this->args() );
		$this->registry->register( 'b', $this->args( array( 'model' => 'text-embedding-3-large' ) ) );

		$this->assertCount( 2, $this->registry->get_targets() );
		$this->assertCount( 2, $this->registry->get_targets_covering( 'post', 'post' ) );
		$this->assertTrue( $this->registry->covers( 'post', 'post' ) );
		$this->assertFalse( $this->registry->covers( 'post', 'page' ) );
		$this->assertFalse( $this->registry->covers( 'term', 'post_tag' ) );
	}

	/**
	 * Tests that a second consumer asking for other dimensions from the same model is rejected.
	 *
	 * @since x.x.x
	 */
	public function test_rejects_conflicting_dimensions_on_one_model(): void {
		$this->setExpectedIncorrectUsage( Consumer_Registry::class . '::register' );

		$this->registry->register( 'a', $this->args( array( 'dimensions' => 1536 ) ) );

		$this->assertFalse( $this->registry->register( 'b', $this->args( array( 'dimensions' => 512 ) ) ) );
		$this->assertSame( array( 'a' ), $this->registry->get_consumer_ids() );
	}

	/**
	 * Tests the validation rules.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_invalid_registrations
	 *
	 * @param string               $id   Consumer ID.
	 * @param array<string, mixed> $args Arguments.
	 */
	public function test_rejects_invalid_registrations( string $id, array $args ): void {
		$this->setExpectedIncorrectUsage( Consumer_Registry::class . '::register' );

		$this->assertFalse( $this->registry->register( $id, $args ) );
		$this->assertFalse( $this->registry->has_consumers() );
	}

	/**
	 * Data provider for invalid registrations.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}> Cases.
	 */
	public function data_invalid_registrations(): array {
		$valid = array(
			'provider' => 'openai',
			'model'    => 'm',
			'objects'  => array( 'post' => array( 'post' ) ),
		);

		return array(
			'empty id'            => array( ' ', $valid ),
			'missing provider'    => array( 'x', array_merge( $valid, array( 'provider' => '' ) ) ),
			'missing model'       => array( 'x', array_merge( $valid, array( 'model' => null ) ) ),
			'zero dimensions'     => array( 'x', array_merge( $valid, array( 'dimensions' => 0 ) ) ),
			'no objects'          => array( 'x', array_merge( $valid, array( 'objects' => array() ) ) ),
			'unsupported type'    => array( 'x', array_merge( $valid, array( 'objects' => array( 'user' => array( 'administrator' ) ) ) ) ),
			'empty subtypes'      => array( 'x', array_merge( $valid, array( 'objects' => array( 'post' => array( '' ) ) ) ) ),
			'subtypes not a list' => array( 'x', array_merge( $valid, array( 'objects' => array( 'post' => 'post' ) ) ) ),
		);
	}

	/**
	 * Tests that a duplicate ID is rejected.
	 *
	 * @since x.x.x
	 */
	public function test_rejects_duplicate_id(): void {
		$this->setExpectedIncorrectUsage( Consumer_Registry::class . '::register' );

		$this->registry->register( 'a', $this->args() );

		$this->assertFalse( $this->registry->register( 'a', $this->args() ) );
	}

	/**
	 * Tests that unknown subtypes are dropped, and a consumer left with none is removed.
	 *
	 * @since x.x.x
	 */
	public function test_remove_unknown_subtypes(): void {
		$this->setExpectedIncorrectUsage( Consumer_Registry::class . '::register' );

		$this->registry->register( 'a', $this->args( array( 'objects' => array( 'post' => array( 'post', 'not_a_type' ) ) ) ) );
		$this->registry->register(
			'b',
			$this->args(
				array(
					'model'   => 'other',
					'objects' => array( 'post' => array( 'not_a_type' ) ),
				)
			)
		);

		$this->registry->remove_unknown_subtypes( static fn( string $type, string $subtype ): bool => 'not_a_type' !== $subtype );

		$this->assertSame( array( 'a' ), $this->registry->get_consumer_ids() );
		$this->assertSame( array( 'post' ), $this->registry->get_consumer( 'a' )['objects']['post'] );
		$this->assertCount( 1, $this->registry->get_targets() );
	}

	/**
	 * Tests unregistering.
	 *
	 * @since x.x.x
	 */
	public function test_unregister(): void {
		$this->registry->register( 'a', $this->args() );
		$this->registry->unregister( 'a' );

		$this->assertFalse( $this->registry->has_consumers() );
		$this->assertNull( $this->registry->get_target_for_consumer( 'a' ) );
	}

	/**
	 * Tests that the cached targets are rebuilt after registering and unregistering.
	 *
	 * @since x.x.x
	 */
	public function test_targets_cache_is_rebuilt_after_changes(): void {
		$this->registry->register( 'a', $this->args( array( 'model' => 'm1' ) ) );
		$this->registry->get_targets();

		$this->registry->register( 'b', $this->args( array( 'model' => 'm2' ) ) );

		$this->assertCount( 2, $this->registry->get_targets() );

		$this->registry->unregister( 'a' );

		$targets = $this->registry->get_targets();

		$this->assertCount( 1, $targets );
		$this->assertSame( array( Embedding_Target::key_for( 'openai', 'm2' ) ), array_keys( $targets ) );
	}

	/**
	 * Tests that a consumer asking for explicit dimensions is rejected when another uses the model default.
	 *
	 * @since x.x.x
	 */
	public function test_rejects_null_versus_explicit_dimensions_on_one_model(): void {
		$this->setExpectedIncorrectUsage( Consumer_Registry::class . '::register' );

		$this->registry->register( 'a', $this->args() );

		$this->assertFalse( $this->registry->register( 'b', $this->args( array( 'dimensions' => 1536 ) ) ) );
		$this->assertSame( array( 'a' ), $this->registry->get_consumer_ids() );
	}
}
