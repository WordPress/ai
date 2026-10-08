<?php
/**
 * Markdown comment renderer.
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
 * Renders comments as Markdown.
 *
 * @since x.x.x
 */
class Markdown_Comment_Renderer {

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
	public function render_feed(): string {
		global $wp_query;

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

		while ( $wp_query->have_comments() ) {
			$wp_query->the_comment();

			if ( ! $GLOBALS['comment'] instanceof WP_Comment ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reset with wp_reset_postdata() after the loop.
			$GLOBALS['post'] = get_post( (int) $GLOBALS['comment']->comment_post_ID );

			$blocks[] = $this->render_item( $GLOBALS['comment'], true, 2 );
		}

		$wp_query->rewind_comments();
		wp_reset_postdata();

		$blocks = array_filter(
			$blocks,
			static function ( string $block ): bool {
				return '' !== $block;
			}
		);

		return implode( "\n\n", $blocks ) . "\n";
	}

	/**
	 * Renders a post's approved comments as the Comments section of its singular document.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post Post whose comments to render.
	 * @return string Markdown section, or an empty string when the post has no approved comments.
	 */
	public function render_post_comments( WP_Post $post ): string {
		// Skip the query for posts with not comments, unless a filter wants to shape the query itself.
		if ( 0 === (int) $post->comment_count && ! has_filter( 'wpai_markdown_singular_comments_args' ) ) {
			return '';
		}

		$args = array(
			'post_id'      => $post->ID,
			'status'       => 'approve',
			'type__not_in' => array( 'pingback', 'trackback', 'note' ),
			'orderby'      => 'comment_date_gmt',
			'order'        => 'desc' === get_option( 'comment_order' ) ? 'DESC' : 'ASC',
			'hierarchical' => get_option( 'thread_comments' ) ? 'threaded' : false,
			'number'       => get_option( 'page_comments' ) ? (int) get_option( 'comments_per_page' ) : 0,
		);

		/**
		 * Filters the comment query arguments for the Comments section of a singular Markdown document.
		 *
		 * @since x.x.x
		 *
		 * @param array<string, mixed> $args Arguments passed to get_comments().
		 * @param \WP_Post             $post Post being rendered.
		 */
		$args = apply_filters( 'wpai_markdown_singular_comments_args', $args, $post );

		$comments = get_comments( $args );

		if ( ! is_array( $comments ) ) {
			return '';
		}

		if ( 'threaded' === ( $args['hierarchical'] ?? false ) ) {
			$comments = $this->flatten_threads( $comments );
		}

		$previous_comment = $GLOBALS['comment'] ?? null;
		$previous_post    = $GLOBALS['post'] ?? null;
		$items            = array();

		foreach ( $comments as $comment ) {
			if ( ! $comment instanceof WP_Comment ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored after the loop, so the comment_text filters see the comment being rendered.
			$GLOBALS['comment'] = $comment;
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored after the loop. The commented post, as the query filter may list another post's comments.
			$GLOBALS['post'] = get_post( (int) $comment->comment_post_ID );

			$items[] = $this->render_item( $comment, false, 3 );
		}

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the previous value.
		$GLOBALS['comment'] = $previous_comment;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the previous value. Only the post global was changed, so the post data needs no reset.
		$GLOBALS['post'] = $previous_post;

		$items = array_filter(
			$items,
			static function ( string $item ): bool {
				return '' !== $item;
			}
		);

		if ( array() === $items ) {
			return '';
		}

		return implode( "\n\n", array_merge( array( '## ' . __( 'Comments', 'ai' ) ), $items ) );
	}

	/**
	 * Lists threaded comments in thread order.
	 *
	 * @since x.x.x
	 *
	 * @param array<int|string, mixed> $comments Top-level comments with their replies attached.
	 * @return list<mixed> Every comment, replies after their parent.
	 */
	private function flatten_threads( array $comments ): array {
		$flat = array();

		foreach ( $comments as $comment ) {
			$flat[] = $comment;

			if ( ! $comment instanceof WP_Comment ) {
				continue;
			}

			$flat = array_merge( $flat, array_values( $comment->get_children( array( 'format' => 'flat' ) ) ) );
		}

		return $flat;
	}

	/**
	 * Renders one comment as a Markdown block.
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
			$content_html = (string) apply_filters( 'comment_text', get_comment_text( $comment ), $comment, array() );

			// Comments come from visitors, so their text must not turn into document structure.
			$content_markdown = $this->converter->convert( $this->converter->escape_markdown_in_html( $content_html ), $link );
		}

		$sections = array(
			'title'   => str_repeat( '#', $heading_level ) . ' ' . $title,
			'meta'    => implode( "\n", $meta_lines ),
			'content' => $content_markdown,
		);

		/**
		 * Filters the Markdown sections for a single comment, in the site-wide comment feed and in a singular document's Comments section.
		 *
		 * @since x.x.x
		 *
		 * @param array<string, string> $sections Named Markdown sections.
		 * @param \WP_Comment           $comment  Comment being rendered.
		 */
		$sections = apply_filters( 'wpai_markdown_comment_sections', $sections, $comment );

		$sections = array_filter(
			array_map( 'strval', $sections ),
			static function ( string $section ): bool {
				return '' !== $section;
			}
		);

		return implode( "\n\n", $sections );
	}
}
