<?php
/**
 * Integration tests for feature capability metadata.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Features
 */

namespace WordPress\AI\Tests\Integration\Includes\Features;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Alt_Text_Generation\Alt_Text_Generation;
use WordPress\AI\Features\Image_Generation\Image_Generation;

/**
 * Feature capability test case.
 *
 * @since x.x.x
 */
class Feature_CapabilityTest extends WP_UnitTestCase {
	/**
	 * Test that image generation advertises image generation capability.
	 *
	 * @since x.x.x
	 */
	public function test_image_generation_feature_uses_image_generation_capability(): void {
		$feature = new Image_Generation();

		$this->assertSame( 'image_generation', $feature->get_capability() );
	}

	/**
	 * Test that alt text generation advertises vision capability.
	 *
	 * @since x.x.x
	 */
	public function test_alt_text_generation_feature_uses_vision_capability(): void {
		$feature = new Alt_Text_Generation();

		$this->assertSame( 'vision', $feature->get_capability() );
	}
}
