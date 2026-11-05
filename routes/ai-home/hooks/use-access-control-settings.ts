/**
 * WordPress dependencies
 */
import { store as coreStore } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import { useCallback, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

interface AccessControlSettings {
	enabled: boolean;
	roles: string[] | null;
	users: number[];
}

interface UseAccessControlSettingsReturn {
	settings: AccessControlSettings;
	savedEnabled: boolean;
	savedRoles: string[] | null;
	stage: ( next: Partial< AccessControlSettings > ) => void;
	save: ( pending?: Partial< AccessControlSettings > ) => Promise< void >;
	isDirty: boolean;
	isSaving: boolean;
}

/**
 * Reads and writes the access control settings for a specific feature.
 *
 * @param {string} featureId The feature ID.
 * @return {UseAccessControlSettingsReturn} The settings and update functions.
 */
export function useAccessControlSettings(
	featureId: string
): UseAccessControlSettingsReturn {
	const enabledKey = `wpai_feature_${ featureId }_access_control_enabled`;
	const rolesKey = `wpai_feature_${ featureId }_roles`;
	const usersKey = `wpai_feature_${ featureId }_users`;

	const { record, editedRecord, nonTransientEdits, isSaving } = useSelect(
		( select ) => {
			const store: any = select( coreStore );
			return {
				record: store.getEntityRecord( 'root', 'site' ) as
					| Record< string, unknown >
					| undefined,
				editedRecord: store.getEditedEntityRecord( 'root', 'site' ) as
					| Record< string, unknown >
					| undefined,
				nonTransientEdits: ( store.getEntityRecordNonTransientEdits(
					'root',
					'site'
				) ?? {} ) as Record< string, unknown >,
				isSaving: store.isSavingEntityRecord(
					'root',
					'site'
				) as boolean,
			};
		},
		[]
	);

	const { editEntityRecord } = useDispatch( coreStore );
	const { __experimentalSaveSpecifiedEntityEdits: saveSpecifiedEdits } =
		useDispatch( coreStore ) as any;
	const { createErrorNotice, createSuccessNotice } =
		useDispatch( noticesStore );

	const rawEnabled = editedRecord?.[ enabledKey ];
	const rawRoles = editedRecord?.[ rolesKey ];
	const rawUsers = editedRecord?.[ usersKey ];
	const savedEnabled = record?.[ enabledKey ] === true;
	const rawSavedRoles = record?.[ rolesKey ];
	const savedRoles = Array.isArray( rawSavedRoles )
		? rawSavedRoles.map( String )
		: null;

	const settings: AccessControlSettings = {
		enabled: typeof rawEnabled === 'boolean' ? rawEnabled : false,
		roles: Array.isArray( rawRoles ) ? rawRoles.map( String ) : null,
		users: Array.isArray( rawUsers ) ? rawUsers.map( Number ) : [],
	};

	const isDirty = useMemo(
		() =>
			enabledKey in nonTransientEdits ||
			rolesKey in nonTransientEdits ||
			usersKey in nonTransientEdits,
		[ enabledKey, rolesKey, usersKey, nonTransientEdits ]
	);

	const stage = useCallback(
		( next: Partial< AccessControlSettings > ) => {
			const edits: Record< string, unknown > = {};
			if ( next.enabled !== undefined ) {
				// A saved `false` is stored as '' and comes back from REST as
				// null, so staging `false` over anything but a persisted `true`
				// stages the persisted value, which reverts the edit.
				const persistedEnabled = record?.[ enabledKey ];
				edits[ enabledKey ] =
					! next.enabled && persistedEnabled !== true
						? persistedEnabled
						: next.enabled;
			}
			if ( next.roles !== undefined ) {
				edits[ rolesKey ] = next.roles;
			}
			if ( next.users !== undefined ) {
				edits[ usersKey ] = next.users;
			}
			// @ts-expect-error -- core-data types don't expose editEntityRecord for 'root'/'site' args.
			editEntityRecord( 'root', 'site', undefined, edits );
		},
		[ enabledKey, rolesKey, usersKey, record, editEntityRecord ]
	);

	// `pending` is staged right before saving, so its keys are saved too.
	const save = useCallback(
		async ( pending?: Partial< AccessControlSettings > ) => {
			if ( pending ) {
				stage( pending );
			}
			const pendingKeys = pending
				? [
						pending.enabled !== undefined ? enabledKey : '',
						pending.roles !== undefined ? rolesKey : '',
						pending.users !== undefined ? usersKey : '',
				  ]
				: [];
			const editedKeys = [ enabledKey, rolesKey, usersKey ].filter(
				( key ) =>
					key in nonTransientEdits || pendingKeys.includes( key )
			);
			if ( editedKeys.length === 0 ) {
				return;
			}
			try {
				await saveSpecifiedEdits(
					'root',
					'site',
					undefined,
					editedKeys,
					{
						throwOnError: true,
					}
				);
				createSuccessNotice(
					__( 'Access control settings saved.', 'ai' ),
					{
						type: 'snackbar',
					}
				);
			} catch {
				createErrorNotice(
					__( 'Failed to save access control settings.', 'ai' ),
					{ type: 'snackbar' }
				);
			}
		},
		[
			enabledKey,
			rolesKey,
			usersKey,
			nonTransientEdits,
			stage,
			saveSpecifiedEdits,
			createSuccessNotice,
			createErrorNotice,
		]
	);

	return {
		settings,
		savedEnabled,
		savedRoles,
		stage,
		save,
		isDirty,
		isSaving,
	};
}
