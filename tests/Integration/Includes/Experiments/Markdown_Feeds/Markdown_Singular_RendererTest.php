<?php
/**
 * Integration tests for the Markdown_Singular_Renderer class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Markdown_Feeds
 */

namespace WordPress\AI\Tests\Integration\Experiments\Markdown_Feeds;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Markdown_Feeds\Markdown_Singular_Renderer;

/**
 * Markdown_Singular_Renderer test case.
 *
 * @since x.x.x
 */
class Markdown_Singular_RendererTest extends WP_UnitTestCase {

	/**
	 * Tests that the document contains title, permalink, and converted content.
	 */
	public function test_renders_title_meta_and_content(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Hello Markdown',
				'post_content' => '<p>Some <strong>bold</strong> text.</p>',
				'post_status'  => 'publish',
			)
		);
		$post    = get_post( $post_id );

		$markdown = ( new Markdown_Singular_Renderer() )->render( $post );

		$this->assertStringContainsString( '# Hello Markdown', $markdown );
		$this->assertStringContainsString( get_permalink( $post ), $markdown );
		$this->assertStringContainsString( '**bold**', $markdown );
	}

	/**
	 * Tests that block markup is rendered (delimiter comments must not leak through).
	 */
	public function test_renders_block_content_without_block_comments(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => "<!-- wp:paragraph -->\n<p>Block content here.</p>\n<!-- /wp:paragraph -->",
				'post_status'  => 'publish',
			)
		);

		$markdown = ( new Markdown_Singular_Renderer() )->render( get_post( $post_id ) );

		$this->assertStringContainsString( 'Block content here.', $markdown );
		$this->assertStringNotContainsString( 'wp:paragraph', $markdown );
	}

	/**
	 * Tests that the sections filter can inject and remove sections.
	 */
	public function test_sections_are_filterable(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		add_filter(
			'wpai_markdown_singular_sections',
			static function ( array $sections ): array {
				unset( $sections['meta'] );
				$sections['custom'] = 'CUSTOM SECTION MARKER';
				return $sections;
			}
		);

		$markdown = ( new Markdown_Singular_Renderer() )->render( get_post( $post_id ) );

		$this->assertStringContainsString( 'CUSTOM SECTION MARKER', $markdown );
		$this->assertStringNotContainsString( 'Published:', $markdown );
	}
}
