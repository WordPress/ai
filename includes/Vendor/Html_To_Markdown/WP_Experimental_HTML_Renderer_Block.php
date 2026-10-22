<?php

namespace WordPress\AI\Vendor\Html_To_Markdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class WP_Experimental_HTML_Renderer_Block {
	public function append( WP_Experimental_HTML_Renderer_Block $block ): void {
		$type = \strtr( \get_class( $this ), array( 'Block_' => '' ) );
		throw new \Error( "Cannot add blocks inside of block type '{$type}'" );
	}

	public function append_line( WP_Experimental_HTML_Renderer_Line_Buffer $line ): void {
		$type = \strtr( \get_class( $this ), array( 'Block_' => '' ) );
		throw new \Error( "Cannot add lines to block type '{$type}'" );
	}
	abstract public function flush( WP_Experimental_HTML_Renderer_Options $options ): string;

	abstract public function is_empty(): bool;
}