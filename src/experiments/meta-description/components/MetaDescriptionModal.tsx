/**
 * Modal component for generating and editing a meta description suggestion.
 */

/**
 * WordPress dependencies
 */
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Modal, Button, TextareaControl, Notice } from '@wordpress/components';

/**
 * Internal dependencies
 */
import CharacterCount from './CharacterCount';
import { useCopyToClipboardFeedback } from '../../../hooks/use-copy-to-clipboard-feedback';
import type { MetaDescriptionSuggestion } from '../types';

interface MetaDescriptionModalProps {
	isGenerating: boolean;
	suggestion: MetaDescriptionSuggestion | null;
	editableText: string;
	isContentTooShort: boolean;
	tooShortLabel: string;
	onEditableTextChange: ( text: string ) => void;
	onGenerate: () => Promise< void >;
	onApply: ( text: string ) => void;
	onClose: () => void;
}

function CopyButton( {
	text,
	disabled,
}: {
	text: string;
	disabled: boolean;
} ): JSX.Element {
	const { ref, hasCopied } = useCopyToClipboardFeedback< HTMLButtonElement >(
		{
			text,
			announcement: __( 'Meta description copied to clipboard.', 'ai' ),
		}
	);

	const isCopyDisabled = disabled || hasCopied;

	return (
		<Button
			ref={ isCopyDisabled ? undefined : ref }
			variant="tertiary"
			disabled={ isCopyDisabled }
			accessibleWhenDisabled
			__next40pxDefaultSize
		>
			{ hasCopied
				? __( 'Copied!', 'ai' )
				: __( 'Copy to clipboard', 'ai' ) }
		</Button>
	);
}

/**
 * Modal for generating and editing a meta description.
 *
 * @param props                      Component props.
 * @param props.isGenerating         Whether generation is in progress.
 * @param props.suggestion           The generated suggestion.
 * @param props.editableText         The current editable text value.
 * @param props.isContentTooShort    Whether the content is too short to generate a suggestion.
 * @param props.tooShortLabel        Label to show when content is too short.
 * @param props.onEditableTextChange Callback to update the editable text.
 * @param props.onGenerate           Callback to trigger generation.
 * @param props.onApply              Callback to apply the description.
 * @param props.onClose              Callback to close the modal.
 */
export default function MetaDescriptionModal( {
	isGenerating,
	suggestion,
	editableText,
	isContentTooShort,
	tooShortLabel,
	onEditableTextChange,
	onGenerate,
	onApply,
	onClose,
}: MetaDescriptionModalProps ): React.JSX.Element {
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
		generateButtonLabel = __( 'Generating…', 'ai' );
	}

	return (
		<Modal
			title={ __( 'Meta Description', 'ai' ) }
			onRequestClose={ onClose }
			className="ai-meta-description-modal"
			size="medium"
			focusOnMount="firstContentElement"
		>
			<div className="ai-meta-description-modal__content">
				{ isContentTooShort && (
					<Notice status="warning" isDismissible={ false }>
						{ tooShortLabel }
					</Notice>
				) }

				{ /* Editable textarea */ }
				<div className="ai-meta-description-modal__editor">
					<TextareaControl
						label={ __( 'Meta description', 'ai' ) }
						hideLabelFromVision
						value={ editableText }
						onChange={ onEditableTextChange }
						rows={ 3 }
						help={ __(
							'Aim for 140–160 characters for optimal display in search results.',
							'ai'
						) }
						disabled={ isGenerating }
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
						accessibleWhenDisabled
						disabled={
							isGenerating ||
							( !! editableText &&
								editableText.trim().length === 0 )
						}
						__next40pxDefaultSize
					>
						{ __( 'Apply', 'ai' ) }
					</Button>
					<Button
						variant="secondary"
						label={
							isContentTooShort
								? tooShortLabel
								: generateButtonLabel
						}
						showTooltip
						onClick={ onGenerate }
						disabled={ isGenerating || isContentTooShort }
						isBusy={ isGenerating }
						accessibleWhenDisabled
						__next40pxDefaultSize
					>
						{ generateButtonLabel }
					</Button>
					<CopyButton
						text={ editableText }
						disabled={
							isGenerating || editableText.trim().length === 0
						}
					/>
					<Button
						variant="tertiary"
						isDestructive
						onClick={ onClose }
						className="ai-meta-description-modal__cancel"
						__next40pxDefaultSize
					>
						{ __( 'Cancel', 'ai' ) }
					</Button>
				</div>
			</div>
		</Modal>
	);
}
