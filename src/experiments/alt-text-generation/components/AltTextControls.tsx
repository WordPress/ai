/**
 * Alt text generation controls for the image block inspector.
 */

/**
 * WordPress dependencies
 */
import {
	Button,
	TextareaControl,
	Spinner,
	Notice,
} from '@wordpress/components';
import {
	InspectorControls,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { dispatch, select } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { store as editorStore } from '@wordpress/editor';
/* eslint-disable import/no-extraneous-dependencies -- @wordpress/blocks is in dependencies; types are in devDependencies */
import { serialize } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import type {
	AltTextGenerationAbilityInput,
	ImageBlockAttributes,
} from '../types';
import { runAbility } from '../../../utils/run-ability';

const IMAGE_PLACEHOLDER = '[[IMAGE_GOES_HERE]]';

interface AltTextControlsProps {
	clientId: string;
	attributes: ImageBlockAttributes;
	setAttributes: ( attributes: Partial< ImageBlockAttributes > ) => void;
}

/**
 * Replaces the current image block markup in post content with a placeholder.
 *
 * @param {string} content  Full post content.
 * @param {string} clientId Client ID of the current image block.
 * @return {string} Content with this image block replaced by the placeholder.
 */
function replaceImageBlockWithPlaceholder(
	content: string,
	clientId: string
): string {
	// eslint-disable-next-line dot-notation -- getBlock from store index signature
	const block = select( blockEditorStore )[ 'getBlock' ]( clientId );
	if ( ! block ) {
		return content;
	}

	const serializedBlock = serialize( block );
	if ( ! serializedBlock || ! content.includes( serializedBlock ) ) {
		return content;
	}

	return content.replace( serializedBlock, IMAGE_PLACEHOLDER );
}

/**
 * Generates alt text for an image using the AI ability.
 *
 * @param {number|undefined} attachmentId The attachment ID.
 * @param {string|undefined} imageUrl     The image URL (fallback if no attachment ID).
 * @param {string|undefined} content      The content of the post.
 * @param {string|undefined} clientId     The client ID of the current image block.
 * @return {Promise<string>} The generated alt text.
 */
async function generateAltText(
	attachmentId: number | undefined,
	imageUrl: string | undefined,
	content: string | undefined,
	clientId: string | undefined
): Promise< string > {
	const params: AltTextGenerationAbilityInput = {};

	if ( attachmentId ) {
		params.attachment_id = attachmentId;
	} else if ( imageUrl ) {
		params.image_url = imageUrl;
	} else {
		throw new Error(
			__( 'No image available to generate alt text for.', 'ai' )
		);
	}

	if ( content ) {
		// Replace the image block with the placeholder.
		const contentWithPlaceholder =
			clientId !== undefined
				? replaceImageBlockWithPlaceholder( content, clientId )
				: content;

		// Prepare the context.
		params.context = `What follows is the full article content, where the image has been replaced with the placeholder ${ IMAGE_PLACEHOLDER }. Use the surrounding text to understand the purpose, subject, and relevance of the image within the article. Be sure to describe only information not already conveyed in nearby text. CONTENT: \n\n${ contentWithPlaceholder }`;
	}

	const response = await runAbility( 'ai/alt-text-generation', params );

	if ( response && typeof response === 'object' && 'alt_text' in response ) {
		return response.alt_text as string;
	}

	throw new Error( __( 'Failed to generate alt text.', 'ai' ) );
}

/**
 * Returns the appropriate button label based on state.
 *
 * @param {boolean} hasExistingAlt Whether the image has existing alt text.
 * @return {string} The button label.
 */
function getButtonLabel( hasExistingAlt: boolean ): string {
	return hasExistingAlt
		? __( 'Regenerate Alt Text', 'ai' )
		: __( 'Generate Alt Text', 'ai' );
}

/**
 * AltTextControls component.
 *
 * Adds a "Generate Alt Text" button to the image block inspector panel.
 *
 * @param {AltTextControlsProps} props               The component props.
 * @param {string}               props.clientId      The block client ID.
 * @param {ImageBlockAttributes} props.attributes    The block attributes.
 * @param {Function}             props.setAttributes The function to set the block attributes.
 * @return {JSX.Element|null} The component.
 */
export function AltTextControls( {
	clientId,
	attributes,
	setAttributes,
}: AltTextControlsProps ): JSX.Element | null {
	const { id: attachmentId, url: imageUrl, alt } = attributes;

	const [ isGenerating, setIsGenerating ] = useState< boolean >( false );
	const [ generatedAlt, setGeneratedAlt ] = useState< string | null >( null );
	const [ error, setError ] = useState< string | null >( null );

	// Don't show controls if there's no image.
	if ( ! attachmentId && ! imageUrl ) {
		return null;
	}

	const hasExistingAlt = alt && alt.trim().length > 0;
	const hasGeneratedAlt = generatedAlt !== null;

	/**
	 * Handles the generate button click.
	 */
	const handleGenerate = async () => {
		setIsGenerating( true );
		setError( null );
		setGeneratedAlt( null );

		// Clear any previous notices.
		( dispatch( noticesStore ) as any ).removeNotice(
			'ai_alt_text_generation_error'
		);

		try {
			const content = select( editorStore ).getEditedPostContent();
			const result = await generateAltText(
				attachmentId,
				imageUrl,
				content,
				clientId
			);
			setGeneratedAlt( result );
		} catch ( err: any ) {
			const errorMessage =
				err?.message ||
				__( 'An error occurred while generating alt text.', 'ai' );
			setError( errorMessage );
			( dispatch( noticesStore ) as any ).createErrorNotice(
				errorMessage,
				{
					id: 'ai_alt_text_generation_error',
					isDismissible: true,
				}
			);
		} finally {
			setIsGenerating( false );
		}
	};

	/**
	 * Applies the generated alt text to the image block.
	 */
	const handleApply = () => {
		if ( generatedAlt ) {
			setAttributes( { alt: generatedAlt } );
			setGeneratedAlt( null );
		}
	};

	/**
	 * Dismisses the generated alt text suggestion.
	 */
	const handleDismiss = () => {
		setGeneratedAlt( null );
		setError( null );
	};

	return (
		<InspectorControls>
			<div className="ai-alt-text-controls" style={ { padding: '16px' } }>
				<h3 style={ { marginTop: 0, marginBottom: '8px' } }>
					{ __( 'AI Alternative Text', 'ai' ) }
				</h3>

				{ /* Error display */ }
				{ error && (
					<Notice
						status="error"
						isDismissible
						onRemove={ () => setError( null ) }
					>
						<div style={ { marginBottom: '12px' } }>{ error }</div>
					</Notice>
				) }

				{ /* Generated alt text preview */ }
				{ hasGeneratedAlt && (
					<div style={ { marginBottom: '12px' } }>
						<TextareaControl
							label={ __( 'Generated Alt Text', 'ai' ) }
							hideLabelFromVision
							value={ generatedAlt || '' }
							onChange={ ( value ) => setGeneratedAlt( value ) }
							rows={ 3 }
							__nextHasNoMarginBottom
						/>
						<div
							style={ {
								display: 'flex',
								gap: '8px',
								marginTop: '8px',
							} }
						>
							<Button variant="primary" onClick={ handleApply }>
								{ __( 'Apply', 'ai' ) }
							</Button>
							<Button
								variant="secondary"
								onClick={ handleDismiss }
							>
								{ __( 'Dismiss', 'ai' ) }
							</Button>
						</div>
					</div>
				) }

				{ /* Generate button */ }
				{ ! hasGeneratedAlt && (
					<Button
						variant="secondary"
						onClick={ handleGenerate }
						disabled={ isGenerating }
						style={ { width: '100%', justifyContent: 'center' } }
					>
						{ isGenerating ? (
							<>
								<Spinner />
								<span style={ { marginLeft: '8px' } }>
									{ __( 'Generating…', 'ai' ) }
								</span>
							</>
						) : (
							getButtonLabel( !! hasExistingAlt )
						) }
					</Button>
				) }
			</div>
		</InspectorControls>
	);
}
