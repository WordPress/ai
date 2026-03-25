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

function AIIcon() {
	return (
		<svg
			width="1em"
			height="1em"
			viewBox="50 30 160 200"
			style={ { verticalAlign: '-0.025em' } }
			fill="none"
			xmlns="http://www.w3.org/2000/svg"
			aria-hidden="true"
		>
			<path
				d="M193.227 204.475L160.753 120.186V89.1724H173.497V77.0521H82.4936V89.1724H95.2377V120.186L62.7643 204.475C62.2666 205.794 62 207.185 62 208.593C62 214.885 67.1012 220 73.3755 220H182.615C184.02 220 185.406 219.733 186.721 219.234C192.587 216.97 195.502 210.357 193.227 204.475ZM107.324 122.45V89.5288H148.667V122.45L164.823 164.389C161.144 163.445 157.341 162.963 153.466 162.963C142.588 162.963 132.279 166.796 124.085 173.658C118.038 178.724 110.405 181.494 102.525 181.482C96.7129 181.482 91.1318 180.003 86.2084 177.258L107.324 122.45ZM74.4064 207.88L81.8182 188.666C88.1636 191.892 95.2199 193.621 102.543 193.621C113.421 193.621 123.73 189.788 131.924 182.926C137.949 177.9 145.485 175.102 153.484 175.102C159.705 175.102 165.641 176.795 170.831 179.932L181.585 207.88H74.4064Z"
				fill="currentColor"
			/>
			<path
				d="M126.024 95.7743L126.935 98.243C128.129 101.48 128.726 103.099 129.904 104.279C131.081 105.46 132.695 106.059 135.923 107.257L138.385 108.17L135.923 109.084C132.695 110.282 131.081 110.881 129.904 112.061C128.726 113.242 128.129 114.86 126.935 118.098L126.024 120.566L125.113 118.098C123.918 114.86 123.321 113.242 122.144 112.061C120.966 110.881 119.352 110.282 116.124 109.084L113.662 108.17L116.124 107.257C119.352 106.059 120.966 105.46 122.144 104.279C123.321 103.099 123.918 101.48 125.113 98.243L126.024 95.7743Z"
				fill="currentColor"
			/>
			<path
				d="M127.882 54.0236L128.527 55.7728C129.373 58.0666 129.797 59.2135 130.631 60.0501C131.465 60.8868 132.609 61.3111 134.896 62.1599L136.641 62.8072L134.896 63.4545C132.609 64.3032 131.465 64.7276 130.631 65.5643C129.797 66.4009 129.373 67.5478 128.527 69.8416L127.882 71.5908L127.236 69.8416C126.39 67.5478 125.967 66.4009 125.132 65.5643C124.298 64.7276 123.154 64.3032 120.867 63.4545L119.123 62.8072L120.867 62.1599C123.154 61.3111 124.298 60.8868 125.132 60.0501C125.967 59.2135 126.39 58.0666 127.236 55.7728L127.882 54.0236Z"
				fill="currentColor"
			/>
			<path
				d="M140.622 36L141.083 37.2495C141.688 38.8879 141.99 39.7071 142.586 40.3047C143.182 40.9023 143.999 41.2054 145.633 41.8117L146.879 42.274L145.633 42.7364C143.999 43.3426 143.182 43.6458 142.586 44.2434C141.99 44.841 141.688 45.6602 141.083 47.2986L140.622 48.548L140.161 47.2986C139.557 45.6602 139.254 44.841 138.658 44.2434C138.062 43.6458 137.245 43.3426 135.612 42.7364L134.366 42.274L135.612 41.8117C137.245 41.2054 138.062 40.9023 138.658 40.3047C139.254 39.7071 139.557 38.8879 140.161 37.2495L140.622 36Z"
				fill="currentColor"
			/>
		</svg>
	);
}

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
			children: [ 'wpai_feature_abilities-explorer_enabled' ],
		},
	],
};

function AISettingsPage() {
	const [ data, setData ] = useState< AISettings >( DEFAULT_SETTINGS );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ hasEdits, setHasEdits ] = useState( false );

	// eslint-disable-next-line dot-notation
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

	const handleChange = useCallback( ( edits: Record< string, unknown > ) => {
		setData( ( prev ) => ( {
			...prev,
			...( edits as AISettings ),
		} ) );
		setHasEdits( true );
	}, [] );

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
