/**
 * WordPress dependencies
 */
import type { View } from '@wordpress/dataviews';
import { useView } from '@wordpress/views';

/**
 * External dependencies
 */
import { useCallback, useEffect, useRef } from 'react';

interface PersistedViewReturn< T extends View > {
	view: T;
	setView: ( next: T | ( ( prev: T ) => T ) ) => void;
	resetView: () => void;
	isModified: boolean;
}

const VIEW_KIND = 'root';
const VIEW_NAME = 'ai-admin';

/**
 * Thin wrapper around `@wordpress/views` that mimics the previous
 * usePersistedView signature so existing DataViews components can persist
 * their view configuration using WordPress preferences (available in 6.9+).
 */
export function usePersistedView< T extends View >(
	slug: string,
	defaultView: T
): PersistedViewReturn< T > {
	const { view, updateView, resetToDefault, isModified } = useView( {
		kind: VIEW_KIND,
		name: VIEW_NAME,
		slug,
		defaultView,
	} );

	const latestViewRef = useRef< View >( view );
	useEffect( () => {
		latestViewRef.current = view;
	}, [ view ] );

	const setView = useCallback(
		( next: T | ( ( prev: T ) => T ) ) => {
			updateView(
				typeof next === 'function'
					? ( next as ( prev: T ) => T )( latestViewRef.current as T )
					: next
			);
		},
		[ updateView ]
	);

	const resetView = useCallback( () => {
		resetToDefault();
	}, [ resetToDefault ] );

	return {
		view: view as T,
		setView,
		resetView,
		isModified,
	};
}
