<?php
/**
 * Markdown feed renderer.
 *
 * @since x.x.x
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Markdown_Feeds;

use WP_Post;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the current feed query as a Markdown document.
 *
 * @since x.x.x
 */
class Markdown_Feed_Renderer {

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
	 * Renders the current main query as a Markdown feed document.
	 *
	 * @since x.x.x
	 *
	 * @return string Markdown document.
	 */
	public function render(): string {
		$use_excerpt = (bool) get_option( 'rss_use_excerpt' );

		$blocks = array(
			'# ' . wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
		);

		$description = (string) get_bloginfo( 'description' );
		if ( '' !== $description ) {
			$blocks[] = $description;
		}

		$blocks[] = '- ' . sprintf(
			/* translators: %s: site home URL. */
			__( 'Site: %s', 'ai' ),
			home_url( '/' )
		);

		while ( have_posts() ) {
			the_post();
			$post = get_post();

			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$blocks[] = $this->render_item( $post, $use_excerpt );
		}

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
	 * Renders one post as a Markdown feed item.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post        Post to render (must be the current loop post).
	 * @param bool     $use_excerpt Whether to render the excerpt instead of full content.
	 * @return string Markdown block for this item.
	 */
	private function render_item( WP_Post $post, bool $use_excerpt ): string {
		$permalink = (string) get_permalink( $post );

		if ( $use_excerpt ) {
			$content_markdown = trim( wp_strip_all_tags( (string) get_the_excerpt( $post ), true ) );
		} else {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
			$content_html     = (string) apply_filters( 'the_content', get_the_content( null, false, $post ) );
			$content_markdown = $this->converter->convert( $content_html, $permalink );
		}

		$meta_lines = array(
			'- ' . sprintf(
				/* translators: %s: post permalink URL. */
				__( 'Link: %s', 'ai' ),
				$permalink
			),
			'- ' . sprintf(
				/* translators: %s: post publish date. */
				__( 'Published: %s', 'ai' ),
				(string) get_the_date( 'c', $post )
			),
			'- ' . sprintf(
				/* translators: %s: post author display name. */
				__( 'Author: %s', 'ai' ),
				(string) get_the_author_meta( 'display_name', (int) $post->post_author )
			),
		);

		$sections = array(
			'title'   => '## ' . wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ),
			'meta'    => implode( "\n", $meta_lines ),
			'content' => $content_markdown,
		);

		/**
		 * Filters the Markdown sections for a single feed item.
		 *
		 * Each entry is a named block of Markdown; blocks are joined with
		 * blank lines in array order. Add, remove, or reorder entries to
		 * customize the output (e.g. inject custom fields).
		 *
		 * @since x.x.x
		 *
		 * @param array<string, string> $sections Named Markdown sections.
		 * @param \WP_Post               $post     Post being rendered.
		 */
		$sections = apply_filters( 'wpai_markdown_feed_item_sections', $sections, $post );

		$sections = array_filter(
			array_map( 'strval', $sections ),
			static function ( string $section ): bool {
				return '' !== $section;
			}
		);

		return implode( "\n\n", $sections );
	}
}
