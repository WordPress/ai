/**
 * View routing on the `action` and `ability` query arguments.
 */

/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { addQueryArgs, getQueryArg } from '@wordpress/url';

/**
 * Internal dependencies
 */
import { getSettings } from './settings';

export type Route =
	| { view: 'list' }
	| { view: 'detail'; ability: string }
	| { view: 'runner'; ability: string };

export const LIST_ROUTE: Route = { view: 'list' };

/**
 * Reads the route from a URL.
 *
 * @param url The URL to read.
 * @return The route.
 */
export function parseRoute( url: string ): Route {
	const action = getQueryArg( url, 'action' );
	const rawAbility = getQueryArg( url, 'ability' );
	const ability = typeof rawAbility === 'string' ? rawAbility : '';

	if ( 'view' === action ) {
		return { view: 'detail', ability };
	}

	if ( 'test' === action ) {
		return { view: 'runner', ability };
	}

	return LIST_ROUTE;
}

/**
 * Builds the URL for a route on the current admin page.
 *
 * @param route The route.
 * @return The URL, relative to the site root.
 */
export function routeToUrl( route: Route ): string {
	const base = addQueryArgs( window.location.pathname, {
		page: getSettings().pageSlug,
	} );

	if ( 'list' === route.view ) {
		return base;
	}

	return addQueryArgs( base, {
		action: 'detail' === route.view ? 'view' : 'test',
		ability: route.ability,
	} );
}

export interface RouterState {
	route: Route;
	navigate: ( route: Route ) => void;
	/** Zero until the first navigation, so focus moves only after navigating. */
	navigationCount: number;
}

/**
 * Tracks the current route, pushing in-app navigation and restoring on `popstate`.
 *
 * @return The router state.
 */
export function useRouter(): RouterState {
	const [ route, setRoute ] = useState< Route >( () =>
		parseRoute( window.location.href )
	);
	const [ navigationCount, setNavigationCount ] = useState( 0 );

	useEffect( () => {
		const onPopState = () => {
			setRoute( parseRoute( window.location.href ) );
			setNavigationCount( ( count ) => count + 1 );
		};

		window.addEventListener( 'popstate', onPopState );

		return () => window.removeEventListener( 'popstate', onPopState );
	}, [] );

	const navigate = useCallback( ( next: Route ) => {
		const url = routeToUrl( next );
		const current = window.location.pathname + window.location.search;

		if ( url !== current ) {
			window.history.pushState( null, '', url );
		}

		setRoute( next );
		setNavigationCount( ( count ) => count + 1 );
	}, [] );

	return { route, navigate, navigationCount };
}
