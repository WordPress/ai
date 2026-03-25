/**
 * WordPress dependencies
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Button, Spinner } from '@wordpress/components';
import { DataForm } from '@wordpress/dataviews';
import { __ } from '@wordpress/i18n';
import type { Field, Form } from '@wordpress/dataviews';

type AISettings = {
	wpai_features_enabled: boolean;
	wpai_feature_excerpt_generation_enabled: boolean;
	wpai_feature_title_generation_enabled: boolean;
	wpai_feature_alt_text_generation_enabled: boolean;
	wpai_feature_summarization_enabled: boolean;
	wpai_feature_image_generation_enabled: boolean;
	wpai_feature_review_notes_enabled: boolean;
	wpai_feature_abilities_explorer_enabled: boolean;
	wpai_feature_example_experiment_enabled: boolean;
};

const AI_SETTING_KEYS: ( keyof AISettings )[] = [
	'wpai_features_enabled',
	'wpai_feature_excerpt_generation_enabled',
	'wpai_feature_title_generation_enabled',
	'wpai_feature_alt_text_generation_enabled',
	'wpai_feature_summarization_enabled',
	'wpai_feature_image_generation_enabled',
	'wpai_feature_review_notes_enabled',
	'wpai_feature_abilities_explorer_enabled',
	'wpai_feature_example_experiment_enabled',
];

const DEFAULT_SETTINGS: AISettings = {
	wpai_features_enabled: false,
	wpai_feature_excerpt_generation_enabled: false,
	wpai_feature_title_generation_enabled: false,
	wpai_feature_alt_text_generation_enabled: false,
	wpai_feature_summarization_enabled: false,
	wpai_feature_image_generation_enabled: false,
	wpai_feature_review_notes_enabled: false,
	wpai_feature_abilities_explorer_enabled: false,
	wpai_feature_example_experiment_enabled: false,
};

const fields: Field< AISettings >[] = [
	{
		id: 'wpai_features_enabled',
		label: __( 'Enable AI', 'ai' ),
		type: 'boolean',
	},
	{
		id: 'wpai_feature_excerpt_generation_enabled',
		label: __( 'Excerpt Generation', 'ai' ),
		description: __(
			'Generates excerpt suggestions from content',
			'ai'
		),
		type: 'boolean',
	},
	{
		id: 'wpai_feature_alt_text_generation_enabled',
		label: __( 'Alt Text Generation', 'ai' ),
		description: __(
			'Generates descriptive alt text for images using AI vision models.',
			'ai'
		),
		type: 'boolean',
	},
	{
		id: 'wpai_feature_image_generation_enabled',
		label: __( 'Image Generation and Editing', 'ai' ),
		description: __( 'Generate and edit images using AI', 'ai' ),
		type: 'boolean',
	},
	{
		id: 'wpai_feature_review_notes_enabled',
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
		id: 'wpai_feature_title_generation_enabled',
		label: __( 'Title Generation', 'ai' ),
		description: __(
			'Generates title suggestions from content',
			'ai'
		),
		type: 'boolean',
	},
	{
		id: 'wpai_feature_abilities_explorer_enabled',
		label: __( 'Abilities Explorer', 'ai' ),
		description: __(
			'Discover, inspect, test, and document all abilities registered via the WordPress Abilities API.',
			'ai'
		),
		type: 'boolean',
	},
	{
		id: 'wpai_feature_example_experiment_enabled',
		label: __( 'Example Experiment', 'ai' ),
		description: __(
			'Demonstrates the AI experiment system with example hooks and functionality.',
			'ai'
		),
		type: 'boolean',
	},
];

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
				'wpai_feature_excerpt_generation_enabled',
				'wpai_feature_alt_text_generation_enabled',
				'wpai_feature_image_generation_enabled',
				'wpai_feature_review_notes_enabled',
				'wpai_feature_summarization_enabled',
				'wpai_feature_title_generation_enabled',
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
				'wpai_feature_abilities_explorer_enabled',
				'wpai_feature_example_experiment_enabled',
			],
		},
	],
};

function AISettingsForm() {
	const [ data, setData ] = useState< AISettings >( DEFAULT_SETTINGS );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ hasEdits, setHasEdits ] = useState( false );

	useEffect( () => {
		apiFetch< Record< string, unknown > >( {
			path: '/wp/v2/settings',
		} ).then( ( settings ) => {
			const aiSettings: AISettings = { ...DEFAULT_SETTINGS };
			for ( const key of AI_SETTING_KEYS ) {
				if ( key in settings ) {
					aiSettings[ key ] = Boolean( settings[ key ] );
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
				...edits,
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

	if ( isLoading ) {
		return <Spinner />;
	}

	return (
		<div style={ { maxWidth: '800px' } }>
			<DataForm< AISettings >
				data={ data }
				fields={ fields }
				form={ form }
				onChange={ handleChange }
			/>
			<div style={ { marginTop: '16px' } }>
				<Button
					variant="primary"
					onClick={ handleSave }
					isBusy={ isSaving }
					disabled={ ! hasEdits || isSaving }
				>
					{ __( 'Save Changes', 'ai' ) }
				</Button>
			</div>
		</div>
	);
}

export function stage() {
	return <AISettingsForm />;
}
