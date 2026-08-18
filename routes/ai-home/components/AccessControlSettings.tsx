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
import { useRolesUsersContext } from '../hooks/use-roles-users';
import type { Role, User } from '../hooks/use-roles-users';

interface AccessControlSettingsProps {
	featureId: string;
	roles?: Role[];
	isLoading?: boolean;
	fetchError?: string | null;
	suggestions?: User[];
	isSearching?: boolean;
	search?: ( query: string ) => void;
}

export function AccessControlSettings( {
	featureId,
	roles: propsRoles,
	isLoading: propsIsLoading,
	fetchError: propsFetchError,
	suggestions: propsSuggestions,
	isSearching: propsIsSearching,
	search: propsSearch,
}: AccessControlSettingsProps ): React.JSX.Element {
	const contextData = useRolesUsersContext();
	const roles = propsRoles ?? contextData.roles;
	const isLoading = propsIsLoading ?? contextData.isLoading;
	const fetchError = propsFetchError ?? contextData.fetchError;
	const suggestions = propsSuggestions ?? contextData.suggestions;
	const isSearching = propsIsSearching ?? contextData.isSearching;
	const search = propsSearch ?? contextData.search;

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

	// Seed selectedUserMap with users returned from the API (capped at
	// MAX_USERS i.e. 10 at a time). If more than
	// MAX_USERS users are saved, any beyond the cap won't be included in
	// this response and will fall back to showing their raw ID.
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
		<div className="ai-access-control-mode-fields ai-feature-settings-form">
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
							<fieldset className="ai-access-control-mode-fields__fieldset">
								<legend className="ai-access-control-mode-fields__legend">
									{ __( 'Roles', 'ai' ) }
								</legend>
								<div className="ai-access-control-mode-fields__roles-grid">
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
							<Flex className="ai-access-control-mode-fields__user-search-wrapper">
								<div className="ai-access-control-mode-fields__user-search-input">
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
									<div className="ai-access-control-mode-fields__user-search-spinner">
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
