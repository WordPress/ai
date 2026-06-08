<?php
/**
 * Lean WordPress-native post chunker for RAG indexing.
 *
 * @package WordPress\AI\RAG
 */

declare( strict_types=1 );

namespace WordPress\AI\RAG;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Converts posts into deterministic text chunks.
 *
 * @since 1.1.0
 */
class Post_Chunker {
	/**
	 * Default chunk window size in characters.
	 */
	private const DEFAULT_WINDOW_CHARS = 1500;

	/**
	 * Default chunk step size in characters.
	 */
	private const DEFAULT_STEP_CHARS = 750;

	/**
	 * Minimum useful chunk size in characters.
	 */
	private const MIN_CHUNK_CHARS = 80;

	/**
	 * Chunks a post for embedding.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_Post $post Post object.
	 * @return list<array{chunk_id:string, chunk_index:int, chunk_offset:int, anchor:string|null, title:string, permalink:string, content:string}> Chunk records.
	 */
	public function chunk_post( WP_Post $post ): array {
		$text = $this->get_indexable_text( $post );

		if ( '' === $text ) {
			return array();
		}

		$window = $this->get_window_chars();
		$step   = $this->get_step_chars( $window );

		$chunks     = array();
		$text_len   = strlen( $text );
		$offset     = 0;
		$chunk_idx  = 0;
		$permalink  = get_permalink( $post );
		$permalink  = is_string( $permalink ) ? $permalink : '';
		$post_title = get_the_title( $post );

		while ( $offset < $text_len ) {
			$length  = min( $window, $text_len - $offset );
			$segment = substr( $text, $offset, $length );

			if ( $offset + $length < $text_len ) {
				$segment = $this->trim_to_sentence_boundary( $segment );
			}

			$segment = trim( (string) $segment );
			if ( strlen( $segment ) >= self::MIN_CHUNK_CHARS || 0 === $chunk_idx ) {
				$chunks[] = array(
					'chunk_id'     => $this->build_chunk_id( (int) $post->ID, $offset ),
					'chunk_index'  => $chunk_idx,
					'chunk_offset' => $offset,
					'anchor'       => null,
					'title'        => $post_title,
					'permalink'    => $permalink,
					'content'      => $segment,
				);
				++$chunk_idx;
			}

			if ( $offset + $window >= $text_len ) {
				break;
			}

			$offset += $step;
		}

		return $chunks;
	}

	/**
	 * Builds normalized text for embedding.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_Post $post Post object.
	 * @return string Indexable text.
	 */
	public function get_indexable_text( WP_Post $post ): string {
		$content = (string) $post->post_content;

		if ( function_exists( 'do_blocks' ) ) {
			$content = do_blocks( $content );
		}

		$content = strip_shortcodes( $content );
		$content = apply_filters( 'the_content', $content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$content = $this->html_to_plain_text( (string) $content );

		$title = trim( wp_strip_all_tags( get_the_title( $post ) ) );
		$text  = trim( $title . "\n\n" . $content );

		/**
		 * Filters the normalized post text before RAG chunking.
		 *
		 * @since 1.1.0
		 *
		 * @param string   $text Normalized text.
		 * @param \WP_Post $post Post object.
		 */
		$text = (string) apply_filters( 'wpai_rag_indexable_post_text', $text, $post );

		return trim( preg_replace( '/\s+/u', ' ', $text ) ?? $text );
	}

	/**
	 * Converts HTML to readable plain text.
	 *
	 * @since 1.1.0
	 *
	 * @param string $html HTML content.
	 * @return string Plain text.
	 */
	private function html_to_plain_text( string $html ): string {
		$html = preg_replace( '#<(h[1-6]|p|li|blockquote|pre|br)\b[^>]*>#i', "\n$0", $html ) ?? $html;
		$html = preg_replace( '#</(h[1-6]|p|li|blockquote|pre)>#i', "$0\n", $html ) ?? $html;
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );

		return trim( preg_replace( '/[ \t\r\n]+/u', ' ', $text ) ?? $text );
	}

	/**
	 * Trims a segment to the last sentence boundary when practical.
	 *
	 * @since 1.1.0
	 *
	 * @param string $segment Text segment.
	 * @return string Trimmed segment.
	 */
	private function trim_to_sentence_boundary( string $segment ): string {
		if ( preg_match_all( '/[.!?;:]\s+/u', $segment, $matches, PREG_OFFSET_CAPTURE ) && ! empty( $matches[0] ) ) {
			$last_match = end( $matches[0] );
			$boundary   = (int) $last_match[1] + strlen( (string) $last_match[0] );

			if ( $boundary > (int) ( strlen( $segment ) * 0.6 ) ) {
				return substr( $segment, 0, $boundary );
			}
		}

		return $segment;
	}

	/**
	 * Builds a deterministic chunk UUID.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Post ID.
	 * @param int $offset  Chunk offset.
	 * @return string UUID-shaped chunk ID.
	 */
	private function build_chunk_id( int $post_id, int $offset ): string {
		$hash = md5( $post_id . ':' . $offset );

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $hash, 0, 8 ),
			substr( $hash, 8, 4 ),
			substr( $hash, 12, 4 ),
			substr( $hash, 16, 4 ),
			substr( $hash, 20, 12 )
		);
	}

	/**
	 * Returns the configured chunk window.
	 *
	 * @since 1.1.0
	 *
	 * @return int Window size.
	 */
	private function get_window_chars(): int {
		/**
		 * Filters the RAG chunk window size in characters.
		 *
		 * @since 1.1.0
		 *
		 * @param int $window_chars Window size.
		 */
		return max( 300, (int) apply_filters( 'wpai_rag_chunk_window_chars', self::DEFAULT_WINDOW_CHARS ) );
	}

	/**
	 * Returns the configured chunk step.
	 *
	 * @since 1.1.0
	 *
	 * @param int $window Window size.
	 * @return int Step size.
	 */
	private function get_step_chars( int $window ): int {
		/**
		 * Filters the RAG chunk step size in characters.
		 *
		 * @since 1.1.0
		 *
		 * @param int $step_chars Step size.
		 * @param int $window     Window size.
		 */
		$step = (int) apply_filters( 'wpai_rag_chunk_step_chars', self::DEFAULT_STEP_CHARS, $window );

		return max( 100, min( $window, $step ) );
	}
}
