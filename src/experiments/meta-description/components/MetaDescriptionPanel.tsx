/**
 * Sidebar panel component for the meta description experiment.
 */

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
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
export default function MetaDescriptionPanel(): JSX.Element {
	const {
		isGenerating,
		suggestion,
		currentDescription,
		generateDescription,
		applyDescription,
	} = useMetaDescription();

	const [ isModalOpen, setIsModalOpen ] = useState( false );
	const [ editableText, setEditableText ] = useState( '' );

	const hasDescription =
		currentDescription && currentDescription.trim().length > 0;

	const handleOpenModal = async () => {
		setEditableText( currentDescription );
		setIsModalOpen( true );

		// Auto-generate on first open if no existing description.
		if ( ! hasDescription ) {
			await generateDescription();
		}
	};

	const handleOpenEditModal = () => {
		setEditableText( currentDescription );
		setIsModalOpen( true );
	};

	const handleRegenerate = async () => {
		setEditableText( currentDescription );
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
							onClick={ handleOpenEditModal }
							size="compact"
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
					{ __( 'Generate Meta Description', 'ai' ) }
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
					onClose={ () => setIsModalOpen( false ) }
				/>
			) }
		</div>
	);
}
