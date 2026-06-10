/**
 * WordPress dependencies
 */
import { store as coreStore } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import { useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

interface UseDataRemovalSettingReturn {
	enabled: boolean;
	update: ( next: boolean ) => Promise< void >;
	isSaving: boolean;
}

/**
 * Setting key registered on the `root`/`site` entity (see Settings_Registration).
 */
const OPTION_KEY = 'wpai_remove_data_on_uninstall';

/**
 * Reads and writes the "remove all plugin data on uninstall" opt-in.
 *
 * The value is stored in the WordPress option `wpai_remove_data_on_uninstall`
 * and exposed via the REST API settings endpoint.
 *
 * @return {UseDataRemovalSettingReturn} The setting value and update function.
 */
export function useDataRemovalSetting(): UseDataRemovalSettingReturn {
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
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );

	const enabled = Boolean( editedRecord?.[ OPTION_KEY ] );

	const update = useCallback(
		async ( next: boolean ) => {
			// @ts-expect-error -- core-data types don't expose editEntityRecord for 'root'/'site' args.
			editEntityRecord( 'root', 'site', undefined, {
				[ OPTION_KEY ]: next,
			} );
			try {
				await saveSpecifiedEdits(
					'root',
					'site',
					undefined,
					[ OPTION_KEY ],
					{ throwOnError: true }
				);
				createSuccessNotice(
					next
						? __( 'Data removal on uninstall enabled.', 'ai' )
						: __( 'Data removal on uninstall disabled.', 'ai' ),
					{ type: 'snackbar' }
				);
			} catch {
				createErrorNotice( __( 'Failed to save settings.', 'ai' ), {
					type: 'snackbar',
				} );
			}
		},
		[
			editEntityRecord,
			saveSpecifiedEdits,
			createSuccessNotice,
			createErrorNotice,
		]
	);

	return { enabled, update, isSaving };
}
