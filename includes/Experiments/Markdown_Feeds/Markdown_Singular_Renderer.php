<?php
/**
 * Markdown singular renderer.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Markdown_Feeds;

use WP_Post;

use function apply_filters;
use function esc_html;
use function esc_html__;
use function esc_url;
use function get_option;
use function get_permalink;
use function get_post_field;
use function get_post_modified_time;
use function get_post_time;
use function get_the_title;
use function nocache_headers;
use function sanitize_key;
use function status_header;
use function wp_strip_all_tags;

/**
 * Outputs a Markdown representation of a singular post.
 *
 * @since x.x.x
 */
final class Markdown_Singular_Renderer {
	/**
	 * Writes the Markdown response for a post.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post Post object.
	 */
	public function render( WP_Post $post ): void {
		$this->send_headers( 200 );

		if ( $this->is_head_request() ) {
			return;
		}

		$this->send_post( $post );
	}

	/**
	 * Writes a Markdown "not found" response.
	 *
	 * @since x.x.x
	 */
	public function render_not_found(): void {
		$this->send_headers( 404 );

		if ( $this->is_head_request() ) {
			return;
		}

		echo '# ' . esc_html__( 'Not Found', 'ai' ) . "\n\n";
		echo esc_html__( 'No Markdown representation is available for this URL.', 'ai' ) . "\n";
	}

	/**
	 * Sends HTTP headers for the Markdown response.
	 *
	 * @since x.x.x
	 *
	 * @param int  $status_code HTTP status code.
	 */
	private function send_headers( int $status_code ): void {
		status_header( $status_code );
		nocache_headers();
		header( 'Content-Type: text/markdown; charset=' . get_option( 'blog_charset' ), true );
		header( 'X-Content-Type-Options: nosniff', true );
	}

	/**
	 * Outputs a single post block in Markdown.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post Post object.
	 */
	private function send_post( WP_Post $post ): void {
		$title     = wp_strip_all_tags( (string) get_the_title( $post ) );
		$permalink = (string) get_permalink( $post );

		$published = (string) get_post_time( 'r', true, $post );
		$modified  = (string) get_post_modified_time( 'r', true, $post );

		echo '# ' . esc_html( $title ) . "\n\n";
		echo esc_html__( 'URL:', 'ai' ) . ' <' . esc_url( $permalink ) . ">\n";
		echo esc_html__( 'Published:', 'ai' ) . ' ' . esc_html( $published ) . "\n";

		if ( $modified !== $published ) {
			echo esc_html__( 'Updated:', 'ai' ) . ' ' . esc_html( $modified ) . "\n";
		}

		echo "\n";

		$content = (string) get_post_field( 'post_content', $post );
		$html    = (string) apply_filters( 'the_content', $content );

		/**
		 * Filters the HTML input before conversion to Markdown.
		 *
		 * @since x.x.x
		 *
		 * @param string   $html HTML to convert.
		 * @param \WP_Post $post Post object.
		 */
		$html = (string) apply_filters( 'ai_experiments_markdown_singular_html', $html, $post );

		$converter = new HTML_To_Markdown_Converter();
		$markdown  = $converter->convert( $html );

		/**
		 * Filters the Markdown output after conversion.
		 *
		 * @since x.x.x
		 *
		 * @param string   $markdown Markdown output.
		 * @param string   $html     Original HTML input.
		 * @param \WP_Post $post     Post object.
		 */
		$markdown = (string) apply_filters( 'ai_experiments_markdown_singular_markdown', $markdown, $html, $post );
		$markdown = trim( $markdown );

		if ( '' === $markdown ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markdown response.
		echo $markdown . "\n";
	}

	/**
	 * Checks whether the current HTTP request is a HEAD request.
	 *
	 * @since x.x.x
	 *
	 * @return bool
	 */
	private function is_head_request(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( (string) $_SERVER['REQUEST_METHOD'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return 'head' === $method;
	}
}
