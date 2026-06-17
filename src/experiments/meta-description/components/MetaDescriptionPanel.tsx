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
		isContentTooShort,
		tooShortLabel,
		ensureProviderAvailable,
		generateDescription,
		applyDescription,
		clearSuggestion,
	} = useMetaDescription();

	const [ isModalOpen, setIsModalOpen ] = useState( false );
	const [ editableText, setEditableText ] = useState( '' );

	const shouldFocusEditButton = useRef( false );
	const shouldFocusGenerateButton = useRef( false );

	const hasDescription =
		currentDescription && currentDescription.trim().length > 0;

	const focusEditButtonOnFirstMount = ( node: HTMLButtonElement | null ) => {
		if ( shouldFocusEditButton.current && node ) {
			node.focus();
			shouldFocusEditButton.current = false;
		}
	};

	const focusGenerateButtonOnEmptyState = (
		node: HTMLButtonElement | null
	) => {
		if ( ! hasDescription && shouldFocusGenerateButton.current && node ) {
			node.focus();
			shouldFocusGenerateButton.current = false;
		}
	};

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
							label={
								isContentTooShort
									? tooShortLabel
									: __( 'Regenerate meta description', 'ai' )
							}
							showTooltip
							onClick={ handleRegenerate }
							disabled={ isGenerating || isContentTooShort }
							size="compact"
							accessibleWhenDisabled
						/>
					</div>
				</div>
			) : (
				<Button
					variant="secondary"
					label={
						isContentTooShort
							? tooShortLabel
							: __( 'Generate Meta Description', 'ai' )
					}
					onClick={ handleOpenModal }
					disabled={ isGenerating || isContentTooShort }
					isBusy={ isGenerating }
					ref={ focusGenerateButtonOnEmptyState }
					accessibleWhenDisabled
					__next40pxDefaultSize
					className="ai-meta-description-panel__generate-button"
				>
					{ isGenerating
						? __( 'Generating…', 'ai' )
						: __( 'Generate Meta Description', 'ai' ) }
				</Button>
			) }

			{ isContentTooShort && ! hasDescription && (
				<p
					className="ai-meta-description__hint components-base-control__help"
					style={ { color: '#757575' } }
				>
					{ tooShortLabel }
				</p>
			) }

			{ isModalOpen && (
				<MetaDescriptionModal
					isGenerating={ isGenerating }
					suggestion={ suggestion }
					editableText={ editableText }
					isContentTooShort={ isContentTooShort }
					tooShortLabel={ tooShortLabel }
					onEditableTextChange={ setEditableText }
					onGenerate={ generateDescription }
					onApply={ ( text ) => {
						applyDescription( text );

						// Restore focus to the generate button when applying an empty description.
						if ( text.trim().length === 0 ) {
							shouldFocusEditButton.current = false;
							shouldFocusGenerateButton.current = true;
						}
					} }
					onClose={ () => {
						clearSuggestion();
						setIsModalOpen( false );
					} }
				/>
			) }
		</div>
	);
}
