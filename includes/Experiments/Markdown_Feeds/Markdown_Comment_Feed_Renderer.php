<?php
/**
 * Markdown comment feed renderer.
 *
 * @since x.x.x
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Markdown_Feeds;

use WP_Comment;
use WP_Post;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders comment feeds as Markdown: the site-wide comment feed, and the Comments section of a post's own feed.
 *
 * @since x.x.x
 */
class Markdown_Comment_Feed_Renderer {

	/**
	 * HTML to Markdown converter.
	 *
	 * @var \WordPress\AI\Experiments\Markdown_Feeds\Markdown_Converter
	 */
	private $converter;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Experiments\Markdown_Feeds\Markdown_Converter|null $converter Optional converter instance, for testing.
	 */
	public function __construct( ?Markdown_Converter $converter = null ) {
		$this->converter = $converter ?? new Markdown_Converter();
	}

	/**
	 * Renders the site-wide comment feed as a Markdown document.
	 *
	 * @since x.x.x
	 *
	 * @return string Markdown document.
	 */
	public function render(): string {
		$blocks = array(
			'# ' . sprintf(
				/* translators: %s: site name. */
				__( 'Comments for %s', 'ai' ),
				$this->converter->decode_entities( (string) get_bloginfo( 'name' ) )
			),
			'- ' . sprintf(
				/* translators: %s: site home URL. */
				__( 'Site: %s', 'ai' ),
				home_url( '/' )
			),
		);

		return implode( "\n\n", array_merge( $blocks, $this->render_items( true, 2 ) ) ) . "\n";
	}

	/**
	 * Renders the approved comments of a post's feed as a Comments section.
	 *
	 * @since x.x.x
	 *
	 * @return string Markdown section, or an empty string when the post has no comments.
	 */
	public function render_post_comments(): string {
		$items = $this->render_items( false, 3 );

		if ( array() === $items ) {
			return '';
		}

		return implode( "\n\n", array_merge( array( '## ' . __( 'Comments', 'ai' ) ), $items ) );
	}

	/**
	 * Renders every comment of the current query, in loop order.
	 *
	 * @since x.x.x
	 *
	 * @param bool $name_post     Whether to name the commented post in each heading.
	 * @param int  $heading_level Markdown heading level for the items.
	 * @return list<string> Markdown blocks, one per comment.
	 */
	private function render_items( bool $name_post, int $heading_level ): array {
		global $wp_query;

		$items = array();

		// The comment loop sets the global comment, and each item sets the global post, so the comment_text filters see the same context as the core feeds.
		while ( $wp_query->have_comments() ) {
			$wp_query->the_comment();

			if ( ! $GLOBALS['comment'] instanceof WP_Comment ) {
				continue;
			}

			$item = $this->render_item( $GLOBALS['comment'], $name_post, $heading_level );

			if ( '' === $item ) {
				continue;
			}

			$items[] = $item;
		}

		$wp_query->rewind_comments();
		wp_reset_postdata();

		return $items;
	}

	/**
	 * Renders one comment as a Markdown feed item.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Comment $comment       Comment to render.
	 * @param bool        $name_post     Whether to name the commented post in the heading.
	 * @param int         $heading_level Markdown heading level for the title.
	 * @return string Markdown block for this comment.
	 */
	private function render_item( WP_Comment $comment, bool $name_post, int $heading_level ): string {
		$author = esc_html( (string) get_comment_author( $comment ) );
		$post   = get_post( (int) $comment->comment_post_ID );
		$link   = (string) get_comment_link( $comment );

		if ( $post instanceof WP_Post ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reset with wp_reset_postdata() after the loop.
			$GLOBALS['post'] = $post;
			setup_postdata( $post );
		}

		if ( $name_post && $post instanceof WP_Post ) {
			$title = sprintf(
				/* translators: 1: post title, 2: comment author name. */
				__( 'Comment on %1$s by %2$s', 'ai' ),
				$this->converter->decode_entities( get_the_title( $post ) ),
				$author
			);
		} else {
			$title = sprintf(
				/* translators: %s: comment author name. */
				__( 'By: %s', 'ai' ),
				$author
			);
		}

		$meta_lines = array(
			'- ' . sprintf(
				/* translators: %s: comment permalink URL. */
				__( 'Link: %s', 'ai' ),
				$link
			),
			'- ' . sprintf(
				/* translators: %s: comment publish date. */
				__( 'Published: %s', 'ai' ),
				(string) get_comment_date( 'c', $comment )
			),
		);

		if ( $post instanceof WP_Post && post_password_required( $post ) ) {
			$content_markdown = __( 'Protected Comments: Please enter your password to view comments.', 'ai' );
		} else {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
			$content_html     = (string) apply_filters( 'comment_text', get_comment_text( $comment ), $comment, array() );
			$content_markdown = $this->converter->convert( $content_html, $link );
		}

		$sections = array(
			'title'   => str_repeat( '#', $heading_level ) . ' ' . $title,
			'meta'    => implode( "\n", $meta_lines ),
			'content' => $content_markdown,
		);

		/**
		 * Filters the Markdown sections for a single comment feed item.
		 *
		 * Each entry is a named block of Markdown; blocks are joined with
		 * blank lines in array order. Add, remove, or reorder entries to
		 * customize the output.
		 *
		 * @since x.x.x
		 *
		 * @param array<string, string> $sections Named Markdown sections.
		 * @param \WP_Comment           $comment  Comment being rendered.
		 */
		$sections = apply_filters( 'wpai_markdown_comment_feed_item_sections', $sections, $comment );

		$sections = array_filter(
			array_map( 'strval', $sections ),
			static function ( string $section ): bool {
				return '' !== $section;
			}
		);

		return implode( "\n\n", $sections );
	}
}
