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
	ToggleControl,
} from '@wordpress/components';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useAccessControlSettings } from '../hooks/use-access-control-settings';
import { useRolesUsersContext } from '../hooks/use-roles-users';
import type { Role, User } from '../hooks/use-roles-users';

interface UserTokenItem {
	id?: number;
	value: string;
}

interface AccessControlSettingsProps {
	featureId: string;
	roles?: Role[];
	isLoading?: boolean;
	fetchError?: string | null;
	suggestions?: User[];
	isSearching?: boolean;
	search?: ( query: string ) => void;
}

const formatUserToken = ( id: number, name?: string ): string => {
	return name ? `${ name } (#${ id })` : id.toString();
};

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

	const [ localEnabled, setLocalEnabled ] = useState< boolean | null >(
		null
	);
	const [ localRoles, setLocalRoles ] = useState< string[] | null >( null );
	const [ selectedUserMap, setSelectedUserMap ] = useState<
		Map< number, string >
	>( new Map() );
	const [ localUsers, setLocalUsers ] = useState< number[] | null >( null );

	const effectiveEnabled = localEnabled ?? settings.enabled;
	const effectiveRoles = useMemo(
		() => localRoles ?? settings.roles,
		[ localRoles, settings.roles ]
	);
	const effectiveUsers = localUsers ?? settings.users;

	const handleToggleEnabled = useCallback(
		( enabled: boolean ) => {
			setLocalEnabled( enabled );
			stage( {
				enabled,
				roles: effectiveRoles,
				users: effectiveUsers,
			} );
		},
		[ stage, effectiveRoles, effectiveUsers ]
	);

	const suggestionTokenToId = useMemo( () => {
		const map = new Map< string, number >();
		suggestions.forEach( ( u: User ) => {
			map.set( formatUserToken( u.id, u.name ), u.id );
			map.set( u.name, u.id );
		} );
		return map;
	}, [ suggestions ] );

	const selectedUsersTokens = useMemo( () => {
		return effectiveUsers.map( ( id ) => ( {
			id,
			value: formatUserToken( id, selectedUserMap.get( id ) ),
		} ) );
	}, [ effectiveUsers, selectedUserMap ] );

	// Seed selectedUserMap with users returned from search suggestions.
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
				.map( ( u: User ) => formatUserToken( u.id, u.name ) ),
		[ suggestions, effectiveUsers ]
	);

	const handleRoleToggle = useCallback(
		( roleId: string, checked: boolean ) => {
			const newRoles = checked
				? [ ...effectiveRoles, roleId ]
				: effectiveRoles.filter( ( r ) => r !== roleId );
			setLocalRoles( newRoles );
			stage( {
				enabled: effectiveEnabled,
				roles: newRoles,
				users: effectiveUsers,
			} );
		},
		[ stage, effectiveEnabled, effectiveRoles, effectiveUsers ]
	);

	const handleUsersChange = useCallback(
		( tokens: ( string | UserTokenItem )[] ) => {
			const newUsers: number[] = [];
			const newMap = new Map< number, string >( selectedUserMap );

			tokens.forEach( ( token ) => {
				let id: number | undefined;
				let label = '';

				if ( typeof token === 'object' && token !== null ) {
					label = token.value;
					if (
						'id' in token &&
						typeof ( token as { id?: unknown } ).id === 'number'
					) {
						id = ( token as { id: number } ).id;
					}
				} else if ( typeof token === 'string' ) {
					label = token;
				}

				// If id is not already known from the token object, extract from label.
				if ( id === undefined ) {
					// 1. Check formatted token e.g. "Jane Doe (#12)" or "(ID: 12)"
					const idMatch = label.match( /(?:#|ID:\s*)(\d+)\)?$/i );
					if ( idMatch ) {
						id = Number( idMatch[ 1 ] );
					}
				}

				if ( id === undefined ) {
					// 2. Check if the token is a numeric user ID string e.g. "12"
					const numericId = Number( label );
					if ( Number.isInteger( numericId ) && numericId > 0 ) {
						id = numericId;
					}
				}

				if ( id === undefined ) {
					// 3. Fallback: match from suggestionTokenToId
					id = suggestionTokenToId.get( label );
				}

				if ( id !== undefined ) {
					if ( ! newUsers.includes( id ) ) {
						newUsers.push( id );
					}

					// Extract clean display name if available and save in newMap
					const cleanName = label
						.replace( /\s*\((?:#|ID:\s*)\d+\)$/i, '' )
						.trim();
					if ( cleanName && cleanName !== id.toString() ) {
						newMap.set( id, cleanName );
					}
				}
			} );

			setLocalUsers( newUsers );
			setSelectedUserMap( newMap );
			stage( {
				enabled: effectiveEnabled,
				roles: effectiveRoles,
				users: newUsers,
			} );
			search( '' );
		},
		[
			stage,
			effectiveEnabled,
			effectiveRoles,
			suggestionTokenToId,
			selectedUserMap,
			search,
		]
	);

	const handleInputChange = useCallback(
		( input: string ) => {
			search( input );
		},
		[ search ]
	);

	const handleSave = useCallback( async () => {
		await save();
		setLocalEnabled( null );
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
				<Flex gap={ 4 } direction="column">
					<FlexItem>
						<ToggleControl
							label={ __( 'Enable access control', 'ai' ) }
							help={
								effectiveEnabled
									? __(
											'Only selected roles and users can access this feature.',
											'ai'
									  )
									: __(
											'Currently disabled so default permissions will apply.',
											'ai'
									  )
							}
							checked={ effectiveEnabled }
							onChange={ handleToggleEnabled }
						/>
					</FlexItem>
					{ effectiveEnabled && (
						<>
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
												added: __(
													'User added.',
													'ai'
												),
												removed: __(
													'User removed.',
													'ai'
												),
												remove: __(
													'Remove user',
													'ai'
												),
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
						</>
					) }
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
								{ __( 'Save', 'ai' ) }
							</Button>
						</FlexItem>
					) }
				</Flex>
			) }
		</div>
	);
}
