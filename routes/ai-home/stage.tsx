/**
 * WordPress dependencies
 */
import { Page } from '@wordpress/admin-ui';
import {
	Button,
	ExternalLink,
	Notice,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect, useDispatch, useRegistry } from '@wordpress/data';
import { DataForm } from '@wordpress/dataviews';
import { useCallback, useMemo, useState } from '@wordpress/element';
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

interface SettingsFieldData {
	id: string;
	label: string;
	type: string;
	default?: unknown;
	elements?: Array< { value: string; label: string } >;
	isValid?: Record< string, number >;
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
const noop = () => {};

function isRecord( value: unknown ): value is Record< string, unknown > {
	return typeof value === 'object' && value !== null;
}

function toStringValue( value: unknown ): string {
	return typeof value === 'string' ? value : '';
}

function isDefined< T >( value: T | null | undefined ): value is T {
	return value !== null && value !== undefined;
}

function isSettingsField( value: unknown ): value is SettingsFieldData {
	if ( ! isRecord( value ) ) {
		return false;
	}
	// eslint-disable-next-line dot-notation
	const id = value[ 'id' ];
	return typeof id === 'string' && id !== '';
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
		settingsFields: ( rawFields as unknown[] ).filter( isSettingsField ),
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

function buildToggleMessage(
	edits: Record< string, unknown >,
	featureDefinitions: FeatureData[]
): string {
	const entries = Object.entries( edits );
	if ( entries.length === 0 ) {
		return __( 'Settings saved.', 'ai' );
	}

	// Bulk toggle (multiple experiments).
	if ( entries.length > 1 ) {
		const allEnabled = entries.every( ( [ , value ] ) => value === true );
		const allDisabled = entries.every( ( [ , value ] ) => value === false );
		const count = entries.length;

		if ( allEnabled ) {
			return sprintf(
				// translators: %d: Number of experiments.
				__( '%d experiments enabled', 'ai' ),
				count
			);
		}
		if ( allDisabled ) {
			return sprintf(
				// translators: %d: Number of experiments.
				__( '%d experiments disabled', 'ai' ),
				count
			);
		}
		// Just a fallback for mixed state (shouldn't happen with our buttons, but handle it).
		return sprintf(
			// translators: %d: Number of experiments.
			__( '%d experiments updated', 'ai' ),
			count
		);
	}

	// Single toggle
	const entry = entries[ 0 ];
	if ( ! entry ) {
		return __( 'Settings saved.', 'ai' );
	}

	if ( entry[ 0 ] === GLOBAL_FIELD_ID ) {
		return entry[ 1 ]
			? __( 'AI enabled.', 'ai' )
			: __( 'AI disabled.', 'ai' );
	}
	const feature = featureDefinitions.find(
		( f ) => f.settingName === entry[ 0 ]
	);
	const label = feature?.label ?? entry[ 0 ];
	return entry[ 1 ]
		? // translators: %s: Feature label.
		  sprintf( __( '%s enabled.', 'ai' ), label )
		: // translators: %s: Feature label.
		  sprintf( __( '%s disabled.', 'ai' ), label );
}

function DisabledToggle( { field, data }: DataFormControlProps< AISettings > ) {
	return (
		<ToggleControl
			__nextHasNoMarginBottom
			label={ field.label }
			help={ field.description }
			checked={ !! field.getValue( { item: data } ) }
			// No-op handler required to satisfy React's controlled-component warning; the toggle is disabled.
			onChange={ noop }
			disabled
		/>
	);
}

interface SectionActionsProps extends DataFormControlProps< AISettings > {
	experimentSettings: string[];
	globalEnabled: boolean;
	onBulkChange: ( edits: Record< string, boolean > ) => void;
}

function SectionActions( {
	experimentSettings,
	data,
	globalEnabled,
	onBulkChange,
}: SectionActionsProps ) {
	const allEnabled = useMemo( () => {
		return experimentSettings.every(
			( settingName ) => data[ settingName ]
		);
	}, [ experimentSettings, data ] );

	const allDisabled = useMemo( () => {
		return experimentSettings.every(
			( settingName ) => ! data[ settingName ]
		);
	}, [ experimentSettings, data ] );

	const handleEnableAll = useCallback( () => {
		const edits: Record< string, boolean > = {};
		let enabledCount = 0;

		for ( const settingName of experimentSettings ) {
			if ( ! data[ settingName ] ) {
				edits[ settingName ] = true;
				enabledCount++;
			}
		}

		if ( enabledCount > 0 ) {
			onBulkChange( edits );
		}
	}, [ experimentSettings, data, onBulkChange ] );

	const handleDisableAll = useCallback( () => {
		const edits: Record< string, boolean > = {};
		let disabledCount = 0;

		for ( const settingName of experimentSettings ) {
			if ( data[ settingName ] ) {
				edits[ settingName ] = false;
				disabledCount++;
			}
		}

		if ( disabledCount > 0 ) {
			onBulkChange( edits );
		}
	}, [ experimentSettings, data, onBulkChange ] );

	return (
		<div className="ai-section-actions">
			<Button
				variant="secondary"
				size="compact"
				onClick={ handleEnableAll }
				disabled={ ! globalEnabled || allEnabled }
			>
				{ __( 'Enable all', 'ai' ) }
			</Button>
			<Button
				variant="secondary"
				size="compact"
				onClick={ handleDisableAll }
				disabled={ ! globalEnabled || allDisabled }
			>
				{ __( 'Disable all', 'ai' ) }
			</Button>
		</div>
	);
}

function InlineFeatureSettings( { feature }: { feature: FeatureData } ) {
	const fieldIds = useMemo(
		() => feature.settingsFields.map( ( f ) => f.id ),
		[ feature.settingsFields ]
	);

	const { editedRecord, nonTransientEdits } = useSelect( ( select ) => {
		// eslint-disable-next-line @typescript-eslint/no-explicit-any -- core-data store selectors aren't fully typed for 'root'/'site' entity args.
		const store: any = select( coreStore );
		return {
			editedRecord: store.getEditedEntityRecord( 'root', 'site' ) as
				| Record< string, unknown >
				| undefined,
			nonTransientEdits: ( store.getEntityRecordNonTransientEdits(
				'root',
				'site'
			) ?? {} ) as Record< string, unknown >,
		};
	}, [] );

	const [ isSaving, setIsSaving ] = useState( false );

	const isDirty = useMemo(
		() => fieldIds.some( ( id ) => id in nonTransientEdits ),
		[ fieldIds, nonTransientEdits ]
	);

	const { editEntityRecord } = useDispatch( coreStore );
	// eslint-disable-next-line @typescript-eslint/no-explicit-any -- __experimentalSaveSpecifiedEntityEdits is not in the public types.
	const { __experimentalSaveSpecifiedEntityEdits: saveSpecifiedEdits } =
		useDispatch( coreStore ) as any;
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );

	const data = useMemo( () => {
		const base: Record< string, unknown > = {};
		for ( const field of feature.settingsFields ) {
			base[ field.id ] = editedRecord?.[ field.id ] ?? field.default;
		}
		return base;
	}, [ feature.settingsFields, editedRecord ] );

	const fields = useMemo< Field< Record< string, unknown > >[] >(
		() =>
			feature.settingsFields.map(
				( { default: _, ...fieldProps } ) =>
					fieldProps as Field< Record< string, unknown > >
			),
		[ feature.settingsFields ]
	);

	const form = useMemo< Form >(
		() => ( {
			fields: feature.settingsFields.map( ( f ) => f.id ),
		} ),
		[ feature.settingsFields ]
	);

	const handleChange = useCallback(
		( edits: Record< string, unknown > ) => {
			// @ts-expect-error -- core-data types don't expose editEntityRecord for 'root'/'site' args.
			editEntityRecord( 'root', 'site', undefined, edits );
		},
		[ editEntityRecord ]
	);

	const handleSave = useCallback( async () => {
		setIsSaving( true );
		try {
			await saveSpecifiedEdits( 'root', 'site', undefined, fieldIds, {
				throwOnError: true,
			} );
			createSuccessNotice(
				sprintf(
					// translators: %s: Feature label.
					__( '%s settings saved.', 'ai' ),
					feature.label
				),
				{ type: 'snackbar' }
			);
		} catch {
			// Edits remain in the store — user can retry or adjust values.
			createErrorNotice( __( 'Failed to save settings.', 'ai' ), {
				type: 'snackbar',
			} );
		} finally {
			setIsSaving( false );
		}
	}, [
		saveSpecifiedEdits,
		fieldIds,
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
			{ isDirty && (
				<div className="ai-feature-settings-form__actions">
					<Button
						variant="primary"
						onClick={ handleSave }
						isBusy={ isSaving }
						disabled={ isSaving }
						size="compact"
						aria-label={ sprintf(
							// translators: %s: Feature label.
							__( 'Save %s settings', 'ai' ),
							feature.label
						) }
					>
						{ __( 'Save', 'ai' ) }
					</Button>
				</div>
			) }
		</div>
	);
}

const FEATURES_BY_SETTING = new Map(
	PAGE_DATA.features
		.filter( ( f ) => f.settingsFields.length > 0 )
		.map( ( f ) => [ f.settingName, f ] as const )
);

function FeatureToggleWithSettings( {
	field,
	data,
	onChange,
}: DataFormControlProps< AISettings > ) {
	const feature = FEATURES_BY_SETTING.get( field.id );
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
			{ checked && feature && (
				<InlineFeatureSettings feature={ feature } />
			) }
		</div>
	);
}

function AISettingsPage() {
	const { editedRecord, isLoading } = useSelect( ( select ) => {
		// eslint-disable-next-line @typescript-eslint/no-explicit-any -- core-data store selectors aren't fully typed for 'root'/'site' entity args.
		const store: any = select( coreStore );
		return {
			editedRecord: store.getEditedEntityRecord( 'root', 'site' ) as
				| Record< string, unknown >
				| undefined,
			isLoading: ! store.hasFinishedResolution( 'getEntityRecord', [
				'root',
				'site',
			] ) as boolean,
		};
	}, [] );

	const { editEntityRecord } = useDispatch( coreStore );
	// eslint-disable-next-line @typescript-eslint/no-explicit-any -- __experimentalSaveSpecifiedEntityEdits is not in the public types.
	const { __experimentalSaveSpecifiedEntityEdits: saveSpecifiedEdits } =
		useDispatch( coreStore ) as any;
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );
	const registry = useRegistry();

	const featureDefinitions = useMemo< FeatureData[] >( () => {
		const sourceFeatures =
			PAGE_DATA.features.length > 0
				? PAGE_DATA.features
				: Object.keys( editedRecord ?? {} )
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
	}, [ editedRecord ] );

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
			aiSettings[ key ] = Boolean( editedRecord?.[ key ] ?? false );
		}
		return aiSettings;
	}, [ aiSettingKeys, editedRecord ] );

	const globalEnabled = Boolean( data[ GLOBAL_FIELD.id ] );

	const handleChange = useCallback(
		async ( edits: Record< string, unknown > ) => {
			const keys = Object.keys( edits );

			// Optimistic update — the UI reflects the new value immediately.
			// @ts-expect-error -- core-data types don't expose editEntityRecord for 'root'/'site' args.
			editEntityRecord( 'root', 'site', undefined, edits );

			const message = buildToggleMessage( edits, featureDefinitions );

			try {
				await saveSpecifiedEdits( 'root', 'site', undefined, keys, {
					throwOnError: true,
				} );
				createSuccessNotice( message, { type: 'snackbar' } );
			} catch {
				// Revert only the toggled keys to their server-side values.
				// eslint-disable-next-line @typescript-eslint/no-explicit-any -- DataRegistry typing doesn't expose .select(); core-data selectors aren't fully typed for 'root'/'site' entity args.
				const serverRecord = ( registry as any )
					.select( coreStore )
					.getEntityRecord( 'root', 'site' ) as
					| Record< string, unknown >
					| undefined;
				const revert: Record< string, unknown > = {};
				for ( const key of keys ) {
					revert[ key ] = serverRecord?.[ key ];
				}
				// @ts-expect-error -- core-data types don't expose editEntityRecord for 'root'/'site' args.
				editEntityRecord( 'root', 'site', undefined, revert );
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
			featureDefinitions,
			registry,
		]
	);

	const fields = useMemo< Field< AISettings >[] >( () => {
		const sectionActionsFields: Field< AISettings >[] = [];
		const groupedFields = new Map< string, string[] >();

		// Group features by category
		for ( const feature of featureDefinitions ) {
			const category = feature.category || 'other';
			const categoryFields = groupedFields.get( category ) ?? [];
			categoryFields.push( feature.settingName );
			groupedFields.set( category, categoryFields );
		}

		// Create section action fields for each group
		for ( const group of featureGroups ) {
			const experimentSettings = groupedFields.get( group.id ) ?? [];
			if ( experimentSettings.length === 0 ) {
				continue;
			}

			const actionFieldId = `section-actions-${ group.id }`;
			sectionActionsFields.push( {
				id: actionFieldId,
				label: '',
				type: 'text',
				Edit: ( props ) => (
					<SectionActions
						{ ...props }
						experimentSettings={ experimentSettings }
						globalEnabled={ globalEnabled }
						onBulkChange={ handleChange }
					/>
				),
			} );
		}

		// Create feature toggle fields
		const featureFields = featureDefinitions.map( ( feature ) => {
			const baseField: Field< AISettings > = {
				id: feature.settingName,
				label: feature.label,
				description: feature.description,
				type: 'boolean' as const,
			};

			if ( ! globalEnabled ) {
				baseField.Edit = DisabledToggle;
			} else if ( feature.settingsFields.length > 0 ) {
				baseField.Edit = FeatureToggleWithSettings;
			} else {
				baseField.Edit = 'toggle' as const;
			}

			return baseField;
		} );

		return [ GLOBAL_FIELD, ...sectionActionsFields, ...featureFields ];
	}, [ featureDefinitions, featureGroups, globalEnabled, handleChange ] );

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
			const actionFieldId = `section-actions-${ group.id }`;
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
				children: [ ...children, actionFieldId ],
			} );
		}

		for ( const [ category, children ] of groupedFields.entries() ) {
			if ( children.length === 0 || seenCategories.has( category ) ) {
				continue;
			}

			const actionFieldId = `section-actions-${ category }`;
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
				children: [ ...children, actionFieldId ],
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
				<div className="ai-settings-page__actions">
					<ExternalLink href="https://github.com/WordPress/ai/tree/develop/docs">
						{ __( 'Docs', 'ai' ) }
					</ExternalLink>
					<ExternalLink href="https://github.com/WordPress/ai/blob/develop/CONTRIBUTING.md">
						{ __( 'Contribute', 'ai' ) }
					</ExternalLink>
				</div>
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
