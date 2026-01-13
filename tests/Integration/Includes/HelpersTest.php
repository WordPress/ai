<?php
/**
 * Integration tests for helper functions.
 *
 * @package WordPress\AI\Tests\Integration\Includes
 */

namespace WordPress\AI\Tests\Integration\Includes;

use WP_UnitTestCase;

/**
 * Helper functions test case.
 *
 * @since 0.1.0
 */
class HelpersTest extends WP_UnitTestCase {
	/**
	 * Set up test case.
	 *
	 * @since 0.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		// Create a user with proper permissions for reading posts.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.1.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that normalize_content() strips HTML entities.
	 *
	 * @since 0.1.0
	 */
	public function test_normalize_content_strips_html_entities() {
		$content = 'Test &amp; content &lt;test&gt;';
		$result  = \WordPress\AI\normalize_content( $content );

		$this->assertStringNotContainsString( '&amp;', $result, 'Should remove HTML entities' );
		$this->assertStringNotContainsString( '&lt;', $result, 'Should remove HTML entities' );
		$this->assertStringNotContainsString( '&gt;', $result, 'Should remove HTML entities' );
	}

	/**
	 * Test that normalize_content() replaces HTML linebreaks with newlines.
	 *
	 * @since 0.1.0
	 */
	public function test_normalize_content_replaces_linebreaks() {
		$content = 'Line 1<br>Line 2<br/>Line 3';
		$result  = \WordPress\AI\normalize_content( $content );

		$this->assertStringNotContainsString( '<br>', $result, 'Should remove br tags' );
		$this->assertStringContainsString( "\n\n", $result, 'Should replace br with newlines' );
	}

	/**
	 * Test that normalize_content() strips HTML tags.
	 *
	 * @since 0.1.0
	 */
	public function test_normalize_content_strips_html_tags() {
		$content = '<p>Test <strong>content</strong> with <em>HTML</em></p>';
		$result  = \WordPress\AI\normalize_content( $content );

		$this->assertStringNotContainsString( '<p>', $result, 'Should remove HTML tags' );
		$this->assertStringNotContainsString( '<strong>', $result, 'Should remove HTML tags' );
		$this->assertStringNotContainsString( '<em>', $result, 'Should remove HTML tags' );
		$this->assertStringContainsString( 'Test content with HTML', $result, 'Should preserve text content' );
	}

	/**
	 * Test that normalize_content() removes unrendered shortcode tags.
	 *
	 * @since 0.1.0
	 */
	public function test_normalize_content_removes_shortcode_tags() {
		$content = '[shortcode]content[/shortcode]';
		$result  = \WordPress\AI\normalize_content( $content );

		$this->assertStringNotContainsString( '[shortcode]', $result, 'Should remove shortcode tags' );
		$this->assertStringContainsString( 'content', $result, 'Should preserve shortcode content' );
	}

	/**
	 * Test that normalize_content() trims whitespace.
	 *
	 * @since 0.1.0
	 */
	public function test_normalize_content_trims_whitespace() {
		$content = '  Test content  ';
		$result  = \WordPress\AI\normalize_content( $content );

		$this->assertEquals( 'Test content', $result, 'Should trim whitespace' );
	}

	/**
	 * Test that normalize_content() applies filters.
	 *
	 * @since 0.1.0
	 */
	public function test_normalize_content_applies_filters() {
		add_filter( 'ai_experiments_pre_normalize_content', function( $content ) {
			return 'Filtered: ' . $content;
		} );

		$result = \WordPress\AI\normalize_content( 'test' );

		$this->assertStringContainsString( 'Filtered:', $result, 'Should apply pre-normalize filter' );

		remove_all_filters( 'ai_experiments_pre_normalize_content' );
	}

	/**
	 * Test that get_post_context() returns empty array for non-existent post.
	 *
	 * @since 0.1.0
	 */
	public function test_get_post_context_returns_empty_for_nonexistent_post() {
		// Expect the incorrect usage notice when abilities are called with non-existent posts.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WP_Ability class not available' );
			return;
		}

		$context = \WordPress\AI\get_post_context( 99999 );

		$this->assertIsArray( $context, 'Should return an array' );
		$this->assertEmpty( $context, 'Should return empty array for non-existent post' );
	}

	/**
	 * Test that get_post_context() returns post content.
	 *
	 * @since 0.1.0
	 */
	public function test_get_post_context_returns_post_content() {
		$post_id = $this->factory->post->create(
			array(
				'post_content' => 'Test post content',
				'post_title'   => 'Test Post',
			)
		);

		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WP_Ability class not available' );
			return;
		}

		$context = \WordPress\AI\get_post_context( $post_id );

		$this->assertIsArray( $context, 'Should return an array' );
		$this->assertArrayHasKey( 'content', $context, 'Should have content key' );
		$this->assertNotEmpty( $context['content'], 'Content should not be empty' );
	}

	/**
	 * Test that get_post_context() returns post metadata.
	 *
	 * @since 0.1.0
	 */
	public function test_get_post_context_returns_post_metadata() {
		$author_id = $this->factory->user->create( array( 'display_name' => 'Test Author' ) );
		$post_id   = $this->factory->post->create(
			array(
				'post_title'   => 'Test Post Title',
				'post_name'    => 'test-post-slug',
				'post_author'  => $author_id,
				'post_type'    => 'post',
				'post_excerpt' => 'Test excerpt',
			)
		);

		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WP_Ability class not available' );
			return;
		}

		$context = \WordPress\AI\get_post_context( $post_id );

		$this->assertArrayHasKey( 'title', $context, 'Should have title' );
		$this->assertEquals( 'Test Post Title', $context['title'], 'Title should match' );
		$this->assertArrayHasKey( 'slug', $context, 'Should have slug' );
		$this->assertEquals( 'test-post-slug', $context['slug'], 'Slug should match' );
		$this->assertArrayHasKey( 'author', $context, 'Should have author' );
		$this->assertEquals( 'Test Author', $context['author'], 'Author should match' );
		$this->assertArrayHasKey( 'content_type', $context, 'Should have content_type' );
		$this->assertEquals( 'post', $context['content_type'], 'Content type should match' );
		$this->assertArrayHasKey( 'excerpt', $context, 'Should have excerpt' );
		$this->assertEquals( 'Test excerpt', $context['excerpt'], 'Excerpt should match' );
	}

	/**
	 * Test that get_post_context() includes categories and tags.
	 *
	 * @since 0.1.0
	 */
	public function test_get_post_context_includes_categories_and_tags() {
		$category_id = $this->factory->category->create( array( 'name' => 'Test Category' ) );
		$tag_id      = $this->factory->tag->create( array( 'name' => 'Test Tag' ) );
		$post_id     = $this->factory->post->create(
			array(
				'post_content' => 'Test content',
			)
		);

		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WP_Ability class not available' );
			return;
		}

		wp_set_post_categories( $post_id, array( $category_id ) );
		wp_set_post_tags( $post_id, array( 'Test Tag' ) );

		$context = \WordPress\AI\get_post_context( $post_id );

		// The get-terms ability returns terms grouped by taxonomy name (e.g., 'category', 'post_tag').
		$this->assertArrayHasKey( 'category', $context, 'Should have category key' );
		$this->assertStringContainsString( 'Test Category', $context['category'], 'Should include category name' );
		$this->assertArrayHasKey( 'post_tag', $context, 'Should have post_tag key' );
		$this->assertStringContainsString( 'Test Tag', $context['post_tag'], 'Should include tag name' );
	}

	/**
	 * Test that get_preferred_models_for_text_generation() returns an array.
	 *
	 * @since 0.1.0
	 */
	public function test_get_preferred_models_for_text_generation_returns_array() {
		$result = \WordPress\AI\get_preferred_models_for_text_generation();

		$this->assertIsArray( $result, 'Should return an array' );
		$this->assertNotEmpty( $result, 'Should not be empty' );
	}

	/**
	 * Test that get_preferred_models_for_text_generation() returns expected default models.
	 *
	 * @since 0.1.0
	 */
	public function test_get_preferred_models_for_text_generation_returns_default_models() {
		$result = \WordPress\AI\get_preferred_models_for_text_generation();

		$this->assertCount( 4, $result, 'Should have 4 preferred models' );

		// Check first model (anthropic).
		$this->assertIsArray( $result[0], 'First model should be an array' );
		$this->assertCount( 2, $result[0], 'First model should have 2 elements' );
		$this->assertEquals( 'anthropic', $result[0][0], 'First model provider should be anthropic' );
		$this->assertEquals( 'claude-haiku-4-5', $result[0][1], 'First model name should be claude-haiku-4-5' );

		// Check second model (google).
		$this->assertIsArray( $result[1], 'Second model should be an array' );
		$this->assertCount( 2, $result[1], 'Second model should have 2 elements' );
		$this->assertEquals( 'google', $result[1][0], 'Second model provider should be google' );
		$this->assertEquals( 'gemini-2.5-flash', $result[1][1], 'Second model name should be gemini-2.5-flash' );

		// Check third model (openai).
		$this->assertIsArray( $result[2], 'Third model should be an array' );
		$this->assertCount( 2, $result[2], 'Third model should have 2 elements' );
		$this->assertEquals( 'openai', $result[2][0], 'Third model provider should be openai' );
		$this->assertEquals( 'gpt-4o-mini', $result[2][1], 'Third model name should be gpt-4o-mini' );

		// Check fourth model (openai).
		$this->assertIsArray( $result[3], 'Fourth model should be an array' );
		$this->assertCount( 2, $result[3], 'Fourth model should have 2 elements' );
		$this->assertEquals( 'openai', $result[3][0], 'Fourth model provider should be openai' );
		$this->assertEquals( 'gpt-4.1', $result[3][1], 'Fourth model name should be gpt-4.1' );
	}

	/**
	 * Test that get_preferred_models_for_text_generation() applies filter.
	 *
	 * @since 0.1.0
	 */
	public function test_get_preferred_models_for_text_generation_applies_filter() {
		add_filter(
			'ai_experiments_preferred_models_for_text_generation',
			function( $models ) {
				// Add a custom model.
				$models[] = array(
					'custom',
					'custom-model',
				);
				return $models;
			}
		);

		$result = \WordPress\AI\get_preferred_models_for_text_generation();

		$this->assertCount( 5, $result, 'Should have 5 models after filter' );
		$this->assertEquals( 'custom', $result[4][0], 'Fifth model provider should be custom' );
		$this->assertEquals( 'custom-model', $result[4][1], 'Fifth model name should be custom-model' );

		remove_all_filters( 'ai_experiments_preferred_models_for_text_generation' );
	}

	/**
	 * Test that get_preferred_models_for_text_generation() filter can replace models.
	 *
	 * @since 0.1.0
	 */
	public function test_get_preferred_models_for_text_generation_filter_can_replace_models() {
		add_filter(
			'ai_experiments_preferred_models_for_text_generation',
			function( $models ) {
				// Replace with a single model.
				return array(
					array(
						'test',
						'test-model',
					),
				);
			}
		);

		$result = \WordPress\AI\get_preferred_models_for_text_generation();

		$this->assertCount( 1, $result, 'Should have 1 model after filter replacement' );
		$this->assertEquals( 'test', $result[0][0], 'Model provider should be test' );
		$this->assertEquals( 'test-model', $result[0][1], 'Model name should be test-model' );

		remove_all_filters( 'ai_experiments_preferred_models_for_text_generation' );
	}

	/**
	 * Test that get_preferred_image_models() returns an array.
	 *
	 * @since x.x.x
	 */
	public function test_get_preferred_image_models_returns_array() {
		$result = \WordPress\AI\get_preferred_image_models();

		$this->assertIsArray( $result, 'Should return an array' );
		$this->assertNotEmpty( $result, 'Should not be empty' );
	}

	/**
	 * Test that get_preferred_image_models() returns expected default models.
	 *
	 * @since x.x.x
	 */
	public function test_get_preferred_image_models_returns_default_models() {
		$result = \WordPress\AI\get_preferred_image_models();

		$this->assertCount( 5, $result, 'Should have 5 preferred image models' );

		// Check first model (google).
		$this->assertIsArray( $result[0], 'First model should be an array' );
		$this->assertCount( 2, $result[0], 'First model should have 2 elements' );
		$this->assertEquals( 'google', $result[0][0], 'First model provider should be google' );
		$this->assertEquals( 'gemini-3-pro-image-preview', $result[0][1], 'First model name should be gemini-3-pro-image-preview' );

		// Check second model (google).
		$this->assertIsArray( $result[1], 'Second model should be an array' );
		$this->assertCount( 2, $result[1], 'Second model should have 2 elements' );
		$this->assertEquals( 'google', $result[1][0], 'Second model provider should be google' );
		$this->assertEquals( 'gemini-2.5-flash-image', $result[1][1], 'Second model name should be gemini-2.5-flash-image' );

		// Check third model (google).
		$this->assertIsArray( $result[2], 'Third model should be an array' );
		$this->assertCount( 2, $result[2], 'Third model should have 2 elements' );
		$this->assertEquals( 'google', $result[2][0], 'Third model provider should be google' );
		$this->assertEquals( 'imagen-4.0-generate-001', $result[2][1], 'Third model name should be imagen-4.0-generate-001' );

		// Check third model (openai).
		$this->assertIsArray( $result[3], 'Fourth model should be an array' );
		$this->assertCount( 2, $result[3], 'Fourth model should have 2 elements' );
		$this->assertEquals( 'openai', $result[3][0], 'Fourth model provider should be openai' );
		$this->assertEquals( 'gpt-image-1', $result[3][1], 'Fourth model name should be gpt-image-1' );

		// Check fourth model (openai).
		$this->assertIsArray( $result[4], 'Fifth model should be an array' );
		$this->assertCount( 2, $result[4], 'Fifth model should have 2 elements' );
		$this->assertEquals( 'openai', $result[4][0], 'Fifth model provider should be openai' );
		$this->assertEquals( 'dall-e-3', $result[4][1], 'Fifth model name should be dall-e-3' );
	}

	/**
	 * Test that get_preferred_image_models() applies filter.
	 *
	 * @since x.x.x
	 */
	public function test_get_preferred_image_models_applies_filter() {
		add_filter(
			'ai_experiments_preferred_image_models',
			function( $models ) {
				// Add a custom model.
				$models[] = array(
					'custom',
					'custom-image-model',
				);
				return $models;
			}
		);

		$result = \WordPress\AI\get_preferred_image_models();

		$this->assertCount( 6, $result, 'Should have 6 models after filter' );
		$this->assertEquals( 'custom', $result[5][0], 'Sixth model provider should be custom' );
		$this->assertEquals( 'custom-image-model', $result[5][1], 'Sixth model name should be custom-image-model' );

		remove_all_filters( 'ai_experiments_preferred_image_models' );
	}

	/**
	 * Test that get_preferred_image_models() filter can replace models.
	 *
	 * @since x.x.x
	 */
	public function test_get_preferred_image_models_filter_can_replace_models() {
		add_filter(
			'ai_experiments_preferred_image_models',
			function( $models ) {
				// Replace with a single model.
				return array(
					array(
						'test',
						'test-image-model',
					),
				);
			}
		);

		$result = \WordPress\AI\get_preferred_image_models();

		$this->assertCount( 1, $result, 'Should have 1 model after filter replacement' );
		$this->assertEquals( 'test', $result[0][0], 'Model provider should be test' );
		$this->assertEquals( 'test-image-model', $result[0][1], 'Model name should be test-image-model' );

		remove_all_filters( 'ai_experiments_preferred_image_models' );
	}
}
