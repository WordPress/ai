<?php

namespace WordPress\AI\Vendor\Html_To_Markdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Experimental_HTML_Renderer_Block_Code extends WP_Experimental_HTML_Renderer_Block {
	public ?WP_Experimental_HTML_Renderer_Line_Buffer $code = null;

	public ?string $language = null;

	public ?string $language_fallback = null;

	public function append_line( WP_Experimental_HTML_Renderer_Line_Buffer $line ): void {
		$this->code = $line;
	}

	public function append( WP_Experimental_HTML_Renderer_Block $block ): void {
		if ( $block instanceof WP_Experimental_HTML_Renderer_Block_Paragraph ) {
			$this->code = $block->lines[0] ?? null;
		} else {
			$type = \strtr( \get_class( $block ), array( 'Block_' => '' ) );
			throw new \Error( "Cannot add block of type '{$type}' to code block." );
		}
	}

	public function infer_language( \WP_HTML_Processor $processor ): void {
		if ( isset( $this->language ) ) {
			return;
		}

		$language_map = array(
			'c++'     => 'cpp',
			'eix'     => 'elixir',
			'erl'     => 'erlang',
			'golang'  => 'go',
			'md'      => 'markdown',
			'js'      => 'javascript',
			'patch'   => 'diff',
			'py'      => 'python',
			'python2' => 'python',
			'python3' => 'python',
			'rs'      => 'rust',
			'ts'      => 'typescript',
		);

		$known_languages = array( 'asm', 'apl', 'bash', 'c', 'cpp', 'clojure', 'clojurescript', 'commonlisp', 'diff', 'elixir', 'erlang', 'go', 'html', 'javascript', 'lisp', 'php', 'python', 'rust', 'scheme', 'typescript' );
		$known_rejects   = array( 'nil', 'src' );
		$try_slug = function ( $trial ) use ( $language_map, $known_languages, $known_rejects ) {
			if ( \in_array( $trial, $known_rejects, true ) || \in_array( $this->language, $known_languages, true ) ) {
				return;
			}

			$trial = $language_map[ $trial ] ?? $trial;
			if ( \in_array( $trial, $known_languages, true ) ) {
				$this->language = $trial;
				return;
			}

			$this->language_fallback = $trial;
		};

		switch ( $processor->get_token_name() ) {
			case 'PRE':
			case 'CODE':
				$class_list = $processor->class_list();
				if ( ! isset( $class_list ) ) {
					break;
				}

				foreach ( $processor->class_list() as $class ) {
					if ( \str_starts_with( $class, 'language-' ) ) {
						$try_slug( \substr( $class, 9 ) );
					}

					if ( \str_starts_with( $class, 'pre-' ) || \str_starts_with( $class, 'src-' ) ) {
						$try_slug( \substr( $class, 4 ) );
						return;
					}

					$try_slug( $class );
				}
				break;
		}
	}

	public function flush( WP_Experimental_HTML_Renderer_Options $options ): string {
		if ( ! isset( $this->code ) || $this->code->is_empty() ) {
			return '';
		}

		$previous_indent = $options->indent;
		$options->indent[] = '    ';

		$slug                    = $this->language ?? $this->language_fallback ?? '';
		$indent                  = \implode( '', $options->indent );
		$indent_length           = \mb_strwidth( $indent );
		$soft_limit              = $options->soft_line_wrap;
		$options->soft_line_wrap = \max( 1, $soft_limit - $indent_length );

		$prefix = "{$indent}```{$slug}\n";
		$buffer = '';
		foreach ( \explode( "\n", $this->code->raw_buffer() ) as $line ) {
			$chunk             = "{$indent}{$line}\n";
			$is_all_whitespace = \strspn( $chunk, " \t\f\r\n" ) === \strlen( $chunk );
			$buffer           .= $is_all_whitespace ? "\n" : $chunk;
		}
		$buffer = \trim( $buffer, "\n" );
		$suffix = "\n{$indent}```\n";

		$options->soft_line_wrap = $soft_limit;
		$options->indent         = $previous_indent;
		return "{$prefix}{$buffer}{$suffix}";
	}

	public function is_empty(): bool {
		return null === $this->code || $this->code->is_empty();
	}
}