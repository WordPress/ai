/**
 * Alt text generation controls for the image block inspector.
 */

/**
 * WordPress dependencies
 */
import { Button, TextareaControl, Notice } from '@wordpress/components';
import { update } from '@wordpress/icons';
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
 * @param {boolean} isGenerating   Whether alt text is currently being generated.
 * @return {string} The button label.
 */
export function getButtonLabel(
	hasExistingAlt: boolean,
	isGenerating: boolean
): string {
	if ( isGenerating ) {
		return __( 'Generating…', 'ai' );
	}
	if ( hasExistingAlt ) {
		return __( 'Regenerate Alt Text', 'ai' );
	}
	return __( 'Generate Alt Text', 'ai' );
}

/**
 * Decorative notice component.
 *
 * Displays a notice when an image is decorative.
 *
 * @return {React.JSX.Element} The component.
 */
export function DecorativeNotice(): React.JSX.Element {
	return (
		<Notice status="info" isDismissible={ false }>
			{ __(
				'This image appears to be decorative. Applying will set an empty alt attribute, which tells screen readers to skip it.',
				'ai'
			) }
		</Notice>
	);
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
 * @return {React.JSX.Element|null} The component.
 */
export function AltTextControls( {
	clientId,
	attributes,
	setAttributes,
}: AltTextControlsProps ): React.JSX.Element | null {
	const { id: attachmentId, url: imageUrl, alt } = attributes;

	const [ isGenerating, setIsGenerating ] = useState< boolean >( false );
	const [ generatedAlt, setGeneratedAlt ] = useState< string | null >( null );
	const [ isDecorative, setIsDecorative ] = useState< boolean >( false );

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
		setIsDecorative( false );

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
				clientId,
				{
					linkDestination: attributes?.linkDestination,
					href: attributes?.href,
					linkTarget: attributes?.linkTarget,
					caption:
						typeof attributes?.caption === 'string'
							? attributes.caption
							: ( attributes?.caption as any )?.text,
				}
			);

			if ( result.is_decorative ) {
				setIsDecorative( true );
				setGeneratedAlt( '' );
			} else {
				setGeneratedAlt( result.alt_text );
			}
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
		if ( isDecorative ) {
			setAttributes( { alt: '' } );
		} else if ( generatedAlt ) {
			setAttributes( { alt: generatedAlt } );
		}
		setGeneratedAlt( null );
		setIsDecorative( false );
	};

	/**
	 * Dismisses the generated alt text suggestion.
	 */
	const handleDismiss = () => {
		setGeneratedAlt( null );
		setIsDecorative( false );
	};

	return (
		<InspectorControls group="content">
			<div
				className="ai-alt-text-controls"
				style={ { padding: '0 16px' } }
			>
				{ /* Generated alt text preview */ }
				{ hasGeneratedAlt && ! isDecorative && (
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

				{ /* Decorative image notice */ }
				{ isDecorative && (
					<div style={ { marginBottom: '12px' } }>
						<DecorativeNotice />
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
				{ ! hasGeneratedAlt && ! isDecorative && (
					<Button
						variant="secondary"
						onClick={ handleGenerate }
						disabled={ isGenerating }
						style={ { width: '100%', justifyContent: 'center' } }
						isBusy={ isGenerating }
						icon={ update }
					>
						{ getButtonLabel( !! hasExistingAlt, isGenerating ) }
					</Button>
				) }
			</div>
		</InspectorControls>
	);
}
