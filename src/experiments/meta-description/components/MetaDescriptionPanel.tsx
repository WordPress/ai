/**
 * Sidebar panel component for the meta description experiment.
 */

/**
 * WordPress dependencies
 */
import { useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { update } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { useMetaDescription } from './useMetaDescription';
import MetaDescriptionModal from './MetaDescriptionModal';
import CharacterCount from './CharacterCount';

/**
 * Panel rendering the current meta description state and controls.
 *
 * Shows a generate button when no description exists, or the current
 * description with edit/regenerate actions when one does.
 */
export default function MetaDescriptionPanel(): React.JSX.Element {
	const {
		isGenerating,
		suggestion,
		currentDescription,
		ensureProviderAvailable,
		generateDescription,
		applyDescription,
		clearSuggestion,
	} = useMetaDescription();

	const [ isModalOpen, setIsModalOpen ] = useState( false );
	const [ editableText, setEditableText ] = useState( '' );

	const shouldFocusEditButton = useRef( false );

	const focusEditButtonOnFirstMount = ( node: HTMLButtonElement | null ) => {
		if ( shouldFocusEditButton.current && node ) {
			node.focus();
			shouldFocusEditButton.current = false;
		}
	};

	const hasDescription =
		currentDescription && currentDescription.trim().length > 0;

	const handleOpenModal = async () => {
		setEditableText( currentDescription );

		// Auto-generate on first open if no existing description.
		if ( ! hasDescription ) {
			if ( ! ensureProviderAvailable() ) {
				return;
			}
			setIsModalOpen( true );
			await generateDescription();

			shouldFocusEditButton.current = true;
			return;
		}

		setIsModalOpen( true );
	};

	const handleOpenEditModal = () => {
		clearSuggestion();
		setEditableText( currentDescription );
		setIsModalOpen( true );
	};

	const handleRegenerate = async () => {
		setEditableText( currentDescription );
		if ( ! ensureProviderAvailable() ) {
			return;
		}
		setIsModalOpen( true );
		await generateDescription();
	};

	return (
		<div className="ai-meta-description-panel">
			{ hasDescription ? (
				<div className="ai-meta-description-panel__display">
					<p className="ai-meta-description-panel__text">
						{ currentDescription }
					</p>
					<CharacterCount count={ currentDescription.length } />
					<div className="ai-meta-description-panel__actions">
						<Button
							variant="link"
							size="compact"
							onClick={ handleOpenEditModal }
							ref={ focusEditButtonOnFirstMount }
						>
							{ __( 'Edit description', 'ai' ) }
						</Button>
						<Button
							icon={ update }
							label={ __( 'Regenerate meta description', 'ai' ) }
							onClick={ handleRegenerate }
							disabled={ isGenerating }
							size="compact"
							accessibleWhenDisabled
						/>
					</div>
				</div>
			) : (
				<Button
					variant="secondary"
					onClick={ handleOpenModal }
					disabled={ isGenerating }
					isBusy={ isGenerating }
					accessibleWhenDisabled
				>
					{ isGenerating
						? __( 'Generating…', 'ai' )
						: __( 'Generate Meta Description', 'ai' ) }
				</Button>
			) }

			{ isModalOpen && (
				<MetaDescriptionModal
					isGenerating={ isGenerating }
					suggestion={ suggestion }
					editableText={ editableText }
					onEditableTextChange={ setEditableText }
					onGenerate={ generateDescription }
					onApply={ applyDescription }
					onClose={ () => {
						clearSuggestion();
						setIsModalOpen( false );
					} }
				/>
			) }
		</div>
	);
}
