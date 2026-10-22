/**
 * Sidebar panel component for the excerpt generation experiment.
 */

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { useExcerptGeneration } from './useExcerptGeneration';
import ExcerptGenerationModal from './ExcerptGenerationModal';

/**
 * Panel with the generate button. Generation happens in a modal where the
 * suggestion can be reviewed and edited before it is applied to the post.
 */
export default function ExcerptGenerationPanel(): React.JSX.Element {
	const {
		isGenerating,
		suggestion,
		currentExcerpt,
		isContentTooShort,
		tooShortLabel,
		ensureProviderAvailable,
		generateExcerpt,
		cancelGeneration,
		applyExcerpt,
		clearSuggestion,
	} = useExcerptGeneration();

	const [ isModalOpen, setIsModalOpen ] = useState( false );
	const [ editableText, setEditableText ] = useState( '' );

	const hasExcerpt = currentExcerpt.trim().length > 0;

	let buttonLabel: string = hasExcerpt
		? __( 'Regenerate excerpt', 'ai' )
		: __( 'Generate excerpt', 'ai' );

	if ( isGenerating ) {
		buttonLabel = __( 'Generating…', 'ai' );
	}

	const handleOpenModal = async () => {
		if ( ! ensureProviderAvailable() ) {
			return;
		}

		setEditableText( currentExcerpt );
		setIsModalOpen( true );
		await generateExcerpt();
	};

	return (
		<div className="ai-excerpt-generation-panel">
			<Button
				variant="secondary"
				label={ isContentTooShort ? tooShortLabel : buttonLabel }
				onClick={ handleOpenModal }
				disabled={ isGenerating || isContentTooShort }
				isBusy={ isGenerating }
				accessibleWhenDisabled
				__next40pxDefaultSize
				className="ai-excerpt-generation-panel__generate-button"
			>
				{ buttonLabel }
			</Button>

			{ isContentTooShort && (
				<p
					className="ai-excerpt-generation__hint components-base-control__help"
					style={ { color: '#757575' } }
				>
					{ tooShortLabel }
				</p>
			) }

			{ isModalOpen && (
				<ExcerptGenerationModal
					isGenerating={ isGenerating }
					suggestion={ suggestion }
					editableText={ editableText }
					isContentTooShort={ isContentTooShort }
					tooShortLabel={ tooShortLabel }
					onEditableTextChange={ setEditableText }
					onGenerate={ generateExcerpt }
					onApply={ applyExcerpt }
					onClose={ () => {
						cancelGeneration();
						clearSuggestion();
						setIsModalOpen( false );
					} }
				/>
			) }
		</div>
	);
}
