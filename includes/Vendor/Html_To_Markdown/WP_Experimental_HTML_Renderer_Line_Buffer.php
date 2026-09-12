<?php

namespace WordPress\AI\Vendor\Html_To_Markdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores formatting information while building a line for rendering.
 */
class WP_Experimental_HTML_Renderer_Line_Buffer {
	/**
	 * Contains the plaintext content of the buffer, without any syntax.
	 *
	 * @var string
	 */
	private string $buffer = '';

	/**
	 * Byte offsets into buffer where the format for the given format index
	 * starts to apply or ends: positive if starting, negative if ending.
	 *
	 * Example:
	 *
	 *     $buffer         = 'The dog did it!';
	 *     $format_offsets = array( 4, -7 );
	 *     $format_indices = array( 0, 0 );
	 *     $formats        = array( new InlineFormat_Generic( 'emphasizing' ) );
	 *
	 * @see self::$formats
	 *
	 * @var Array<int>
	 */
	private array $format_offsets = array();

	/**
	 * Relates an index in the {@see self::$format_offsets} array to a format in
	 * the {@see self::$formats} array; used to asosociate start and end of formats.
	 *
	 * @see self::$formats
	 *
	 * @var Array<int>
	 */
	private array $format_indices = array();

	private array $open_formats = array();

	private bool $contains_non_whitespace = false;

	/**
	 * References to inline formats and their state.
	 *
	 * @see self::$format_offset
	 * @see self::$format_indices
	 *
	 * @var array<WP_Experimental_HTML_Renderer_Format>
	 */
	private array $formats = array();

	public function append_text( string $text ) {
		$this->buffer .= $text;
		if ( ! $this->contains_non_whitespace ) {
			$this->contains_non_whitespace = \strspn( $text, " \t\n" ) !== \strlen( $text );
		}
	}

	public function require_format( WP_Experimental_HTML_Renderer_Format $format ) {
		$next_format_at         = \count( $this->formats );
		$this->open_formats[]   = $next_format_at;
		$this->format_offsets[] = \strlen( $this->buffer );
		$this->format_indices[] = $next_format_at;
		$this->formats[]        = $format;
	}

	public function release_format() {
		$this->format_offsets[] = -\strlen( $this->buffer );

		/*
		 * Sometimes, the line buffer has been replaced by the time that the
		 * format is released. What could cause this to happen?
		 */
//		assert( count( $this->open_formats ) > 0 );
		$this->format_indices[] = \array_pop( $this->open_formats );
	}

	public function raw_buffer(): string {
		return $this->buffer;
	}

	public function flush( WP_Experimental_HTML_Renderer_Options $options ): string {
		$offsets      = $this->format_offsets;
		$indices      = $this->format_indices;
		$formats      = $this->formats;
		$was_at       = 0;
		$buffer       = '';
		$length       = \strlen( $this->buffer );
		/** @var Array<string, int> $effects */
		$effects      = array(
			'bolding'        => 0,
			'emphasizing'    => 0,
			'monospacing'    => 0,
			'newlining'      => 0,
			'quoting'        => 0,
			'striking-out'   => 0,
			'subscripting'   => 0,
			'superscripting' => 0,
		);
		$syntax       = array(
			'bolding'      => array( '**' ),
			'emphasizing'  => array( '_' ),
			'monospacing'  => array( '`' ),
			'newlining'    => array( "\n" ),
			'quoting'      => array( '“', '”', '‘', '’' ),
			'striking-out' => array( '~' ),
		);
		$replacements = array(
			'bolding'      => array( '*' => '\*' ),
			'emphasizing'  => array( '_' => '\_' ),
			'monospacing'  => array( '`' => '\`' ),
			'striking-out' => array( '~' => '\~' ),
		);

		for ( $i = 0; $i < \count( $offsets ); $i++ ) {
			$at     = $offsets[ $i ];
			$state  = $at < 0 ? 'exiting' : 'entering';
			$at     = \abs( $at );
			$index  = $indices[ $i ];

			// @todo Why is this necessary?
			if ( ! isset( $index ) ) {
				goto next;
			}

			$format = $formats[ $index ];

			if ( $at > $was_at ) {
				$chunk   = \substr( $this->buffer, $was_at, $at - $was_at );
				foreach ( $effects as $effect => $depth ) {
					if ( $depth > 0 && isset( $replacements[ $effect ] ) ) {
						$chunk = \strtr( $chunk, $replacements[ $effect ] );
					}
				}
				$buffer .= $chunk;
			}

			if ( $format instanceof WP_Experimental_HTML_Renderer_Format_Generic ) {
				$type  = $format->type;
				$depth = $effects[ $type ];

				if ( 'quoting' === $type ) {
					$quote   = 'entering' === $state ? ( $depth * 2 ) : ( ( $depth - 1 ) * 2 + 1 );
					$buffer .= $syntax['quoting'][ $quote % 4 ];
				} elseif ( 'subscripting' === $type ) {
					if ( 'entering' === $state && 0 === $depth ) {
						$buffer .= '_(';
					} elseif ( 'exiting' === $state && 1 === $depth) {
						$buffer .= ')';
					}
				} elseif ( 'superscripting' === $type ) {
					if ( 'entering' === $state && 0 === $depth ) {
						$buffer .= '^(';
					} elseif ( 'exiting' === $state && 1 === $depth) {
						$buffer .= ')';
					}
				} elseif ( ( 0 === $depth && 'entering' === $state ) || ( $depth === 1 && 'exiting' === $state ) ) {
					$matching_offset = null;

					for ( $k = 0; $k < \count( $indices ); $k++ ) {
						if ( $k !== $i && $indices[ $k ] === $index ) {
							$matching_offset = $offsets[ $k ];
						}
					}

					if ( ! isset( $matching_offset ) || \abs( $matching_offset ) !== $at ) {
						$buffer .= $syntax[ $type ][0];
					}
				}

				$effects[ $type ] += 'entering' === $state ? 1 : -1;
			}

			if ( $format instanceof WP_Experimental_HTML_Renderer_Format_Link ) {
				$url = WP_Experimental_HTML_Renderer_Format_Link::normalize( $format->url, $options->base_url );

				/*
				 * Only render absolute HTTP/S links. Any relative links
				 * should be expanded here by now. It may be worth rendering
				 * other special schemes, like `ftp://` and `sftp://` and
				 * more, but this implementation is currently limited to HTTP.
				 */
				if (
					! \str_starts_with( $url, 'http://' ) &&
					! \str_starts_with( $url, 'https://' )
				) {
					$formats[ $index ] = null;
					goto next;
				}

				if ( 'entering' === $state ) {
					$buffer .= "\u{E0001}[";
				} else {
					$buffer .= "]({$url})\u{E007F}";
				}
			}

			if ( $format instanceof WP_Experimental_HTML_Renderer_Format_Image ) {
				/*
				 * There’s a special case for “block images,” which comprise
				 * the entirety of the line buffer. In these cases, the image
				 * should behave more like a block than a format. It might be
				 * best to handle this elsewhere, but the determination is
				 * complex. For example, it could be the case that _any_ image
				 * inside of a PICTURE or FIGURE element should turn into a
				 * block image, but that information won’t be known here.
				 */
				if (
					1 === \count( $formats ) &&
					'' === \trim( $this->buffer, " \r\t\f\n" )
				) {
					return '' !== $format->title
						? "![{$format->alt_text}]({$format->src_url} \"{$format->title}\")"
						: "![{$format->alt_text}]({$format->src_url})";
				}

				if ( '' === $format->alt_text || 'exiting' === $state ) {
					$formats[ $index ] = null;
					goto next;
				}

				$buffer .= "⌊{$format->alt_text}⌉";
			}

			next:
			$was_at = $at;
		}

		if ( $was_at < $length ) {
			$buffer .= \substr( $this->buffer, $was_at );
		}

		return \rtrim( $buffer, " \t\f\r\n" );
	}

	public function is_empty(): bool {
		return ! $this->has_non_whitespace_content();
	}

	public function has_non_whitespace_content(): bool {
		if ( $this->contains_non_whitespace ) {
			return true;
		}

		/*
		 * If a line buffer comprises only an image, it is a block image
		 * and therefore not empty in the normal sense.
		 */
		foreach ( $this->formats as $format ) {
			if ( $format instanceof WP_Experimental_HTML_Renderer_Format_Image ) {
				return true;
			}
		}

		return false;
	}
}