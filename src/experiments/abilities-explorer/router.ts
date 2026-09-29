/**
 * View routing on the screen's existing query arguments.
 *
 * `action=view&ability=<name>` is the detail view and `action=test&ability=<name>`
 * the test runner; anything else is the list. In-app navigation writes the URL
 * with `pushState`, and browser back and forward restore the view on `popstate`.
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
 * An `ability` argument that is missing or empty is kept as `''`, which the
 * app shows as "No ability specified.".
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
	/**
	 * Increments on every in-app navigation and history move, but not on the
	 * first render, so the app moves focus to the heading only after navigation.
	 */
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
