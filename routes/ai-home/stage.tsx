/**
 * WordPress dependencies
 */
import { Page } from '@wordpress/admin-ui';
import { Button, Notice, Spinner, ToggleControl } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect, useDispatch } from '@wordpress/data';
import { DataForm } from '@wordpress/dataviews';
import { useCallback, useMemo } from '@wordpress/element';
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

interface FeatureData {
	id: string;
	settingName: string;
	label: string;
	description: string;
	category: string;
}

interface PageData {
	hasCredentials: boolean;
	hasValidCredentials: boolean;
	connectorsUrl: string;
	featureGroups: FeatureGroupData[];
	features: FeatureData[];
}

interface SelectAllToggleProps extends DataFormControlProps< AISettings > {
	featureSettingNames: string[];
	groupLabel: string;
	globalEnabled: boolean;
}

const FEATURE_SETTING_PATTERN = /^wpai_feature_(.+)_enabled$/;
const GLOBAL_FIELD_ID = 'wpai_features_enabled';
const SELECT_ALL_FIELD_PREFIX = 'wpai_select_all_';

function isRecord( value: unknown ): value is Record< string, unknown > {
	return typeof value === 'object' && value !== null;
}

function toStringValue( value: unknown ): string {
	return typeof value === 'string' ? value : '';
}

function isDefined< T >( value: T | null | undefined ): value is T {
	return value !== null && value !== undefined;
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

	return {
		id,
		settingName,
		label: toStringValue( feature.label ) || getDefaultLabel( id ),
		description: toStringValue( feature.description ),
		category: toStringValue( feature.category ) || 'other',
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

function getSelectAllFieldId( groupId: string ): string {
	return `${ SELECT_ALL_FIELD_PREFIX }${ groupId.replace(
		/[^a-zA-Z0-9_-]/g,
		'_'
	) }`;
}

function createSelectAllEditComponent(
	featureSettingNames: string[],
	groupLabel: string,
	globalEnabled: boolean
): React.ComponentType< DataFormControlProps< AISettings > > {
	return function SelectAllEdit( props ) {
		return (
			<SelectAllToggle
				{ ...props }
				featureSettingNames={ featureSettingNames }
				groupLabel={ groupLabel }
				globalEnabled={ globalEnabled }
			/>
		);
	};
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

function SelectAllToggle( {
	data,
	onChange,
	featureSettingNames,
	groupLabel,
	globalEnabled,
}: SelectAllToggleProps ) {
	const enabledCount = featureSettingNames.filter(
		( settingName ) => !! data[ settingName ]
	).length;

	const isAllEnabled = enabledCount === featureSettingNames.length;

	// Dynamic label based on current state
	const label = isAllEnabled
		? sprintf(
				// translators: %s: Group label (e.g., "Editor Experiments")
				__( 'Disable all %s', 'ai' ),
				groupLabel
		  )
		: sprintf(
				// translators: %s: Group label (e.g., "Editor Experiments")
				__( 'Enable all %s', 'ai' ),
				groupLabel
		  );

	const handleToggle = useCallback(
		( checked: boolean ) => {
			const updates: Record< string, boolean > = {};

			for ( const settingName of featureSettingNames ) {
				updates[ settingName ] = checked;
			}

			onChange( updates );
		},
		[ featureSettingNames, onChange ]
	);

	return (
		<div className="ai-select-all-toggle">
			<ToggleControl
				__nextHasNoMarginBottom
				label={ label }
				checked={ isAllEnabled }
				onChange={ handleToggle }
				disabled={ ! globalEnabled }
			/>
		</div>
	);
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

		// Add select-all field IDs (they're virtual, not saved to DB)
		for ( const group of featureGroups ) {
			settingKeys.add( getSelectAllFieldId( group.id ) );
		}

		return Array.from( settingKeys );
	}, [ featureDefinitions, featureGroups ] );

	const data: AISettings = useMemo( () => {
		const aiSettings: AISettings = {};
		for ( const key of aiSettingKeys ) {
			aiSettings[ key ] = Boolean( siteSettings?.[ key ] ?? false );
		}
		return aiSettings;
	}, [ aiSettingKeys, siteSettings ] );

	const globalEnabled = Boolean( data[ GLOBAL_FIELD.id ] );

	const fields = useMemo< Field< AISettings >[] >( () => {
		// Group features by category to create select-all fields
		const groupedFeatures = new Map< string, FeatureData[] >();
		for ( const feature of featureDefinitions ) {
			const category = feature.category || 'other';
			const categoryFeatures = groupedFeatures.get( category ) ?? [];
			categoryFeatures.push( feature );
			groupedFeatures.set( category, categoryFeatures );
		}

		// Create select-all fields for each group
		const selectAllFields: Field< AISettings >[] = [];
		for ( const group of featureGroups ) {
			const groupFeatures = groupedFeatures.get( group.id ) ?? [];
			if ( groupFeatures.length === 0 ) {
				continue;
			}

			const featureSettingNames = groupFeatures.map(
				( f ) => f.settingName
			);

			selectAllFields.push( {
				id: getSelectAllFieldId( group.id ),
				label: '', // Dynamic label set by SelectAllToggle component
				type: 'boolean',
				Edit: createSelectAllEditComponent(
					featureSettingNames,
					group.label,
					globalEnabled
				),
			} );
		}

		// Create individual feature fields
		const featureFields = featureDefinitions.map( ( feature ) => ( {
			id: feature.settingName,
			label: feature.label,
			description: feature.description,
			type: 'boolean' as const,
			Edit: globalEnabled ? ( 'toggle' as const ) : DisabledToggle,
		} ) );

		return [ GLOBAL_FIELD, ...selectAllFields, ...featureFields ];
	}, [ featureDefinitions, featureGroups, globalEnabled ] );

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

			// Add select-all field as first child
			const selectAllFieldId = getSelectAllFieldId( group.id );
			const sectionChildren = [ selectAllFieldId, ...children ];

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
				children: sectionChildren,
			} );
		}

		for ( const [ category, children ] of groupedFields.entries() ) {
			if ( children.length === 0 || seenCategories.has( category ) ) {
				continue;
			}

			// Add select-all field as first child
			const selectAllFieldId = getSelectAllFieldId( category );
			const sectionChildren = [ selectAllFieldId, ...children ];

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
				children: sectionChildren,
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
			// Filter out virtual select-all fields - they shouldn't be saved
			const actualEdits: Record< string, unknown > = {};
			for ( const [ key, value ] of Object.entries( edits ) ) {
				if ( ! key.startsWith( SELECT_ALL_FIELD_PREFIX ) ) {
					actualEdits[ key ] = value;
				}
			}

			// If no actual edits after filtering, skip
			if ( Object.keys( actualEdits ).length === 0 ) {
				return;
			}

			// @ts-expect-error -- core-data types don't expose editEntityRecord for 'root'/'site' args.
			editEntityRecord( 'root', 'site', undefined, actualEdits );

			// Determine success message
			let message: string;
			const editCount = Object.keys( actualEdits ).length;

			if ( editCount > 1 ) {
				// Bulk edit message
				const enabledCount =
					Object.values( actualEdits ).filter( Boolean ).length;
				if ( enabledCount === editCount ) {
					message = sprintf(
						// translators: %d: number of experiments.
						__( '%d experiments enabled.', 'ai' ),
						editCount
					);
				} else if ( enabledCount === 0 ) {
					message = sprintf(
						// translators: %d: number of experiments.
						__( '%d experiments disabled.', 'ai' ),
						editCount
					);
				} else {
					message = sprintf(
						// translators: %d: number of experiments.
						__( '%d experiments updated.', 'ai' ),
						editCount
					);
				}
			} else {
				// Single edit message
				const entry = Object.entries( actualEdits )[ 0 ];
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
			}

			try {
				// @ts-expect-error -- core-data types don't expose saveEditedEntityRecord for 'root'/'site' args.
				await saveEditedEntityRecord( 'root', 'site' );
				createSuccessNotice( message, { type: 'snackbar' } );
			} catch {
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
