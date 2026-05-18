/**
 * WordPress dependencies
 */
import { Page } from '@wordpress/admin-ui';
import {
	Button,
	Card,
	Icon,
	Link,
	Notice,
	Popover,
	Stack,
	VisuallyHidden,
} from '@wordpress/ui';
import {
	DropdownMenu,
	MenuGroup,
	MenuItem,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useDispatch, useRegistry, useSelect } from '@wordpress/data';
import type { DataFormControlProps, Field, Form } from '@wordpress/dataviews';
import { DataForm } from '@wordpress/dataviews';
import { useCallback, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	check as checkIcon,
	info as infoIcon,
	moreVertical as moreVerticalIcon,
} from '@wordpress/icons';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import AIIcon from './ai-icon';
import { DeveloperSettings } from './components/DeveloperSettings';
import { FeatureToggle } from './components/FeatureToggle';
import {
	DeveloperModeContext,
	useDeveloperMode,
	useDeveloperModeContext,
} from './hooks/use-developer-mode';
import './style.scss';

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
	stability: string;
	image: string;
	capability: string;
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
		stability: toStringValue( feature.stability ) || 'experimental',
		image: toStringValue( feature.image ),
		capability: toStringValue( feature.capability ) || 'text_generation',
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

// Pre-computed at module level so the reference is stable across re-renders.
// When this is non-empty, the featureDefinitions useMemo returns it directly,
// preventing unnecessary downstream re-computation when unrelated parts of the
// entity record change (e.g. saving developer settings).
const STABLE_FEATURE_DEFINITIONS: FeatureData[] = ( () => {
	const unique: FeatureData[] = [];
	const seen = new Set< string >();
	for ( const feature of PAGE_DATA.features ) {
		if ( ! seen.has( feature.settingName ) ) {
			seen.add( feature.settingName );
			unique.push( feature );
		}
	}
	return unique;
} )();

interface InfoTipProps {
	content: string;
}

function InfoTip( { content }: InfoTipProps ) {
	const title = __( 'More information', 'ai' );

	return (
		<Popover.Root>
			<Popover.Trigger
				openOnHover
				delay={ 200 }
				closeDelay={ 200 }
				aria-label={ title }
				className="ai-settings-page__infotip-trigger"
			>
				<Icon icon={ infoIcon } size={ 20 } />
			</Popover.Trigger>
			<Popover.Popup
				side="bottom"
				align="end"
				className="ai-settings-page__infotip-popover"
			>
				<Popover.Arrow />
				<VisuallyHidden render={ <Popover.Title /> }>
					{ title }
				</VisuallyHidden>
				<Popover.Description className="ai-settings-page__infotip-description">
					{ content }
				</Popover.Description>
			</Popover.Popup>
		</Popover.Root>
	);
}

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
				_n(
					'%d experiment enabled',
					'%d experiments enabled',
					count,
					'ai'
				),
				count
			);
		}
		if ( allDisabled ) {
			return sprintf(
				// translators: %d: Number of experiments.
				_n(
					'%d experiment disabled',
					'%d experiments disabled',
					count,
					'ai'
				),
				count
			);
		}
		// Just a fallback for mixed state (shouldn't happen with our buttons, but handle it).
		return sprintf(
			// translators: %d: Number of experiments.
			_n(
				'%d experiment updated',
				'%d experiments updated',
				count,
				'ai'
			),
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
		<Stack className="ai-section-actions" direction="row" gap="sm">
			<Button
				variant="outline"
				size="compact"
				onClick={ handleEnableAll }
				disabled={ ! globalEnabled || allEnabled }
			>
				{ __( 'Enable all', 'ai' ) }
			</Button>
			<Button
				variant="outline"
				size="compact"
				onClick={ handleDisableAll }
				disabled={ ! globalEnabled || allDisabled }
			>
				{ __( 'Disable all', 'ai' ) }
			</Button>
		</Stack>
	);
}

function InlineFeatureSettings( { feature }: { feature: FeatureData } ) {
	const fieldIds = useMemo(
		() => feature.settingsFields.map( ( f ) => f.id ),
		[ feature.settingsFields ]
	);

	const { editedRecord, nonTransientEdits } = useSelect( ( select ) => {
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
		<Stack direction="column" gap="md" className="ai-feature-settings-form">
			<DataForm< Record< string, unknown > >
				data={ data }
				fields={ fields }
				form={ form }
				onChange={ handleChange }
			/>
			{ isDirty && (
				<Stack align="flex-end" direction="row">
					<Button
						variant="solid"
						onClick={ handleSave }
						disabled={ isSaving }
						size="compact"
						aria-label={ sprintf(
							// translators: %s: Feature label.
							__( 'Save %s settings', 'ai' ),
							feature.label
						) }
						loadingAnnouncement={
							isSaving ? __( 'Saving settings…', 'ai' ) : ''
						}
						loading={ isSaving }
					>
						{ __( 'Save', 'ai' ) }
					</Button>
				</Stack>
			) }
		</Stack>
	);
}

const FEATURES_BY_SETTING = new Map(
	STABLE_FEATURE_DEFINITIONS.filter(
		( f ) => f.settingsFields.length > 0
	).map( ( f ) => [ f.settingName, f ] as const )
);

function FeatureToggleWithSettings( {
	field,
	data,
	onChange,
}: DataFormControlProps< AISettings > ) {
	const feature = FEATURES_BY_SETTING.get( field.id );
	const checked = !! field.getValue( { item: data } );
	const isDeveloperMode = useDeveloperModeContext();

	return (
		<div className="ai-feature-toggle-with-settings">
			<ToggleControl
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
			{ checked && isDeveloperMode && feature && (
				<DeveloperSettings
					featureId={ feature.id }
					capability={ feature.capability }
				/>
			) }
		</div>
	);
}

const VISUAL_CARD_FEATURES = new Map(
	STABLE_FEATURE_DEFINITIONS.filter(
		( f ) => f.stability === 'stable' && f.image !== ''
	).map( ( f ) => [ f.settingName, f ] as const )
);

function VisualCardToggle( {
	field,
	data,
	onChange,
}: DataFormControlProps< AISettings > ) {
	const feature = VISUAL_CARD_FEATURES.get( field.id );
	const globalEnabled = !! data[ GLOBAL_FIELD_ID ];
	const checked = !! field.getValue( { item: data } );
	const isDeveloperMode = useDeveloperModeContext();

	return (
		<Card.Root
			className={ `${
				! globalEnabled ? ' ai-showcase-card--disabled' : ''
			}` }
		>
			{ feature?.image && (
				<img alt="" loading="lazy" src={ feature.image } />
			) }
			<Card.Content>
				<ToggleControl
					label={ field.label }
					checked={ checked }
					onChange={ ( value ) =>
						onChange( { [ field.id ]: value } )
					}
					disabled={ ! globalEnabled }
					help={ field.description }
				/>
				{ checked && isDeveloperMode && feature && (
					<DeveloperSettings
						featureId={ feature.id }
						capability={ feature.capability }
					/>
				) }
			</Card.Content>
		</Card.Root>
	);
}

function AISettingsPage() {
	const { editedRecord, isLoading } = useSelect( ( select ) => {
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
	const { __experimentalSaveSpecifiedEntityEdits: saveSpecifiedEdits } =
		useDispatch( coreStore ) as any;
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );
	const registry = useRegistry();
	const { isDeveloperMode, toggleDeveloperMode } = useDeveloperMode();

	const featureDefinitions = useMemo< FeatureData[] >( () => {
		// Return the stable module-level reference when page data is available so
		// that downstream useMemos/useCallbacks don't re-run when unrelated parts
		// of the entity record change (e.g. saving developer settings).
		if ( STABLE_FEATURE_DEFINITIONS.length > 0 ) {
			return STABLE_FEATURE_DEFINITIONS;
		}

		// Fallback: derive from the entity record when page data is absent.
		const seen = new Set< string >();
		return Object.keys( editedRecord ?? {} )
			.filter( ( key ) => FEATURE_SETTING_PATTERN.test( key ) )
			.sort()
			.reduce< FeatureData[] >( ( acc, settingName ) => {
				if ( seen.has( settingName ) ) {
					return acc;
				}
				seen.add( settingName );
				const id = getFeatureIdFromSettingName( settingName );
				acc.push( {
					id,
					settingName,
					label: getDefaultLabel( id ),
					description: '',
					category: 'other',
					settingsFields: [],
					stability: 'experimental',
					image: '',
					capability: 'text_generation',
				} );
				return acc;
			}, [] );
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

	const globalEnabled = Boolean( data[ GLOBAL_FIELD_ID ] );
	const globalToggleDescription = __(
		'Control whether AI is enabled for your site. When disabled, all features and experiments will be inactive regardless of their individual settings.',
		'ai'
	);

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
			if ( experimentSettings.length <= 1 ) {
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

			if ( VISUAL_CARD_FEATURES.has( feature.settingName ) ) {
				baseField.Edit = VisualCardToggle;
			} else if ( ! globalEnabled ) {
				baseField.Edit = DisabledToggle;
			} else if ( feature.settingsFields.length > 0 ) {
				baseField.Edit = FeatureToggleWithSettings;
			} else {
				const featureId = feature.id;
				const featureCapability = feature.capability;
				baseField.Edit = ( props ) => (
					<FeatureToggle
						{ ...props }
						featureId={ featureId }
						capability={ featureCapability }
					/>
				);
			}

			return baseField;
		} );

		return [ ...sectionActionsFields, ...featureFields ];
	}, [ featureDefinitions, featureGroups, globalEnabled, handleChange ] );

	const form = useMemo< Form >( () => {
		const showcaseChildren: string[] = [];
		const groupedFields = new Map< string, string[] >();
		for ( const feature of featureDefinitions ) {
			if ( VISUAL_CARD_FEATURES.has( feature.settingName ) ) {
				showcaseChildren.push( feature.settingName );
			} else {
				const category = feature.category || 'other';
				const categoryFields = groupedFields.get( category ) ?? [];
				categoryFields.push( feature.settingName );
				groupedFields.set( category, categoryFields );
			}
		}

		const sectionFields: NonNullable< Form[ 'fields' ] > = [];

		// Add showcase section with row layout (2 per row).
		if ( showcaseChildren.length > 0 ) {
			const rows: NonNullable< Form[ 'fields' ] > = [];
			for ( let i = 0; i < showcaseChildren.length; i += 2 ) {
				rows.push( {
					id: `showcase-row-${ i }`,
					layout: { type: 'row' as const },
					children: showcaseChildren.slice( i, i + 2 ),
				} );
			}

			sectionFields.push( {
				id: 'feature-group-showcase',
				layout: {
					type: 'regular',
					labelPosition: 'none',
				},
				children: rows,
			} );
		}

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
				children:
					children.length > 1
						? [ ...children, actionFieldId ]
						: children,
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
				children:
					children.length > 1
						? [ ...children, actionFieldId ]
						: children,
			} );
		}

		return {
			fields: sectionFields,
		};
	}, [ featureDefinitions, featureGroups ] );

	return (
		<DeveloperModeContext.Provider value={ isDeveloperMode }>
			<Page
				visual={ <AIIcon /> }
				title={ __( 'AI', 'ai' ) }
				subTitle={ __(
					'Configure AI features and experiments for your WordPress site.',
					'ai'
				) }
				actions={
					<>
						<Stack align="center" gap="xs">
							<ToggleControl
								label={ __( 'Enable AI', 'ai' ) }
								checked={ globalEnabled }
								onChange={ ( checked ) => {
									void handleChange( {
										[ GLOBAL_FIELD_ID ]: checked,
									} );
								} }
								disabled={ isLoading }
							/>
							<InfoTip content={ globalToggleDescription } />
						</Stack>
						<Link
							href="https://github.com/WordPress/ai/tree/develop/docs"
							openInNewTab
						>
							{ __( 'Docs', 'ai' ) }
						</Link>
						<Link
							href="https://github.com/WordPress/ai/blob/develop/CONTRIBUTING.md"
							openInNewTab
						>
							{ __( 'Contribute', 'ai' ) }
						</Link>
						<DropdownMenu
							icon={ moreVerticalIcon }
							label={ __( 'Developer Tools', 'ai' ) }
						>
							{ () => (
								<MenuGroup
									label={ __( 'Developer Tools', 'ai' ) }
								>
									<MenuItem
										role="menuitemcheckbox"
										isSelected={ isDeveloperMode }
										info={ __(
											'Select a specific provider and model per feature',
											'ai'
										) }
										icon={
											isDeveloperMode ? checkIcon : null
										}
										onClick={ () => {
											toggleDeveloperMode();
										} }
									>
										{ __( 'Model selection', 'ai' ) }
									</MenuItem>
								</MenuGroup>
							) }
						</DropdownMenu>
					</>
				}
			>
				<Stack className="ai-settings-page" direction="column" gap="md">
					{ ! PAGE_DATA.hasValidCredentials && (
						<Notice.Root intent="error">
							<Notice.Description>
								{ ! PAGE_DATA.hasCredentials
									? __(
											'The AI plugin requires a valid AI Connector to function properly. Verify you have one or more AI Connectors configured.',
											'ai'
									  )
									: __(
											'The AI plugin requires a valid AI Connector to function properly. Please review the AI Connectors you have configured to ensure they are valid.',
											'ai'
									  ) }
							</Notice.Description>
							{ PAGE_DATA.connectorsUrl && (
								<Notice.Actions>
									<Notice.ActionLink
										href={ PAGE_DATA.connectorsUrl }
									>
										{ __( 'Manage Connectors', 'ai' ) }
									</Notice.ActionLink>
								</Notice.Actions>
							) }
						</Notice.Root>
					) }
					{ isLoading ? (
						<Stack
							align="center"
							className="ai-settings-page__loading"
							justify="center"
						>
							<Spinner />
						</Stack>
					) : (
						<DataForm< AISettings >
							data={ data }
							fields={ fields }
							form={ form }
							onChange={ handleChange }
						/>
					) }
				</Stack>
			</Page>
		</DeveloperModeContext.Provider>
	);
}
export const stage = AISettingsPage;
