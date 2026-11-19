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
import type { User } from '../hooks/use-roles-users';

interface UserTokenItem {
	id?: number;
	value: string;
}

interface AccessControlSettingsProps {
	featureId: string;
}

const formatUserToken = ( id: number, name?: string ): string => {
	return name ? `${ name } (#${ id })` : id.toString();
};

export function AccessControlSettings( {
	featureId,
}: AccessControlSettingsProps ): React.JSX.Element {
	const { roles, defaultRoles, suggestions, isSearching, search } =
		useRolesUsersContext();

	const {
		settings,
		savedEnabled,
		savedRoles,
		stage,
		save,
		isDirty,
		isSaving,
	} = useAccessControlSettings( featureId );

	const rolesNotYetSaved = savedEnabled && savedRoles === null;

	const [ localEnabled, setLocalEnabled ] = useState< boolean | null >(
		null
	);
	const [ localRoles, setLocalRoles ] = useState< string[] | null >( null );
	const [ selectedUserMap, setSelectedUserMap ] = useState<
		Map< number, string >
	>( new Map() );
	const [ localUsers, setLocalUsers ] = useState< number[] | null >( null );

	const effectiveEnabled = localEnabled ?? settings.enabled;
	// Unset roles (never saved) show the pre-checked defaults.
	const effectiveRoles = useMemo(
		() => localRoles ?? settings.roles ?? defaultRoles,
		[ localRoles, settings.roles, defaultRoles ]
	);
	const effectiveUsers = localUsers ?? settings.users;

	const handleToggleEnabled = useCallback(
		( enabled: boolean ) => {
			setLocalEnabled( enabled );
			if ( enabled ) {
				stage(
					settings.roles === null
						? { enabled, roles: effectiveRoles }
						: { enabled }
				);
			} else if ( savedRoles === null ) {
				stage( { enabled, roles: null } );
			} else {
				stage( { enabled } );
			}
		},
		[ stage, settings.roles, savedRoles, effectiveRoles ]
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
			stage( { roles: newRoles } );
		},
		[ stage, effectiveRoles ]
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
			stage( { users: newUsers } );
			search( '' );
		},
		[ stage, suggestionTokenToId, selectedUserMap, search ]
	);

	const handleInputChange = useCallback(
		( input: string ) => {
			search( input );
		},
		[ search ]
	);

	const handleSave = useCallback( async () => {
		await save(
			effectiveEnabled && settings.roles === null
				? { roles: effectiveRoles }
				: undefined
		);
		setLocalEnabled( null );
		setLocalRoles( null );
		setLocalUsers( null );
	}, [ save, effectiveEnabled, settings.roles, effectiveRoles ] );

	return (
		<div className="ai-access-control-mode-fields ai-feature-settings-form">
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
								{ rolesNotYetSaved && (
									<p className="ai-access-control-mode-fields__roles-hint">
										{ __(
											'Role selections have not been saved yet, so access is not restricted.',
											'ai'
										) }
									</p>
								) }
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
					</>
				) }
				{ ( isDirty || rolesNotYetSaved ) && (
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
		</div>
	);
}

/**
 * Shows that a feature is restricted while the access controls view is hidden.
 *
 * @param {Object} props           Component props.
 * @param {string} props.featureId The feature ID.
 * @return {React.JSX.Element|null} The notice, or null when unrestricted.
 */
export function AccessRestrictedNotice( {
	featureId,
}: {
	featureId: string;
} ): React.JSX.Element | null {
	const { savedEnabled, savedRoles } = useAccessControlSettings( featureId );

	if ( ! savedEnabled || savedRoles === null ) {
		return null;
	}

	return (
		<p className="ai-access-restricted-notice">
			{ __( 'Access restricted to selected roles and users.', 'ai' ) }
		</p>
	);
}
