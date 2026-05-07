/**
 * WordPress dependencies
 */
import { store as coreStore } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import { useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

interface DeveloperFeatureSettings {
	provider: string;
	model: string;
}

interface UseDeveloperFeatureSettingsReturn {
	settings: DeveloperFeatureSettings;
	update: ( next: DeveloperFeatureSettings ) => Promise< void >;
	clear: () => Promise< void >;
	isSaving: boolean;
}

const EMPTY_SETTINGS: DeveloperFeatureSettings = { provider: '', model: '' };

/**
 * Reads and writes the developer feature settings for a specific feature.
 *
 * Settings are stored in the WordPress option `wpai_feature_{featureId}_field_developer`
 * and exposed via the REST API settings endpoint.
 *
 * @param {string} featureId The feature ID.
 * @return {UseDeveloperFeatureSettingsReturn} The settings and update functions.
 */
export function useDeveloperFeatureSettings(
	featureId: string
): UseDeveloperFeatureSettingsReturn {
	const fieldKey = `wpai_feature_${ featureId }_field_developer`;

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

	const rawValue = editedRecord?.[ fieldKey ];
	const settings: DeveloperFeatureSettings =
		rawValue && typeof rawValue === 'object' && ! Array.isArray( rawValue )
			? ( () => {
					const raw = rawValue as {
						provider?: unknown;
						model?: unknown;
					};
					return {
						provider:
							typeof raw.provider === 'string'
								? raw.provider
								: '',
						model: typeof raw.model === 'string' ? raw.model : '',
					};
			  } )()
			: EMPTY_SETTINGS;

	const save = useCallback(
		async ( value: DeveloperFeatureSettings | Record< string, never > ) => {
			// @ts-expect-error -- core-data types don't expose editEntityRecord for 'root'/'site' args.
			editEntityRecord( 'root', 'site', undefined, {
				[ fieldKey ]: value,
			} );
			try {
				await saveSpecifiedEdits(
					'root',
					'site',
					undefined,
					[ fieldKey ],
					{ throwOnError: true }
				);
			} catch {
				createErrorNotice(
					__( 'Failed to save developer settings.', 'ai' ),
					{ type: 'snackbar' }
				);
			}
		},
		[ fieldKey, editEntityRecord, saveSpecifiedEdits, createErrorNotice ]
	);

	const update = useCallback(
		( next: DeveloperFeatureSettings ) => save( next ),
		[ save ]
	);

	const clear = useCallback(
		() => save( {} as Record< string, never > ),
		[ save ]
	);

	return { settings, update, clear, isSaving };
}
