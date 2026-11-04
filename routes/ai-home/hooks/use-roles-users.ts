/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
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
import { addQueryArgs } from '@wordpress/url';

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

export interface RolesUsersContextValue {
	roles: Role[];
	defaultRoles: string[];
	suggestions: User[];
	isSearching: boolean;
	search: ( query: string ) => void;
}

const DEFAULT_CONTEXT_VALUE: RolesUsersContextValue = {
	roles: [],
	defaultRoles: [],
	suggestions: [],
	isSearching: false,
	search: () => {},
};

const RolesUsersContext = createContext< RolesUsersContextValue >(
	DEFAULT_CONTEXT_VALUE
);

const DEBOUNCE_MS = 300;
const SEARCH_PER_PAGE = 10;
const USERS_OPTION_PATTERN = /^wpai_feature_.+_users$/;

function fetchUsers( query: Record< string, string | number > ) {
	return apiFetch< Array< { id: number; name: string } > >( {
		path: addQueryArgs( '/wp/v2/users', {
			context: 'view',
			_fields: 'id,name',
			...query,
		} ),
	} ).then( ( users ) =>
		users.map( ( user ) => ( { id: user.id, name: user.name } ) )
	);
}

/**
 * Shares the role list and a debounced user search with access control fields.
 *
 * Roles come from page data. Users come from core's /wp/v2/users endpoint:
 * saved user IDs are resolved once for their display names, and the search
 * results are merged after them.
 *
 * @param {Object}          props              Component props.
 * @param {Role[]}          props.roles        Roles offered as checkboxes.
 * @param {string[]}        props.defaultRoles Roles pre-checked while roles are unsaved.
 * @param {React.ReactNode} props.children     Child elements.
 * @return {React.JSX.Element} The provider.
 */
export function RolesUsersProvider( {
	roles,
	defaultRoles,
	children,
}: {
	roles: Role[];
	defaultRoles: string[];
	children: React.ReactNode;
} ): React.JSX.Element {
	const isEnabled = useAccessControlModeContext();

	// A string key keeps the selector's return value referentially stable.
	const savedIdsKey = useSelect( ( select ) => {
		const record = ( select( coreStore ) as any ).getEntityRecord(
			'root',
			'site'
		) as Record< string, unknown > | undefined;
		const ids = new Set< number >();
		Object.entries( record ?? {} ).forEach( ( [ key, value ] ) => {
			if ( USERS_OPTION_PATTERN.test( key ) && Array.isArray( value ) ) {
				value.forEach( ( id ) => {
					const num = Number( id );
					if ( Number.isInteger( num ) && num > 0 ) {
						ids.add( num );
					}
				} );
			}
		} );
		return Array.from( ids )
			.sort( ( a, b ) => a - b )
			.join( ',' );
	}, [] );

	const [ savedUsers, setSavedUsers ] = useState< User[] >( [] );
	const [ searchResults, setSearchResults ] = useState< User[] >( [] );
	const [ isSearching, setIsSearching ] = useState( false );
	const debounceTimer = useRef< ReturnType< typeof setTimeout > | null >(
		null
	);
	const latestRequest = useRef( 0 );

	useEffect( () => {
		if ( ! isEnabled || ! savedIdsKey ) {
			return;
		}
		let isCurrent = true;
		fetchUsers( { include: savedIdsKey, per_page: 100 } )
			.then( ( users ) => {
				if ( isCurrent ) {
					setSavedUsers( users );
				}
			} )
			.catch( () => {} );
		return () => {
			isCurrent = false;
		};
	}, [ isEnabled, savedIdsKey ] );

	const runSearch = useCallback( ( query: string ) => {
		const requestId = ++latestRequest.current;
		setIsSearching( true );
		const params: Record< string, string | number > = {
			per_page: SEARCH_PER_PAGE,
			...( query ? { search: query } : {} ),
		};
		fetchUsers( params )
			.then( ( users ) => {
				if ( requestId === latestRequest.current ) {
					setSearchResults( users );
				}
			} )
			.catch( () => {} )
			.finally( () => {
				if ( requestId === latestRequest.current ) {
					setIsSearching( false );
				}
			} );
	}, [] );

	useEffect( () => {
		if ( ! isEnabled ) {
			return;
		}
		runSearch( '' );
		return () => {
			if ( debounceTimer.current ) {
				clearTimeout( debounceTimer.current );
			}
		};
	}, [ isEnabled, runSearch ] );

	const search = useCallback(
		( query: string ) => {
			if ( ! isEnabled ) {
				return;
			}
			if ( debounceTimer.current ) {
				clearTimeout( debounceTimer.current );
			}
			debounceTimer.current = setTimeout(
				() => runSearch( query ),
				DEBOUNCE_MS
			);
		},
		[ isEnabled, runSearch ]
	);

	const value = useMemo( () => {
		const seen = new Set< number >();
		const suggestions = [ ...savedUsers, ...searchResults ].filter(
			( user ) => {
				if ( seen.has( user.id ) ) {
					return false;
				}
				seen.add( user.id );
				return true;
			}
		);
		return { roles, defaultRoles, suggestions, isSearching, search };
	}, [
		roles,
		defaultRoles,
		savedUsers,
		searchResults,
		isSearching,
		search,
	] );

	return createElement( RolesUsersContext.Provider, { value }, children );
}

/**
 * Returns the shared roles and user search state.
 *
 * @return {RolesUsersContextValue} Roles, user suggestions and search trigger.
 */
export function useRolesUsersContext(): RolesUsersContextValue {
	return useContext( RolesUsersContext );
}
