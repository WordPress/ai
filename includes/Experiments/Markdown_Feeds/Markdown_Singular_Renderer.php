<?php
/**
 * Singular post Markdown renderer.
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
 * Renders a single post as a Markdown document.
 *
 * @since x.x.x
 */
class Markdown_Singular_Renderer {

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
	 * Renders the given post as a Markdown document.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post Post to render.
	 * @return string Markdown document.
	 */
	public function render( WP_Post $post ): string {
		$permalink = (string) get_permalink( $post );
		$title     = wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES );

		$content_html = $this->get_rendered_content( $post );

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
			'title'   => '# ' . $title,
			'meta'    => implode( "\n", $meta_lines ),
			'content' => $this->converter->convert( $content_html, $permalink ),
		);

		/**
		 * Filters the Markdown sections for a singular post document.
		 *
		 * Each entry is a named block of Markdown; blocks are joined with
		 * blank lines in array order. Add, remove, or reorder entries to
		 * customize the output.
		 *
		 * @since x.x.x
		 *
		 * @param array<string, string> $sections Named Markdown sections.
		 * @param \WP_Post               $post     Post being rendered.
		 */
		$sections = apply_filters( 'wpai_markdown_singular_sections', $sections, $post );

		$sections = array_filter(
			array_map( 'strval', $sections ),
			static function ( string $section ): bool {
				return '' !== $section;
			}
		);

		return implode( "\n\n", $sections ) . "\n";
	}

	/**
	 * Returns the post content with content filters applied.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $target_post Post to render content for.
	 * @return string Rendered HTML.
	 */
	private function get_rendered_content( WP_Post $target_post ): string {
		global $post;

		$original_post = $post;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored below.
		$post = $target_post;
		setup_postdata( $post );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		$content_html = (string) apply_filters( 'the_content', get_the_content( null, false, $target_post ) );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring original global.
		$post = $original_post;
		if ( $original_post instanceof WP_Post ) {
			setup_postdata( $post );
		} else {
			wp_reset_postdata();
		}

		return $content_html;
	}
}
