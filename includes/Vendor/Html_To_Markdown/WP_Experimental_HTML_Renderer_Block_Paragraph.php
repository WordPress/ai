<?php

namespace WordPress\AI\Vendor\Html_To_Markdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Experimental_HTML_Renderer_Block_Paragraph extends WP_Experimental_HTML_Renderer_Block {
	/**
	 * @var Array<WP_Experimental_HTML_Renderer_Line_Buffer>
	 */
	public array $lines = array();

	public function append_line( WP_Experimental_HTML_Renderer_Line_Buffer $line ): void {
		$this->lines[] = $line;
	}

	public function flush( WP_Experimental_HTML_Renderer_Options $options ): string {
		$md = array();
		foreach ( $this->lines as $line ) {
			if ( ! $line->has_non_whitespace_content() ) {
				continue;
			}

			$md[] = \implode( "\n", line_wrap( $line->flush( $options ), $options->soft_line_wrap ) );
		}

		return \implode( "\n\n", $md );
	}

	public function is_empty(): bool {
		foreach ( $this->lines as $line ) {
			if ( ! $line->is_empty() ) {
				return false;
			}
		}

		return true;
	}
}