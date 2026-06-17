/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';

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

interface UseRolesUsersReturn {
	roles: Role[];
	users: User[];
	isLoading: boolean;
	fetchError: string | null;
}

/**
 * Fetches roles and users from the REST API.
 *
 * @return {UseRolesUsersReturn} The roles, users, and loading state.
 */
export function useRolesUsers(): UseRolesUsersReturn {
	const [ roles, setRoles ] = useState< Role[] >( [] );
	const [ users, setUsers ] = useState< User[] >( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ fetchError, setFetchError ] = useState< string | null >( null );

	useEffect( () => {
		let isMounted = true;

		apiFetch< RolesUsersResponse >( { path: '/ai/v1/roles-users' } )
			.then( ( data ) => {
				if ( isMounted ) {
					setRoles( data.roles || [] );
					setUsers( data.users || [] );
					setIsLoading( false );
				}
			} )
			.catch( ( error: unknown ) => {
				if ( isMounted ) {
					setFetchError(
						error instanceof Error
							? error.message
							: 'Failed to fetch roles and users'
					);
					setIsLoading( false );
				}
			} );

		return () => {
			isMounted = false;
		};
	}, [] );

	return { roles, users, isLoading, fetchError };
}
