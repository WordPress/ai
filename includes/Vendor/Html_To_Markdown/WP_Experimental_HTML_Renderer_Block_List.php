<?php

namespace WordPress\AI\Vendor\Html_To_Markdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Experimental_HTML_Renderer_Block_List extends WP_Experimental_HTML_Renderer_Block {
	private string $style;

	/**
	 * @var Array<WP_Experimental_HTML_Renderer_Block>
	 */
	public array $items = array();

	public function __construct( string $style ) {
		$this->style = $style;
	}

	public function append( WP_Experimental_HTML_Renderer_Block $block ): void {
		$this->items[] = $block;
	}

	public function flush( WP_Experimental_HTML_Renderer_Options $options ): string {
		return '.' === ( $this->style[1] ?? '' )
			? $this->flush_ordered( $options )
			: $this->flush_unordered( $options );
	}

	public function flush_ordered( WP_Experimental_HTML_Renderer_Options $options ): string {
		list( $bullet, $n ) = explode( '.', $this->style );

		$n              = (int) $n;
		$md             = array();
		$count          = \count( $this->items );
		$longest_prefix = \strlen( (string) ( $n + $count ) );
		$indent         = \implode( '', $options->indent );
		$indent_width   = \mb_strwidth( $indent );
		$prefix_width   = $indent_width + $longest_prefix;
		$spacerN        = \str_repeat( ' ', $longest_prefix );

		$soft_limit = $options->soft_line_wrap;
		$options->soft_line_wrap -= $prefix_width;

		foreach ( $this->items as $item ) {
			$buffer = '';

			foreach ( \explode( "\n", $item->flush( $options ) ) as $i => $line ) {
				if ( 0 === $i ) {
					// @todo This only supports 1 and a, but should support all types.
					// @todo This only goes up to 26 for a.
					$item_number = '1' === $bullet ? (string) $n++ : 'abcdefghijklmnopqrstuvwxyz'[ ( $n++ - 1 ) % 26 ];
					$spacing     = \str_repeat( ' ', $longest_prefix - \strlen( $item_number ) );
					$buffer     .= "{$indent} {$item_number}.{$spacing} {$line}\n";
				} else {
					$buffer .= "{$indent} {$spacerN}  {$line}\n";
				}
			}

			$md[] = \rtrim( $buffer, "\n" );
		}

		$options->soft_line_wrap = $soft_limit;

		return \implode( "\n", $md );
	}

	public function flush_unordered( WP_Experimental_HTML_Renderer_Options $options ): string {
		$md           = array();
		$bullet       = $this->style;
		$indent       = \implode( '', $options->indent );
		$prefix1      = "{$indent} {$bullet} ";
		$prefixN      = "{$indent} " . \str_repeat( ' ', \mb_strwidth( $bullet ) ) . ' ';
		$prefix_width = \mb_strwidth( $prefix1 );

		$soft_limit               = $options->soft_line_wrap;
		$options->soft_line_wrap -= $prefix_width;
		$was_sublist              = false;

		foreach ( $this->items as $item ) {
			$buffer = '';
			$is_sublist = $item instanceof WP_Experimental_HTML_Renderer_Block_List;

			foreach ( \explode( "\n", $item->flush( $options ) ) as $i => $line ) {
				if ( 0 === $i && $is_sublist && ! $was_sublist ) {
					$buffer .= "{$prefixN}{$line}\n";
				} else {
					$buffer .= $i === 0 ? "{$prefix1}{$line}\n" : "{$prefixN}{$line}\n";
				}
			}

			$was_sublist = $is_sublist;

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