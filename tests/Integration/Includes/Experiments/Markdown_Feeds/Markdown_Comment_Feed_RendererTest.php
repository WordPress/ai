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
	 * Tests that the comment and post globals point at the comment being rendered.
	 */
	public function test_comment_text_filters_see_the_current_comment_and_post(): void {
		$first_post  = self::factory()->post->create( array( 'post_title' => 'First Context Post' ) );
		$second_post = self::factory()->post->create( array( 'post_title' => 'Second Context Post' ) );
		$first       = self::factory()->comment->create(
			array(
				'comment_post_ID' => $first_post,
				'comment_content' => 'First context comment.',
			)
		);
		$second      = self::factory()->comment->create(
			array(
				'comment_post_ID' => $second_post,
				'comment_content' => 'Second context comment.',
			)
		);

		add_filter(
			'comment_text',
			static function ( string $text ): string {
				return $text . ' [comment ' . get_comment_ID() . ' on post ' . get_the_ID() . ']';
			}
		);

		$this->go_to( '/?feed=markdown&withcomments=1' );
		$queried  = get_queried_object_id();
		$markdown = ( new Markdown_Comment_Feed_Renderer() )->render();

		$this->assertStringContainsString( "First context comment. [comment {$first} on post {$first_post}]", $markdown );
		$this->assertStringContainsString( "Second context comment. [comment {$second} on post {$second_post}]", $markdown );
		$this->assertSame( $queried, get_queried_object_id(), 'The main query should be untouched after rendering.' );
	}

	/**
	 * Tests that the comment author is escaped in the item heading.
	 */
	public function test_comment_author_is_escaped_in_the_heading(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Escape Post' ) );
		self::factory()->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'comment_author'  => 'Tom & <b>Jerry</b>',
				'comment_content' => 'Plain text.',
			)
		);

		$this->go_to( '/?p=' . $post_id . '&feed=markdown' );
		$markdown = ( new Markdown_Comment_Feed_Renderer() )->render();

		$this->assertStringContainsString( '## By: Tom &amp; &lt;b&gt;Jerry&lt;/b&gt;', $markdown );
		$this->assertStringNotContainsString( '<b>', $markdown );
	}

	/**
	 * Tests that the comment loop is rewound after rendering, so it can run again.
	 */
	public function test_comment_loop_is_rewound_after_rendering(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Rewind Post' ) );
		self::factory()->comment->create_many( 2, array( 'comment_post_ID' => $post_id ) );

		$this->go_to( '/?p=' . $post_id . '&feed=markdown' );
		$renderer = new Markdown_Comment_Feed_Renderer();
		$markdown = $renderer->render();

		$this->assertSame( -1, $GLOBALS['wp_query']->current_comment );
		$this->assertTrue( $GLOBALS['wp_query']->have_comments() );
		$this->assertSame( $markdown, $renderer->render() );
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
