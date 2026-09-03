<?php
/**
 * HTML to Markdown converter wrapper.
 *
 * @since x.x.x
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Markdown_Feeds;

use WordPress\AI\Vendor\Html_To_Markdown\WP_Experimental_HTML_Renderer;
use WordPress\AI\Vendor\Html_To_Markdown\WP_Experimental_HTML_Renderer_Options;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts HTML fragments to Markdown using the vendored html-to-md renderer.
 *
 * @since x.x.x
 */
class Markdown_Converter {

	/**
	 * Converts an HTML fragment to Markdown.
	 *
	 * @since x.x.x
	 *
	 * @param string      $html     HTML fragment to convert.
	 * @param string|null $base_url Base URL used to resolve relative links and images.
	 * @return string Markdown text, or an empty string for empty input.
	 */
	public function convert( string $html, ?string $base_url = null ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		try {
			if ( ! function_exists( 'WordPress\\AI\\Vendor\\Html_To_Markdown\\line_wrap' ) ) {
				require_once WPAI_PLUGIN_DIR . 'includes/Vendor/Html_To_Markdown/WP_Experimental_HTML_Renderer_Line_Wrapper.php';
			}

			$options           = new WP_Experimental_HTML_Renderer_Options();
			$options->base_url = $base_url;

			$renderer = new WP_Experimental_HTML_Renderer( $html, $options );
			$markdown = (string) $renderer->to_markdown();
		} catch ( \Throwable $e ) {
			$markdown = '';
		}

		if ( '' === trim( $markdown ) ) {
			return trim( wp_strip_all_tags( $html, true ) );
		}

		return trim( $markdown );
	}
}
