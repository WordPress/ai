/**
 * WordPress dependencies
 */
import { store as coreStore } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import { useCallback, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

interface AccessControlSettings {
	roles: string[];
	users: number[];
}

interface UseAccessControlSettingsReturn {
	settings: AccessControlSettings;
	stage: ( next: AccessControlSettings ) => void;
	save: () => Promise< void >;
	clear: () => void;
	isDirty: boolean;
	isSaving: boolean;
}

const EMPTY_SETTINGS: AccessControlSettings = { roles: [], users: [] };

/**
 * Reads and writes the access control settings for a specific feature.
 *
 * @param {string} featureId The feature ID.
 * @return {UseAccessControlSettingsReturn} The settings and update functions.
 */
export function useAccessControlSettings(
	featureId: string
): UseAccessControlSettingsReturn {
	const rolesKey = `wpai_feature_${ featureId }_roles`;
	const usersKey = `wpai_feature_${ featureId }_users`;

	const { editedRecord, nonTransientEdits, isSaving } = useSelect(
		( select ) => {
			const store: any = select( coreStore );
			return {
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

	const rawRoles = editedRecord?.[ rolesKey ];
	const rawUsers = editedRecord?.[ usersKey ];

	const settings: AccessControlSettings = {
		roles: Array.isArray( rawRoles ) ? rawRoles.map( String ) : [],
		users: Array.isArray( rawUsers ) ? rawUsers.map( Number ) : [],
	};

	const isDirty = useMemo(
		() => rolesKey in nonTransientEdits || usersKey in nonTransientEdits,
		[ rolesKey, usersKey, nonTransientEdits ]
	);

	const stage = useCallback(
		( next: AccessControlSettings ) => {
			// @ts-expect-error -- core-data types don't expose editEntityRecord for 'root'/'site' args.
			editEntityRecord( 'root', 'site', undefined, {
				[ rolesKey ]: next.roles,
				[ usersKey ]: next.users,
			} );
		},
		[ rolesKey, usersKey, editEntityRecord ]
	);

	const save = useCallback( async () => {
		try {
			await saveSpecifiedEdits(
				'root',
				'site',
				undefined,
				[ rolesKey, usersKey ],
				{ throwOnError: true }
			);
			createSuccessNotice( __( 'Access control settings saved.', 'ai' ), {
				type: 'snackbar',
			} );
		} catch {
			createErrorNotice(
				__( 'Failed to save access control settings.', 'ai' ),
				{ type: 'snackbar' }
			);
		}
	}, [
		rolesKey,
		usersKey,
		saveSpecifiedEdits,
		createSuccessNotice,
		createErrorNotice,
	] );

	const clear = useCallback( () => {
		stage( EMPTY_SETTINGS );
	}, [ stage ] );

	return { settings, stage, save, clear, isDirty, isSaving };
}
