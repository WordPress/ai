<?php
/**
 * Integration tests for the Markdown_Feed_Renderer class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Markdown_Feeds
 */

namespace WordPress\AI\Tests\Integration\Experiments\Markdown_Feeds;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Markdown_Feeds\Markdown_Feed_Renderer;

/**
 * Markdown_Feed_Renderer test case.
 *
 * @since x.x.x
 */
class Markdown_Feed_RendererTest extends WP_UnitTestCase {

	/**
	 * Tests that the feed document contains the site header and every queried post.
	 */
	public function test_renders_site_header_and_items(): void {
		self::factory()->post->create(
			array(
				'post_title'   => 'First Feed Post',
				'post_content' => '<p>First <strong>content</strong>.</p>',
			)
		);
		self::factory()->post->create( array( 'post_title' => 'Second Feed Post' ) );

		$this->go_to( '/?feed=markdown' );
		$markdown = ( new Markdown_Feed_Renderer() )->render();

		$this->assertStringContainsString( '# ' . get_bloginfo( 'name' ), $markdown );
		$this->assertStringContainsString( '## First Feed Post', $markdown );
		$this->assertStringContainsString( '## Second Feed Post', $markdown );
		$this->assertStringContainsString( '**content**', $markdown );
	}

	/**
	 * Tests that the site's "feed shows excerpt" setting is respected.
	 */
	public function test_respects_rss_use_excerpt_option(): void {
		self::factory()->post->create(
			array(
				'post_title'   => 'Excerpted Post',
				'post_content' => '<p>Full body that should not appear.</p>',
				'post_excerpt' => 'Short excerpt only.',
			)
		);
		update_option( 'rss_use_excerpt', '1' );

		$this->go_to( '/?feed=markdown' );
		$markdown = ( new Markdown_Feed_Renderer() )->render();

		$this->assertStringContainsString( 'Short excerpt only.', $markdown );
		$this->assertStringNotContainsString( 'Full body that should not appear.', $markdown );
	}

	/**
	 * Tests that per-item sections are filterable.
	 */
	public function test_item_sections_are_filterable(): void {
		self::factory()->post->create( array( 'post_title' => 'Filtered Post' ) );

		add_filter(
			'wpai_markdown_feed_item_sections',
			static function ( array $sections ): array {
				$sections['custom'] = 'FEED ITEM MARKER';
				return $sections;
			}
		);

		$this->go_to( '/?feed=markdown' );
		$markdown = ( new Markdown_Feed_Renderer() )->render();

		$this->assertStringContainsString( 'FEED ITEM MARKER', $markdown );
	}

	/**
	 * Tests that a category feed context only renders that category's posts.
	 */
	public function test_category_feed_scopes_items(): void {
		$category_id = self::factory()->category->create( array( 'slug' => 'md-cat' ) );
		self::factory()->post->create(
			array(
				'post_title'    => 'In Category Post',
				'post_category' => array( $category_id ),
			)
		);
		self::factory()->post->create( array( 'post_title' => 'Out Of Category Post' ) );

		$this->go_to( '/?cat=' . $category_id . '&feed=markdown' );
		$markdown = ( new Markdown_Feed_Renderer() )->render();

		$this->assertStringContainsString( '## In Category Post', $markdown );
		$this->assertStringNotContainsString( '## Out Of Category Post', $markdown );
	}
}
