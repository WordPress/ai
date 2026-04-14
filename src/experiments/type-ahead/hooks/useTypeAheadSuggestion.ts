/**
 * Hooks for type-ahead suggestion.
 */

/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { REQUEST_TIMEOUT_MS } from '../constants';
import type {
	CaretData,
	Suggestion,
	TypeAheadAbilityInput,
	TypeAheadResponse,
	TypeAheadSettings,
} from '../types';
import {
	addLeadingSpaceIfNeeded,
	shouldTriggerFromContext,
} from '../utils/text';
import { runAbility } from '../../../utils/run-ability';

type UseTypeAheadSuggestionArgs = {
	shouldRequest: boolean;
	caret: CaretData | null;
	editable: HTMLElement | null;
	plainContent: string;
	followingText: string;
	siblingContext: string;
	postId: number;
	clientId: string;
	settings: TypeAheadSettings;
};

type UseTypeAheadSuggestionResult = {
	suggestion: Suggestion | null;
	setSuggestion: ( next: Suggestion | null ) => void;
	cancelPendingRequest: () => void;
	triggerManualFetch: () => void;
};

/**
 * Handles request scheduling and fetching for block type-ahead suggestions.
 *
 * @param args Type-ahead request context.
 * @return Suggestion state and control handlers.
 */
export const useTypeAheadSuggestion = (
	args: UseTypeAheadSuggestionArgs
): UseTypeAheadSuggestionResult => {
	const {
		shouldRequest,
		caret,
		editable,
		plainContent,
		followingText,
		siblingContext,
		postId,
		clientId,
		settings,
	} = args;
	const [ suggestion, setSuggestion ] = useState< Suggestion | null >( null );
	const [ requestNonce, setRequestNonce ] = useState( 0 );
	const requestRef = useRef( 0 );
	const abortControllerRef = useRef< AbortController | null >( null );
	const debounceTimerRef = useRef< number | null >( null );
	const requestTimeoutRef = useRef< number | null >( null );

	const stateRef = useRef( {
		caret,
		plainContent,
		followingText,
		siblingContext,
		postId,
		clientId,
		completionMode: settings.completionMode,
		confidence: settings.confidence,
		maxWords: settings.maxWords,
	} );

	useEffect( () => {
		stateRef.current = {
			caret,
			plainContent,
			followingText,
			siblingContext,
			postId,
			clientId,
			completionMode: settings.completionMode,
			confidence: settings.confidence,
			maxWords: settings.maxWords,
		};
	}, [
		caret,
		plainContent,
		followingText,
		siblingContext,
		postId,
		clientId,
		settings.completionMode,
		settings.confidence,
		settings.maxWords,
	] );

	const clearRequestTimeout = useCallback( () => {
		if ( requestTimeoutRef.current !== null ) {
			window.clearTimeout( requestTimeoutRef.current );
			requestTimeoutRef.current = null;
		}
	}, [] );

	const cancelPendingRequest = useCallback( () => {
		if ( debounceTimerRef.current !== null ) {
			window.clearTimeout( debounceTimerRef.current );
			debounceTimerRef.current = null;
		}

		if ( abortControllerRef.current ) {
			abortControllerRef.current.abort();
			abortControllerRef.current = null;
		}

		clearRequestTimeout();
	}, [ clearRequestTimeout ] );

	const fetchSuggestion = useCallback(
		async ( manual: boolean ) => {
			const state = stateRef.current;

			if ( ! state.caret ) {
				return;
			}

			if ( abortControllerRef.current ) {
				abortControllerRef.current.abort();
			}
			clearRequestTimeout();

			const controller = new AbortController();
			abortControllerRef.current = controller;
			const currentRequest = ++requestRef.current;
			requestTimeoutRef.current = window.setTimeout( () => {
				controller.abort();
			}, REQUEST_TIMEOUT_MS );

			const input: TypeAheadAbilityInput = {
				post_id: state.postId,
				block_content: state.plainContent,
				preceding_text: state.caret.precedingText,
				following_text: state.followingText,
				surrounding_context: state.siblingContext,
				cursor_position: state.caret.offset,
				mode: state.completionMode,
				max_words: state.maxWords,
				manual_trigger: manual,
			};

			try {
				const response = await runAbility< TypeAheadResponse >(
					'ai/type-ahead',
					input,
					{ signal: controller.signal }
				);

				if ( currentRequest !== requestRef.current ) {
					return;
				}

				if ( ! response?.suggestion ) {
					setSuggestion( null );
					return;
				}

				if (
					typeof response.confidence === 'number' &&
					response.confidence < state.confidence
				) {
					setSuggestion( null );
					return;
				}

				const precedingText =
					stateRef.current.caret?.precedingText ?? '';
				const normalizedText = addLeadingSpaceIfNeeded(
					String( response.suggestion ),
					precedingText
				);

				setSuggestion( {
					text: normalizedText,
					confidence: Number( response.confidence || 0 ),
				} );
			} catch ( error: unknown ) {
				if (
					error instanceof DOMException &&
					error.name === 'AbortError'
				) {
					return;
				}

				// eslint-disable-next-line no-console
				console.error( '[AI Type Ahead] Request failed', error );
				setSuggestion( null );
			} finally {
				if ( abortControllerRef.current === controller ) {
					abortControllerRef.current = null;
				}
				clearRequestTimeout();
			}
		},
		[ clearRequestTimeout ]
	);

	const scheduleFetch = useCallback(
		( manual: boolean ) => {
			if ( debounceTimerRef.current !== null ) {
				window.clearTimeout( debounceTimerRef.current );
			}

			if ( manual ) {
				fetchSuggestion( true );
				return;
			}

			const delay = Math.max( 200, settings.triggerDelay || 500 );
			debounceTimerRef.current = window.setTimeout( () => {
				debounceTimerRef.current = null;

				const state = stateRef.current;
				const contextTriggered = state.caret
					? shouldTriggerFromContext( state.caret.precedingText )
					: false;

				if ( state.completionMode === 'word' && ! contextTriggered ) {
					return;
				}

				fetchSuggestion( false );
			}, delay );
		},
		[ fetchSuggestion, settings.triggerDelay ]
	);

	useEffect( () => {
		if ( ! shouldRequest || ! caret || ! editable ) {
			cancelPendingRequest();
			setSuggestion( null );
			return;
		}

		scheduleFetch( false );
		return cancelPendingRequest;
	}, [
		shouldRequest,
		caret?.offset,
		plainContent,
		requestNonce,
		cancelPendingRequest,
		scheduleFetch,
		caret,
		editable,
	] );

	const triggerManualFetch = useCallback( () => {
		setRequestNonce( ( prev ) => prev + 1 );
		scheduleFetch( true );
	}, [ scheduleFetch ] );

	return {
		suggestion,
		setSuggestion,
		cancelPendingRequest,
		triggerManualFetch,
	};
};
