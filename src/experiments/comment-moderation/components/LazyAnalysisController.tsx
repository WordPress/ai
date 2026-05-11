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

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';

type AnalysisResult = {
	comment_id: number;
	toxicity_score: number;
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
			};
		};
	}
}

type PendingComment = {
	id: number;
	sentimentBadge: HTMLElement;
	toxicityBadge: HTMLElement;
};

/**
 * Gets the toxicity label and class from score.
 */
function getToxicityDisplay( score: number ): {
	label: string;
	className: string;
	icon: string;
} {
	const toxicities = window.aiCommentModerationData?.labels?.toxicity || {};

	for ( const config of Object.values( toxicities ) ) {
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

	return { label: 'Low', className: 'ai-badge--low-toxicity', icon: '✓' };
}

/**
 * Gets the sentiment display info.
 */
function getSentimentDisplay( sentiment: string ): {
	label: string;
	className: string;
	icon: string;
} {
	const sentiments = window.aiCommentModerationData?.labels?.sentiment || {};
	const config = sentiments[ sentiment ] || sentiments.neutral;

	return {
		label: config.label,
		className: config.class,
		icon: config.icon,
	};
}

/**
 * Updates the badge elements with analysis results.
 */
function updateBadges( comment: PendingComment, result: AnalysisResult ): void {
	const sentimentDisplay = getSentimentDisplay( result.sentiment );
	const toxicityDisplay = getToxicityDisplay( result.toxicity_score );

	// Update sentiment badge.
	comment.sentimentBadge.className = `ai-badge ${ sentimentDisplay.className }`;
	comment.sentimentBadge.textContent = `${ sentimentDisplay.icon } ${ sentimentDisplay.label }`;
	comment.sentimentBadge.title = sentimentDisplay.label;
	comment.sentimentBadge.removeAttribute( 'data-ai-status' );

	// Update toxicity badge.
	comment.toxicityBadge.className = `ai-badge ${ toxicityDisplay.className }`;
	comment.toxicityBadge.textContent = `${ toxicityDisplay.icon } ${ toxicityDisplay.label }`;
	comment.toxicityBadge.title = `${ toxicityDisplay.label } (${ Math.round(
		result.toxicity_score * 100
	) }%)`;
}

/**
 * Marks a badge as failed.
 */
function markBadgeFailed( badge: HTMLElement ): void {
	badge.className = 'ai-badge ai-badge--failed';
	badge.textContent = 'Failed';
	badge.setAttribute( 'data-ai-status', 'failed' );
}

/**
 * Marks a badge as processing.
 */
function markBadgeProcessing( badge: HTMLElement ): void {
	badge.className = 'ai-badge ai-badge--processing';
	badge.textContent = 'Analyzing…';
	badge.setAttribute( 'data-ai-status', 'processing' );
}

/**
 * Removes the queued-analysis query arg from the URL.
 */
function clearQueuedAnalysisQueryArg(): void {
	const url = new URL( window.location.href );

	if ( ! url.searchParams.has( 'wpai_analysis_queued' ) ) {
		return;
	}

	url.searchParams.delete( 'wpai_analysis_queued' );
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
			}
		} );

		// Only return comments that have both badges.
		return Array.from( commentMap.values() ).filter(
			( c ): c is PendingComment =>
				c.sentimentBadge !== undefined && c.toxicityBadge !== undefined
		);
	}, [] );

	/**
	 * Analyzes a single comment.
	 */
	const analyzeComment = useCallback(
		async ( comment: PendingComment ): Promise< void > => {
			// Mark as processing.
			markBadgeProcessing( comment.sentimentBadge );
			markBadgeProcessing( comment.toxicityBadge );

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
				markBadgeFailed( comment.sentimentBadge );
				markBadgeFailed( comment.toxicityBadge );
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
