<?php
/**
 * Tests for the Guidelines service class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Services
 */

namespace WordPress\AI\Tests\Integration\Includes\Services;

use WP_UnitTestCase;
use WordPress\AI\Services\Guidelines;

/**
 * Guidelines test case.
 *
 * @since x.x.x
 */
class Guidelines_Test extends WP_UnitTestCase {

	use Guidelines_CPT_Helpers;

	/**
	 * Service instance.
	 *
	 * @var \WordPress\AI\Services\Guidelines
	 */
	private Guidelines $service;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();
		Guidelines::reset_cache();
		$this->service = Guidelines::get_instance();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		Guidelines::reset_cache();
		remove_all_filters( 'wpai_use_guidelines' );
		remove_all_filters( 'wpai_max_guideline_length' );
		parent::tearDown();
	}

	/**
	 * Tests that is_available() returns false when the CPT is not registered.
	 *
	 * @since x.x.x
	 */
	public function test_is_available_returns_false_when_cpt_not_registered(): void {
		// Ensure the CPT is not registered, as it may already exist in WP 7.0+.
		if ( post_type_exists( 'wp_content_guideline' ) ) {
			unregister_post_type( 'wp_content_guideline' );
		}

		$this->assertFalse(
			$this->service->is_available(),
			'Should return false when wp_content_guideline CPT is not registered'
		);
	}

	/**
	 * Tests that get_guidelines() returns null when the CPT is unavailable.
	 *
	 * @since x.x.x
	 */
	public function test_get_guidelines_returns_null_when_unavailable(): void {
		$this->assertNull(
			$this->service->get_guidelines(),
			'Should return null when CPT is not registered'
		);
	}

	/**
	 * Tests that get_guidelines() returns null when no guidelines post exists.
	 *
	 * @since x.x.x
	 */
	public function test_get_guidelines_returns_null_when_no_post_exists(): void {
		$this->register_guidelines_cpt();

		$this->assertNull(
			$this->service->get_guidelines(),
			'Should return null when no guidelines post exists'
		);
	}

	/**
	 * Tests that get_guidelines() returns a keyed array when a post exists.
	 *
	 * @since x.x.x
	 */
	public function test_get_guidelines_returns_array_when_post_exists(): void {
		$this->register_guidelines_cpt();
		$this->create_guidelines_post(
			array(
				'copy' => 'Keep sentences under 25 words.',
				'site' => 'Use a professional tone.',
			)
		);

		$guidelines = $this->service->get_guidelines();

		$this->assertIsArray( $guidelines, 'Should return an array' );
		$this->assertArrayHasKey( 'copy', $guidelines );
		$this->assertArrayHasKey( 'site', $guidelines );
		$this->assertEquals( 'Keep sentences under 25 words.', $guidelines['copy'] );
		$this->assertEquals( 'Use a professional tone.', $guidelines['site'] );
	}

	/**
	 * Tests that get_guidelines() filters by category when a category is passed.
	 *
	 * @since x.x.x
	 */
	public function test_get_guidelines_filters_by_category(): void {
		$this->register_guidelines_cpt();
		$this->create_guidelines_post(
			array(
				'copy' => 'Keep sentences under 25 words.',
				'site' => 'Use a professional tone.',
			)
		);

		$guidelines = $this->service->get_guidelines( 'copy' );

		$this->assertIsArray( $guidelines );
		$this->assertArrayHasKey( 'copy', $guidelines );
		$this->assertArrayNotHasKey( 'site', $guidelines );
	}

	/**
	 * Tests that get_guidelines() returns null for a nonexistent category.
	 *
	 * @since x.x.x
	 */
	public function test_get_guidelines_returns_null_for_nonexistent_category(): void {
		$this->register_guidelines_cpt();
		$this->create_guidelines_post(
			array(
				'copy' => 'Keep sentences under 25 words.',
			)
		);

		$this->assertNull(
			$this->service->get_guidelines( 'nonexistent' ),
			'Should return null for a nonexistent category'
		);
	}

	/**
	 * Tests that get_block_guidelines() returns a string for a block with guidelines.
	 *
	 * @since x.x.x
	 */
	public function test_get_block_guidelines_returns_string(): void {
		$this->register_guidelines_cpt();
		$post_id = $this->create_guidelines_post(
			array( 'site' => 'Professional tone.' )
		);
		update_post_meta( $post_id, '_content_guideline_block_core_paragraph', 'Keep paragraphs concise.' );

		$result = $this->service->get_block_guidelines( 'core/paragraph' );

		$this->assertEquals( 'Keep paragraphs concise.', $result );
	}

	/**
	 * Tests that get_block_guidelines() returns null for a block without guidelines.
	 *
	 * @since x.x.x
	 */
	public function test_get_block_guidelines_returns_null_for_missing_block(): void {
		$this->register_guidelines_cpt();
		$this->create_guidelines_post( array( 'site' => 'Professional tone.' ) );

		$this->assertNull(
			$this->service->get_block_guidelines( 'core/heading' ),
			'Should return null for a block without guidelines'
		);
	}

	/**
	 * Tests that format_for_prompt() returns an empty string when no guidelines exist.
	 *
	 * @since x.x.x
	 */
	public function test_format_for_prompt_returns_empty_when_no_guidelines(): void {
		$this->register_guidelines_cpt();

		$this->assertSame(
			'',
			$this->service->format_for_prompt( array( 'site', 'copy' ) ),
			'Should return empty string when no guidelines exist'
		);
	}

	/**
	 * Tests that format_for_prompt() returns an XML string with correct structure.
	 *
	 * @since x.x.x
	 */
	public function test_format_for_prompt_returns_xml_string(): void {
		$this->register_guidelines_cpt();
		$this->create_guidelines_post(
			array(
				'site' => 'Professional tone.',
				'copy' => 'Keep it short.',
			)
		);

		$result = $this->service->format_for_prompt( array( 'site', 'copy' ) );

		$this->assertStringContainsString( '<guidelines>', $result );
		$this->assertStringContainsString( '</guidelines>', $result );
		$this->assertStringContainsString( '<site-context>Professional tone.</site-context>', $result );
		$this->assertStringContainsString( '<copy-guidelines>Keep it short.</copy-guidelines>', $result );
	}

	/**
	 * Tests that format_for_prompt() truncates long guidelines.
	 *
	 * @since x.x.x
	 */
	public function test_format_for_prompt_truncates_long_guidelines(): void {
		$this->register_guidelines_cpt();
		$long_text = str_repeat( 'a', 6000 );
		$this->create_guidelines_post( array( 'site' => $long_text ) );

		$result = $this->service->format_for_prompt( array( 'site' ) );

		// Default max is 5000 chars per category.
		$this->assertStringContainsString( '<site-context>', $result );
		// The content between the tags should be truncated.
		preg_match( '/<site-context>(.*?)<\/site-context>/s', $result, $matches );
		$this->assertNotEmpty( $matches );
		$this->assertEquals( 5000, mb_strlen( $matches[1], 'UTF-8' ) );
	}

	/**
	 * Tests that format_for_prompt() includes block-specific guidelines.
	 *
	 * @since x.x.x
	 */
	public function test_format_for_prompt_includes_block_guidelines(): void {
		$this->register_guidelines_cpt();
		$post_id = $this->create_guidelines_post( array( 'site' => 'Professional tone.' ) );
		update_post_meta( $post_id, '_content_guideline_block_core_paragraph', 'Keep paragraphs concise.' );

		$result = $this->service->format_for_prompt( array( 'site' ), 'core/paragraph' );

		$this->assertStringContainsString( '<block-guidelines>Keep paragraphs concise.</block-guidelines>', $result );
	}

	/**
	 * Tests that the service caches results and only queries the database once.
	 *
	 * @since x.x.x
	 */
	public function test_caching_only_queries_once(): void {
		$this->register_guidelines_cpt();
		$this->create_guidelines_post( array( 'site' => 'Professional tone.' ) );

		$result1 = $this->service->get_guidelines();
		$result2 = $this->service->get_guidelines();

		$this->assertEquals( $result1, $result2, 'Both calls should return the same result' );
	}

	/**
	 * Tests that the wpai_use_guidelines filter can disable guidelines.
	 *
	 * @since x.x.x
	 */
	public function test_wpai_use_guidelines_filter_disables(): void {
		$this->register_guidelines_cpt();
		$this->create_guidelines_post( array( 'site' => 'Professional tone.' ) );

		add_filter( 'wpai_use_guidelines', '__return_false' );

		$this->assertNull(
			$this->service->get_guidelines(),
			'Should return null when filter disables guidelines'
		);
		$this->assertSame(
			'',
			$this->service->format_for_prompt( array( 'site' ) ),
			'Should return empty string when filter disables guidelines'
		);
	}

	/**
	 * Tests that the wpai_max_guideline_length filter is applied.
	 *
	 * @since x.x.x
	 */
	public function test_wpai_max_guideline_length_filter(): void {
		$this->register_guidelines_cpt();
		$this->create_guidelines_post( array( 'site' => str_repeat( 'a', 500 ) ) );

		add_filter(
			'wpai_max_guideline_length',
			static function (): int {
				return 100;
			}
		);

		$result = $this->service->format_for_prompt( array( 'site' ) );

		preg_match( '/<site-context>(.*?)<\/site-context>/s', $result, $matches );
		$this->assertNotEmpty( $matches );
		$this->assertEquals( 100, mb_strlen( $matches[1], 'UTF-8' ) );
	}

	/**
	 * Tests that get_guidelines() returns null when a post exists but has no guideline meta.
	 *
	 * @since x.x.x
	 */
	public function test_get_guidelines_returns_null_when_post_has_no_meta(): void {
		$this->register_guidelines_cpt();

		// Create a guidelines post with no meta values.
		self::factory()->post->create(
			array(
				'post_type'   => 'wp_content_guideline',
				'post_status' => 'publish',
				'post_title'  => 'Empty Guidelines',
			)
		);

		$this->assertNull(
			$this->service->get_guidelines(),
			'Should return null when post exists but has no guideline meta'
		);
	}

	/**
	 * Tests that get_block_guidelines() returns null when disabled by filter.
	 *
	 * @since x.x.x
	 */
	public function test_get_block_guidelines_returns_null_when_disabled_by_filter(): void {
		$this->register_guidelines_cpt();
		$post_id = $this->create_guidelines_post( array( 'site' => 'Professional tone.' ) );
		update_post_meta( $post_id, '_content_guideline_block_core_paragraph', 'Keep paragraphs concise.' );

		add_filter( 'wpai_use_guidelines', '__return_false' );

		$this->assertNull(
			$this->service->get_block_guidelines( 'core/paragraph' ),
			'Should return null when filter disables guidelines'
		);
	}

	/**
	 * Tests that format_for_prompt() skips empty categories.
	 *
	 * @since x.x.x
	 */
	public function test_format_for_prompt_skips_empty_categories(): void {
		$this->register_guidelines_cpt();
		$this->create_guidelines_post( array( 'site' => 'Professional tone.' ) );

		$result = $this->service->format_for_prompt( array( 'site', 'images' ) );

		$this->assertStringContainsString( '<site-context>', $result );
		$this->assertStringNotContainsString( '<image-guidelines>', $result );
	}

}
