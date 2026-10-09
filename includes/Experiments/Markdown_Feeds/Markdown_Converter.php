<?php
/**
 * HTML to Markdown converter wrapper.
 *
 * @since 1.4.0
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
 * @since 1.4.0
 */
class Markdown_Converter {

	/**
	 * Converts an HTML fragment to Markdown.
	 *
	 * @since 1.4.0
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

	/**
	 * Escapes Markdown syntax in the text of an HTML fragment, so the text converts to literal characters.
	 *
	 * @since x.x.x
	 *
	 * @param string $html HTML fragment from an untrusted source.
	 * @return string The HTML fragment with Markdown syntax escaped in its text.
	 */
	public function escape_markdown_in_html( string $html ): string {
		$processor = new \WP_HTML_Tag_Processor( $html );
		$code_tags = array( 'CODE', 'KBD', 'PRE', 'SAMP' );
		$in_code   = 0;

		while ( $processor->next_token() ) {
			$token = $processor->get_token_name();

			if ( in_array( $token, $code_tags, true ) ) {
				$in_code = max( 0, $in_code + ( $processor->is_tag_closer() ? -1 : 1 ) );
				continue;
			}

			if ( '#text' !== $token || $in_code > 0 ) {
				continue;
			}

			$text    = (string) $processor->get_modifiable_text();
			$escaped = $this->escape_markdown( $text );

			if ( $escaped === $text ) {
				continue;
			}

			$processor->set_modifiable_text( $escaped );
		}

		return $processor->get_updated_html();
	}

	/**
	 * Escapes Markdown syntax in plain text, so the text renders as literal characters.
	 *
	 * @since x.x.x
	 *
	 * @param string $text Plain text from an untrusted source.
	 * @return string The text with Markdown syntax escaped.
	 */
	public function escape_markdown( string $text ): string {
		// Inline syntax: emphasis, strikethrough, code spans and fences, links and images, raw HTML, table cells.
		$escaped = (string) preg_replace( '/[\\\\`*_~\[\]<|]/', '\\\\$0', $text );
		// Line-start syntax at the start of a word: headings, quotes, list items, thematic breaks, setext underlines.
		$escaped = (string) preg_replace( '/(^|\s)([#>+=-])/', '$1\\\\$2', $escaped );
		// Ordered list items.
		return (string) preg_replace( '/(^|\s)(\d+)([.)])(?=\s|$)/', '$1$2\\\\$3', $escaped );
	}

	/**
	 * Decodes HTML entities in a plain-text string.
	 *
	 * @since 1.4.0
	 *
	 * @param string $text Text that may contain HTML entities.
	 * @return string Text with the entities decoded to their characters.
	 */
	public function decode_entities( string $text ): string {
		return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );
	}
}
