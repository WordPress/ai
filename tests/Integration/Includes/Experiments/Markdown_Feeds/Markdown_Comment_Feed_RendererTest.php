<?php
/**
 * Integration tests for the Markdown_Comment_Feed_Renderer class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Markdown_Feeds
 */

namespace WordPress\AI\Tests\Integration\Experiments\Markdown_Feeds;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Markdown_Feeds\Markdown_Comment_Feed_Renderer;

/**
 * Markdown_Comment_Feed_Renderer test case.
 *
 * @since x.x.x
 */
class Markdown_Comment_Feed_RendererTest extends WP_UnitTestCase {

	/**
	 * Tests that the site-wide comment feed lists comments, not posts.
	 */
	public function test_site_comment_feed_renders_comments_not_posts(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Commented Post',
				'post_content' => '<p>Post body that must not appear.</p>',
			)
		);
		self::factory()->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'comment_author'  => 'Reader',
				'comment_content' => 'A <strong>thoughtful</strong> comment.',
			)
		);

		$this->go_to( '/?feed=markdown&withcomments=1' );
		$markdown = ( new Markdown_Comment_Feed_Renderer() )->render();

		$this->assertTrue( is_comment_feed() );
		$this->assertStringContainsString( '# Comments for ' . get_bloginfo( 'name' ), $markdown );
		$this->assertStringContainsString( '## Comment on Commented Post by Reader', $markdown );
		$this->assertStringContainsString( 'A **thoughtful** comment.', $markdown );
		$this->assertStringNotContainsString( 'Post body that must not appear.', $markdown );
	}

	/**
	 * Tests that a post's comment feed lists the comments on that post only.
	 */
	public function test_post_comment_feed_lists_only_that_posts_comments(): void {
		$post_id  = self::factory()->post->create(
			array(
				'post_title'   => 'First Post',
				'post_content' => '<p>First body.</p>',
			)
		);
		$other_id = self::factory()->post->create( array( 'post_title' => 'Other Post' ) );
		self::factory()->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'comment_author'  => 'Alpha',
				'comment_content' => 'Alpha comment.',
			)
		);
		self::factory()->comment->create(
			array(
				'comment_post_ID' => $other_id,
				'comment_author'  => 'Beta',
				'comment_content' => 'Beta comment.',
			)
		);

		$this->go_to( '/?p=' . $post_id . '&feed=markdown' );
		$markdown = ( new Markdown_Comment_Feed_Renderer() )->render();

		$this->assertStringContainsString( "# Comments on: First Post\n\n- Link: " . get_permalink( $post_id ), $markdown );
		$this->assertStringContainsString( '## By: Alpha', $markdown );
		$this->assertStringContainsString( 'Alpha comment.', $markdown );
		$this->assertStringNotContainsString( 'Beta', $markdown );
		$this->assertStringNotContainsString( 'First body.', $markdown );
	}

	/**
	 * Tests that a comment feed without comments renders only the header.
	 */
	public function test_empty_comment_feed_renders_only_the_header(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Quiet Post' ) );

		$this->go_to( '/?p=' . $post_id . '&feed=markdown' );
		$markdown = ( new Markdown_Comment_Feed_Renderer() )->render();

		$this->assertSame( "# Comments on: Quiet Post\n\n- Link: " . get_permalink( $post_id ) . "\n", $markdown );
	}

	/**
	 * Tests that comments on password-protected posts are listed without their text.
	 */
	public function test_comments_on_protected_posts_hide_their_text(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'    => 'Locked Post',
				'post_password' => 'secret',
			)
		);
		self::factory()->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'comment_author'  => 'Insider',
				'comment_content' => 'Hidden comment text.',
			)
		);

		$this->go_to( '/?feed=markdown&withcomments=1' );
		$markdown = ( new Markdown_Comment_Feed_Renderer() )->render();

		$this->assertStringContainsString( '## Comment on Protected: Locked Post by Insider', $markdown );
		$this->assertStringContainsString( 'Protected Comments: Please enter your password to view comments.', $markdown );
		$this->assertStringNotContainsString( 'Hidden comment text.', $markdown );
	}

	/**
	 * Tests that per-comment sections are filterable.
	 */
	public function test_item_sections_are_filterable(): void {
		$post_id = self::factory()->post->create();
		self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );

		add_filter(
			'wpai_markdown_comment_feed_item_sections',
			static function ( array $sections ): array {
				$sections['custom'] = 'COMMENT MARKER';
				return $sections;
			}
		);

		$this->go_to( '/?feed=markdown&withcomments=1' );
		$markdown = ( new Markdown_Comment_Feed_Renderer() )->render();

		$this->assertStringContainsString( 'COMMENT MARKER', $markdown );
	}
}
