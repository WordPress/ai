/**
 * Alt text generation control for Gutenberg's experimental Media Editor.
 */

/**
 * WordPress dependencies
 */
import { Button, TextareaControl } from '@wordpress/components';
import { update } from '@wordpress/icons';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { dispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import type { DataFormControlProps } from '@wordpress/dataviews';

/**
 * Internal dependencies
 */
import { getButtonLabel, DecorativeNotice } from './AltTextControls';
import { generateAltText } from '../../../utils/generate-alt-text';
import type { MediaEditorAttachment } from '../types';

/**
 * MediaEditorAltTextControl component.
 *
 * Replaces the core `alt_text` field to add a generate/regenerate button.
 *
 * @param {DataFormControlProps<MediaEditorAttachment>} props                     The DataForm control props.
 * @param {MediaEditorAttachment}                       props.data                The attachment record.
 * @param {Object}                                      props.field               The normalized field definition.
 * @param {Function}                                    props.onChange            Callback to update the attachment record.
 * @param {boolean}                                     props.hideLabelFromVision Whether to visually hide the textarea label.
 * @return {React.JSX.Element|null} The control.
 */
export function MediaEditorAltTextControl( {
	data,
	field,
	onChange,
	hideLabelFromVision,
}: DataFormControlProps< MediaEditorAttachment > ): React.JSX.Element | null {
	const {
		id: attachmentId,
		alt_text: currentAlt,
		source_url: imageUrl,
	} = data || {};

	const [ isGenerating, setIsGenerating ] = useState< boolean >( false );
	const [ showDecorativeNotice, setShowDecorativeNotice ] =
		useState< boolean >( false );

	const hasExistingAlt =
		typeof currentAlt === 'string' && currentAlt.trim().length > 0;
	const canGenerate = !! attachmentId || !! imageUrl;

	/**
	 * Handles the generate/regenerate button click.
	 * Writes the returned suggestion directly into the textarea.
	 */
	const handleGenerate = async () => {
		setIsGenerating( true );
		setShowDecorativeNotice( false );

		// Clear any previous notices.
		dispatch( noticesStore ).removeNotice( 'ai_alt_text_generation_error' );

		try {
			const result = await generateAltText( attachmentId, imageUrl );

			if ( result.is_decorative ) {
				onChange( { alt_text: '' } );
				setShowDecorativeNotice( true );
			} else {
				onChange( { alt_text: result.alt_text } );
			}
		} catch ( err: any ) {
			const errorMessage =
				err?.message ||
				__( 'An error occurred while generating alt text.', 'ai' );
			dispatch( noticesStore ).createErrorNotice( errorMessage, {
				id: 'ai_alt_text_generation_error',
				isDismissible: true,
			} );
		} finally {
			setIsGenerating( false );
		}
	};

	return (
		<div className="ai-alt-text-controls ai-alt-text-controls--media-editor">
			{ /* Core alt text textarea (mirrors the field being replaced). */ }
			<TextareaControl
				label={ field.label }
				hideLabelFromVision={ !! hideLabelFromVision }
				value={ currentAlt || '' }
				onChange={ ( value ) => onChange( { alt_text: value } ) }
				rows={ 2 }
			/>

			{ /* Decorative image notice. */ }
			{ showDecorativeNotice && (
				<div style={ { marginTop: '12px' } }>
					<DecorativeNotice />
				</div>
			) }

			{ /* Generate / Regenerate button. */ }
			{ canGenerate && (
				<Button
					variant="secondary"
					onClick={ handleGenerate }
					disabled={ isGenerating }
					style={ {
						width: '100%',
						justifyContent: 'center',
						marginTop: '8px',
					} }
					isBusy={ isGenerating }
					icon={ update }
					__next40pxDefaultSize
				>
					{ getButtonLabel( hasExistingAlt, isGenerating ) }
				</Button>
			) }
		</div>
	);
}
