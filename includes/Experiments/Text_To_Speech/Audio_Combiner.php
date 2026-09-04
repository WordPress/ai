<?php
/**
 * Audio chunk combiner for text to speech generation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Text_To_Speech;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Combines MP3 audio chunks into a single stream by byte concatenation.
 *
 * All chunks of a job are generated with the same model, voice, and
 * `audio/mpeg` output, so their encoding parameters match and MP3 frames can
 * be concatenated directly. ID3v2 headers are stripped from every chunk after
 * the first and ID3v1 trailers from every chunk before the last, so metadata
 * blocks never land mid-stream. Joins are not guaranteed gapless; this is a
 * deliberate trade-off to avoid requiring server-side transcoding tools.
 *
 * @since x.x.x
 */
final class Audio_Combiner {

	/**
	 * Prepares a chunk's bytes for concatenation based on its position.
	 *
	 * @since x.x.x
	 *
	 * @param string $bytes    The decoded audio bytes for the chunk.
	 * @param bool   $is_first Whether this is the first chunk of the stream.
	 * @param bool   $is_last  Whether this is the last chunk of the stream.
	 * @return string The prepared bytes.
	 */
	public static function prepare_chunk( string $bytes, bool $is_first, bool $is_last ): string {
		if ( ! $is_first ) {
			$bytes = self::strip_id3v2( $bytes );
		}

		if ( ! $is_last ) {
			$bytes = self::strip_id3v1( $bytes );
		}

		return $bytes;
	}

	/**
	 * Appends an audio chunk to the combined output file.
	 *
	 * The first append truncates any pre-existing file contents so a
	 * restarted job never inherits stale bytes.
	 *
	 * @since x.x.x
	 *
	 * @param string $file_path The absolute path of the combined output file.
	 * @param string $bytes     The decoded audio bytes for this chunk.
	 * @param bool   $is_first  Whether this is the first chunk of the job.
	 * @param bool   $is_last   Whether this is the last chunk of the job.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public static function append_chunk( string $file_path, string $bytes, bool $is_first, bool $is_last ) {
		$bytes = self::prepare_chunk( $bytes, $is_first, $is_last );

		if ( '' === $bytes ) {
			return new WP_Error(
				'empty_audio_chunk',
				esc_html__( 'An audio chunk contained no audio data.', 'ai' )
			);
		}

		$written = file_put_contents( $file_path, $bytes, $is_first ? 0 : FILE_APPEND ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents

		if ( false === $written ) {
			return new WP_Error(
				'write_failed',
				esc_html__( 'Failed to write audio data to the temporary file.', 'ai' )
			);
		}

		return true;
	}

	/**
	 * Strips a leading ID3v2 tag from the given bytes, if present.
	 *
	 * An ID3v2 tag starts with "ID3" followed by a 10-byte header whose last
	 * four bytes are a syncsafe integer describing the tag body length.
	 *
	 * @since x.x.x
	 *
	 * @param string $bytes The audio bytes.
	 * @return string The bytes without a leading ID3v2 tag.
	 */
	public static function strip_id3v2( string $bytes ): string {
		if ( strlen( $bytes ) < 10 || 'ID3' !== substr( $bytes, 0, 3 ) ) {
			return $bytes;
		}

		$size = ( ( ord( $bytes[6] ) & 0x7F ) << 21 )
			| ( ( ord( $bytes[7] ) & 0x7F ) << 14 )
			| ( ( ord( $bytes[8] ) & 0x7F ) << 7 )
			| ( ord( $bytes[9] ) & 0x7F );

		$tag_length = 10 + $size;

		if ( strlen( $bytes ) <= $tag_length ) {
			return '';
		}

		return substr( $bytes, $tag_length );
	}

	/**
	 * Strips a trailing ID3v1 tag (final 128 bytes starting "TAG") from the
	 * given bytes, if present.
	 *
	 * @since x.x.x
	 *
	 * @param string $bytes The audio bytes.
	 * @return string The bytes without a trailing ID3v1 tag.
	 */
	public static function strip_id3v1( string $bytes ): string {
		if ( strlen( $bytes ) >= 128 && 'TAG' === substr( $bytes, -128, 3 ) ) {
			return substr( $bytes, 0, -128 );
		}

		return $bytes;
	}
}
