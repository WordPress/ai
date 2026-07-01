/**
 * WordPress dependencies
 */
import {
	Button,
	CheckboxControl,
	Flex,
	FlexItem,
	FormTokenField,
	Spinner,
} from '@wordpress/components';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useAccessControlSettings } from '../hooks/use-access-control-settings';
import { useRoles, useUserSearch } from '../hooks/use-roles-users';
import type { User } from '../hooks/use-roles-users';

interface AccessControlSettingsProps {
	featureId: string;
}

export function AccessControlSettings( {
	featureId,
}: AccessControlSettingsProps ): React.JSX.Element {
	const { roles, isLoading, fetchError } = useRoles();
	const { suggestions, isSearching, search } = useUserSearch();
	const { settings, stage, save, isDirty, isSaving } =
		useAccessControlSettings( featureId );

	const [ localRoles, setLocalRoles ] = useState< string[] | null >( null );
	const [ selectedUserMap, setSelectedUserMap ] = useState<
		Map< number, string >
	>( new Map() );
	const [ localUsers, setLocalUsers ] = useState< number[] | null >( null );

	const effectiveRoles = localRoles ?? settings.roles;
	const effectiveUsers = localUsers ?? settings.users;
	const suggestionNameToId = useMemo( () => {
		const map = new Map< string, number >();
		suggestions.forEach( ( u: User ) => map.set( u.name, u.id ) );
		return map;
	}, [ suggestions ] );

	const selectedUsersTokens = useMemo( () => {
		return effectiveUsers.map(
			( id ) => selectedUserMap.get( id ) ?? id.toString()
		);
	}, [ effectiveUsers, selectedUserMap ] );

	// Seed selectedUserMap with every user returned from the API so that
	// saved users always show their display name instead of a raw ID.
	useEffect( () => {
		setSelectedUserMap( ( prev ) => {
			const next = new Map( prev );
			suggestions.forEach( ( u: User ) => next.set( u.id, u.name ) );
			return next;
		} );
	}, [ suggestions ] );

	// Exclude already-selected users from the suggestions dropdown.
	const userSuggestionNames = useMemo(
		() =>
			suggestions
				.filter( ( u: User ) => ! effectiveUsers.includes( u.id ) )
				.map( ( u: User ) => u.name ),
		[ suggestions, effectiveUsers ]
	);

	const handleRoleToggle = useCallback(
		( roleId: string, checked: boolean ) => {
			const newRoles = checked
				? [ ...effectiveRoles, roleId ]
				: effectiveRoles.filter( ( r ) => r !== roleId );
			setLocalRoles( newRoles );
			stage( { roles: newRoles, users: effectiveUsers } );
		},
		[ stage, effectiveRoles, effectiveUsers ]
	);

	const handleUsersChange = useCallback(
		( tokens: ( string | { value: string } )[] ) => {
			const newUsers: number[] = [];
			const newMap = new Map< number, string >( selectedUserMap );

			tokens.forEach( ( token ) => {
				const label = typeof token === 'string' ? token : token.value;
				let id = suggestionNameToId.get( label );

				if ( id === undefined ) {
					for ( const [
						mapId,
						mapLabel,
					] of selectedUserMap.entries() ) {
						if ( mapLabel === label ) {
							id = mapId;
							break;
						}
					}
				}

				if ( id !== undefined ) {
					newUsers.push( id );
					newMap.set( id, label );
				}
			} );

			setLocalUsers( newUsers );
			setSelectedUserMap( newMap );
			stage( { roles: effectiveRoles, users: newUsers } );
			search( '' );
		},
		[ stage, effectiveRoles, suggestionNameToId, selectedUserMap, search ]
	);

	const handleInputChange = useCallback(
		( input: string ) => {
			search( input );
		},
		[ search ]
	);

	const handleSave = useCallback( async () => {
		await save();
		setLocalRoles( null );
		setLocalUsers( null );
	}, [ save ] );

	return (
		<div
			className="ai-access-control-mode-fields ai-feature-settings-form"
			style={ { marginTop: 'var(--wpds-dimension-gap-md, 12px)' } }
		>
			{ isLoading && <Spinner /> }
			{ ! isLoading && fetchError && (
				<p className="ai-access-control-mode-field__error">
					{ fetchError }
				</p>
			) }
			{ ! isLoading && ! fetchError && (
				<>
					<Flex gap={ 4 } direction="column">
						<FlexItem>
							<fieldset style={ { border: 'none' } }>
								<legend
									style={ {
										fontSize: '11px',
										fontWeight: 500,
										textTransform: 'uppercase',
										letterSpacing: '0.5px',
										marginBottom: '8px',
									} }
								>
									{ __( 'Roles', 'ai' ) }
								</legend>
								<div
									style={ {
										display: 'grid',
										gridTemplateColumns: 'repeat(3, 1fr)',
										gap: '12px',
									} }
								>
									{ roles.map( ( role ) => (
										<CheckboxControl
											key={ role.id }
											label={ role.name }
											checked={ effectiveRoles.includes(
												role.id
											) }
											onChange={ ( checked ) =>
												handleRoleToggle(
													role.id,
													checked
												)
											}
										/>
									) ) }
								</div>
							</fieldset>
						</FlexItem>
						<FlexItem>
							<Flex
								style={ {
									position: 'relative',
								} }
							>
								<div style={ { flex: 1 } }>
									<FormTokenField
										label={ __( 'Users', 'ai' ) }
										value={ selectedUsersTokens }
										suggestions={ userSuggestionNames }
										onChange={ handleUsersChange }
										onInputChange={ handleInputChange }
										__experimentalExpandOnFocus
										__experimentalShowHowTo={ false }
										__next40pxDefaultSize
										messages={ {
											added: __( 'User added.', 'ai' ),
											removed: __(
												'User removed.',
												'ai'
											),
											remove: __( 'Remove user', 'ai' ),
											__experimentalInvalid: __(
												'No matching user found.',
												'ai'
											),
										} }
									/>
								</div>
								{ isSearching && (
									<div
										style={ {
											marginTop: '4px',
											position: 'absolute',
											right: 0,
											top: '20px',
										} }
									>
										<Spinner />
									</div>
								) }
							</Flex>
						</FlexItem>
						{ isDirty && (
							<FlexItem>
								<Button
									variant="primary"
									onClick={ handleSave }
									disabled={ isSaving }
									size="compact"
									isBusy={ isSaving }
									accessibleWhenDisabled
								>
									{ isSaving ? (
										<Spinner />
									) : (
										__( 'Save', 'ai' )
									) }
								</Button>
							</FlexItem>
						) }
					</Flex>
				</>
			) }
		</div>
	);
}
