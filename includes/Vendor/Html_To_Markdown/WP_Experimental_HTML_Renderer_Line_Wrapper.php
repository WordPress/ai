<?php

namespace WordPress\AI\Vendor\Html_To_Markdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function line_wrap( string $text, int $soft_limit ): array {
	/** Tune to better align the ending edge of wrapped lines. */
	$fractional_soft_limit_ratio = 0.4;

	$bi          = \IntlBreakIterator::createWordInstance( \locale_get_default() );
	$pi          = $bi->getPartsIterator();
	$lines       = array();
	$line_length = 0;
	$was_at      = 0;

	// Determine non-wrappable regions.
	$at    = 0;
	$end   = \strlen( $text );
	$nobrs = array();
	$delta = 0;
	while ( $at < $end ) {
		$nobr_at = \strpos( $text, "\u{E0001}", $at );
		if ( false === $nobr_at ) {
			break;
		}

		$at = \strpos( $text, "\u{E007F}", $nobr_at );
		// Given the bug with formats closing unexpectedly, there may be no closer.
//		assert( false !== $at );
		if ( false === $at ) {
			$next_br_at = strpos( $text, "\u{E0001}", $nobr_at + 1 );

			// This length value is arbitrarily chosen. This is already a degenerate case.
			$at = false === $next_br_at ? ( $nobr_at + 20 ) : min( $nobr_at + 20, $next_br_at );
		}
		$nobrs[] = array( $nobr_at - $delta, $at - $delta - 4 );
		$delta += 8;
	}
	$nobr = \array_shift( $nobrs );
	$text = str_replace( array( "\u{E0001}", "\u{E007F}" ), '', $text );

	$bi->setText( $text );

	foreach ( $pi as $part ) {
		$offset          = $bi->current();
		$chunk_width     = \mb_strwidth( $part );
		$width_remaining = $soft_limit - $line_length;

		if ( $nobr && $offset >= $nobr[1] ) {
			$nobr = \array_shift( $nobrs );
		}

		$is_unbreakable = $nobr && $offset > $nobr[0] && $offset <= $nobr[1];
		if ( $is_unbreakable ) {
			$line_length += $chunk_width;
			continue;
		}

		// Add trailing non-word content to the previous line.
		if (
			0 === $line_length &&
			\IntlBreakIterator::WORD_NONE === $bi->getRuleStatus() &&
			\count( $lines ) > 0 &&
			1 === \preg_match( '~\A[`*_\p{C}\p{P}\p{Z}]*\Z~u', $part )
		) {
			$lines[ count( $lines ) - 1 ] .= \preg_replace( '~\p{Z}+\Z~u', '', $part );
			$was_at = $offset;
			continue;
		}

		if ( $chunk_width < $width_remaining ) {
			// Is this faster from OOP/speculation than `= $next_width`?
			$line_length += $chunk_width;
			continue;
		}

		// If it sticks out a little, append it, otherwise start a new line.
		if ( ( $chunk_width / max( 1, $width_remaining ) ) < $fractional_soft_limit_ratio ) {
			$line_length += $chunk_width;
			continue;
		}

		// It’s too long, but for now just append it and move on.
		$lines[]     = \substr( $text, $was_at, $offset - $was_at );
		$line_length = 0;
		$was_at      = $offset;
	}

	if ( $was_at < \strlen( $text ) ) {
		$lines[] = \substr( $text, $was_at );
	}

	return $lines;
}