<?php
/**
 * Vector arithmetic for embeddings.
 *
 * @package WordPress\AI\Embeddings
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Pure arithmetic over embedding vectors.
 *
 * Every method takes plain lists of numbers and returns a number or a new list.
 * Every operand must pass `Vector_Codec::validate()`. Two-vector functions also
 * require equal lengths, and the functions whose result would be undefined for
 * a zero vector reject one.
 *
 * @since x.x.x
 */
final class Vector_Math {

	/**
	 * Cosine similarity: higher is more similar, range [-1, 1].
	 *
	 * @since x.x.x
	 * @var string
	 */
	public const METRIC_COSINE = 'cosine';

	/**
	 * Dot product: higher is more similar. Equals cosine similarity when both vectors are unit length.
	 *
	 * @since x.x.x
	 * @var string
	 */
	public const METRIC_DOT_PRODUCT = 'dot_product';

	/**
	 * Euclidean (L2) distance: lower is closer, range [0, ∞).
	 *
	 * @since x.x.x
	 * @var string
	 */
	public const METRIC_EUCLIDEAN = 'euclidean';

	/**
	 * Returns the dot product of two vectors.
	 *
	 * @since x.x.x
	 *
	 * @param list<int|float> $a First vector.
	 * @param list<int|float> $b Second vector, the same length as $a.
	 * @return float The dot product.
	 *
	 * @throws \InvalidArgumentException If either vector is invalid or the lengths differ.
	 */
	public static function dot_product( array $a, array $b ): float {
		self::assert_comparable( $a, $b );

		$sum = 0.0;
		foreach ( $a as $index => $value ) {
			$sum += (float) $value * (float) $b[ $index ];
		}

		return $sum;
	}

	/**
	 * Returns the Euclidean (L2) norm of a vector.
	 *
	 * @since x.x.x
	 *
	 * @param list<int|float> $vector The vector.
	 * @return float The norm; 0.0 for a zero vector.
	 *
	 * @throws \InvalidArgumentException If the vector is invalid.
	 */
	public static function norm( array $vector ): float {
		Vector_Codec::validate( $vector );

		$sum = 0.0;
		foreach ( $vector as $value ) {
			$sum += (float) $value * (float) $value;
		}

		return sqrt( $sum );
	}

	/**
	 * Returns the cosine similarity of two vectors.
	 *
	 * @since x.x.x
	 *
	 * @param list<int|float> $a First vector.
	 * @param list<int|float> $b Second vector, the same length as $a.
	 * @return float Similarity in [-1, 1]; 1 is identical direction, 0 orthogonal, -1 opposite.
	 *
	 * @throws \InvalidArgumentException If either vector is invalid, the lengths differ, or either
	 *                                  vector is zero (the similarity is undefined).
	 */
	public static function cosine_similarity( array $a, array $b ): float {
		self::assert_comparable( $a, $b );

		$dot       = 0.0;
		$squared_a = 0.0;
		$squared_b = 0.0;

		foreach ( $a as $index => $value ) {
			$value_a = (float) $value;
			$value_b = (float) $b[ $index ];

			$dot       += $value_a * $value_b;
			$squared_a += $value_a * $value_a;
			$squared_b += $value_b * $value_b;
		}

		if ( 0.0 === $squared_a || 0.0 === $squared_b ) {
			throw new InvalidArgumentException( 'Cosine similarity is undefined for a zero vector.' );
		}

		return max( -1.0, min( 1.0, $dot / sqrt( $squared_a * $squared_b ) ) );
	}

	/**
	 * Returns the Euclidean (L2) distance between two vectors.
	 *
	 * @since x.x.x
	 *
	 * @param list<int|float> $a First vector.
	 * @param list<int|float> $b Second vector, the same length as $a.
	 * @return float The distance; 0.0 for identical vectors.
	 *
	 * @throws \InvalidArgumentException If either vector is invalid or the lengths differ.
	 */
	public static function euclidean_distance( array $a, array $b ): float {
		self::assert_comparable( $a, $b );

		$sum = 0.0;
		foreach ( $a as $index => $value ) {
			$difference = (float) $value - (float) $b[ $index ];
			$sum       += $difference * $difference;
		}

		return sqrt( $sum );
	}

	/**
	 * Returns the unit vector pointing in the same direction as the given vector.
	 *
	 * @since x.x.x
	 *
	 * @param list<int|float> $vector The vector.
	 * @return list<float> A vector of length 1.
	 *
	 * @throws \InvalidArgumentException If the vector is invalid or is a zero vector (no direction).
	 */
	public static function normalize( array $vector ): array {
		$norm = self::norm( $vector );

		if ( 0.0 === $norm ) {
			throw new InvalidArgumentException( 'A zero vector cannot be normalized.' );
		}

		$unit = array();
		foreach ( $vector as $value ) {
			$unit[] = (float) $value / $norm;
		}

		return $unit;
	}

	/**
	 * Returns the centroid (component-wise mean) of a set of vectors.
	 *
	 * @since x.x.x
	 *
	 * @param array<int|string, list<int|float>> $vectors One or more vectors of equal length.
	 * @return list<float> The centroid, the same length as the inputs.
	 *
	 * @throws \InvalidArgumentException If the set is empty, a member is not a valid vector, or the
	 *                                  lengths differ.
	 */
	public static function centroid( array $vectors ): array {
		if ( array() === $vectors ) {
			throw new InvalidArgumentException( 'A centroid needs at least one vector.' );
		}

		$sums  = null;
		$count = 0;

		foreach ( $vectors as $key => $vector ) {
			if ( ! is_array( $vector ) ) {
				throw new InvalidArgumentException(
					esc_html( sprintf( 'Vector at key %s is not an array.', (string) $key ) )
				);
			}

			Vector_Codec::validate( $vector );

			if ( null === $sums ) {
				$sums = array_fill( 0, count( $vector ), 0.0 );
			} elseif ( count( $vector ) !== count( $sums ) ) {
				throw new InvalidArgumentException(
					esc_html(
						sprintf(
							'Vector at key %s has %d dimensions; expected %d.',
							(string) $key,
							count( $vector ),
							count( $sums )
						)
					)
				);
			}

			foreach ( $vector as $index => $value ) {
				$sums[ $index ] += (float) $value;
			}

			++$count;
		}

		$centroid = array();
		foreach ( $sums as $sum ) {
			$centroid[] = $sum / $count;
		}

		return $centroid;
	}

	/**
	 * Validates two vectors and confirms they have the same number of dimensions.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $a First vector.
	 * @param array<mixed> $b Second vector.
	 *
	 * @throws \InvalidArgumentException If either vector is invalid or the lengths differ.
	 */
	private static function assert_comparable( array $a, array $b ): void {
		Vector_Codec::validate( $a );
		Vector_Codec::validate( $b );

		if ( count( $a ) !== count( $b ) ) {
			throw new InvalidArgumentException(
				esc_html(
					sprintf(
						'Vectors must have the same number of dimensions; got %1$d and %2$d.',
						count( $a ),
						count( $b )
					)
				)
			);
		}
	}
}
