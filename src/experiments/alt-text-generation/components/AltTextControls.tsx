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
import { InspectorControls } from '@wordpress/block-editor';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { dispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import type { ImageBlockAttributes } from '../types';
import { runAbility } from '../../../utils/run-ability';

interface AltTextControlsProps {
	attributes: ImageBlockAttributes;
	setAttributes: ( attributes: Partial< ImageBlockAttributes > ) => void;
}

/**
 * Generates alt text for an image using the AI ability.
 *
 * @param {number|undefined} attachmentId The attachment ID.
 * @param {string|undefined} imageUrl     The image URL (fallback if no attachment ID).
 * @return {Promise<string>} The generated alt text.
 */
async function generateAltText(
	attachmentId: number | undefined,
	imageUrl: string | undefined
): Promise< string > {
	const params: Record< string, any > = {};

	if ( attachmentId ) {
		params.attachment_id = attachmentId;
	} else if ( imageUrl ) {
		params.image_url = imageUrl;
	} else {
		throw new Error(
			__( 'No image available to generate alt text for.', 'ai' )
		);
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
 */
export function AltTextControls( {
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
			const result = await generateAltText( attachmentId, imageUrl );
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
					{ __( 'AI Alt Text', 'ai' ) }
				</h3>

				{ /* Error display */ }
				{ error && (
					<Notice
						status="error"
						isDismissible
						onRemove={ () => setError( null ) }
						style={ { marginBottom: '12px' } }
					>
						{ error }
					</Notice>
				) }

				{ /* Generated alt text preview */ }
				{ hasGeneratedAlt && (
					<div style={ { marginBottom: '12px' } }>
						<TextareaControl
							label={ __( 'Generated Alt Text', 'ai' ) }
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
									{ __( 'Generating...', 'ai' ) }
								</span>
							</>
						) : (
							getButtonLabel( !! hasExistingAlt )
						) }
					</Button>
				) }

				{ /* Current alt text info */ }
				{ hasExistingAlt && ! hasGeneratedAlt && (
					<p
						style={ {
							marginTop: '8px',
							fontSize: '12px',
							color: '#757575',
						} }
					>
						{ __( 'Current alt text:', 'ai' ) } &ldquo;{ alt }
						&rdquo;
					</p>
				) }
			</div>
		</InspectorControls>
	);
}
