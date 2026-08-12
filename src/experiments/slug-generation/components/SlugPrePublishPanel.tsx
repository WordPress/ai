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
import { useEffect, useRef, useState } from '@wordpress/element';
import { update, check } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { hasMinimumContent } from '../../../utils/character-count';
import {
	applySlug,
	describeSlugApplication,
	useSlugGeneration,
	useSlugSource,
} from '../hooks/useSlugGeneration';
import { getSettings } from '../settings';

const NOTICE_ID = 'ai_slug_prepublish_error';

/**
 * Renders the pre-publish sidebar panel for generating and applying slug suggestions.
 *
 * @return The panel component.
 */
export default function SlugPrePublishPanel(): React.JSX.Element {
	const { postId, title, content, currentSlug } = useSlugSource();
	const { suggestions, isGenerating, generate } = useSlugGeneration( {
		noticeId: NOTICE_ID,
	} );
	const [ selectedSlug, setSelectedSlug ] = useState( '' );
	const applyButtonRef = useRef< HTMLButtonElement >( null );

	/*
	 * Preselect the first suggestion whenever a new set arrives, and move focus to
	 * Apply, which only mounts once there are suggestions to apply. Keyed on
	 * `suggestions` so this runs once per generation rather than stealing focus
	 * while the slug is edited.
	 */
	useEffect( () => {
		if ( suggestions.length === 0 ) {
			return;
		}

		setSelectedSlug( suggestions[ 0 ] ?? '' );
		applyButtonRef.current?.focus();
	}, [ suggestions ] );

	const { minContentLength } = getSettings();
	const isContentTooShort = ! hasMinimumContent( content, minContentLength );

	/*
	 * Edits are normalized on apply, so the applied state is derived from the
	 * normalized form. Comparing the raw text against the stored slug would
	 * keep the button on "Apply" forever after applying an edited value.
	 */
	const { cleanedSlug, help } = describeSlugApplication( selectedSlug );
	const isApplied = !! cleanedSlug && cleanedSlug === currentSlug;

	const handleGenerate = () => {
		if ( isGenerating ) {
			return;
		}

		generate( { postId, title, content } );
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

	let generateLabel: string = __( 'Generate Suggestions', 'ai' );
	if ( isGenerating ) {
		generateLabel = __( 'Generating…', 'ai' );
	} else if ( suggestions.length > 0 ) {
		generateLabel = __( 'Regenerate', 'ai' );
	}

	return (
		<div className="ai-slug-prepublish-content">
			<div className="ai-slug-prepublish-current">
				<strong>{ __( 'Current Slug:', 'ai' ) }</strong>{ ' ' }
				<code>{ currentSlug || __( '(no slug set)', 'ai' ) }</code>
			</div>

			{ isGenerating && suggestions.length === 0 ? (
				<div className="ai-slug-prepublish-spinner-container">
					<Spinner />
					<span>{ __( 'Generating suggestions…', 'ai' ) }</span>
				</div>
			) : (
				suggestions.length > 0 && (
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
							disabled={ isGenerating }
							help={ isApplied ? undefined : help }
						/>
					</div>
				)
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
						disabled={ isGenerating }
						isBusy={ isGenerating }
						accessibleWhenDisabled
						__next40pxDefaultSize
					>
						{ generateLabel }
					</Button>
				</FlexItem>
				{ suggestions.length > 0 && (
					<FlexItem>
						<Button
							ref={ applyButtonRef }
							variant="primary"
							icon={ check }
							onClick={ () => applySlug( selectedSlug ) }
							disabled={
								isGenerating || ! cleanedSlug || isApplied
							}
							accessibleWhenDisabled
							__next40pxDefaultSize
						>
							{ isApplied
								? __( 'Applied', 'ai' )
								: __( 'Apply', 'ai' ) }
						</Button>
					</FlexItem>
				) }
			</Flex>
		</div>
	);
}
