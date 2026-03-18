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
		suggestions,
		currentDescription,
		generateDescriptions,
		applyDescription,
		copyToClipboard,
	} = useMetaDescription();

	const [ isModalOpen, setIsModalOpen ] = useState( false );

	const hasDescription =
		currentDescription && currentDescription.trim().length > 0;

	const handleOpenModal = async () => {
		setIsModalOpen( true );

		// Auto-generate on first open if no suggestions yet and no existing description.
		if ( suggestions.length === 0 && ! hasDescription ) {
			await generateDescriptions();
		}
	};

	const handleOpenEditModal = () => {
		setIsModalOpen( true );
	};

	const handleRegenerate = async () => {
		setIsModalOpen( true );
		await generateDescriptions();
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
							variant="secondary"
							onClick={ handleOpenEditModal }
							size="compact"
						>
							{ __( 'Edit', 'ai' ) }
						</Button>
						<Button
							icon={ update }
							label={ __( 'Regenerate meta description', 'ai' ) }
							onClick={ handleRegenerate }
							disabled={ isGenerating }
							size="compact"
						/>
					</div>
				</div>
			) : (
				<Button
					variant="secondary"
					onClick={ handleOpenModal }
					disabled={ isGenerating }
					isBusy={ isGenerating }
				>
					{ __( 'Generate meta description', 'ai' ) }
				</Button>
			) }

			{ isModalOpen && (
				<MetaDescriptionModal
					isGenerating={ isGenerating }
					suggestions={ suggestions }
					initialDescription={ currentDescription }
					onGenerate={ generateDescriptions }
					onApply={ applyDescription }
					onCopy={ copyToClipboard }
					onClose={ () => setIsModalOpen( false ) }
				/>
			) }
		</div>
	);
}
