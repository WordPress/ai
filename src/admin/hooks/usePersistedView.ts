/**
 * WordPress dependencies
 */
import type { View } from '@wordpress/dataviews';
import { useView } from '@wordpress/views';

/**
 * External dependencies
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

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
 * @param slug
 * @param defaultView
 */
export function usePersistedView< T extends View >(
	slug: string,
	defaultView: T
): PersistedViewReturn< T > {
	const defaultQuery = useMemo(
		() => ( {
			page: defaultView.page ?? 1,
			search: defaultView.search ?? '',
		} ),
		[ defaultView.page, defaultView.search ]
	);

	const [ queryParams, setQueryParams ] = useState( defaultQuery );

	// Keep query params in sync if defaults change (should be rare).
	useEffect( () => {
		setQueryParams( ( previous ) => ( {
			page: previous.page ?? defaultQuery.page,
			search:
				typeof previous.search === 'string'
					? previous.search
					: defaultQuery.search,
		} ) );
	}, [ defaultQuery.page, defaultQuery.search ] );

	const { view, updateView, resetToDefault, isModified } = useView( {
		kind: VIEW_KIND,
		name: VIEW_NAME,
		slug,
		defaultView,
		queryParams,
		onChangeQueryParams: ( params ) => {
			setQueryParams( {
				page:
					typeof params.page === 'number'
						? params.page
						: defaultQuery.page,
				search:
					typeof params.search === 'string'
						? params.search
						: defaultQuery.search,
			} );
		},
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
		setQueryParams( defaultQuery );
	}, [ resetToDefault, defaultQuery ] );

	return {
		view: view as T,
		setView,
		resetView,
		isModified,
	};
}
