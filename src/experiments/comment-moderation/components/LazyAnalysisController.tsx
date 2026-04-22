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
import { useEffect, useState, useCallback } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';

type AnalysisResult = {
	comment_id: number;
	toxicity_score: number;
	sentiment: 'positive' | 'negative' | 'neutral';
};

type SentimentDisplay = {
	label: string;
	className: string;
	icon: string;
};

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
	if ( score >= 0.7 ) {
		return {
			label: 'High',
			className: 'ai-badge--high-toxicity',
			icon: '⚠️',
		};
	}
	if ( score >= 0.4 ) {
		return {
			label: 'Medium',
			className: 'ai-badge--medium-toxicity',
			icon: '⚡',
		};
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
	const displays: Record< AnalysisResult[ 'sentiment' ], SentimentDisplay > = {
		positive: {
			label: 'Positive',
			className: 'ai-badge--positive',
			icon: '😊',
		},
		negative: {
			label: 'Negative',
			className: 'ai-badge--negative',
			icon: '😟',
		},
		neutral: {
			label: 'Neutral',
			className: 'ai-badge--neutral',
			icon: '😐',
		},
	};

	if (
		sentiment === 'positive' ||
		sentiment === 'negative' ||
		sentiment === 'neutral'
	) {
		return displays[ sentiment ];
	}

	return displays.neutral;
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
 * LazyAnalysisController component.
 *
 * Handles lazy loading of comment analysis when comments are viewed.
 */
export function LazyAnalysisController(): React.ReactElement | null {
	const [ isAnalyzing, setIsAnalyzing ] = useState( false );

	/**
	 * Finds all pending comments in the DOM.
	 */
	const findPendingComments = useCallback( (): PendingComment[] => {
		const pendingBadges = document.querySelectorAll< HTMLElement >(
			'[data-ai-status="pending"], [data-ai-status="failed"]'
		);

		const commentMap = new Map< number, Partial< PendingComment > >();

		pendingBadges.forEach( ( badge ) => {
			const commentId = parseInt( badge.dataset[ 'commentId' ] || '0', 10 );
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
				// TODO: if things fail, we end up in an infinite retry loop.
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
		if ( isAnalyzing ) {
			return;
		}

		const pendingComments = findPendingComments();

		if ( pendingComments.length === 0 ) {
			return;
		}

		setIsAnalyzing( true );

		// Process comments one at a time to avoid overwhelming the server.
		for ( const comment of pendingComments ) {
			await analyzeComment( comment );
		}

		setIsAnalyzing( false );
	}, [ isAnalyzing, findPendingComments, analyzeComment ] );

	/**
	 * Initialize analysis on mount.
	 */
	useEffect( () => {
		// Small delay to ensure DOM is fully rendered.
		const timeoutId = setTimeout( () => {
			processPendingComments();
		}, 500 );

		return () => clearTimeout( timeoutId );
	}, [ processPendingComments ] );

	// This component doesn't render anything visible.
	return null;
}
