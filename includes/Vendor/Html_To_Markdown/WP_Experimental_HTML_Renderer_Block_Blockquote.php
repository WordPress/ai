<?php

namespace WordPress\AI\Vendor\Html_To_Markdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Experimental_HTML_Renderer_Block_Blockquote extends WP_Experimental_HTML_Renderer_Block {
	/**
	 * @var Array<WP_Experimental_HTML_Renderer_Block>
	 */
	public array $items = array();

	public function append( WP_Experimental_HTML_Renderer_Block $block ): void {
		$this->items[] = $block;
	}

	public function flush( WP_Experimental_HTML_Renderer_Options $options ): string {
		$md           = array();
		$indent       = \implode( '', $options->indent );
		$prefix       = "{$indent}> ";
		$prefix_width = \mb_strwidth( $prefix );

		$soft_limit = $options->soft_line_wrap;
		$options->soft_line_wrap -= $prefix_width;

		foreach ( $this->items as $item ) {
			$buffer = '';

			foreach ( \explode( "\n", $item->flush( $options ) ) as $line ) {
				$buffer .= "{$prefix}{$line}\n";
			}

			$md[] = \rtrim( $buffer, "\n" );
		}

		$options->soft_line_wrap = $soft_limit;

		return \implode( "\n", $md );
	}

	public function is_empty(): bool {
		foreach ( $this->items as $item ) {
			if ( ! $item->is_empty() ) {
				return false;
			}
		}

		return true;
	}
}