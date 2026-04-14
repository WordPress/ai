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
	 * Test that normalize_content() replaces HTML linebreaks and removes linebreaks.
	 *
	 * @since 0.1.0
	 */
	public function test_normalize_content_replaces_linebreaks() {
		$content = 'Line 1<br>Line 2<br/>Line 3';
		$result  = \WordPress\AI\normalize_content( $content );

		$this->assertStringNotContainsString( '<br>', $result, 'Should remove br tags' );
		$this->assertStringNotContainsString( "\n", $result, 'Should replace newlines with spaces' );
		$this->assertStringNotContainsString( "\r", $result, 'Should replace carriage returns with spaces' );
		$this->assertStringContainsString( 'Line 1', $result, 'Should preserve Line 1' );
		$this->assertStringContainsString( 'Line 2', $result, 'Should preserve Line 2' );
		$this->assertStringContainsString( 'Line 3', $result, 'Should preserve Line 3' );
	}

	/**
	 * Test that normalize_content() removes linebreaks and replaces with spaces.
	 *
	 * @since 0.1.0
	 */
	public function test_normalize_content_removes_linebreaks() {
		$content = "Line 1\nLine 2\rLine 3\r\nLine 4";
		$result  = \WordPress\AI\normalize_content( $content );

		$this->assertStringNotContainsString( "\n", $result, 'Should replace newlines with spaces' );
		$this->assertStringNotContainsString( "\r", $result, 'Should replace carriage returns with spaces' );
		$this->assertStringContainsString( 'Line 1', $result, 'Should preserve Line 1' );
		$this->assertStringContainsString( 'Line 2', $result, 'Should preserve Line 2' );
		$this->assertStringContainsString( 'Line 3', $result, 'Should preserve Line 3' );
		$this->assertStringContainsString( 'Line 4', $result, 'Should preserve Line 4' );
		// Verify lines are separated by spaces, not running together
		$this->assertStringContainsString( 'Line 1 Line 2', $result, 'Lines should be separated by spaces' );
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
		add_filter(
			'wpai_pre_normalize_content',
			static function ( $content ) {
				return 'Filtered: ' . $content;
			}
		);

		$result = \WordPress\AI\normalize_content( 'test' );

		$this->assertStringContainsString( 'Filtered:', $result, 'Should apply pre-normalize filter' );

		remove_all_filters( 'wpai_pre_normalize_content' );
	}

	/**
	 * Test that get_post_context() returns empty array for non-existent post.
	 *
	 * @since 0.1.0
	 */
	public function test_get_post_context_returns_empty_for_nonexistent_post() {
		// Expect the incorrect usage notice when abilities are called with non-existent posts.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

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
	 * Test that the wpai_get_post_details filter modifies the ability output.
	 *
	 * @since 0.7.0
	 */
	public function test_wpai_get_post_details_filter_modifies_output() {
		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Original Title',
				'post_content' => 'Original content',
			)
		);

		$filter_callback = static function ( $details ) {
			$details['title'] = 'Filtered Title';
			return $details;
		};

		add_filter( 'wpai_get_post_details', $filter_callback );

		$ability = wp_get_ability( 'ai/get-post-details' );
		$this->assertNotNull( $ability, 'get-post-details ability should be registered' );

		$result = $ability->execute( array( 'post_id' => $post_id ) );

		remove_filter( 'wpai_get_post_details', $filter_callback );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'title', $result, 'Result should have title key' );
		$this->assertEquals( 'Filtered Title', $result['title'], 'Filter should have modified the title' );
	}

	/**
	 * Test that the wpai_get_post_details filter receives the correct arguments.
	 *
	 * @since 0.7.0
	 */
	public function test_wpai_get_post_details_filter_receives_arguments() {
		$post_id = $this->factory->post->create(
			array(
				'post_title' => 'Test Post',
			)
		);

		$filter_callback = static function ( $details, $filter_post_id, $filter_fields ) {
			$details['title'] = sprintf( 'post:%d|fields:%s', $filter_post_id, implode( ',', $filter_fields ) );
			return $details;
		};

		add_filter( 'wpai_get_post_details', $filter_callback, 10, 3 );

		$ability = wp_get_ability( 'ai/get-post-details' );
		$result  = $ability->execute(
			array(
				'post_id' => $post_id,
				'fields'  => array( 'title', 'slug' ),
			)
		);

		remove_filter( 'wpai_get_post_details', $filter_callback, 10 );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'title', $result, 'Result should include title' );
		$this->assertSame(
			sprintf( 'post:%d|fields:title,slug', $post_id ),
			$result['title'],
			'Filter output should encode the received post ID and requested fields'
		);
	}

	/**
	 * Test that the wpai_get_post_terms filter modifies the ability output.
	 *
	 * @since 0.7.0
	 */
	public function test_wpai_get_post_terms_filter_modifies_output() {
		$category_id = $this->factory->category->create( array( 'name' => 'Original Category' ) );
		$post_id     = $this->factory->post->create();
		wp_set_post_categories( $post_id, array( $category_id ) );

		$filter_callback = static function () {
			// Replace terms with an empty array.
			return array();
		};

		add_filter( 'wpai_get_post_terms', $filter_callback );

		$ability = wp_get_ability( 'ai/get-post-terms' );
		$this->assertNotNull( $ability, 'get-post-terms ability should be registered' );

		$result = $ability->execute( array( 'post_id' => $post_id ) );

		remove_filter( 'wpai_get_post_terms', $filter_callback );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertEmpty( $result, 'Filter should have replaced the terms with an empty array' );
	}

	/**
	 * Test that the wpai_get_post_terms filter receives the correct arguments.
	 *
	 * @since 0.7.0
	 */
	public function test_wpai_get_post_terms_filter_receives_arguments() {
		$category_id = $this->factory->category->create( array( 'name' => 'Test Category' ) );
		$post_id     = $this->factory->post->create();
		wp_set_post_categories( $post_id, array( $category_id ) );

		$filter_callback = static function ( $terms, $filter_post_id, $filter_taxonomies ) {
			$terms['category'] = sprintf( 'post:%d|taxonomies:%s', $filter_post_id, implode( ',', $filter_taxonomies ) );
			return $terms;
		};

		add_filter( 'wpai_get_post_terms', $filter_callback, 10, 3 );

		$ability = wp_get_ability( 'ai/get-post-terms' );
		$result  = $ability->execute( array( 'post_id' => $post_id ) );

		remove_filter( 'wpai_get_post_terms', $filter_callback, 10 );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'category', $result, 'Result should include category key' );
		$this->assertSame(
			sprintf( 'post:%d|taxonomies:category,post_tag', $post_id ),
			$result['category'],
			'Filter output should encode the received post ID and taxonomies'
		);
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

		$this->assertCount( 5, $result, 'Should have 5 preferred models' );

		// Check first model (anthropic).
		$this->assertIsArray( $result[0], 'First model should be an array' );
		$this->assertCount( 2, $result[0], 'First model should have 2 elements' );
		$this->assertEquals( 'anthropic', $result[0][0], 'First model provider should be anthropic' );
		$this->assertEquals( 'claude-sonnet-4-6', $result[0][1], 'First model name should be claude-sonnet-4-6' );

		// Check second model (google).
		$this->assertIsArray( $result[1], 'Second model should be an array' );
		$this->assertCount( 2, $result[1], 'Second model should have 2 elements' );
		$this->assertEquals( 'google', $result[1][0], 'Second model provider should be google' );
		$this->assertEquals( 'gemini-3-flash-preview', $result[1][1], 'Second model name should be gemini-3-flash-preview' );

		// Check third model (google).
		$this->assertIsArray( $result[2], 'Third model should be an array' );
		$this->assertCount( 2, $result[2], 'Third model should have 2 elements' );
		$this->assertEquals( 'google', $result[2][0], 'Third model provider should be google' );
		$this->assertEquals( 'gemini-2.5-flash', $result[2][1], 'Third model name should be gemini-2.5-flash' );

		// Check fourth model (openai).
		$this->assertIsArray( $result[3], 'Fourth model should be an array' );
		$this->assertCount( 2, $result[3], 'Fourth model should have 2 elements' );
		$this->assertEquals( 'openai', $result[3][0], 'Fourth model provider should be openai' );
		$this->assertEquals( 'gpt-5.4-mini', $result[3][1], 'Fourth model name should be gpt-5.4-mini' );

		// Check fifth model (openai).
		$this->assertIsArray( $result[4], 'Fifth model should be an array' );
		$this->assertCount( 2, $result[4], 'Fifth model should have 2 elements' );
		$this->assertEquals( 'openai', $result[4][0], 'Fifth model provider should be openai' );
		$this->assertEquals( 'gpt-4.1-mini', $result[4][1], 'Fifth model name should be gpt-4.1-mini' );
	}

	/**
	 * Test that get_preferred_models_for_text_generation() applies filter.
	 *
	 * @since 0.1.0
	 */
	public function test_get_preferred_models_for_text_generation_applies_filter() {
		add_filter(
			'wpai_preferred_text_models',
			static function ( $models ) {
				// Add a custom model.
				$models[] = array(
					'custom',
					'custom-model',
				);
				return $models;
			}
		);

		$result = \WordPress\AI\get_preferred_models_for_text_generation();

		$this->assertCount( 6, $result, 'Should have 6 models after filter' );
		$this->assertEquals( 'custom', $result[5][0], 'Sixth model provider should be custom' );
		$this->assertEquals( 'custom-model', $result[5][1], 'Sixth model name should be custom-model' );

		remove_all_filters( 'wpai_preferred_text_models' );
	}

	/**
	 * Test that get_preferred_models_for_text_generation() filter can replace models.
	 *
	 * @since 0.1.0
	 */
	public function test_get_preferred_models_for_text_generation_filter_can_replace_models() {
		add_filter(
			'wpai_preferred_text_models',
			static function () {
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

		remove_all_filters( 'wpai_preferred_text_models' );
	}

	/**
	 * Test that get_preferred_image_models() returns an array.
	 *
	 * @since 0.2.0
	 */
	public function test_get_preferred_image_models_returns_array() {
		$result = \WordPress\AI\get_preferred_image_models();

		$this->assertIsArray( $result, 'Should return an array' );
		$this->assertNotEmpty( $result, 'Should not be empty' );
	}

	/**
	 * Test that get_preferred_image_models() returns expected default models.
	 *
	 * @since 0.2.0
	 */
	public function test_get_preferred_image_models_returns_default_models() {
		$result = \WordPress\AI\get_preferred_image_models();

		$this->assertCount( 6, $result, 'Should have 6 preferred image models' );

		// Check first model (google).
		$this->assertIsArray( $result[0], 'First model should be an array' );
		$this->assertCount( 2, $result[0], 'First model should have 2 elements' );
		$this->assertEquals( 'google', $result[0][0], 'First model provider should be google' );
		$this->assertEquals( 'gemini-3.1-flash-image-preview', $result[0][1], 'First model name should be gemini-3.1-flash-image-preview' );

		// Check second model (google).
		$this->assertIsArray( $result[1], 'Second model should be an array' );
		$this->assertCount( 2, $result[1], 'Second model should have 2 elements' );
		$this->assertEquals( 'google', $result[1][0], 'Second model provider should be google' );
		$this->assertEquals( 'gemini-3-pro-image-preview', $result[1][1], 'Second model name should be gemini-3-pro-image-preview' );

		// Check third model (google).
		$this->assertIsArray( $result[2], 'Third model should be an array' );
		$this->assertCount( 2, $result[2], 'Third model should have 2 elements' );
		$this->assertEquals( 'google', $result[2][0], 'Third model provider should be google' );
		$this->assertEquals( 'gemini-2.5-flash-image', $result[2][1], 'Third model name should be gemini-2.5-flash-image' );

		// Check fourth model (google).
		$this->assertIsArray( $result[3], 'Fourth model should be an array' );
		$this->assertCount( 2, $result[3], 'Fourth model should have 2 elements' );
		$this->assertEquals( 'google', $result[3][0], 'Fourth model provider should be google' );
		$this->assertEquals( 'imagen-4.0-generate-001', $result[3][1], 'Fourth model name should be imagen-4.0-generate-001' );

		// Check fifth model (openai).
		$this->assertIsArray( $result[4], 'Fifth model should be an array' );
		$this->assertCount( 2, $result[4], 'Fifth model should have 2 elements' );
		$this->assertEquals( 'openai', $result[4][0], 'Fifth model provider should be openai' );
		$this->assertEquals( 'gpt-image-1.5', $result[4][1], 'Fifth model name should be gpt-image-1.5' );

		// Check sixth model (openai).
		$this->assertIsArray( $result[5], 'Sixth model should be an array' );
		$this->assertCount( 2, $result[5], 'Sixth model should have 2 elements' );
		$this->assertEquals( 'openai', $result[5][0], 'Sixth model provider should be openai' );
		$this->assertEquals( 'gpt-image-1-mini', $result[5][1], 'Sixth model name should be gpt-image-1' );
	}

	/**
	 * Test that get_preferred_image_models() applies filter.
	 *
	 * @since 0.2.0
	 */
	public function test_get_preferred_image_models_applies_filter() {
		add_filter(
			'wpai_preferred_image_models',
			static function ( $models ) {
				// Add a custom model.
				$models[] = array(
					'custom',
					'custom-image-model',
				);
				return $models;
			}
		);

		$result = \WordPress\AI\get_preferred_image_models();

		$this->assertCount( 7, $result, 'Should have 7 models after filter' );
		$this->assertEquals( 'custom', $result[6][0], 'Seventh model provider should be custom' );
		$this->assertEquals( 'custom-image-model', $result[6][1], 'Seventh model name should be custom-image-model' );

		remove_all_filters( 'wpai_preferred_image_models' );
	}

	/**
	 * Test that get_preferred_image_models() filter can replace models.
	 *
	 * @since 0.2.0
	 */
	public function test_get_preferred_image_models_filter_can_replace_models() {
		add_filter(
			'wpai_preferred_image_models',
			static function ( $models ) {
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

		remove_all_filters( 'wpai_preferred_image_models' );
	}

	/**
	 * Test that get_preferred_vision_models() returns an array.
	 *
	 * @since 0.3.0
	 */
	public function test_get_preferred_vision_models_returns_array() {
		$result = \WordPress\AI\get_preferred_vision_models();

		$this->assertIsArray( $result, 'Should return an array' );
		$this->assertNotEmpty( $result, 'Should not be empty' );
	}

	/**
	 * Test that get_preferred_vision_models() returns expected default models.
	 *
	 * @since 0.3.0
	 */
	public function test_get_preferred_vision_models_returns_default_models() {
		$result = \WordPress\AI\get_preferred_vision_models();

		$this->assertCount( 5, $result, 'Should have 5 preferred vision models' );

		$this->assertIsArray( $result[0], 'First model should be an array' );
		$this->assertCount( 2, $result[0], 'First model should have 2 elements' );
		$this->assertEquals( 'anthropic', $result[0][0], 'First model provider should be anthropic' );
		$this->assertEquals( 'claude-sonnet-4-6', $result[0][1], 'First model name should be claude-sonnet-4-6' );

		$this->assertIsArray( $result[1], 'Second model should be an array' );
		$this->assertCount( 2, $result[1], 'Second model should have 2 elements' );
		$this->assertEquals( 'google', $result[1][0], 'Second model provider should be google' );
		$this->assertEquals( 'gemini-3-flash-preview', $result[1][1], 'Second model name should be gemini-3-flash-preview' );

		$this->assertIsArray( $result[2], 'Third model should be an array' );
		$this->assertCount( 2, $result[2], 'Third model should have 2 elements' );
		$this->assertEquals( 'google', $result[2][0], 'Third model provider should be google' );
		$this->assertEquals( 'gemini-2.5-flash', $result[2][1], 'Third model name should be gemini-2.5-flash' );

		$this->assertIsArray( $result[3], 'Fourth model should be an array' );
		$this->assertCount( 2, $result[3], 'Fourth model should have 2 elements' );
		$this->assertEquals( 'openai', $result[3][0], 'Fourth model provider should be openai' );
		$this->assertEquals( 'gpt-5.4-mini', $result[3][1], 'Fourth model name should be gpt-5.4-mini' );

		$this->assertIsArray( $result[4], 'Fifth model should be an array' );
		$this->assertCount( 2, $result[4], 'Fifth model should have 2 elements' );
		$this->assertEquals( 'openai', $result[4][0], 'Fifth model provider should be openai' );
		$this->assertEquals( 'gpt-4.1-mini', $result[4][1], 'Fifth model name should be gpt-4.1-mini' );
	}

	/**
	 * Test that get_preferred_vision_models() applies filter.
	 *
	 * @since 0.3.0
	 */
	public function test_get_preferred_vision_models_applies_filter() {
		add_filter(
			'wpai_preferred_vision_models',
			static function ( $models ) {
				$models[] = array(
					'custom',
					'custom-vision-model',
				);
				return $models;
			}
		);

		$result = \WordPress\AI\get_preferred_vision_models();

		$this->assertCount( 6, $result, 'Should have 6 models after filter' );
		$this->assertEquals( 'custom', $result[5][0], 'Sixth model provider should be custom' );
		$this->assertEquals( 'custom-vision-model', $result[5][1], 'Sixth model name should be custom-vision-model' );

		remove_all_filters( 'wpai_preferred_vision_models' );
	}

	/**
	 * Test that get_preferred_vision_models() filter can replace models.
	 *
	 * @since 0.3.0
	 */
	public function test_get_preferred_vision_models_filter_can_replace_models() {
		add_filter(
			'wpai_preferred_vision_models',
			static function ( $models ) {
				return array(
					array(
						'test',
						'test-vision-model',
					),
				);
			}
		);

		$result = \WordPress\AI\get_preferred_vision_models();

		$this->assertCount( 1, $result, 'Should have 1 model after filter replacement' );
		$this->assertEquals( 'test', $result[0][0], 'Model provider should be test' );
		$this->assertEquals( 'test-vision-model', $result[0][1], 'Model name should be test-vision-model' );

		remove_all_filters( 'wpai_preferred_vision_models' );
	}
}
