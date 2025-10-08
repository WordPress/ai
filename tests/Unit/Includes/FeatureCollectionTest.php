<?php
/**
 * Unit tests for the Feature_Collection class.
 *
 * @package WordPress\AI\Tests\Unit
 */

namespace WordPress\AI\Tests\Unit\Includes;

use PHPUnit\Framework\TestCase;
use WordPress\AI\Feature_Collection;
use WordPress\AI\Interfaces\Feature;

/**
 * Mock Feature class for testing Feature_Collection.
 */
class Mock_Feature implements Feature {
	private $id;
	private $label;
	private $description;
	private $enabled;

	public function __construct( string $id, bool $enabled = true ) {
		$this->id          = $id;
		$this->label       = ucfirst( $id );
		$this->description = 'Description for ' . $id;
		$this->enabled     = $enabled;
	}

	public function get_id(): string {
		return $this->id;
	}

	public function get_label(): string {
		return $this->label;
	}

	public function get_description(): string {
		return $this->description;
	}

	public function register(): void {
		// No-op for mock.
	}

	public function is_enabled(): bool {
		return $this->enabled;
	}
}

/**
 * Unit tests for the Feature_Collection class.
 *
 * @since 0.1.0
 */
class FeatureCollectionTest extends TestCase {
	/**
	 * Test that a feature can be registered.
	 */
	public function test_register_feature() {
		$collection = new Feature_Collection();
		$feature    = new Mock_Feature( 'test-feature' );

		$this->assertTrue( $collection->register_feature( $feature ) );
		$this->assertTrue( $collection->has_feature( 'test-feature' ) );
	}

	/**
	 * Test that a duplicate feature cannot be registered.
	 */
	public function test_register_duplicate_feature_fails() {
		$collection = new Feature_Collection();
		$feature    = new Mock_Feature( 'test-feature' );

		$collection->register_feature( $feature );
		$this->assertFalse( $collection->register_feature( $feature ) );
	}

	/**
	 * Test that a feature can be retrieved by ID.
	 */
	public function test_get_feature() {
		$collection = new Feature_Collection();
		$feature    = new Mock_Feature( 'test-feature' );
		$collection->register_feature( $feature );

		$this->assertSame( $feature, $collection->get_feature( 'test-feature' ) );
	}

	/**
	 * Test that getting a non-existent feature returns null.
	 */
	public function test_get_nonexistent_feature_returns_null() {
		$collection = new Feature_Collection();
		$this->assertNull( $collection->get_feature( 'nonexistent-feature' ) );
	}

	/**
	 * Test that all registered features can be retrieved.
	 */
	public function test_get_all_features() {
		$collection = new Feature_Collection();
		$feature1   = new Mock_Feature( 'test-feature-1' );
		$feature2   = new Mock_Feature( 'test-feature-2' );

		$collection->register_feature( $feature1 );
		$collection->register_feature( $feature2 );

		$all_features = $collection->get_all_features();

		$this->assertCount( 2, $all_features );
		$this->assertArrayHasKey( 'test-feature-1', $all_features );
		$this->assertArrayHasKey( 'test-feature-2', $all_features );
		$this->assertSame( $feature1, $all_features['test-feature-1'] );
		$this->assertSame( $feature2, $all_features['test-feature-2'] );
	}

	/**
	 * Test that has_feature returns true for existing feature.
	 */
	public function test_has_feature_returns_true_for_existing_feature() {
		$collection = new Feature_Collection();
		$feature    = new Mock_Feature( 'test-feature' );
		$collection->register_feature( $feature );

		$this->assertTrue( $collection->has_feature( 'test-feature' ) );
	}

	/**
	 * Test that has_feature returns false for non-existent feature.
	 */
	public function test_has_feature_returns_false_for_nonexistent_feature() {
		$collection = new Feature_Collection();
		$this->assertFalse( $collection->has_feature( 'nonexistent-feature' ) );
	}
}
