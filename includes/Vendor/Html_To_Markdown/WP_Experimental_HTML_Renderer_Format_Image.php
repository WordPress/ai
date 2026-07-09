<?php

namespace WordPress\AI\Vendor\Html_To_Markdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Represents an image, possibly with alternative text.
 */
class WP_Experimental_HTML_Renderer_Format_Image extends WP_Experimental_HTML_Renderer_Format {
	/**
	 * Source of the image.
	 *
	 * @var string
	 */
	public string $src_url;

	/**
	 * Alternative text for the image. Images which should
	 * not appear in assistive translations contain the empty string.
	 *
	 * @var string
	 */
	public string $alt_text;

	/**
	 * Descriptive title to accompany image, if provided.
	 *
	 * @var string
	 */
	public string $title;

	/**
	 * Create a format for a linked span of content.
	 *
	 * @param string $src_url
	 * @param string $alt_text
	 */
	public function __construct( string $src_url, string $alt_text, string $title = '' ) {
		$this->src_url  = $src_url;
		$this->alt_text = $alt_text;
		$this->title    = $title;
	}
}