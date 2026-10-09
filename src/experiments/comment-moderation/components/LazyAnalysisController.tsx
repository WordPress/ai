/**
 * Lazy Analysis Controller component.
 *
 * Detects pending comments in the list table and triggers analysis on-demand.
 */

/**
 * External dependencies
 */
import type React from 'react';

/**
 * WordPress dependencies
 */
import { useEffect, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';

type AnalysisResult = {
	comment_id: number;
	toxicity_score: number;
	value_score: number;
	sentiment: 'positive' | 'negative' | 'neutral';
};

declare global {
	interface Window {
		aiCommentModerationData: {
			enabled: boolean;
			labels: {
				sentiment: Record<
					string,
					{ label: string; class: string; icon: string }
				> & {
					neutral: { label: string; class: string; icon: string }; // Allows us to access neutral for fallbacks directly.
				};
				toxicity: Record<
					string,
					{
						label: string;
						class: string;
						icon: string;
						min: number;
						max: number;
					}
				>;
				value_score: Record<
					string,
					{
						label: string;
						class: string;
						icon: string;
						min: number;
						max: number;
					}
				>;
			};
		};
	}
}

/**
 * A comment queued for analysis.
 *
 * The badges are optional because a site can filter any of the three columns
 * out of the list table. Whichever badges are present get updated.
 */
type PendingComment = {
	id: number;
	sentimentBadge?: HTMLElement;
	toxicityBadge?: HTMLElement;
	valueScoreBadge?: HTMLElement;
};

type BadgeDisplay = {
	label: string;
	className: string;
	icon: string;
};

/**
 * Gets the label, class, and icon for a 0-1 score from its tier configuration.
 */
function getScoreDisplay(
	score: number,
	configKey: 'toxicity' | 'value_score',
	fallback: BadgeDisplay
): BadgeDisplay {
	const tiers = window.aiCommentModerationData?.labels?.[ configKey ] || {};

	for ( const config of Object.values( tiers ) ) {
		if (
			score >= config.min &&
			( score < config.max || config.max === 1 )
		) {
			return {
				label: config.label,
				className: config.class,
				icon: config.icon,
			};
		}
	}

	return fallback;
}

/**
 * Gets the toxicity label and class from score.
 */
function getToxicityDisplay( score: number ): BadgeDisplay {
	return getScoreDisplay( score, 'toxicity', {
		label: 'Low',
		className: 'ai-badge--low-toxicity',
		icon: '✓',
	} );
}

/**
 * Gets the sentiment display info.
 */
function getSentimentDisplay( sentiment: string ): BadgeDisplay {
	const sentiments = window.aiCommentModerationData?.labels?.sentiment || {};
	const config = sentiments[ sentiment ] || sentiments.neutral;

	return {
		label: config.label,
		className: config.class,
		icon: config.icon,
	};
}

/**
 * Gets the value score label and class from score.
 */
function getValueScoreDisplay( score: number ): BadgeDisplay {
	return getScoreDisplay( score, 'value_score', {
		label: 'Low',
		className: 'ai-badge--low-value',
		icon: '👎',
	} );
}

/**
 * Returns the badges present for a comment, skipping any missing column.
 */
function presentBadges( comment: PendingComment ): HTMLElement[] {
	return [
		comment.sentimentBadge,
		comment.toxicityBadge,
		comment.valueScoreBadge,
	].filter( ( badge ): badge is HTMLElement => badge !== undefined );
}

/**
 * Applies a score badge's display info, including its percentage tooltip.
 */
function applyScoreBadge(
	badge: HTMLElement,
	display: BadgeDisplay,
	score: number
): void {
	badge.className = `ai-badge ${ display.className }`;
	badge.textContent = `${ display.icon } ${ display.label }`;
	badge.title = `${ display.label } (${ Math.round( score * 100 ) }%)`;
	badge.removeAttribute( 'data-ai-status' );
}

/**
 * Updates the badge elements with analysis results.
 */
function updateBadges( comment: PendingComment, result: AnalysisResult ): void {
	if ( comment.sentimentBadge ) {
		const sentimentDisplay = getSentimentDisplay( result.sentiment );

		comment.sentimentBadge.className = `ai-badge ${ sentimentDisplay.className }`;
		comment.sentimentBadge.textContent = `${ sentimentDisplay.icon } ${ sentimentDisplay.label }`;
		comment.sentimentBadge.title = sentimentDisplay.label;
		comment.sentimentBadge.removeAttribute( 'data-ai-status' );
	}

	if ( comment.toxicityBadge ) {
		applyScoreBadge(
			comment.toxicityBadge,
			getToxicityDisplay( result.toxicity_score ),
			result.toxicity_score
		);
	}

	if ( comment.valueScoreBadge ) {
		applyScoreBadge(
			comment.valueScoreBadge,
			getValueScoreDisplay( result.value_score ),
			result.value_score
		);
	}
}

/**
 * Marks a badge as failed.
 */
function markBadgeFailed( badge: HTMLElement ): void {
	badge.className = 'ai-badge ai-badge--failed';
	badge.textContent = __( 'Failed', 'ai' );
	badge.setAttribute( 'data-ai-status', 'failed' );
}

/**
 * Marks a badge as processing.
 */
function markBadgeProcessing( badge: HTMLElement ): void {
	badge.className = 'ai-badge ai-badge--processing';
	badge.textContent = __( 'Analyzing…', 'ai' );
	badge.setAttribute( 'data-ai-status', 'processing' );
}

/**
 * Bulk analysis notice query args that should not linger in the URL.
 */
const BULK_NOTICE_QUERY_ARGS = [
	'wpai_analysis_queued',
	'wpai_analysis_truncated',
];

/**
 * Removes the bulk analysis notice query args from the URL.
 */
function clearQueuedAnalysisQueryArg(): void {
	const url = new URL( window.location.href );

	if (
		! BULK_NOTICE_QUERY_ARGS.some( ( arg ) => url.searchParams.has( arg ) )
	) {
		return;
	}

	BULK_NOTICE_QUERY_ARGS.forEach( ( arg ) => url.searchParams.delete( arg ) );
	window.history.replaceState(
		null,
		'',
		`${ url.pathname }${ url.search }${ url.hash }`
	);
}

/**
 * LazyAnalysisController component.
 *
 * Handles lazy loading of comment analysis when comments are viewed.
 */
export function LazyAnalysisController(): React.ReactElement | null {
	const isAnalyzingRef = useRef( false );

	/**
	 * Finds all pending comments in the DOM.
	 */
	const findPendingComments = useCallback( (): PendingComment[] => {
		const pendingBadges = document.querySelectorAll< HTMLElement >(
			'[data-ai-status="pending"]'
		);

		const commentMap = new Map< number, Partial< PendingComment > >();

		pendingBadges.forEach( ( badge ) => {
			const commentId = parseInt(
				badge.dataset[ 'commentId' ] || '0', // eslint-disable-line dot-notation
				10
			);
			if ( ! commentId ) {
				return;
			}

			if ( ! commentMap.has( commentId ) ) {
				commentMap.set( commentId, { id: commentId } );
			}

			const entry = commentMap.get( commentId )!;

			// Determine which column this badge is in.
			const cell = badge.closest( 'td' );
			if ( cell?.classList.contains( 'column-wpai_sentiment' ) ) {
				entry.sentimentBadge = badge;
			} else if ( cell?.classList.contains( 'column-wpai_toxicity' ) ) {
				entry.toxicityBadge = badge;
			} else if (
				cell?.classList.contains( 'column-wpai_value_score' )
			) {
				entry.valueScoreBadge = badge;
			}
		} );

		// Keep any comment with at least one pending badge.
		return Array.from( commentMap.values() ).filter(
			( c ): c is PendingComment =>
				c.id !== undefined &&
				( c.sentimentBadge !== undefined ||
					c.toxicityBadge !== undefined ||
					c.valueScoreBadge !== undefined )
		);
	}, [] );

	/**
	 * Analyzes a single comment.
	 */
	const analyzeComment = useCallback(
		async ( comment: PendingComment ): Promise< void > => {
			// Mark as processing.
			presentBadges( comment ).forEach( markBadgeProcessing );

			try {
				const result = await runAbility< AnalysisResult >(
					'ai/comment-analysis',
					{
						comment_id: comment.id,
					}
				);

				updateBadges( comment, result );
			} catch ( error ) {
				// eslint-disable-next-line no-console
				console.error(
					`Failed to analyze comment ${ comment.id }:`,
					error
				);
				// Keep failed state visible but do not auto-retry in this lazy pass.
				presentBadges( comment ).forEach( markBadgeFailed );
			}
		},
		[]
	);

	/**
	 * Processes all pending comments sequentially.
	 */
	const processPendingComments = useCallback( async (): Promise< void > => {
		if ( isAnalyzingRef.current ) {
			return;
		}

		const pendingComments = findPendingComments();

		if ( pendingComments.length === 0 ) {
			clearQueuedAnalysisQueryArg();
			return;
		}

		isAnalyzingRef.current = true;

		try {
			// Process comments one at a time to avoid overwhelming the server.
			for ( const comment of pendingComments ) {
				await analyzeComment( comment );
			}
		} finally {
			isAnalyzingRef.current = false;
			clearQueuedAnalysisQueryArg();
		}
	}, [ findPendingComments, analyzeComment ] );

	/**
	 * Initialize analysis on mount.
	 */
	useEffect( () => {
		// Small delay to ensure DOM is fully rendered.
		const timeoutId = setTimeout( () => {
			processPendingComments();
		}, 500 );

		return () => clearTimeout( timeoutId );
		// Intentionally run once on mount. processPendingComments is stable.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	// This component doesn't render anything visible.
	return null;
}
