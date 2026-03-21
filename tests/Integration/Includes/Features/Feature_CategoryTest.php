<?php
/**
 * Test case for the Feature_Category constants.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Features
 */

namespace WordPress\AI\Tests\Integration\Includes\Features;

use WP_UnitTestCase;
use WordPress\AI\Features\Feature_Category;

/**
 * Tests for the Feature_Category class.
 *
 * @since 0.6.0
 */
class Feature_CategoryTest extends WP_UnitTestCase {
	/**
	 * Test that the OTHER category constant matches the expected value.
	 *
	 * @since 0.6.0
	 */
	public function test_other_category_constant() {
		$this->assertEquals( 'other', Feature_Category::OTHER );
	}
}
