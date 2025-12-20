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
use function sanitize_key;
use function status_header;
use function wp_strip_all_tags;
use function wp_unslash;

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
		$last_modified = strtotime( $post->post_modified_gmt );

		// Check for conditional GET (304 Not Modified).
		if ( $last_modified && $this->handle_conditional_get( $last_modified, $post ) ) {
			return;
		}

		$this->send_headers( 200, $last_modified, $post );

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
	 * Writes a Markdown "password required" response.
	 *
	 * @since x.x.x
	 */
	public function render_password_required(): void {
		$this->send_headers( 401 );

		if ( $this->is_head_request() ) {
			return;
		}

		echo '# ' . esc_html__( 'Password Required', 'ai' ) . "\n\n";
		echo esc_html__( 'This content is password protected. Please provide the password to view the Markdown representation.', 'ai' ) . "\n";
	}

	/**
	 * Sends HTTP headers for the Markdown response.
	 *
	 * @since x.x.x
	 *
	 * @param int           $status_code   HTTP status code.
	 * @param int|false     $last_modified Unix timestamp of last modification, or false.
	 * @param \WP_Post|null $post          Post object for ETag generation.
	 */
	private function send_headers( int $status_code, $last_modified = false, ?WP_Post $post = null ): void {
		status_header( $status_code );
		header( 'Content-Type: text/markdown; charset=' . get_option( 'blog_charset' ), true );
		header( 'X-Content-Type-Options: nosniff', true );

		// Only add caching headers for successful responses with valid data.
		if ( 200 !== $status_code || ! $last_modified || ! $post ) {
			return;
		}

		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $last_modified ) . ' GMT', true );

		// Generate ETag from last modified time and post ID.
		$etag = md5( $last_modified . '-' . $post->ID );
		header( 'ETag: "' . $etag . '"', true );

		// Allow caching for a short period.
		header( 'Cache-Control: max-age=300, must-revalidate', true );
	}

	/**
	 * Handles conditional GET requests (If-Modified-Since, If-None-Match).
	 *
	 * @since x.x.x
	 *
	 * @param int      $last_modified Unix timestamp of last modification.
	 * @param \WP_Post $post          Post object.
	 * @return bool True if 304 response was sent, false otherwise.
	 */
	private function handle_conditional_get( int $last_modified, WP_Post $post ): bool {
		$client_etag          = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( (string) wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$client_last_modified = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? (string) wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$etag = '"' . md5( $last_modified . '-' . $post->ID ) . '"';

		$etag_match          = '' !== $client_etag && $client_etag === $etag;
		$last_modified_match = '' !== $client_last_modified && strtotime( $client_last_modified ) >= $last_modified;

		if ( $etag_match || $last_modified_match ) {
			status_header( 304 );
			header( 'Content-Type: text/markdown; charset=' . get_option( 'blog_charset' ), true );
			return true;
		}

		return false;
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
