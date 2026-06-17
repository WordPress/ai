/**
 * WordPress dependencies
 */
import { FormTokenField, Spinner } from '@wordpress/components';
import { useCallback, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useAccessControlSettings } from '../hooks/use-access-control-settings';
import { useRolesUsers } from '../hooks/use-roles-users';

interface AccessControlSettingsProps {
	featureId: string;
}

export function AccessControlSettings( {
	featureId,
}: AccessControlSettingsProps ): React.JSX.Element {
	const { roles, users, isLoading, fetchError } = useRolesUsers();
	const { settings, update } = useAccessControlSettings( featureId );

	const roleSuggestions = useMemo(
		() => roles.map( ( r ) => r.name ),
		[ roles ]
	);

	const userSuggestions = useMemo(
		() => users.map( ( u ) => `${ u.name } (#${ u.id })` ),
		[ users ]
	);

	const roleMap = useMemo( () => {
		const map = new Map< string, string >();
		roles.forEach( ( r ) => map.set( r.name, r.id ) );
		return map;
	}, [ roles ] );

	const reverseRoleMap = useMemo( () => {
		const map = new Map< string, string >();
		roles.forEach( ( r ) => map.set( r.id, r.name ) );
		return map;
	}, [ roles ] );

	const userMap = useMemo( () => {
		const map = new Map< string, number >();
		users.forEach( ( u ) => map.set( `${ u.name } (#${ u.id })`, u.id ) );
		return map;
	}, [ users ] );

	const reverseUserMap = useMemo( () => {
		const map = new Map< number, string >();
		users.forEach( ( u ) => map.set( u.id, `${ u.name } (#${ u.id })` ) );
		return map;
	}, [ users ] );

	const selectedRolesTokens = useMemo( () => {
		return settings.roles.map( ( r ) => reverseRoleMap.get( r ) || r );
	}, [ settings.roles, reverseRoleMap ] );

	const selectedUsersTokens = useMemo( () => {
		return settings.users.map(
			( u ) => reverseUserMap.get( u ) || u.toString()
		);
	}, [ settings.users, reverseUserMap ] );

	const handleRolesChange = useCallback(
		( tokens: ( string | { value: string } )[] ) => {
			const newRoles: string[] = [];
			tokens.forEach( ( token ) => {
				const label = typeof token === 'string' ? token : token.value;
				const id = roleMap.get( label ) || label;
				newRoles.push( id );
			} );
			void update( { ...settings, roles: newRoles } );
		},
		[ update, settings, roleMap ]
	);

	const handleUsersChange = useCallback(
		( tokens: ( string | { value: string } )[] ) => {
			const newUsers: number[] = [];
			tokens.forEach( ( token ) => {
				const label = typeof token === 'string' ? token : token.value;
				const id = userMap.get( label );
				if ( id !== undefined ) {
					newUsers.push( id );
				}
			} );
			void update( { ...settings, users: newUsers } );
		},
		[ update, settings, userMap ]
	);

	return (
		<div
			className="ai-access-control-mode-fields ai-feature-settings-form"
			style={ { marginTop: '16px' } }
		>
			{ isLoading && <Spinner /> }
			{ ! isLoading && fetchError && (
				<p className="ai-access-control-mode-field__error">
					{ fetchError }
				</p>
			) }
			{ ! isLoading && ! fetchError && (
				<>
					<FormTokenField
						label={ __( 'Roles', 'ai' ) }
						value={ selectedRolesTokens }
						suggestions={ roleSuggestions }
						onChange={ handleRolesChange }
						__experimentalExpandOnFocus
					/>
					<FormTokenField
						label={ __( 'Users', 'ai' ) }
						value={ selectedUsersTokens }
						suggestions={ userSuggestions }
						onChange={ handleUsersChange }
						__experimentalExpandOnFocus
					/>
				</>
			) }
		</div>
	);
}
