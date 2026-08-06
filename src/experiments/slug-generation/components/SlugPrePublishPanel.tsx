/**
 * WordPress dependencies
 */
import {
	Button,
	Flex,
	FlexItem,
	RadioControl,
	TextControl,
	Spinner,
} from '@wordpress/components';
import { dispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useState } from '@wordpress/element';
import { update, check } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';
import { ensureProvider } from '../../../utils/provider-status';
import { hasMinimumContent } from '../../../utils/character-count';
import type {
	SlugGenerationAbilityInput,
	GeneratedSlugData,
	SlugGenerationData,
} from '../types';

const NOTICE_ID = 'ai_slug_prepublish_error';
const MINIMUM_CONTENT_COUNT_DEFAULT = 250;
const NUMBER_OF_SUGGESTIONS_DEFAULT = 3;

const getSettings = (): SlugGenerationData => {
	const settings = window.aiSlugGenerationData ?? {};

	return {
		enabled: settings.enabled ?? false,
		minContentLength:
			settings.minContentLength ?? MINIMUM_CONTENT_COUNT_DEFAULT,
		numberOfSuggestions:
			settings.numberOfSuggestions ?? NUMBER_OF_SUGGESTIONS_DEFAULT,
	};
};

/**
 * Renders the pre-publish sidebar panel for generating and applying slug suggestions.
 *
 * @return The panel component.
 */
export default function SlugPrePublishPanel(): React.JSX.Element | null {
	const { postId, title, content, currentSlug } = useSelect( ( select ) => {
		const editor = select( editorStore );
		const rawSlug =
			( editor.getEditedPostAttribute( 'slug' ) as string ) ?? '';
		const generatedSlug =
			( editor.getEditedPostAttribute( 'generated_slug' ) as string ) ?? '';

		return {
			postId: editor.getCurrentPostId(),
			title: ( editor.getEditedPostAttribute( 'title' ) as string ) ?? '',
			content: ( editor.getEditedPostContent() as string ) ?? '',
			currentSlug: rawSlug || generatedSlug,
		};
	}, [] );

	const [ isGenerating, setIsGenerating ] = useState( false );
	const [ suggestions, setSuggestions ] = useState< string[] >( [] );
	const [ selectedSlug, setSelectedSlug ] = useState( '' );

	const settings = getSettings();
	const minContentLength = settings.minContentLength;
	const isContentTooShort = ! hasMinimumContent( content, minContentLength );

	const handleGenerate = async () => {
		if ( isGenerating ) {
			return;
		}

		if ( ! ensureProvider( NOTICE_ID ) ) {
			return;
		}

		setIsGenerating( true );
		dispatch( noticesStore ).removeNotice( NOTICE_ID );

		try {
			const params: SlugGenerationAbilityInput = {
				title,
				content,
				context: postId ? postId.toString() : '',
				number_of_suggestions: settings.numberOfSuggestions,
			};

			const response = await runAbility< GeneratedSlugData >(
				'ai/slug-generation',
				params
			);

			if (
				response &&
				typeof response === 'object' &&
				'slugs' in response &&
				Array.isArray( response.slugs ) &&
				response.slugs.length > 0
			) {
				setSuggestions( response.slugs );
				setSelectedSlug( response.slugs[ 0 ] ?? '' );
			} else {
				throw new Error(
					__( 'No slug suggestion was generated.', 'ai' )
				);
			}
		} catch ( error: any ) {
			const message =
				typeof error === 'string'
					? error
					: error?.message ?? __( 'Failed to generate slug.', 'ai' );
			dispatch( noticesStore ).createErrorNotice( message, {
				id: NOTICE_ID,
				isDismissible: true,
			} );
		} finally {
			setIsGenerating( false );
		}
	};

	const handleApply = () => {
		if ( selectedSlug ) {
			dispatch( editorStore ).editPost( {
				slug: selectedSlug,
			} );
		}
	};

	const tooShortLabel = sprintf(
		/* translators: %d: minimum number of characters required. */
		__(
			'Slug suggestions will be available when the post content has at least %d characters.',
			'ai'
		),
		minContentLength
	);

	if ( isContentTooShort ) {
		return (
			<p className="ai-slug-prepublish-too-short-notice">
				{ tooShortLabel }
			</p>
		);
	}

	return (
		<div className="ai-slug-prepublish-content">
			<div className="ai-slug-prepublish-current">
				<strong>{ __( 'Current Slug:', 'ai' ) }</strong>{ ' ' }
				<code>{ currentSlug || __( '(no slug set)', 'ai' ) }</code>
			</div>

			{ isGenerating ? (
				<div className="ai-slug-prepublish-spinner-container">
					<Spinner />
					<span>{ __( 'Generating suggestions…', 'ai' ) }</span>
				</div>
			) : (
				<>
					{ suggestions.length > 0 && (
						<div className="ai-slug-prepublish-suggestions">
							<RadioControl
								label={ __( 'Suggested Slugs', 'ai' ) }
								selected={ selectedSlug }
								options={ suggestions.map( ( slug ) => ( {
									label: slug,
									value: slug,
								} ) ) }
								onChange={ setSelectedSlug }
							/>

							<TextControl
								label={ __( 'Customize slug', 'ai' ) }
								value={ selectedSlug }
								onChange={ setSelectedSlug }
								__nextHasNoMarginBottom
							/>
						</div>
					) }

					<Flex
						justify="center"
						gap="2"
						className="ai-slug-prepublish-actions"
					>
						<FlexItem>
							<Button
								variant="secondary"
								icon={ update }
								onClick={ handleGenerate }
								isBusy={ isGenerating }
								__next40pxDefaultSize
							>
								{ suggestions.length > 0
									? __( 'Regenerate', 'ai' )
									: __( 'Generate Suggestions', 'ai' ) }
							</Button>
						</FlexItem>
						{ suggestions.length > 0 && (
							<FlexItem>
								<Button
									variant="primary"
									icon={ check }
									onClick={ handleApply }
									disabled={
										! selectedSlug ||
										selectedSlug === currentSlug
									}
									__next40pxDefaultSize
								>
									{ selectedSlug === currentSlug
										? __( 'Applied', 'ai' )
										: __( 'Apply', 'ai' ) }
								</Button>
							</FlexItem>
						) }
					</Flex>
				</>
			) }
		</div>
	);
}
