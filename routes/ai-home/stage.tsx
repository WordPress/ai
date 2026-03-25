/**
 * WordPress dependencies
 */
import { Page } from '@wordpress/admin-ui';
import apiFetch from '@wordpress/api-fetch';
import { Button, CheckboxControl, Spinner } from '@wordpress/components';
import { DataForm } from '@wordpress/dataviews';
import { useState, useEffect, useCallback, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { Field, Form } from '@wordpress/dataviews';

/**
 * Internal dependencies
 */
import './style.scss';

type AISettings = Record< string, boolean >;

const AI_SETTING_KEYS = [
	'wpai_features_enabled',
	'wpai_feature_excerpt-generation_enabled',
	'wpai_feature_title-generation_enabled',
	'wpai_feature_alt-text-generation_enabled',
	'wpai_feature_summarization_enabled',
	'wpai_feature_image-generation_enabled',
	'wpai_feature_review-notes_enabled',
	'wpai_feature_abilities-explorer_enabled',
];

const DEFAULT_SETTINGS: AISettings = Object.fromEntries(
	AI_SETTING_KEYS.map( ( key ) => [ key, false ] )
);

const GLOBAL_FIELD: Field< AISettings > = {
	id: 'wpai_features_enabled',
	label: __( 'Enable AI', 'ai' ),
	type: 'boolean',
};

const EXPERIMENT_FIELDS: Field< AISettings >[] = [
	{
		id: 'wpai_feature_excerpt-generation_enabled',
		label: __( 'Excerpt Generation', 'ai' ),
		description: __(
			'Generates excerpt suggestions from content',
			'ai'
		),
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
		description: __(
			'Generates title suggestions from content',
			'ai'
		),
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

function DisabledCheckbox( { field, data }: any ) {
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
			children: [
				'wpai_feature_abilities-explorer_enabled',
			],
		},
	],
};

function AISettingsPage() {
	const [ data, setData ] = useState< AISettings >( DEFAULT_SETTINGS );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ hasEdits, setHasEdits ] = useState( false );

	const globalEnabled = data[ 'wpai_features_enabled' ];

	const fields = useMemo< Field< AISettings >[] >(
		() => [
			GLOBAL_FIELD,
			...EXPERIMENT_FIELDS.map( ( field ) => ( {
				...field,
				Edit: globalEnabled
					? ( 'checkbox' as const )
					: DisabledCheckbox,
			} ) ),
		],
		[ globalEnabled ]
	);

	useEffect( () => {
		apiFetch< Record< string, unknown > >( {
			path: '/wp/v2/settings',
		} ).then( ( settings ) => {
			const aiSettings: AISettings = { ...DEFAULT_SETTINGS };
			for ( const key of AI_SETTING_KEYS ) {
				if ( key in settings ) {
					aiSettings[ key ] = Boolean( settings[ key ] ?? false );
				}
			}
			setData( aiSettings );
			setIsLoading( false );
		} );
	}, [] );

	const handleChange = useCallback(
		( edits: Record< string, unknown > ) => {
			setData( ( prev ) => ( {
				...prev,
				...( edits as AISettings ),
			} ) );
			setHasEdits( true );
		},
		[]
	);

	const handleSave = useCallback( async () => {
		setIsSaving( true );
		try {
			await apiFetch( {
				path: '/wp/v2/settings',
				method: 'POST',
				data,
			} );
			setHasEdits( false );
		} finally {
			setIsSaving( false );
		}
	}, [ data ] );

	return (
		<Page
			title={ __( 'AI', 'ai' ) }
			subTitle={ __(
				'Configure AI features and experiments for your WordPress site.',
				'ai'
			) }
		>
			<div className="ai-settings-page">
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

function Stage() {
	return <AISettingsPage />;
}

export const stage = Stage;
