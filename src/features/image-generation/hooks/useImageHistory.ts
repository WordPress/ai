/**
 * Custom hook for managing image generation history.
 */

/**
 * WordPress dependencies
 */
import { useReducer, useCallback } from '@wordpress/element';

/**
 * Internal dependencies
 */
import type { GeneratedImageData, HistoryEntry } from '../types';

interface UseImageHistoryReturn {
	history: HistoryEntry[];
	historyIndex: number;
	activeEntry: HistoryEntry | null;
	canGoBack: boolean;
	canGoForward: boolean;
	addToHistory: (
		data: GeneratedImageData,
		referenceSrc?: string,
		isRefinement?: boolean,
		referenceHistoryIndex?: number
	) => void;
	goBack: () => void;
	goForward: () => void;
	resetHistory: () => void;
}

type HistoryState = {
	history: HistoryEntry[];
	historyIndex: number;
};

type HistoryAction =
	| { type: 'ADD'; entry: HistoryEntry }
	| { type: 'GO_BACK' }
	| { type: 'GO_FORWARD' }
	| { type: 'RESET' };

function historyReducer(
	state: HistoryState,
	action: HistoryAction
): HistoryState {
	switch ( action.type ) {
		case 'ADD':
			return {
				history: [ ...state.history, action.entry ],
				historyIndex: state.history.length,
			};
		case 'GO_BACK':
			return {
				...state,
				historyIndex: Math.max( 0, state.historyIndex - 1 ),
			};
		case 'GO_FORWARD':
			return {
				...state,
				historyIndex: Math.min(
					state.history.length - 1,
					state.historyIndex + 1
				),
			};
		case 'RESET':
			return { history: [], historyIndex: -1 };
	}
}

/**
 * Manages image generation history with navigation support.
 *
 * @return {UseImageHistoryReturn} History state and navigation helpers.
 */
export function useImageHistory(): UseImageHistoryReturn {
	const [ { history, historyIndex }, dispatch ] = useReducer(
		historyReducer,
		{ history: [], historyIndex: -1 }
	);

	const activeEntry =
		historyIndex >= 0 ? history[ historyIndex ] ?? null : null;
	const canGoBack = historyIndex > 0;
	const canGoForward = historyIndex < history.length - 1;

	const addToHistory = useCallback(
		(
			data: GeneratedImageData,
			referenceSrc?: string,
			isRefinement: boolean = false,
			referenceHistoryIndex?: number
		) => {
			const entry: HistoryEntry = { generatedData: data, isRefinement };
			if ( referenceSrc !== undefined ) {
				entry.referenceSrc = referenceSrc;
			}
			if ( referenceHistoryIndex !== undefined ) {
				entry.referenceHistoryIndex = referenceHistoryIndex;
			}
			dispatch( { type: 'ADD', entry } );
		},
		[]
	);

	const goBack = useCallback( () => dispatch( { type: 'GO_BACK' } ), [] );
	const goForward = useCallback(
		() => dispatch( { type: 'GO_FORWARD' } ),
		[]
	);
	const resetHistory = useCallback( () => dispatch( { type: 'RESET' } ), [] );

	return {
		history,
		historyIndex,
		activeEntry,
		canGoBack,
		canGoForward,
		addToHistory,
		goBack,
		goForward,
		resetHistory,
	};
}
