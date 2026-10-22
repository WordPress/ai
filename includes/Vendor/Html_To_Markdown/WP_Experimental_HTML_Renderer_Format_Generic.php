<?php

namespace WordPress\AI\Vendor\Html_To_Markdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Represents generic inline text effects.
 */
class WP_Experimental_HTML_Renderer_Format_Generic extends WP_Experimental_HTML_Renderer_Format {
	/**
	 * What kind of format this represents.
	 *
	 * @todo cleanup to ensure comprehensiveness in this type.
	 *
	 * @var 'bold'|'italic'|'monospace'|'quote'|'strikeout'
	 */
	public string $type;

	/**
	 * Create a format for generic text formatting without state.
	 *
	 * @param 'bold'|'italic'|'monospace'|'quote'|'strikeout' $type
	 */
	public function __construct( string $type ) {
		$this->type = $type;
	}

	public static function from_html_tag( string $tag_name ): ?self {
		$format = array(
			'B'      => 'bolding',
			'BR'     => 'newlining',
			'CODE'   => 'monospacing',
			'EM'     => 'emphasizing',
			'I'      => 'emphasizing',
			'Q'      => 'quoting',
			'S'      => 'striking-out',
			'STRONG' => 'bolding',
			'SUB'    => 'subscripting',
			'SUP'    => 'superscripting',
		)[ $tag_name ] ?? null;

		return isset( $format )
			? new WP_Experimental_HTML_Renderer_Format_Generic( $format )
			: null;
	}
}