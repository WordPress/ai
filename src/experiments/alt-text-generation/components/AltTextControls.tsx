/**
 * Alt text generation controls for the image block inspector.
 */

/**
 * WordPress dependencies
 */
import { Button, TextareaControl, Spinner } from '@wordpress/components';
import { InspectorControls } from '@wordpress/block-editor';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { dispatch, select } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { store as editorStore } from '@wordpress/editor';

/**
 * Internal dependencies
 */
import type { ImageBlockAttributes } from '../types';
import { generateAltText } from '../../../utils/generate-alt-text';

interface AltTextControlsProps {
	clientId: string;
	attributes: ImageBlockAttributes;
	setAttributes: ( attributes: Partial< ImageBlockAttributes > ) => void;
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
	};

	return (
		<InspectorControls group="content">
			<div
				className="ai-alt-text-controls"
				style={ { padding: '0 16px' } }
			>
				<h3 style={ { marginTop: 0, marginBottom: '8px' } }>
					{ __( 'Alternative Text', 'ai' ) }
				</h3>

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
