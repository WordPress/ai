/**
 * WordPress dependencies
 */
import { Page } from '@wordpress/admin-ui';
import {
	Button,
	CheckboxControl,
	Notice,
	Spinner,
} from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect, useDispatch } from '@wordpress/data';
import { DataForm } from '@wordpress/dataviews';
import { useCallback, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import type { Field, Form } from '@wordpress/dataviews';

/**
 * Internal dependencies
 */
import './style.scss';
import AIIcon from './ai-icon';

type AISettings = Record< string, boolean >;

interface PageData {
	hasCredentials: boolean;
	hasValidCredentials: boolean;
	connectorsUrl: string;
}

function getPageData(): PageData {
	try {
		return JSON.parse(
			document.getElementById( 'wp-script-module-data-ai-wp-admin' )
				?.textContent ?? '{}'
		);
	} catch {
		return {
			hasCredentials: false,
			hasValidCredentials: false,
			connectorsUrl: '',
		};
	}
}

const GLOBAL_FIELD: Field< AISettings > = {
	id: 'wpai_features_enabled',
	label: __( 'Enable AI', 'ai' ),
	type: 'boolean',
};

const EXPERIMENT_FIELDS: Field< AISettings >[] = [
	{
		id: 'wpai_feature_excerpt-generation_enabled',
		label: __( 'Excerpt Generation', 'ai' ),
		description: __( 'Generates excerpt suggestions from content', 'ai' ),
		type: 'boolean',
	},
	{
		id: 'wpai_feature_alt-text-generation_enabled',
		label: __( 'Alt Text Generation', 'ai' ),
		description: __(
			'Generates descriptive alt text for images using AI vision models.',
			'ai'
		),
		type: 'boolean',
	},
	{
		id: 'wpai_feature_image-generation_enabled',
		label: __( 'Image Generation and Editing', 'ai' ),
		description: __( 'Generate and edit images using AI', 'ai' ),
		type: 'boolean',
	},
	{
		id: 'wpai_feature_review-notes_enabled',
		label: __( 'Review Notes', 'ai' ),
		description: __(
			'Reviews post content block-by-block and adds Notes with suggestions for Accessibility, Readability, Grammar, and SEO.',
			'ai'
		),
		type: 'boolean',
	},
	{
		id: 'wpai_feature_summarization_enabled',
		label: __( 'Content Summarization', 'ai' ),
		description: __(
			'Summarizes long-form content into digestible overviews',
			'ai'
		),
		type: 'boolean',
	},
	{
		id: 'wpai_feature_title-generation_enabled',
		label: __( 'Title Generation', 'ai' ),
		description: __( 'Generates title suggestions from content', 'ai' ),
		type: 'boolean',
	},
	{
		id: 'wpai_feature_abilities-explorer_enabled',
		label: __( 'Abilities Explorer', 'ai' ),
		description: __(
			'Discover, inspect, test, and document all abilities registered via the WordPress Abilities API.',
			'ai'
		),
		type: 'boolean',
	},
];

const AI_SETTING_KEYS = [ GLOBAL_FIELD, ...EXPERIMENT_FIELDS ].map(
	( f ) => f.id
);

function DisabledCheckbox( {
	field,
	data,
}: {
	field: {
		label: string;
		description?: string;
		getValue: ( args: { item: AISettings } ) => unknown;
	};
	data: AISettings;
} ) {
	return (
		<CheckboxControl
			__nextHasNoMarginBottom
			label={ field.label }
			help={ field.description }
			checked={ !! field.getValue( { item: data } ) }
			onChange={ () => {} }
			disabled
		/>
	);
}

const form: Form = {
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
			children: [ 'wpai_features_enabled' ],
		},
		{
			id: 'editorExperiments',
			label: __( 'Editor Experiments', 'ai' ),
			description: __(
				'AI-powered experiments for the block editor, including content generation and enhancement tools.',
				'ai'
			),
			layout: {
				type: 'card',
				withHeader: true,
				isOpened: true,
				isCollapsible: true,
			},
			children: [
				'wpai_feature_excerpt-generation_enabled',
				'wpai_feature_alt-text-generation_enabled',
				'wpai_feature_image-generation_enabled',
				'wpai_feature_review-notes_enabled',
				'wpai_feature_summarization_enabled',
				'wpai_feature_title-generation_enabled',
			],
		},
		{
			id: 'adminExperiments',
			label: __( 'Admin Experiments', 'ai' ),
			description: __(
				'AI-powered experiments for the WordPress admin area, including exploration and testing tools.',
				'ai'
			),
			layout: {
				type: 'card',
				withHeader: true,
				isOpened: true,
				isCollapsible: true,
			},
			children: [ 'wpai_feature_abilities-explorer_enabled' ],
		},
	],
};

function AISettingsPage() {
	const pageData = getPageData();

	const { siteSettings, hasEdits, isSaving, isLoading } = useSelect(
		( select ) => {
			const store = select( coreStore ) as any;
			return {
				siteSettings: store.getEditedEntityRecord( 'root', 'site' ) as
					| Record< string, unknown >
					| undefined,
				hasEdits: store.hasEditsForEntityRecord(
					'root',
					'site'
				) as boolean,
				isSaving: store.isSavingEntityRecord(
					'root',
					'site'
				) as boolean,
				isLoading: ! store.hasFinishedResolution( 'getEntityRecord', [
					'root',
					'site',
				] ) as boolean,
			};
		},
		[]
	);

	const { editEntityRecord, saveEditedEntityRecord } =
		useDispatch( coreStore );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );

	const data: AISettings = useMemo( () => {
		const aiSettings: AISettings = {};
		for ( const key of AI_SETTING_KEYS ) {
			aiSettings[ key ] = Boolean( siteSettings?.[ key ] ?? false );
		}
		return aiSettings;
	}, [ siteSettings ] );

	const globalEnabled = data[ GLOBAL_FIELD.id ];

	const fields = useMemo< Field< AISettings >[] >(
		() => [
			GLOBAL_FIELD,
			...EXPERIMENT_FIELDS.map( ( field ) => ( {
				...field,
				Edit: globalEnabled
					? ( 'checkbox' as const )
					: ( DisabledCheckbox as any ),
			} ) ),
		],
		[ globalEnabled ]
	);

	const handleChange = useCallback(
		( edits: Record< string, unknown > ) => {
			// @ts-expect-error -- core-data types don't expose editEntityRecord for 'root'/'site' args.
			editEntityRecord( 'root', 'site', undefined, edits );
		},
		[ editEntityRecord ]
	);

	const handleSave = useCallback( async () => {
		try {
			// @ts-expect-error -- core-data types don't expose saveEditedEntityRecord for 'root'/'site' args.
			await saveEditedEntityRecord( 'root', 'site' );
			createSuccessNotice( __( 'Settings saved.', 'ai' ), {
				type: 'snackbar',
			} );
		} catch {
			createErrorNotice( __( 'Failed to save settings.', 'ai' ), {
				type: 'snackbar',
			} );
		}
	}, [ saveEditedEntityRecord, createSuccessNotice, createErrorNotice ] );

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
				{ ! pageData.hasValidCredentials && (
					<Notice status="error" isDismissible={ false }>
						{ ! pageData.hasCredentials
							? __(
									'The AI plugin requires a valid AI Connector to function properly. Verify you have one or more AI Connectors configured.',
									'ai'
							  )
							: __(
									'The AI plugin requires a valid AI Connector to function properly. Please review the AI Connectors you have configured to ensure they are valid.',
									'ai'
							  ) }{ ' ' }
						{ pageData.connectorsUrl && (
							<a href={ pageData.connectorsUrl }>
								{ __( 'Manage Connectors', 'ai' ) }
							</a>
						) }
					</Notice>
				) }
				{ isLoading ? (
					<Spinner />
				) : (
					<>
						<DataForm< AISettings >
							data={ data }
							fields={ fields }
							form={ form }
							onChange={ handleChange }
						/>
						<div className="ai-settings-page__save">
							<Button
								variant="primary"
								onClick={ handleSave }
								isBusy={ isSaving }
								disabled={ ! hasEdits || isSaving }
							>
								{ __( 'Save Changes', 'ai' ) }
							</Button>
						</div>
					</>
				) }
			</div>
		</Page>
	);
}
export const stage = AISettingsPage;
