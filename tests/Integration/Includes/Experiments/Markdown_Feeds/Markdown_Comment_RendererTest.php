<?php
/**
 * Integration tests for the Markdown_Comment_Renderer class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Markdown_Feeds
 */

namespace WordPress\AI\Tests\Integration\Experiments\Markdown_Feeds;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Markdown_Feeds\Markdown_Comment_Renderer;

/**
 * Markdown_Comment_Renderer test case.
 *
 * @since x.x.x
 */
class Markdown_Comment_RendererTest extends WP_UnitTestCase {

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
		$markdown = ( new Markdown_Comment_Renderer() )->render_feed();

		$this->assertTrue( is_comment_feed() );
		$this->assertStringContainsString( '# Comments for ' . get_bloginfo( 'name' ), $markdown );
		$this->assertStringContainsString( '## Comment on Commented Post by Reader', $markdown );
		$this->assertStringContainsString( 'A **thoughtful** comment.', $markdown );
		$this->assertStringNotContainsString( 'Post body that must not appear.', $markdown );
	}

	/**
	 * Tests that a post's Comments section lists the approved comments on that post only, one heading level down.
	 */
	public function test_post_comments_section_lists_only_that_posts_comments(): void {
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

		$markdown = ( new Markdown_Comment_Renderer() )->render_post_comments( get_post( $post_id ) );

		$this->assertStringStartsWith( "## Comments\n\n### By: Alpha\n\n- Link: ", $markdown );
		$this->assertStringContainsString( 'Alpha comment.', $markdown );
		$this->assertStringNotContainsString( 'Beta', $markdown );
		$this->assertStringNotContainsString( 'First body.', $markdown );
		$this->assertStringNotContainsString( 'First Post', $markdown );
	}

	/**
	 * Tests that a post without comments gets no section and that an empty site-wide feed renders only the header.
	 */
	public function test_feeds_without_comments(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Quiet Post' ) );

		$this->assertSame( '', ( new Markdown_Comment_Renderer() )->render_post_comments( get_post( $post_id ) ) );

		$this->go_to( '/?feed=markdown&withcomments=1' );
		$this->assertSame( '# Comments for ' . get_bloginfo( 'name' ) . "\n\n- Site: " . home_url( '/' ) . "\n", ( new Markdown_Comment_Renderer() )->render_feed() );
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
		$markdown = ( new Markdown_Comment_Renderer() )->render_feed();

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
		$markdown = ( new Markdown_Comment_Renderer() )->render_feed();

		$this->assertStringContainsString( "First context comment. \\[comment {$first} on post {$first_post}\\]", $markdown );
		$this->assertStringContainsString( "Second context comment. \\[comment {$second} on post {$second_post}\\]", $markdown );
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

		$markdown = ( new Markdown_Comment_Renderer() )->render_post_comments( get_post( $post_id ) );

		$this->assertStringContainsString( '### By: Tom &amp; &lt;b&gt;Jerry&lt;/b&gt;', $markdown );
		$this->assertStringNotContainsString( '<b>', $markdown );
	}

	/**
	 * Tests that the comment loop is rewound after rendering the feed, so it can run again.
	 */
	public function test_comment_loop_is_rewound_after_rendering(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Rewind Post' ) );
		self::factory()->comment->create_many( 2, array( 'comment_post_ID' => $post_id ) );

		$this->go_to( '/?feed=markdown&withcomments=1' );
		$renderer = new Markdown_Comment_Renderer();
		$markdown = $renderer->render_feed();

		$this->assertStringContainsString( '## Comment on Rewind Post by', $markdown );
		$this->assertSame( -1, $GLOBALS['wp_query']->current_comment );
		$this->assertTrue( $GLOBALS['wp_query']->have_comments() );
		$this->assertSame( $markdown, $renderer->render_feed() );
	}

	/**
	 * Tests that the comment_text filters see the comment being rendered in a post's Comments section.
	 */
	public function test_post_comments_set_the_comment_global_for_filters(): void {
		$post_id    = self::factory()->post->create();
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'comment_content' => 'Global check.',
			)
		);

		add_filter(
			'comment_text',
			static function ( string $text ): string {
				$comment = $GLOBALS['comment'] ?? null;
				return $text . ' [comment ' . ( $comment instanceof \WP_Comment ? (int) $comment->comment_ID : 0 ) . ' on post ' . get_the_ID() . ']';
			}
		);

		$before   = $GLOBALS['comment'] ?? null;
		$markdown = ( new Markdown_Comment_Renderer() )->render_post_comments( get_post( $post_id ) );

		$this->assertStringContainsString( 'Global check. \\[comment ' . $comment_id . ' on post ' . $post_id . '\\]', $markdown );
		$this->assertSame( $before, $GLOBALS['comment'] ?? null, 'The comment global is restored to its previous value.' );
	}

	/**
	 * Tests that a post without comments causes no comment query, and that the caller's post global survives rendering.
	 */
	public function test_post_comments_skip_the_query_and_keep_the_post_global(): void {
		$quiet_id = self::factory()->post->create();
		$noisy_id = self::factory()->post->create();
		self::factory()->comment->create( array( 'comment_post_ID' => $noisy_id ) );

		$queries = 0;
		add_action(
			'pre_get_comments',
			static function () use ( &$queries ): void {
				++$queries;
			}
		);

		$renderer = new Markdown_Comment_Renderer();

		$this->assertSame( '', $renderer->render_post_comments( get_post( $quiet_id ) ) );
		$this->assertSame( 0, $queries, 'A post without comments runs no comment query.' );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating a caller's post context.
		$GLOBALS['post'] = get_post( $quiet_id );
		$this->assertStringContainsString( '## Comments', $renderer->render_post_comments( get_post( $noisy_id ) ) );
		$this->assertGreaterThan( 0, $queries );
		$this->assertSame( $quiet_id, $GLOBALS['post']->ID, 'The caller\'s post global is restored.' );
	}

	/**
	 * Tests that the comment query filter still runs for a post without comments of its own.
	 */
	public function test_comments_args_filter_runs_for_a_post_without_comments(): void {
		$quiet_id = self::factory()->post->create();
		$noisy_id = self::factory()->post->create();
		self::factory()->comment->create(
			array(
				'comment_post_ID' => $noisy_id,
				'comment_author'  => 'Borrowed',
				'comment_content' => 'Borrowed comment.',
			)
		);

		add_filter(
			'wpai_markdown_singular_comments_args',
			static function ( array $args ) use ( $noisy_id ): array {
				$args['post_id'] = $noisy_id;
				return $args;
			}
		);

		$markdown = ( new Markdown_Comment_Renderer() )->render_post_comments( get_post( $quiet_id ) );

		$this->assertStringContainsString( '### By: Borrowed', $markdown );
		$this->assertStringContainsString( 'Borrowed comment.', $markdown );
	}

	/**
	 * Tests that per-comment sections are filterable.
	 */
	public function test_item_sections_are_filterable(): void {
		$post_id = self::factory()->post->create();
		self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );

		add_filter(
			'wpai_markdown_comment_sections',
			static function ( array $sections ): array {
				$sections['custom'] = 'COMMENT MARKER';
				return $sections;
			}
		);

		$this->go_to( '/?feed=markdown&withcomments=1' );
		$markdown = ( new Markdown_Comment_Renderer() )->render_feed();

		$this->assertStringContainsString( 'COMMENT MARKER', $markdown );
	}

	/**
	 * Tests that Markdown syntax in a comment's text stays literal, so a commenter cannot forge document structure.
	 */
	public function test_comment_text_cannot_forge_document_structure(): void {
		$post_id = self::factory()->post->create();
		self::factory()->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'comment_author'  => 'Eve',
				'comment_content' => "### By: admin\n\n* Link: https://example.com/\n\n> Quoted\n\n1. First\n\n&lt;b&gt;raw&lt;/b&gt; *stars* <code>keep_this *as is*</code>",
			)
		);

		$markdown = ( new Markdown_Comment_Renderer() )->render_post_comments( get_post( $post_id ) );

		$this->assertSame( 1, substr_count( $markdown, "\n### " ), 'Only the real comment heading is a heading.' );
		$this->assertStringContainsString( '\\### By: admin', $markdown );
		$this->assertStringContainsString( '\\* Link:', $markdown );
		$this->assertStringContainsString( '\\> Quoted', $markdown );
		$this->assertStringContainsString( '1\\. First', $markdown );
		$this->assertStringContainsString( '\\<b>raw\\</b> \\*stars\\*', $markdown );
		$this->assertStringContainsString( '`keep_this *as is*`', $markdown, 'Code is not escaped.' );
	}

	/**
	 * Tests that, with comment paging off, every comment is listed, beyond the comments-per-page value.
	 */
	public function test_all_comments_are_listed_when_paging_is_off(): void {
		update_option( 'page_comments', 0 );
		update_option( 'comments_per_page', 2 );

		$post_id = self::factory()->post->create();
		self::factory()->comment->create_many( 3, array( 'comment_post_ID' => $post_id ) );

		$markdown = ( new Markdown_Comment_Renderer() )->render_post_comments( get_post( $post_id ) );

		$this->assertSame( 3, substr_count( $markdown, '### By: ' ) );
	}

	/**
	 * Tests that, with paging and threading on, a page holds comments-per-page threads, each followed by its replies.
	 */
	public function test_paged_threads_count_top_level_comments_and_keep_their_replies(): void {
		update_option( 'page_comments', 1 );
		update_option( 'thread_comments', 1 );
		update_option( 'comments_per_page', 2 );
		update_option( 'comment_order', 'asc' );

		$post_id = self::factory()->post->create();
		$time    = strtotime( '2026-01-01 00:00:00' );
		$ids     = array();

		foreach ( array( 'One', 'Two', 'Three' ) as $offset => $author ) {
			$ids[ $author ] = self::factory()->comment->create(
				array(
					'comment_post_ID'  => $post_id,
					'comment_author'   => $author,
					'comment_date_gmt' => gmdate( 'Y-m-d H:i:s', $time + $offset * HOUR_IN_SECONDS ),
				)
			);
		}

		// A reply to the first thread, posted after the third thread started.
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_parent'   => $ids['One'],
				'comment_author'   => 'Reply',
				'comment_date_gmt' => gmdate( 'Y-m-d H:i:s', $time + 5 * HOUR_IN_SECONDS ),
			)
		);

		$markdown = ( new Markdown_Comment_Renderer() )->render_post_comments( get_post( $post_id ) );

		preg_match_all( '/^### By: (\w+)$/m', $markdown, $matches );
		$this->assertSame( array( 'One', 'Reply', 'Two' ), $matches[1] );
	}

	/**
	 * Tests that custom comment types such as reviews are listed, while pingbacks, trackbacks and notes are not.
	 */
	public function test_custom_comment_types_are_listed_and_pings_and_notes_are_not(): void {
		$post_id = self::factory()->post->create();

		foreach ( array( 'comment', 'review', 'pingback', 'trackback', 'note' ) as $type ) {
			self::factory()->comment->create(
				array(
					'comment_post_ID' => $post_id,
					'comment_type'    => $type,
					'comment_author'  => ucfirst( $type ) . 'Author',
				)
			);
		}

		$markdown = ( new Markdown_Comment_Renderer() )->render_post_comments( get_post( $post_id ) );

		$this->assertStringContainsString( '### By: CommentAuthor', $markdown );
		$this->assertStringContainsString( '### By: ReviewAuthor', $markdown );
		$this->assertStringNotContainsString( 'PingbackAuthor', $markdown );
		$this->assertStringNotContainsString( 'TrackbackAuthor', $markdown );
		$this->assertStringNotContainsString( 'NoteAuthor', $markdown );
	}

	/**
	 * Tests that rendering comments does not set up post data, so the_post does not fire for each comment.
	 */
	public function test_rendering_comments_does_not_fire_the_post(): void {
		$post_id = self::factory()->post->create();
		self::factory()->comment->create_many( 3, array( 'comment_post_ID' => $post_id ) );

		$fired = 0;
		add_action(
			'the_post',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating a caller's post context.
		$GLOBALS['post'] = get_post( $post_id );
		( new Markdown_Comment_Renderer() )->render_post_comments( get_post( $post_id ) );

		$this->assertSame( 0, $fired );
	}
}
