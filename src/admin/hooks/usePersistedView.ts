import type { View } from '@wordpress/dataviews';
import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Hook for managing DataViews view state with localStorage persistence.
 *
 * @param key         Unique storage key for this view.
 * @param defaultView Default view configuration.
 * @returns Object with view state, setView function, and reset function.
 */
export function usePersistedView< T extends View >(
	key: string,
	defaultView: T
): {
	view: T;
	setView: ( newView: T | ( ( prev: T ) => T ) ) => void;
	resetView: () => void;
	isModified: boolean;
} {
	const storageKey = `ai-admin-view-${ key }`;

	const loadStoredView = (): T => {
		try {
			const stored = localStorage.getItem( storageKey );
			if ( stored ) {
				const parsed = JSON.parse( stored ) as Partial< T >;
				// Merge with defaults to handle schema changes
				return { ...defaultView, ...parsed };
			}
		} catch {
			// Invalid JSON or storage error - use default
		}
		return defaultView;
	};

	const [ view, setViewState ] = useState< T >( loadStoredView );

	const setView = useCallback(
		( newView: T | ( ( prev: T ) => T ) ) => {
			setViewState( ( prev ) => {
				const next = typeof newView === 'function' ? newView( prev ) : newView;
				try {
					// Only persist layout-related settings, not transient state like page
					const toPersist: Partial< T > = {
						type: next.type,
						perPage: next.perPage,
						fields: next.fields,
						layout: next.layout,
						sort: next.sort,
						filters: next.filters,
						search: next.search,
					} as Partial< T >;
					localStorage.setItem( storageKey, JSON.stringify( toPersist ) );
				} catch {
					// Storage full or unavailable - continue without persistence
				}
				return next;
			} );
		},
		[ storageKey ]
	);

	const resetView = useCallback( () => {
		try {
			localStorage.removeItem( storageKey );
		} catch {
			// Ignore storage errors
		}
		setViewState( defaultView );
	}, [ storageKey, defaultView ] );

	// Check if view differs from default (for "modified" indicator)
	const isModified =
		view.type !== defaultView.type ||
		view.perPage !== defaultView.perPage ||
		JSON.stringify( view.fields ) !== JSON.stringify( defaultView.fields ) ||
		JSON.stringify( view.sort ) !== JSON.stringify( defaultView.sort ) ||
		( view.filters && view.filters.length > 0 ) ||
		( view.search && view.search.length > 0 );

	return { view, setView, resetView, isModified };
}

/**
 * Hook for persisting simple state objects to localStorage.
 *
 * @param key          Unique storage key.
 * @param defaultValue Default value.
 * @returns Tuple of [value, setValue, resetValue].
 */
export function usePersistedState< T >(
	key: string,
	defaultValue: T
): [ T, ( newValue: T | ( ( prev: T ) => T ) ) => void, () => void ] {
	const storageKey = `ai-admin-state-${ key }`;

	const loadStoredValue = (): T => {
		try {
			const stored = localStorage.getItem( storageKey );
			if ( stored ) {
				return JSON.parse( stored ) as T;
			}
		} catch {
			// Invalid JSON or storage error
		}
		return defaultValue;
	};

	const [ value, setValueState ] = useState< T >( loadStoredValue );
	const isInitialMount = useRef( true );

	// Save to localStorage on change (skip initial mount)
	useEffect( () => {
		if ( isInitialMount.current ) {
			isInitialMount.current = false;
			return;
		}
		try {
			localStorage.setItem( storageKey, JSON.stringify( value ) );
		} catch {
			// Storage full or unavailable
		}
	}, [ value, storageKey ] );

	const setValue = useCallback( ( newValue: T | ( ( prev: T ) => T ) ) => {
		setValueState( newValue );
	}, [] );

	const resetValue = useCallback( () => {
		try {
			localStorage.removeItem( storageKey );
		} catch {
			// Ignore
		}
		setValueState( defaultValue );
	}, [ storageKey, defaultValue ] );

	return [ value, setValue, resetValue ];
}
