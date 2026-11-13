<?php

namespace WordPress\AI\Vendor\Html_To_Markdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Represents a link with a label.
 */
class WP_Experimental_HTML_Renderer_Format_Link extends WP_Experimental_HTML_Renderer_Format {
	/**
	 * Where this link points.
	 *
	 * @var string
	 */
	public string $url;

	/**
	 * Create a format for a linked span of content.
	 *
	 * @param string $url
	 */
	public function __construct( string $url ) {
		$this->url = $url;
	}

	/**
	 * Normalize URLs into recognizable forms: includes converting relative paths
	 * into URLs where possible.
	 *
	 * @todo Use the WhatWG URI class where available, or any better parser than {@see parse_url()}.
	 *
	 * @param string $url
	 * @param string|null $base_url
	 * @return string
	 */
	public static function normalize( string $url, ?string $base_url = null ): string {
		if ( '' === $url ) {
			return $url;
		}

		// Without a base URL there’s nothing more to do here.
		if ( ! isset( $base_url ) ) {
			return $url;
		}

		$parts = \parse_url( $url );

		// If a host is already provided then it’s already an absolute URL.
		if ( isset( $parts['host'] ) ) {
			return $url;
		}

		// There could be multiple leading slashes, but these are not handled.
		$is_relatively_absolute = isset( $parts['path'] ) && \str_starts_with( $parts['path'], '/' );

		$base_parts = \parse_url( $base_url );

		if ( $is_relatively_absolute ) {
			// The path should replace the base path on the server.
			$base_parts['path'] = $parts['path'];
		} elseif ( isset( $parts['path'] ) ) {
			// The path should append, replacing the last non-terminated path segment.
			if ( isset( $base_parts['path'] ) && \str_ends_with( $base_parts['path'], '/' ) ) {
				$base_parts['path'] .= $parts['path'];
			} elseif ( isset( $base_parts['path'] ) ) {
				$last_segment_at    = \strrpos( $base_parts['path'], '/' );
				$base_parts['path'] = \substr( $base_parts['path'], 0, $last_segment_at + 1 );
				$base_parts['path'] .= $parts['path'];
			}
		}

		foreach ( array( 'query', 'fragment' ) as $part ) {
			if ( isset( $parts[ $part ] ) ) {
				$base_parts[ $part ] = $parts[ $part ];
			}
		}

		$normalized = '';
		if ( isset( $base_parts['scheme'] ) ) {
			$normalized .= "{$base_parts['scheme']}://";
		}

		if ( isset( $base_parts['user'] ) || isset( $base_parts['pass'] ) ) {
			if ( isset( $base_parts['user'], $base_parts['pass'] ) ) {
				$normalized .= "{$base_parts['user']}:{$base_parts['pass']}@";
			} elseif ( isset( $base_parts['user'] ) ) {
				$normalized .= "{$base_parts['user']}@";
			} else {
				$normalized .= ":{$base_parts['pass']}@";
			}
		}

		if ( isset( $base_parts['host'] ) ) {
			$normalized .= $base_parts['host'];
		}

		if ( isset( $base_parts['port'] ) ) {
			$normalized .= ":{$base_parts['port']}";
		}

		if ( isset( $base_parts['path'] ) ) {
			$normalized .= $base_parts['path'];
		}

		if ( isset( $base_parts['query'] ) ) {
			$normalized .= '?' . $base_parts['query'];
		}

		if ( isset( $base_parts['fragment'] ) ) {
			$normalized .= '#' . $base_parts['fragment'];
		}

		return $normalized;
	}
}