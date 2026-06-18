/**
 * WordPress dependencies
 */
import { Button, FormTokenField, Spinner } from '@wordpress/components';
import { useCallback, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Stack } from '@wordpress/ui';

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
	const { settings, stage, save, isDirty, isSaving } =
		useAccessControlSettings( featureId );

	const [ localRoles, setLocalRoles ] = useState< string[] | null >( null );
	const [ localUsers, setLocalUsers ] = useState< number[] | null >( null );

	const effectiveRoles = localRoles ?? settings.roles;
	const effectiveUsers = localUsers ?? settings.users;

	const roleSuggestions = useMemo(
		() => roles.map( ( r ) => r.name ),
		[ roles ]
	);

	const userSuggestions = useMemo(
		() => users.map( ( u ) => u.name ),
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
		users.forEach( ( u ) => map.set( u.name, u.id ) );
		return map;
	}, [ users ] );

	const reverseUserMap = useMemo( () => {
		const map = new Map< number, string >();
		users.forEach( ( u ) => map.set( u.id, u.name ) );
		return map;
	}, [ users ] );

	const selectedRolesTokens = useMemo(
		() => effectiveRoles.map( ( r ) => reverseRoleMap.get( r ) || r ),
		[ effectiveRoles, reverseRoleMap ]
	);

	const selectedUsersTokens = useMemo(
		() =>
			effectiveUsers.map(
				( u ) => reverseUserMap.get( u ) || u.toString()
			),
		[ effectiveUsers, reverseUserMap ]
	);

	const handleRolesChange = useCallback(
		( tokens: ( string | { value: string } )[] ) => {
			const newRoles: string[] = [];
			tokens.forEach( ( token ) => {
				const label = typeof token === 'string' ? token : token.value;
				const id = roleMap.get( label ) || label;
				newRoles.push( id );
			} );
			setLocalRoles( newRoles );
			stage( { roles: newRoles, users: effectiveUsers } );
		},
		[ stage, effectiveUsers, roleMap ]
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
			setLocalUsers( newUsers );
			stage( { roles: effectiveRoles, users: newUsers } );
		},
		[ stage, effectiveRoles, userMap ]
	);

	const handleSave = useCallback( async () => {
		await save();
		setLocalRoles( null );
		setLocalUsers( null );
	}, [ save ] );

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
					{ isDirty && (
						<Stack align="flex-end" direction="row">
							<Button
								variant="primary"
								onClick={ handleSave }
								disabled={ isSaving }
								size="compact"
								isBusy={ isSaving }
								accessibleWhenDisabled
							>
								{ isSaving ? <Spinner /> : __( 'Save', 'ai' ) }
							</Button>
						</Stack>
					) }
				</>
			) }
		</div>
	);
}
