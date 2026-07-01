/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

export interface Role {
	id: string;
	name: string;
}

export interface User {
	id: number;
	name: string;
}

interface RolesUsersResponse {
	roles: Role[];
	users: User[];
}

interface UseRolesReturn {
	roles: Role[];
	isLoading: boolean;
	fetchError: string | null;
}

interface UseUserSearchReturn {
	suggestions: User[];
	isSearching: boolean;
	search: ( query: string ) => void;
}

const DEBOUNCE_MS = 300;

/**
 * Fetches the complete list of roles once on mount.
 *
 * @return {UseRolesReturn} The roles and loading state.
 */
export function useRoles(): UseRolesReturn {
	const [ roles, setRoles ] = useState< Role[] >( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ fetchError, setFetchError ] = useState< string | null >( null );

	useEffect( () => {
		let isMounted = true;

		apiFetch< RolesUsersResponse >( { path: '/ai/v1/roles-users' } )
			.then( ( data ) => {
				if ( isMounted ) {
					setRoles( data.roles || [] );
					setIsLoading( false );
				}
			} )
			.catch( ( error: unknown ) => {
				if ( isMounted ) {
					setFetchError(
						error instanceof Error
							? error.message
							: 'Failed to fetch roles'
					);
					setIsLoading( false );
				}
			} );

		return () => {
			isMounted = false;
		};
	}, [] );

	return { roles, isLoading, fetchError };
}

/**
 * Provides debounced async user search against the REST endpoint.
 * Loads an initial set of users on mount and updates suggestions as the user types.
 *
 * @return {UseUserSearchReturn} The suggestions list, loading flag, and search trigger.
 */
export function useUserSearch(): UseUserSearchReturn {
	const [ suggestions, setSuggestions ] = useState< User[] >( [] );
	const [ isSearching, setIsSearching ] = useState( false );
	const debounceTimer = useRef< ReturnType< typeof setTimeout > | null >(
		null
	);
	const isMountedRef = useRef( true );

	const fetchUsers = useCallback( ( query: string ) => {
		setIsSearching( true );
		const path = query
			? `/ai/v1/roles-users?search=${ encodeURIComponent( query ) }`
			: '/ai/v1/roles-users';

		apiFetch< RolesUsersResponse >( { path } )
			.then( ( data ) => {
				if ( isMountedRef.current ) {
					setSuggestions( data.users || [] );
					setIsSearching( false );
				}
			} )
			.catch( () => {
				if ( isMountedRef.current ) {
					setIsSearching( false );
				}
			} );
	}, [] );

	useEffect( () => {
		isMountedRef.current = true;
		fetchUsers( '' );
		return () => {
			isMountedRef.current = false;
			if ( debounceTimer.current ) {
				clearTimeout( debounceTimer.current );
			}
		};
	}, [ fetchUsers ] );

	const search = useCallback(
		( query: string ) => {
			if ( debounceTimer.current ) {
				clearTimeout( debounceTimer.current );
			}
			debounceTimer.current = setTimeout( () => {
				fetchUsers( query );
			}, DEBOUNCE_MS );
		},
		[ fetchUsers ]
	);

	return { suggestions, isSearching, search };
}
