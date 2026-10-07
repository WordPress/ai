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
 * @since 1.4.0
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

	/**
	 * Tests that the title is plain text, not HTML entities.
	 */
	public function test_title_has_no_html_entities(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'It\'s a "quoted" title',
				'post_status' => 'publish',
			)
		);

		$markdown = ( new Markdown_Singular_Renderer() )->render( get_post( $post_id ) );

		$this->assertStringContainsString( "# It\u{2019}s a \u{201C}quoted\u{201D} title", $markdown );
		$this->assertStringNotContainsString( '&#', $markdown );
	}

	/**
	 * Tests that approved comments follow the content as a Comments section, one heading level down.
	 */
	public function test_comments_section_follows_the_content(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Discussed Post',
				'post_content' => '<p>Discussed body.</p>',
				'post_status'  => 'publish',
			)
		);
		self::factory()->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'comment_author'  => 'Reader',
				'comment_content' => 'A <strong>thoughtful</strong> comment.',
			)
		);
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_author'   => 'Spammer',
				'comment_content'  => 'Unapproved comment.',
				'comment_approved' => '0',
			)
		);
		self::factory()->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'comment_author'  => 'Pinger',
				'comment_content' => 'A pingback.',
				'comment_type'    => 'pingback',
			)
		);

		$markdown = ( new Markdown_Singular_Renderer() )->render( get_post( $post_id ) );

		$this->assertStringContainsString( "Discussed body.\n\n## Comments\n\n### By: Reader\n\n- Link: ", $markdown );
		$this->assertStringContainsString( 'A **thoughtful** comment.', $markdown );
		$this->assertStringNotContainsString( 'Unapproved comment.', $markdown );
		$this->assertStringNotContainsString( 'A pingback.', $markdown );
	}

	/**
	 * Tests that a post without approved comments renders no Comments section.
	 */
	public function test_document_without_comments_has_no_comments_section(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Quiet Post',
				'post_content' => '<p>Quiet body.</p>',
				'post_status'  => 'publish',
			)
		);

		$markdown = ( new Markdown_Singular_Renderer() )->render( get_post( $post_id ) );

		$this->assertStringEndsWith( "Quiet body.\n", $markdown );
		$this->assertStringNotContainsString( '## Comments', $markdown );
	}

	/**
	 * Tests that the Comments section follows the Discussion settings for order and count.
	 */
	public function test_comments_section_follows_discussion_settings(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_author'   => 'First',
				'comment_content'  => 'Oldest.',
				'comment_date'     => '2026-01-01 10:00:00',
				'comment_date_gmt' => '2026-01-01 10:00:00',
			)
		);
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_author'   => 'Second',
				'comment_content'  => 'Newest.',
				'comment_date'     => '2026-01-02 10:00:00',
				'comment_date_gmt' => '2026-01-02 10:00:00',
			)
		);

		$renderer = new Markdown_Singular_Renderer();
		$post     = get_post( $post_id );
		$markdown = $renderer->render( $post );

		$this->assertLessThan( strpos( $markdown, '### By: Second' ), strpos( $markdown, '### By: First' ), 'Oldest first by default.' );

		update_option( 'comment_order', 'desc' );
		update_option( 'comments_per_page', 1 );
		$markdown = $renderer->render( $post );

		$this->assertStringContainsString( '### By: Second', $markdown );
		$this->assertStringNotContainsString( '### By: First', $markdown );
	}

	/**
	 * Tests that the Comments section can be removed through the sections filter.
	 */
	public function test_comments_section_is_removable_through_the_sections_filter(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );

		add_filter(
			'wpai_markdown_singular_sections',
			static function ( array $sections ): array {
				unset( $sections['comments'] );
				return $sections;
			}
		);

		$this->assertStringNotContainsString( '## Comments', ( new Markdown_Singular_Renderer() )->render( get_post( $post_id ) ) );
	}
}
