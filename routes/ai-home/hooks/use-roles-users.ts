/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import {
	createContext,
	createElement,
	useCallback,
	useContext,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';

/**
 * Internal dependencies
 */
import { useAccessControlModeContext } from './use-access-control-mode';

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

export interface UseRolesReturn {
	roles: Role[];
	isLoading: boolean;
	fetchError: string | null;
}

export interface UseUserSearchReturn {
	suggestions: User[];
	isSearching: boolean;
	search: ( query: string ) => void;
}

export interface RolesUsersContextValue
	extends UseRolesReturn,
		UseUserSearchReturn {}

const DEFAULT_CONTEXT_VALUE: RolesUsersContextValue = {
	roles: [],
	isLoading: false,
	fetchError: null,
	suggestions: [],
	isSearching: false,
	search: () => {},
};

export const RolesUsersContext = createContext< RolesUsersContextValue | null >(
	null
);

/**
 * Provider component that fetches roles and users once at the top level
 * when access control mode is active and shares the state with child components.
 *
 * @param {Object}          props          Component props.
 * @param {React.ReactNode} props.children Child elements.
 * @return {React.JSX.Element} The Provider component.
 */
export function RolesUsersProvider( {
	children,
}: {
	children: React.ReactNode;
} ): React.JSX.Element {
	const isAccessControlMode = useAccessControlModeContext();
	const rolesData = useRoles( isAccessControlMode );
	const userSearchData = useUserSearch( isAccessControlMode );

	const value = useMemo(
		() => ( {
			...rolesData,
			...userSearchData,
		} ),
		[ rolesData, userSearchData ]
	);

	return createElement( RolesUsersContext.Provider, { value }, children );
}

/**
 * Access the shared roles and user search context.
 *
 * @return {RolesUsersContextValue} The shared roles and user search data.
 */
export function useRolesUsersContext(): RolesUsersContextValue {
	const context = useContext( RolesUsersContext );
	return context ?? DEFAULT_CONTEXT_VALUE;
}

const DEBOUNCE_MS = 300;

/**
 * Fetches the complete list of roles once when enabled.
 *
 * @param {boolean} enabled Whether to fetch roles.
 * @return {UseRolesReturn} The roles and loading state.
 */
export function useRoles( enabled = true ): UseRolesReturn {
	const [ roles, setRoles ] = useState< Role[] >( [] );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ fetchError, setFetchError ] = useState< string | null >( null );

	useEffect( () => {
		if ( ! enabled ) {
			return;
		}

		let isMounted = true;
		setIsLoading( true );

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
	}, [ enabled ] );

	return { roles, isLoading, fetchError };
}

/**
 * Provides debounced async user search against the REST endpoint.
 * Loads an initial set of users when enabled and updates suggestions as the user types.
 *
 * @param {boolean} enabled Whether user search is enabled.
 * @return {UseUserSearchReturn} The suggestions list, loading flag, and search trigger.
 */
export function useUserSearch( enabled = true ): UseUserSearchReturn {
	const [ suggestions, setSuggestions ] = useState< User[] >( [] );
	const [ isSearching, setIsSearching ] = useState( false );
	const debounceTimer = useRef< ReturnType< typeof setTimeout > | null >(
		null
	);
	const isMountedRef = useRef( true );

	const fetchUsers = useCallback(
		( query: string ) => {
			if ( ! enabled ) {
				return;
			}
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
		},
		[ enabled ]
	);

	useEffect( () => {
		if ( ! enabled ) {
			return;
		}
		isMountedRef.current = true;
		fetchUsers( '' );
		return () => {
			isMountedRef.current = false;
			if ( debounceTimer.current ) {
				clearTimeout( debounceTimer.current );
			}
		};
	}, [ enabled, fetchUsers ] );

	const search = useCallback(
		( query: string ) => {
			if ( ! enabled ) {
				return;
			}
			if ( debounceTimer.current ) {
				clearTimeout( debounceTimer.current );
			}
			debounceTimer.current = setTimeout( () => {
				fetchUsers( query );
			}, DEBOUNCE_MS );
		},
		[ enabled, fetchUsers ]
	);

	return { suggestions, isSearching, search };
}
