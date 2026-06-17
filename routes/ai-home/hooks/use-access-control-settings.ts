/**
 * WordPress dependencies
 */
import { store as coreStore } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import { useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

interface AccessControlSettings {
	roles: string[];
	users: number[];
}

interface UseAccessControlSettingsReturn {
	settings: AccessControlSettings;
	update: ( next: AccessControlSettings ) => Promise< void >;
	clear: () => Promise< void >;
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

	const { editedRecord, isSaving } = useSelect( ( select ) => {
		const store: any = select( coreStore );
		return {
			editedRecord: store.getEditedEntityRecord( 'root', 'site' ) as
				| Record< string, unknown >
				| undefined,
			isSaving: store.isSavingEntityRecord( 'root', 'site' ) as boolean,
		};
	}, [] );

	const { editEntityRecord } = useDispatch( coreStore );
	const { __experimentalSaveSpecifiedEntityEdits: saveSpecifiedEdits } =
		useDispatch( coreStore ) as any;
	const { createErrorNotice } = useDispatch( noticesStore );

	const rawRoles = editedRecord?.[ rolesKey ];
	const rawUsers = editedRecord?.[ usersKey ];

	const settings: AccessControlSettings = {
		roles: Array.isArray( rawRoles ) ? rawRoles.map( String ) : [],
		users: Array.isArray( rawUsers ) ? rawUsers.map( Number ) : [],
	};

	const save = useCallback(
		async ( value: AccessControlSettings ) => {
			// @ts-expect-error -- core-data types don't expose editEntityRecord for 'root'/'site' args.
			editEntityRecord( 'root', 'site', undefined, {
				[ rolesKey ]: value.roles,
				[ usersKey ]: value.users,
			} );
			try {
				await saveSpecifiedEdits(
					'root',
					'site',
					undefined,
					[ rolesKey, usersKey ],
					{ throwOnError: true }
				);
			} catch {
				createErrorNotice(
					__( 'Failed to save access control settings.', 'ai' ),
					{ type: 'snackbar' }
				);
			}
		},
		[ rolesKey, usersKey, editEntityRecord, saveSpecifiedEdits, createErrorNotice ]
	);

	const update = useCallback(
		( next: AccessControlSettings ) => save( next ),
		[ save ]
	);

	const clear = useCallback(
		() => save( EMPTY_SETTINGS ),
		[ save ]
	);

	return { settings, update, clear, isSaving };
}
