/**
 * Modal component for generating and editing a meta description suggestion.
 */

/**
 * WordPress dependencies
 */
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Modal, Button, TextareaControl } from '@wordpress/components';
import { useCopyToClipboard } from '@wordpress/compose';
import { dispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import CharacterCount from './CharacterCount';
import type { MetaDescriptionSuggestion } from '../types';

interface MetaDescriptionModalProps {
	isGenerating: boolean;
	suggestion: MetaDescriptionSuggestion | null;
	editableText: string;
	onEditableTextChange: ( text: string ) => void;
	onGenerate: () => Promise< void >;
	onApply: ( text: string ) => void;
	onClose: () => void;
}

/**
 * Modal for generating and editing a meta description.
 *
 * @param props                      Component props.
 * @param props.isGenerating         Whether generation is in progress.
 * @param props.suggestion           The generated suggestion.
 * @param props.editableText         The current editable text value.
 * @param props.onEditableTextChange Callback to update the editable text.
 * @param props.onGenerate           Callback to trigger generation.
 * @param props.onApply              Callback to apply the description.
 * @param props.onClose              Callback to close the modal.
 */
export default function MetaDescriptionModal( {
	isGenerating,
	suggestion,
	editableText,
	onEditableTextChange,
	onGenerate,
	onApply,
	onClose,
}: MetaDescriptionModalProps ): JSX.Element {
	const { createSuccessNotice } = dispatch( noticesStore );

	const copyButtonRef = useCopyToClipboard< HTMLButtonElement >(
		() => editableText,
		() => {
			createSuccessNotice(
				__( 'Meta description copied to clipboard.', 'ai' ),
				{
					type: 'snackbar',
					isDismissible: true,
				}
			);
		}
	);

	// Populate the textarea when a new suggestion arrives.
	useEffect( () => {
		if ( suggestion?.text ) {
			onEditableTextChange( suggestion.text );
		}
	}, [ suggestion, onEditableTextChange ] );

	let generateButtonLabel: string = suggestion
		? __( 'Regenerate', 'ai' )
		: __( 'Generate', 'ai' );

	if ( isGenerating ) {
		generateButtonLabel = __( 'Generating', 'ai' );
	}

	return (
		<Modal
			title={ __( 'Meta Description', 'ai' ) }
			onRequestClose={ onClose }
			className="ai-meta-description-modal"
			size="medium"
		>
			<div className="ai-meta-description-modal__content">
				{ /* Editable textarea */ }
				<div className="ai-meta-description-modal__editor">
					<TextareaControl
						label={ __( 'Meta description', 'ai' ) }
						value={ editableText }
						onChange={ onEditableTextChange }
						rows={ 3 }
						help={ __(
							'Aim for 140–160 characters for optimal display in search results.',
							'ai'
						) }
						__nextHasNoMarginBottom
					/>
					<CharacterCount count={ editableText.length } />
				</div>

				{ /* Actions */ }
				<div className="ai-meta-description-modal__actions">
					<Button
						variant="primary"
						onClick={ () => {
							onApply( editableText );
							onClose();
						} }
						disabled={ editableText.trim().length === 0 }
					>
						{ __( 'Apply', 'ai' ) }
					</Button>
					<Button
						variant="secondary"
						onClick={ onGenerate }
						disabled={ isGenerating }
						isBusy={ isGenerating }
					>
						{ generateButtonLabel }
					</Button>
					<Button
						ref={ copyButtonRef }
						variant="tertiary"
						disabled={ editableText.trim().length === 0 }
					>
						{ __( 'Copy to clipboard', 'ai' ) }
					</Button>
					<Button variant="tertiary" onClick={ onClose }>
						{ __( 'Cancel', 'ai' ) }
					</Button>
				</div>
			</div>
		</Modal>
	);
}
