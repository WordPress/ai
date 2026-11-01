<?php
/**
 * In-memory JUMBF (ISO/IEC 19566-5) box tree reader.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\C2pa_Monitor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Walks a JUMBF manifest store box by box and returns its superbox tree.
 *
 * Content boxes are returned as offsets into the original buffer, not copied.
 *
 * @since x.x.x
 *
 * @phpstan-type Jumbf_Box array{type: string, offset: int, length: int}
 * @phpstan-type Jumbf_Superbox array{uuid: string, label: string|null, boxes: list<Jumbf_Box>, children: list<mixed>}
 */
class Jumbf_Reader {
	/**
	 * Deepest superbox nesting to follow.
	 *
	 * @var int
	 */
	private const MAX_DEPTH = 16;

	/**
	 * Most boxes to walk across the whole store before giving up.
	 *
	 * @var int
	 */
	private const MAX_BOXES = 10000;

	/**
	 * Description box toggle bit signalling that a label follows the UUID.
	 *
	 * @var int
	 */
	private const TOGGLE_LABEL = 0x02;

	/**
	 * Store being walked.
	 *
	 * @var string
	 */
	private string $bytes = '';

	/**
	 * Boxes walked so far.
	 *
	 * @var int
	 */
	private int $box_count = 0;

	/**
	 * Parses $bytes, which must start with the root `jumb` superbox.
	 *
	 * @since x.x.x
	 *
	 * @param string $bytes Raw JUMBF manifest store bytes.
	 * @return array<string, mixed> Root superbox node.
	 * @phpstan-return Jumbf_Superbox
	 *
	 * @throws \UnexpectedValueException When the store is malformed or exceeds a bound.
	 */
	public function parse( string $bytes ): array {
		$this->bytes     = $bytes;
		$this->box_count = 0;

		$header = $this->read_box_header( 0, strlen( $bytes ) );
		if ( 'jumb' !== $header['type'] ) {
			throw new \UnexpectedValueException( esc_html__( 'The manifest store does not start with a JUMBF superbox.', 'ai' ) );
		}

		return $this->parse_superbox( $header['data_offset'], $header['end'], 0 );
	}

	/**
	 * Parses the payload of a `jumb` superbox spanning [$start, $end).
	 *
	 * @since x.x.x
	 *
	 * @param int $start Offset of the first child box.
	 * @param int $end   Offset just past the superbox.
	 * @param int $depth Current nesting depth.
	 * @return array<string, mixed>
	 * @phpstan-return Jumbf_Superbox
	 *
	 * @throws \UnexpectedValueException When the superbox is malformed or exceeds a bound.
	 */
	private function parse_superbox( int $start, int $end, int $depth ): array {
		if ( $depth > self::MAX_DEPTH ) {
			throw new \UnexpectedValueException( esc_html__( 'JUMBF superboxes are nested too deeply.', 'ai' ) );
		}

		$description = $this->read_box_header( $start, $end );
		if ( 'jumd' !== $description['type'] ) {
			throw new \UnexpectedValueException( esc_html__( 'A JUMBF superbox is missing its description box.', 'ai' ) );
		}

		$node = $this->parse_description( $description['data_offset'], $description['end'] );

		$offset = $description['end'];
		while ( $offset < $end ) {
			$header = $this->read_box_header( $offset, $end );

			if ( 'jumb' === $header['type'] ) {
				$node['children'][] = $this->parse_superbox( $header['data_offset'], $header['end'], $depth + 1 );
			} else {
				$node['boxes'][] = array(
					'type'   => $header['type'],
					'offset' => $header['data_offset'],
					'length' => $header['end'] - $header['data_offset'],
				);
			}

			$offset = $header['end'];
		}

		return $node;
	}

	/**
	 * Parses the type UUID and label of a `jumd` description box payload.
	 *
	 * @since x.x.x
	 *
	 * @param int $start Offset of the description payload.
	 * @param int $end   Offset just past the description box.
	 * @return array<string, mixed>
	 * @phpstan-return Jumbf_Superbox
	 *
	 * @throws \UnexpectedValueException When the description box is truncated.
	 */
	private function parse_description( int $start, int $end ): array {
		if ( $end - $start < 17 ) {
			throw new \UnexpectedValueException( esc_html__( 'A JUMBF description box is truncated.', 'ai' ) );
		}

		$uuid    = bin2hex( substr( $this->bytes, $start, 16 ) );
		$toggles = ord( $this->bytes[ $start + 16 ] );
		$label   = null;

		if ( $toggles & self::TOGGLE_LABEL ) {
			$label_start = $start + 17;
			$terminator  = strpos( $this->bytes, "\x00", $label_start );
			$label_end   = false === $terminator || $terminator > $end ? $end : $terminator;
			$label       = (string) substr( $this->bytes, $label_start, $label_end - $label_start );

			if ( 1 !== preg_match( '//u', $label ) ) {
				$label = null;
			}
		}

		return array(
			'uuid'     => $uuid,
			'label'    => $label,
			'boxes'    => array(),
			'children' => array(),
		);
	}

	/**
	 * Reads the box header at $offset, bounded by its parent's $end.
	 *
	 * @since x.x.x
	 *
	 * @param int $offset Offset of the box header.
	 * @param int $end    Offset just past the parent box.
	 * @return array{type: string, data_offset: int, end: int}
	 *
	 * @throws \UnexpectedValueException When the header is truncated or the box overruns its parent.
	 */
	private function read_box_header( int $offset, int $end ): array {
		if ( ++$this->box_count > self::MAX_BOXES ) {
			throw new \UnexpectedValueException( esc_html__( 'The manifest store contains too many JUMBF boxes.', 'ai' ) );
		}

		if ( $end - $offset < 8 ) {
			throw new \UnexpectedValueException( esc_html__( 'A JUMBF box header is truncated.', 'ai' ) );
		}

		$size        = self::read_uint32( $this->bytes, $offset );
		$type        = (string) substr( $this->bytes, $offset + 4, 4 );
		$data_offset = $offset + 8;

		if ( 1 === $size ) {
			if ( $end - $offset < 16 ) {
				throw new \UnexpectedValueException( esc_html__( 'A JUMBF box header is truncated.', 'ai' ) );
			}

			$high = self::read_uint32( $this->bytes, $offset + 8 );
			if ( 0 !== $high ) {
				throw new \UnexpectedValueException( esc_html__( 'A JUMBF box declares a length larger than the manifest store.', 'ai' ) );
			}

			$size        = self::read_uint32( $this->bytes, $offset + 12 );
			$data_offset = $offset + 16;
		} elseif ( 0 === $size ) {
			$size = $end - $offset;
		}

		if ( $size < $data_offset - $offset || $size > $end - $offset ) {
			throw new \UnexpectedValueException( esc_html__( 'A JUMBF box declares a length larger than the manifest store.', 'ai' ) );
		}

		return array(
			'type'        => $type,
			'data_offset' => $data_offset,
			'end'         => $offset + $size,
		);
	}

	/**
	 * Reads a big-endian unsigned 32-bit integer.
	 *
	 * @since x.x.x
	 *
	 * @param string $bytes  Buffer.
	 * @param int    $offset Offset of the integer.
	 * @return int
	 */
	private static function read_uint32( string $bytes, int $offset ): int {
		$unpacked = unpack( 'N', $bytes, $offset );
		return false === $unpacked ? 0 : (int) $unpacked[1];
	}
}
