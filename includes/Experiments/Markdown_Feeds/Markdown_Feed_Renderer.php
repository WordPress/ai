<?php
/**
 * Markdown feed renderer.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Markdown_Feeds;

use WP_Post;
use WP_Query;
use function apply_filters;
use function esc_html;
use function esc_html__;
use function esc_url;
use function esc_url_raw;
use function get_bloginfo;
use function get_option;
use function get_permalink;
use function get_post;
use function get_post_field;
use function get_post_time;
use function get_self_link;
use function get_the_title;
use function have_posts;
use function sanitize_key;
use function status_header;
use function the_post;
use function wp_strip_all_tags;
use function wp_unslash;

/**
 * Outputs a Markdown representation of the current feed query.
 */
final class Markdown_Feed_Renderer {
	/**
	 * Writes the Markdown feed response.
	 *
	 * @since x.x.x
	 */
	public function render(): void {
		$last_modified = $this->get_feed_last_modified();

		// Check for conditional GET (304 Not Modified).
		if ( $this->handle_conditional_get( $last_modified ) ) {
			return;
		}

		$this->send_headers( $last_modified );

		if ( $this->is_head_request() ) {
			return;
		}

		$this->send_feed_header();
		$this->send_posts();
	}

	/**
	 * Sends HTTP headers for the Markdown feed response.
	 *
	 * @since x.x.x
	 *
	 * @param int $last_modified Unix timestamp of last modification.
	 */
	private function send_headers( int $last_modified ): void {
		status_header( 200 );
		header( 'Content-Type: text/markdown; charset=' . get_option( 'blog_charset' ), true );
		header( 'X-Content-Type-Options: nosniff', true );

		// Caching headers similar to core RSS feeds.
		if ( $last_modified > 0 ) {
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $last_modified ) . ' GMT', true );

			// Generate ETag from last modified time and feed URL.
			$etag = md5( $last_modified . get_self_link() );
			header( 'ETag: "' . $etag . '"', true );
		}

		// Allow caching for a short period (similar to core feeds behavior).
		header( 'Cache-Control: max-age=300, must-revalidate', true );
	}

	/**
	 * Handles conditional GET requests (If-Modified-Since, If-None-Match).
	 *
	 * @since x.x.x
	 *
	 * @param int $last_modified Unix timestamp of last modification.
	 * @return bool True if 304 response was sent, false otherwise.
	 */
	private function handle_conditional_get( int $last_modified ): bool {
		if ( $last_modified <= 0 ) {
			return false;
		}

		$client_etag          = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( (string) wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$client_last_modified = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? (string) wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$etag = '"' . md5( $last_modified . get_self_link() ) . '"';

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
	 * Gets the last modified timestamp for the feed.
	 *
	 * @since x.x.x
	 *
	 * @return int Unix timestamp, or 0 if unknown.
	 */
	private function get_feed_last_modified(): int {
		global $wp_query;

		if ( ! $wp_query instanceof WP_Query || empty( $wp_query->posts ) ) {
			return 0;
		}

		$latest = 0;
		foreach ( $wp_query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$modified = strtotime( $post->post_modified_gmt );
			if ( $modified <= $latest ) {
				continue;
			}

			$latest = $modified;
		}

		return $latest;
	}

	/**
	 * Outputs the feed header in Markdown.
	 *
	 * @since x.x.x
	 */
	private function send_feed_header(): void {
		$site_name = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
		$site_desc = wp_strip_all_tags( (string) get_bloginfo( 'description' ) );
		$feed_url  = esc_url_raw( get_self_link() );

		echo '# ' . esc_html( $site_name ) . ' — ' . esc_html__( 'Markdown Feed', 'ai' ) . "\n\n";

		if ( '' !== $site_desc ) {
			echo esc_html( $site_desc ) . "\n\n";
		}

		echo esc_html__( 'Feed URL:', 'ai' ) . ' <' . esc_url( $feed_url ) . ">\n\n";
	}

	/**
	 * Outputs all posts in the current feed query.
	 *
	 * @since x.x.x
	 */
	private function send_posts(): void {
		global $more;

		// Ensure full content is used.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Used intentionally to ensure full content in feed.
		$more = 1;

		if ( ! have_posts() ) {
			echo esc_html__( 'No posts found.', 'ai' ) . "\n";
			return;
		}

		while ( have_posts() ) {
			the_post();

			$post = get_post();
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$this->send_post( $post );
		}
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
		$permalink = esc_url_raw( get_permalink( $post ) );
		$date_r    = (string) get_post_time( 'r', true, $post );

		$content = (string) get_post_field( 'post_content', $post );
		$html    = (string) apply_filters( 'the_content', $content );

		$markdown = $this->convert_html_to_markdown( $html );
		$markdown = trim( $markdown );

		$meta_lines = array(
			esc_html__( 'URL:', 'ai' ) . ' <' . esc_url( $permalink ) . '>',
			esc_html__( 'Published:', 'ai' ) . ' ' . esc_html( $date_r ),
		);

		$sections = array(
			'header' => '## ' . esc_html( $title ),
			'meta'   => implode( "\n", $meta_lines ),
		);

		if ( '' !== $markdown ) {
			$sections['content'] = $markdown;
		}

		$sections['footer'] = '---';

		/**
		 * Filters the Markdown feed entry sections.
		 *
		 * Allows reordering or inserting custom sections before output is emitted.
		 *
		 * @since x.x.x
		 *
		 * @param array<string,string> $sections Markdown sections keyed by role.
		 * @param \WP_Post             $post     Post object.
		 */
		$sections = (array) apply_filters( 'ai_experiments_markdown_feed_post_sections', $sections, $post );

		$entry = implode( "\n\n", $sections );
		if ( '' === $entry ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markdown response.
		echo $entry . "\n\n";
	}

	/**
	 * Converts HTML to Markdown.
	 *
	 * @since x.x.x
	 *
	 * @param string $html HTML string.
	 * @return string Markdown string.
	 */
	private function convert_html_to_markdown( string $html ): string {
		/**
		 * Filters the HTML input before conversion to Markdown.
		 *
		 * @since x.x.x
		 *
		 * @param string $html HTML to convert.
		 */
		$html = (string) apply_filters( 'ai_experiments_markdown_feed_html', $html );

		$converter = new HTML_To_Markdown_Converter();
		$markdown  = $converter->convert( $html );

		/**
		 * Filters the Markdown output after conversion.
		 *
		 * @since x.x.x
		 *
		 * @param string $markdown Markdown output.
		 * @param string $html     Original HTML input.
		 */
		return (string) apply_filters( 'ai_experiments_markdown_feed_markdown', $markdown, $html );
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
