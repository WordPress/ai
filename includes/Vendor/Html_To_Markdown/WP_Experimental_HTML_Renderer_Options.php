<?php

namespace WordPress\AI\Vendor\Html_To_Markdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Experimental_HTML_Renderer_Options {
	/**
	 * Used to remap relative URLs into absolute URLs.
	 *
	 * @since {WP_VERSION}
	 *
	 * @var string|null
	 */
	public ?string $base_url = null;

	public int $soft_line_wrap = 80;

	/**
	 * Contains the current indentation stack.
	 * Normally, this would not be set by calling code.
	 *
	 * You probably do not want to set this.
	 *
	 * At times, there might be rendering which occurs within some external
	 * context not conveyed from within the input HTML. For example, if wanting
	 * to render a document as a quote, then one could pass the leading indent
	 * into the converter and all output will be properly prefixed and line-wrapped
	 * in accordance with that indent.
	 *
	 * Example:
	 *
	 *     $options = new MD_Options();
	 *     $options->indent = array( '> ' );
	 *
	 * @var array
	 */
	public array $indent = array();

	/**
	 * The output rendering target is either for visual consumption or
	 * to produce a syntactically-valid Markdown document.
	 *
	 * @var 'syntax'|'presentation'
	 */
	public string $display_mode = 'syntax';

	/**
	 * How to respond when the HTML API is unable to properly parse the
	 * input HTML. This occurs in rare circumstances with malformed markup.
	 *
	 * Callees may want to abort in the presence of parsing errors to call
	 * an external parser which produces a well-formed version of the input,
	 * which might involve higher costs.
	 *
	 * @todo Add 'reduced-fidelity' as default to fallback to Tag Processor
	 *       with assumptions that the HTML is well-formed.
	 *
	 * @var 'abort'
	 */
	public string $recovery_mode = 'abort';
}