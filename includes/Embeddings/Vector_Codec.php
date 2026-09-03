<?php
/**
 * Binary encoding for embedding vectors.
 *
 * @package WordPress\AI\Embeddings
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Packs embedding vectors into compact little-endian float32 byte strings and back.
 *
 * Providers return vectors as float32 values, so storing them as float32 loses no
 * meaningful precision while using a quarter of the space of serialized PHP floats.
 * The encoding is the same one used by MariaDB's `VECTOR` type, which keeps a future
 * native backend able to read the same bytes.
 *
 * @since x.x.x
 */
final class Vector_Codec {

	/**
	 * Number of bytes used per vector component.
	 */
	public const BYTES_PER_COMPONENT = 4;

	/**
	 * Largest magnitude representable as a float32.
	 */
	public const MAX_MAGNITUDE = 3.4028234663852886e38;

	/**
	 * Widest binary quantization code the `embedding_coarse` column can hold, in bytes.
	 */
	public const MAX_COARSE_BYTES = 512;

	/**
	 * Packs a vector into a little-endian float32 byte string.
	 *
	 * @since x.x.x
	 *
	 * @param list<int|float> $vector The vector to pack.
	 * @return string Packed bytes, `4 * count( $vector )` long.
	 *
	 * @throws \InvalidArgumentException If the vector is empty, or contains values that are
	 *                                   non-finite or outside float32 range.
	 */
	public static function pack( array $vector ): string {
		self::validate( $vector );

		$packed = '';
		foreach ( $vector as $value ) {
			$packed .= pack( 'g', (float) $value );
		}

		return $packed;
	}

	/**
	 * Unpacks a little-endian float32 byte string into a vector.
	 *
	 * @since x.x.x
	 *
	 * @param string $packed     Packed bytes as produced by {@see self::pack()}.
	 * @param int    $dimensions Expected number of components.
	 * @return list<float> The unpacked vector.
	 *
	 * @throws \InvalidArgumentException If the byte length does not match the expected dimensions.
	 */
	public static function unpack( string $packed, int $dimensions ): array {
		if ( $dimensions <= 0 || strlen( $packed ) !== $dimensions * self::BYTES_PER_COMPONENT ) {
			throw new InvalidArgumentException(
				esc_html(
					sprintf(
						'Packed vector is %d bytes, expected %d for %d dimensions.',
						strlen( $packed ),
						$dimensions * self::BYTES_PER_COMPONENT,
						$dimensions
					)
				)
			);
		}

		$values = unpack( 'g*', $packed );
		if ( ! is_array( $values ) || count( $values ) !== $dimensions ) {
			throw new InvalidArgumentException( 'Packed vector could not be decoded.' );
		}

		return array_map( 'floatval', array_values( $values ) );
	}

	/**
	 * Calculates the Euclidean (L2) norm of a vector.
	 *
	 * @since x.x.x
	 *
	 * @param list<int|float> $vector The vector.
	 * @return float The norm.
	 */
	public static function norm( array $vector ): float {
		$sum = 0.0;
		foreach ( $vector as $value ) {
			$sum += (float) $value * (float) $value;
		}

		return sqrt( $sum );
	}

	/**
	 * Packs a vector into a binary quantization code, one bit per component.
	 *
	 * Each component contributes a single bit recording its sign, most significant bit first, so a
	 * 1536-dimension vector becomes 192 bytes rather than the 6,144 bytes of its float32 form.
	 *
	 * @since x.x.x
	 *
	 * @param list<int|float> $vector The vector to quantize.
	 * @return string Packed bits, `ceil( count( $vector ) / 8 )` bytes long.
	 *
	 * @throws \InvalidArgumentException If the vector is empty, or contains values that are
	 *                                   non-finite or outside float32 range.
	 */
	public static function pack_coarse( array $vector ): string {
		self::validate( $vector );

		$code   = '';
		$byte   = 0;
		$filled = 0;

		foreach ( $vector as $value ) {
			$byte = ( $byte << 1 ) | ( $value > 0 ? 1 : 0 );

			if ( 8 !== ++$filled ) {
				continue;
			}

			$code  .= chr( $byte );
			$byte   = 0;
			$filled = 0;
		}

		if ( $filled > 0 ) {
			$padding = 8 - $filled;
			$code   .= chr( $byte << $padding );
		}

		return $code;
	}

	/**
	 * Returns the Hamming distance between two binary quantization codes.
	 *
	 * @since x.x.x
	 *
	 * @param string $a First code.
	 * @param string $b Second code, of the same byte length as `$a`.
	 * @return int Number of differing bits.
	 *
	 * @throws \InvalidArgumentException If the codes are empty or of differing lengths.
	 */
	public static function hamming( string $a, string $b ): int {
		if ( '' === $a || strlen( $a ) !== strlen( $b ) ) {
			throw new InvalidArgumentException(
				esc_html(
					sprintf(
						'Binary quantization codes must be non-empty and the same length; got %d and %d bytes.',
						strlen( $a ),
						strlen( $b )
					)
				)
			);
		}

		$table    = self::popcount_table();
		$distance = 0;

		foreach ( count_chars( $a ^ $b, 1 ) as $byte => $occurrences ) {
			$distance += $table[ $byte ] * $occurrences;
		}

		return $distance;
	}

	/**
	 * Returns a lazily built lookup table of set-bit counts for every byte value.
	 *
	 * @since x.x.x
	 *
	 * @return array<int, int> Map of byte value to number of set bits.
	 */
	private static function popcount_table(): array {
		static $table = null;

		if ( null === $table ) {
			$table = array();

			for ( $i = 0; $i < 256; $i++ ) {
				$table[ $i ] = substr_count( decbin( $i ), '1' );
			}
		}

		return $table;
	}

	/**
	 * Validates that a value is a non-empty list of finite numbers.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $vector The candidate vector.
	 * @return void
	 *
	 * @throws \InvalidArgumentException If the value is not a non-empty list of numbers, or if any
	 *                                   component is non-finite or outside float32 range.
	 */
	public static function validate( $vector ): void {
		if ( ! is_array( $vector ) || array() === $vector ) {
			throw new InvalidArgumentException( 'Embedding vector must be a non-empty list of numbers.' );
		}

		$expected_index = 0;

		foreach ( $vector as $index => $value ) {
			if ( $index !== $expected_index ) {
				throw new InvalidArgumentException( 'Embedding vector must be a non-empty list of numbers.' );
			}

			++$expected_index;

			if ( ! is_int( $value ) && ! is_float( $value ) ) {
				throw new InvalidArgumentException(
					esc_html( sprintf( 'Embedding vector component %d is not a number.', $index ) )
				);
			}

			if ( is_float( $value ) && ! is_finite( $value ) ) {
				throw new InvalidArgumentException(
					esc_html( sprintf( 'Embedding vector component %d is not finite.', $index ) )
				);
			}

			if ( $value > self::MAX_MAGNITUDE || $value < -self::MAX_MAGNITUDE ) {
				throw new InvalidArgumentException(
					esc_html(
						sprintf(
							'Embedding vector component %d is outside the range representable as float32.',
							$index
						)
					)
				);
			}
		}
	}
}
