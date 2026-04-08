/**
 * WordPress dependencies
 */
import { Page } from '@wordpress/admin-ui';
import { Button, Notice, Spinner, ToggleControl } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect, useDispatch } from '@wordpress/data';
import { DataForm } from '@wordpress/dataviews';
import { useCallback, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import type { DataFormControlProps, Field, Form } from '@wordpress/dataviews';

/**
 * Internal dependencies
 */
import './style.scss';
import AIIcon from './ai-icon';

type AISettings = Record< string, boolean >;

interface FeatureGroupData {
	id: string;
	label: string;
	description: string;
}

interface SettingsFieldElement {
	value: string;
	label: string;
}

interface SettingsFieldData {
	id: string;
	label: string;
	type: string;
	default: unknown;
	elements: SettingsFieldElement[];
}

interface FeatureData {
	id: string;
	settingName: string;
	label: string;
	description: string;
	category: string;
	settingsFields: SettingsFieldData[];
}

interface PageData {
	hasCredentials: boolean;
	hasValidCredentials: boolean;
	connectorsUrl: string;
	featureGroups: FeatureGroupData[];
	features: FeatureData[];
}

const FEATURE_SETTING_PATTERN = /^wpai_feature_(.+)_enabled$/;
const GLOBAL_FIELD_ID = 'wpai_features_enabled';

function isRecord( value: unknown ): value is Record< string, unknown > {
	return typeof value === 'object' && value !== null;
}

function toStringValue( value: unknown ): string {
	return typeof value === 'string' ? value : '';
}

function isDefined< T >( value: T | null | undefined ): value is T {
	return value !== null && value !== undefined;
}

function parseSettingsField( value: unknown ): SettingsFieldData | null {
	if ( ! isRecord( value ) ) {
		return null;
	}

	const field = value as Partial< SettingsFieldData >;
	const id = toStringValue( field.id );
	if ( ! id ) {
		return null;
	}

	const rawElements = field.elements;

	const type = toStringValue( field.type ) || 'string';

	return {
		id,
		label: toStringValue( field.label ),
		type,
		default: field.default ?? ( type === 'integer' ? 0 : '' ),
		elements: Array.isArray( rawElements )
			? ( rawElements as unknown[] )
					.filter( isRecord )
					.map( ( el ) => {
						const element = el as Partial< SettingsFieldElement >;
						return {
							value: toStringValue( element.value ),
							label: toStringValue( element.label ),
						};
					} )
					.filter( ( el ) => el.value !== '' )
			: [],
	};
}

function parseFeatureGroup( value: unknown ): FeatureGroupData | null {
	if ( ! isRecord( value ) ) {
		return null;
	}

	const featureGroup = value as Partial< FeatureGroupData >;
	const id = toStringValue( featureGroup.id );
	if ( ! id ) {
		return null;
	}

	return {
		id,
		label: toStringValue( featureGroup.label ) || id,
		description: toStringValue( featureGroup.description ),
	};
}

function parseFeature( value: unknown ): FeatureData | null {
	if ( ! isRecord( value ) ) {
		return null;
	}

	const feature = value as Partial< FeatureData >;
	const settingName = toStringValue( feature.settingName );
	if ( ! settingName ) {
		return null;
	}

	const id =
		toStringValue( feature.id ) ||
		getFeatureIdFromSettingName( settingName );

	const rawFields = Array.isArray( feature.settingsFields )
		? feature.settingsFields
		: [];

	return {
		id,
		settingName,
		label: toStringValue( feature.label ) || getDefaultLabel( id ),
		description: toStringValue( feature.description ),
		category: toStringValue( feature.category ) || 'other',
		settingsFields: rawFields.map( parseSettingsField ).filter( isDefined ),
	};
}

function getFeatureIdFromSettingName( settingName: string ): string {
	const match = FEATURE_SETTING_PATTERN.exec( settingName );
	return match?.[ 1 ] ?? settingName;
}

function getDefaultLabel( key: string ): string {
	return key
		.split( /[-_]/ )
		.filter( Boolean )
		.map( ( part ) => part[ 0 ]?.toUpperCase() + part.slice( 1 ) )
		.join( ' ' );
}

function getSectionId( groupId: string ): string {
	return `feature-group-${ groupId.replace( /[^a-zA-Z0-9_-]/g, '-' ) }`;
}

function buildFallbackFeatureGroups(
	features: FeatureData[]
): FeatureGroupData[] {
	const categories = Array.from(
		new Set( features.map( ( feature ) => feature.category || 'other' ) )
	);

	return categories.map( ( category ) => ( {
		id: category,
		label:
			category === 'other'
				? __( 'Other Features', 'ai' )
				: getDefaultLabel( category ),
		description:
			category === 'other'
				? __( 'Additional AI-powered features.', 'ai' )
				: '',
	} ) );
}

function getPageData(): PageData {
	const fallback: PageData = {
		hasCredentials: false,
		hasValidCredentials: false,
		connectorsUrl: '',
		featureGroups: [],
		features: [],
	};

	try {
		const rawData = JSON.parse(
			document.getElementById( 'wp-script-module-data-ai-wp-admin' )
				?.textContent ?? '{}'
		);

		if ( ! isRecord( rawData ) ) {
			return fallback;
		}

		const pageData = rawData as Partial< PageData >;
		const featureGroups = Array.isArray( pageData.featureGroups )
			? pageData.featureGroups
					.map( parseFeatureGroup )
					.filter( isDefined )
			: [];

		const features = Array.isArray( pageData.features )
			? pageData.features.map( parseFeature ).filter( isDefined )
			: [];

		return {
			hasCredentials: Boolean( pageData.hasCredentials ),
			hasValidCredentials: Boolean( pageData.hasValidCredentials ),
			connectorsUrl: toStringValue( pageData.connectorsUrl ),
			featureGroups,
			features,
		};
	} catch {
		return fallback;
	}
}

const PAGE_DATA = getPageData();

const GLOBAL_FIELD: Field< AISettings > = {
	id: GLOBAL_FIELD_ID,
	label: __( 'Enable AI', 'ai' ),
	type: 'boolean',
	Edit: 'toggle',
};

function DisabledToggle( { field, data }: DataFormControlProps< AISettings > ) {
	return (
		<ToggleControl
			__nextHasNoMarginBottom
			label={ field.label }
			help={ field.description }
			checked={ !! field.getValue( { item: data } ) }
			// No-op handler required to satisfy React's controlled-component warning; the toggle is disabled.
			onChange={ () => {} }
			disabled
		/>
	);
}

/**
 * Inline sub-settings form for an experiment's custom fields.
 * Rendered below the toggle inside the same card, with its own Save button.
 *
 * @param {Object}      root0         Component props.
 * @param {FeatureData} root0.feature The feature with custom settings fields.
 */
function InlineFeatureSettings( {
	feature,
}: {
	feature: FeatureData;
} ) {
	// Read siteSettings directly from the store so this component can
	// re-render independently without forcing its parent to recreate.
	const siteSettings = useSelect( ( select ) => {
		// eslint-disable-next-line @typescript-eslint/no-explicit-any -- core-data store selectors aren't fully typed for 'root'/'site' entity args.
		const store: any = select( coreStore );
		return store.getEditedEntityRecord( 'root', 'site' ) as
			| Record< string, unknown >
			| undefined;
	}, [] );
	const [ localEdits, setLocalEdits ] = useState< Record< string, unknown > >(
		{}
	);
	const isDirty = Object.keys( localEdits ).length > 0;
	const [ isSaving, setIsSaving ] = useState( false );

	const { editEntityRecord, saveEditedEntityRecord } =
		useDispatch( coreStore );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );

	const data = useMemo( () => {
		const base: Record< string, unknown > = {};
		for ( const field of feature.settingsFields ) {
			base[ field.id ] = siteSettings?.[ field.id ] ?? field.default;
		}
		return { ...base, ...localEdits };
	}, [ feature.settingsFields, siteSettings, localEdits ] );

	const fields = useMemo< Field< Record< string, unknown > >[] >(
		() =>
			feature.settingsFields.map( ( settingsField ) => {
				const fieldDef: Field< Record< string, unknown > > = {
					id: settingsField.id,
					label: settingsField.label,
				};

				if ( settingsField.type === 'integer' ) {
					fieldDef.type = 'integer';
				} else if ( settingsField.elements.length > 0 ) {
					fieldDef.type = 'text';
					fieldDef.elements = settingsField.elements;
				} else {
					fieldDef.type = 'text';
				}

				return fieldDef;
			} ),
		[ feature.settingsFields ]
	);

	const form = useMemo< Form >(
		() => ( {
			fields: feature.settingsFields.map( ( f ) => f.id ),
		} ),
		[ feature.settingsFields ]
	);

	const handleChange = useCallback( ( edits: Record< string, unknown > ) => {
		setLocalEdits( ( prev ) => ( { ...prev, ...edits } ) );
	}, [] );

	const handleSave = useCallback( async () => {
		setIsSaving( true );

		// Capture previous values for rollback on failure.
		const previousValues: Record< string, unknown > = {};
		for ( const key of Object.keys( localEdits ) ) {
			previousValues[ key ] =
				siteSettings?.[ key ] ??
				feature.settingsFields.find( ( f ) => f.id === key )
					?.default;
		}

		try {
			// @ts-expect-error -- core-data types don't expose editEntityRecord for 'root'/'site' args.
			editEntityRecord( 'root', 'site', undefined, localEdits );
			// @ts-expect-error -- core-data types don't expose saveEditedEntityRecord for 'root'/'site' args.
			await saveEditedEntityRecord( 'root', 'site' );
			setLocalEdits( {} );
			createSuccessNotice(
				sprintf(
					// translators: %s: Feature label.
					__( '%s settings saved.', 'ai' ),
					feature.label
				),
				{ type: 'snackbar' }
			);
		} catch {
			// Revert the optimistic edit on failure.
			// @ts-expect-error -- core-data types don't expose editEntityRecord for 'root'/'site' args.
			editEntityRecord( 'root', 'site', undefined, previousValues );
			createErrorNotice( __( 'Failed to save settings.', 'ai' ), {
				type: 'snackbar',
			} );
		} finally {
			setIsSaving( false );
		}
	}, [
		localEdits,
		siteSettings,
		feature.settingsFields,
		editEntityRecord,
		saveEditedEntityRecord,
		createSuccessNotice,
		createErrorNotice,
		feature.label,
	] );

	return (
		<div className="ai-feature-settings-form">
			<DataForm< Record< string, unknown > >
				data={ data }
				fields={ fields }
				form={ form }
				onChange={ handleChange }
			/>
			{ ( isDirty || isSaving ) && (
				<div className="ai-feature-settings-form__actions">
					<Button
						variant="primary"
						onClick={ handleSave }
						isBusy={ isSaving }
						disabled={ isSaving }
						size="compact"
					>
						{ __( 'Save', 'ai' ) }
					</Button>
				</div>
			) }
		</div>
	);
}

/**
 * Creates a custom Edit component for a feature toggle that also renders
 * inline sub-settings when the feature is enabled.
 *
 * @param {FeatureData} feature The feature with custom settings fields.
 */
function createFeatureToggleWithSettings( feature: FeatureData ) {
	return function FeatureToggleWithSettings( {
		field,
		data,
		onChange,
	}: DataFormControlProps< AISettings > ) {
		const checked = !! field.getValue( { item: data } );

		return (
			<div className="ai-feature-toggle-with-settings">
				<ToggleControl
					__nextHasNoMarginBottom
					label={ field.label }
					help={ field.description }
					checked={ checked }
					onChange={ ( value ) => {
						onChange( { [ field.id ]: value } );
					} }
				/>
				{ checked && (
					<InlineFeatureSettings feature={ feature } />
				) }
			</div>
		);
	};
}

function AISettingsPage() {
	const { siteSettings, isLoading } = useSelect( ( select ) => {
		// eslint-disable-next-line @typescript-eslint/no-explicit-any -- core-data store selectors aren't fully typed for 'root'/'site' entity args.
		const store: any = select( coreStore );
		return {
			siteSettings: store.getEditedEntityRecord( 'root', 'site' ) as
				| Record< string, unknown >
				| undefined,
			isLoading: ! store.hasFinishedResolution( 'getEntityRecord', [
				'root',
				'site',
			] ) as boolean,
		};
	}, [] );

	const { editEntityRecord, saveEditedEntityRecord } =
		useDispatch( coreStore );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );

	const featureDefinitions = useMemo< FeatureData[] >( () => {
		const sourceFeatures =
			PAGE_DATA.features.length > 0
				? PAGE_DATA.features
				: Object.keys( siteSettings ?? {} )
						.filter( ( key ) =>
							FEATURE_SETTING_PATTERN.test( key )
						)
						.sort()
						.map( ( settingName ) => {
							const id =
								getFeatureIdFromSettingName( settingName );
							return {
								id,
								settingName,
								label: getDefaultLabel( id ),
								description: '',
								category: 'other',
								settingsFields: [],
							};
						} );

		const uniqueFeatures: FeatureData[] = [];
		const seenSettingNames = new Set< string >();
		for ( const feature of sourceFeatures ) {
			if ( seenSettingNames.has( feature.settingName ) ) {
				continue;
			}

			seenSettingNames.add( feature.settingName );
			uniqueFeatures.push( feature );
		}

		return uniqueFeatures;
	}, [ siteSettings ] );

	const featureGroups = useMemo< FeatureGroupData[] >(
		() =>
			PAGE_DATA.featureGroups.length > 0
				? PAGE_DATA.featureGroups
				: buildFallbackFeatureGroups( featureDefinitions ),
		[ featureDefinitions ]
	);

	const aiSettingKeys = useMemo( () => {
		const settingKeys = new Set< string >( [ GLOBAL_FIELD_ID ] );

		for ( const feature of featureDefinitions ) {
			settingKeys.add( feature.settingName );
		}

		return Array.from( settingKeys );
	}, [ featureDefinitions ] );

	const data: AISettings = useMemo( () => {
		const aiSettings: AISettings = {};
		for ( const key of aiSettingKeys ) {
			aiSettings[ key ] = Boolean( siteSettings?.[ key ] ?? false );
		}
		return aiSettings;
	}, [ aiSettingKeys, siteSettings ] );

	const globalEnabled = data[ GLOBAL_FIELD.id ];

	// Stable references for feature edit components to prevent React remounts
	// when siteSettings changes (which would reset InlineFeatureSettings state).
	// Stores the feature reference alongside the component so the cache is
	// invalidated when the feature definition changes.
	const editComponentsRef = useRef(
		new Map<
			string,
			{
				component: ReturnType< typeof createFeatureToggleWithSettings >;
				feature: FeatureData;
			}
		>()
	);

	const fields = useMemo< Field< AISettings >[] >(
		() => [
			GLOBAL_FIELD,
			...featureDefinitions.map( ( feature ) => {
				const baseField: Field< AISettings > = {
					id: feature.settingName,
					label: feature.label,
					description: feature.description,
					type: 'boolean' as const,
				};

				if ( ! globalEnabled ) {
					baseField.Edit = DisabledToggle;
				} else if ( feature.settingsFields.length > 0 ) {
					const cached =
						editComponentsRef.current.get( feature.id );
					if ( ! cached || cached.feature !== feature ) {
						editComponentsRef.current.set( feature.id, {
							component:
								createFeatureToggleWithSettings( feature ),
							feature,
						} );
					}
					baseField.Edit =
						editComponentsRef.current.get( feature.id )!
							.component;
				} else {
					baseField.Edit = 'toggle' as const;
				}

				return baseField;
			} ),
		],
		[ featureDefinitions, globalEnabled ]
	);

	const form = useMemo< Form >( () => {
		const groupedFields = new Map< string, string[] >();
		for ( const feature of featureDefinitions ) {
			const category = feature.category || 'other';
			const categoryFields = groupedFields.get( category ) ?? [];
			categoryFields.push( feature.settingName );
			groupedFields.set( category, categoryFields );
		}

		const sectionFields: NonNullable< Form[ 'fields' ] > = [];
		const seenCategories = new Set< string >();

		for ( const group of featureGroups ) {
			const children = groupedFields.get( group.id ) ?? [];

			if ( children.length === 0 ) {
				continue;
			}

			seenCategories.add( group.id );
			sectionFields.push( {
				id: getSectionId( group.id ),
				label: group.label,
				description: group.description,
				layout: {
					type: 'card',
					withHeader: true,
					isOpened: true,
					isCollapsible: true,
				},
				children,
			} );
		}

		for ( const [ category, children ] of groupedFields.entries() ) {
			if ( children.length === 0 || seenCategories.has( category ) ) {
				continue;
			}

			sectionFields.push( {
				id: getSectionId( category ),
				label: getDefaultLabel( category ),
				description: '',
				layout: {
					type: 'card',
					withHeader: true,
					isOpened: true,
					isCollapsible: true,
				},
				children,
			} );
		}

		return {
			fields: [
				{
					id: 'generalSettings',
					label: __( 'General Settings', 'ai' ),
					description: __(
						'Control whether AI is enabled for your site. When disabled, all features and experiments will be inactive regardless of their individual settings.',
						'ai'
					),
					layout: {
						type: 'card',
						withHeader: true,
						isCollapsible: false,
					},
					children: [ GLOBAL_FIELD_ID ],
				},
				...sectionFields,
			],
		};
	}, [ featureDefinitions, featureGroups ] );

	const handleChange = useCallback(
		async ( edits: Record< string, unknown > ) => {
			// Capture previous values for rollback on failure.
			const previousValues: Record< string, unknown > = {};
			for ( const key of Object.keys( edits ) ) {
				previousValues[ key ] = data[ key ];
			}

			// @ts-expect-error -- core-data types don't expose editEntityRecord for 'root'/'site' args.
			editEntityRecord( 'root', 'site', undefined, edits );

			const entry = Object.entries( edits )[ 0 ];
			let message: string;
			if ( ! entry ) {
				message = __( 'Settings saved.', 'ai' );
			} else if ( entry[ 0 ] === GLOBAL_FIELD_ID ) {
				message = entry[ 1 ]
					? __( 'AI enabled.', 'ai' )
					: __( 'AI disabled.', 'ai' );
			} else {
				const feature = featureDefinitions.find(
					( f ) => f.settingName === entry[ 0 ]
				);
				const label = feature?.label ?? entry[ 0 ];
				if ( entry[ 1 ] ) {
					// translators: %s: Feature label.
					message = sprintf( __( '%s enabled.', 'ai' ), label );
				} else {
					// translators: %s: Feature label.
					message = sprintf( __( '%s disabled.', 'ai' ), label );
				}
			}

			try {
				// @ts-expect-error -- core-data types don't expose saveEditedEntityRecord for 'root'/'site' args.
				await saveEditedEntityRecord( 'root', 'site' );
				createSuccessNotice( message, { type: 'snackbar' } );
			} catch {
				// Revert the optimistic edit on failure.
				// @ts-expect-error -- core-data types don't expose editEntityRecord for 'root'/'site' args.
				editEntityRecord( 'root', 'site', undefined, previousValues );
				createErrorNotice( __( 'Failed to save settings.', 'ai' ), {
					type: 'snackbar',
				} );
			}
		},
		[
			editEntityRecord,
			saveEditedEntityRecord,
			createSuccessNotice,
			createErrorNotice,
			featureDefinitions,
			data,
		]
	);

	return (
		<Page
			title={
				<>
					<AIIcon />
					{ __( 'AI', 'ai' ) }
				</>
			}
			subTitle={ __(
				'Configure AI features and experiments for your WordPress site.',
				'ai'
			) }
			actions={
				<>
					<Button
						variant="secondary"
						href="https://github.com/WordPress/ai/tree/develop/docs"
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'Docs', 'ai' ) }
					</Button>
					<Button
						variant="primary"
						href="https://github.com/WordPress/ai/blob/develop/CONTRIBUTING.md"
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'Contribute', 'ai' ) }
					</Button>
				</>
			}
		>
			<div className="ai-settings-page">
				{ ! PAGE_DATA.hasValidCredentials && (
					<Notice status="error" isDismissible={ false }>
						{ ! PAGE_DATA.hasCredentials
							? __(
									'The AI plugin requires a valid AI Connector to function properly. Verify you have one or more AI Connectors configured.',
									'ai'
							  )
							: __(
									'The AI plugin requires a valid AI Connector to function properly. Please review the AI Connectors you have configured to ensure they are valid.',
									'ai'
							  ) }{ ' ' }
						{ PAGE_DATA.connectorsUrl && (
							<Button
								variant="link"
								href={ PAGE_DATA.connectorsUrl }
							>
								{ __( 'Manage Connectors', 'ai' ) }
							</Button>
						) }
					</Notice>
				) }
				{ isLoading ? (
					<Spinner />
				) : (
					<DataForm< AISettings >
						data={ data }
						fields={ fields }
						form={ form }
						onChange={ handleChange }
					/>
				) }
			</div>
		</Page>
	);
}
export const stage = AISettingsPage;
