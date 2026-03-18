/**
 * Modal component for generating and editing meta description suggestions.
 */

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Modal, Button, TextareaControl, Spinner } from '@wordpress/components';

/**
 * Internal dependencies
 */
import SuggestionCard from './SuggestionCard';
import CharacterCount from './CharacterCount';
import type { MetaDescriptionSuggestion } from '../types';

interface MetaDescriptionModalProps {
	isGenerating: boolean;
	suggestions: MetaDescriptionSuggestion[];
	initialDescription: string;
	onGenerate: () => Promise< void >;
	onApply: ( text: string ) => void;
	onCopy: ( text: string ) => Promise< void >;
	onClose: () => void;
}

/**
 * Modal for generating, selecting, and editing meta descriptions.
 *
 * @param props                    Component props.
 * @param props.isGenerating       Whether generation is in progress.
 * @param props.suggestions        Array of generated suggestions.
 * @param props.initialDescription Pre-existing description to edit.
 * @param props.onGenerate         Callback to trigger generation.
 * @param props.onApply            Callback to apply the description.
 * @param props.onCopy             Callback to copy to clipboard.
 * @param props.onClose            Callback to close the modal.
 */
export default function MetaDescriptionModal( {
	isGenerating,
	suggestions,
	initialDescription,
	onGenerate,
	onApply,
	onCopy,
	onClose,
}: MetaDescriptionModalProps ): JSX.Element {
	const [ selectedIndex, setSelectedIndex ] = useState< number | null >(
		null
	);
	const [ editableText, setEditableText ] = useState( initialDescription );

	const handleSelectSuggestion = ( index: number, text: string ) => {
		setSelectedIndex( index );
		setEditableText( text );
	};

	const handleApply = () => {
		onApply( editableText );
		onClose();
	};

	const hasSuggestions = suggestions.length > 0;

	return (
		<Modal
			title={ __( 'Meta Description', 'ai' ) }
			onRequestClose={ onClose }
			className="ai-meta-description-modal"
			size="medium"
		>
			<div className="ai-meta-description-modal__content">
				{ /* Generation controls */ }
				<div className="ai-meta-description-modal__generate">
					<Button
						variant="secondary"
						onClick={ onGenerate }
						disabled={ isGenerating }
						isBusy={ isGenerating }
					>
						{ hasSuggestions
							? __( 'Regenerate suggestions', 'ai' )
							: __( 'Generate suggestions', 'ai' ) }
					</Button>
					{ isGenerating && (
						<span className="ai-meta-description-modal__spinner">
							<Spinner />
						</span>
					) }
				</div>

				{ /* Suggestion cards */ }
				{ hasSuggestions && (
					<div className="ai-meta-description-modal__suggestions">
						<p className="ai-meta-description-modal__suggestions-label">
							{ __(
								'Select a suggestion to use as a starting point:',
								'ai'
							) }
						</p>
						{ suggestions.map( ( suggestion, index ) => (
							<SuggestionCard
								key={ index }
								text={ suggestion.text }
								characterCount={ suggestion.character_count }
								isSelected={ selectedIndex === index }
								onSelect={ () =>
									handleSelectSuggestion(
										index,
										suggestion.text
									)
								}
							/>
						) ) }
					</div>
				) }

				{ /* Editable textarea */ }
				<div className="ai-meta-description-modal__editor">
					<TextareaControl
						label={ __( 'Meta description', 'ai' ) }
						value={ editableText }
						onChange={ setEditableText }
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
						onClick={ handleApply }
						disabled={ editableText.trim().length === 0 }
					>
						{ __( 'Apply', 'ai' ) }
					</Button>
					<Button
						variant="tertiary"
						onClick={ () => onCopy( editableText ) }
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
