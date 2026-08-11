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
import { useEffect, useState } from '@wordpress/element';
import { update, check } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { hasMinimumContent } from '../../../utils/character-count';
import {
	applySlug,
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

	// Preselect the first suggestion whenever a new set arrives.
	useEffect( () => {
		if ( suggestions.length > 0 ) {
			setSelectedSlug( suggestions[ 0 ] ?? '' );
		}
	}, [ suggestions ] );

	const { minContentLength } = getSettings();
	const isContentTooShort = ! hasMinimumContent( content, minContentLength );

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
									onClick={ () => applySlug( selectedSlug ) }
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
