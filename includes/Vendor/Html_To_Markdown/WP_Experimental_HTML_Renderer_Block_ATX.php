<?php

namespace WordPress\AI\Vendor\Html_To_Markdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Experimental_HTML_Renderer_Block_ATX extends WP_Experimental_HTML_Renderer_Block {
	public int                                        $level   = 1;
	public ?WP_Experimental_HTML_Renderer_Line_Buffer $heading = null;

	public function __construct( int $level ) {
		$this->level = $level;
	}

	public function append_line( WP_Experimental_HTML_Renderer_Line_Buffer $line ): void {
		$this->heading = $line;
	}

	public function append( WP_Experimental_HTML_Renderer_Block $block ): void {
		if ( $block instanceof WP_Experimental_HTML_Renderer_Block_Paragraph ) {
			$this->heading = $block->lines[0] ?? null;
		} else {
			$type = \strtr( \get_class( $block ), array( 'Block_' => '' ) );
			throw new \Error( "Cannot add block of type '{$type}' to code block." );
		}
	}

	public function flush( WP_Experimental_HTML_Renderer_Options $options ): string {
		if ( ! isset( $this->heading ) || $this->heading->is_empty() ) {
			return '';
		}

		$prefix = \str_repeat( '#', \max( 1, \min( 6, $this->level ) ) );
		// @todo This is a stylistic choice.
		$heading = \strtr( $this->heading->flush( $options ), array( "\n" => "⏎ " ) );

		return "\n{$prefix} {$heading}\n";
	}

	public function is_empty(): bool {
		return null === $this->heading || $this->heading->is_empty();
	}
}