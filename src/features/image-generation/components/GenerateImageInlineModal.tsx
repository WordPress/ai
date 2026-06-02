/**
 * WordPress dependencies
 */
import { Button, Modal, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { image } from '@wordpress/icons';
import { useFocusOnMount } from '@wordpress/compose';

/**
 * Internal dependencies
 */
import { uploadImage } from '../functions/upload-image';
import { insertIntoBlock } from '../functions/insert-into-block';
import { openGalleryMediaLibraryWithImage } from '../functions/open-gallery-media-library';
import { useImageGeneration } from '../hooks/useImageGeneration';
import type { UploadedImage } from '../types';
import {
	GeneratingState,
	ImageHistoryNav,
	PromptForm,
	RefinePromptForm,
} from './shared';

const { aiImageGenerationData } = window as any;

interface Props {
	blockName: string;
	clientId: string;
	setAttributes: ( attrs: Record< string, unknown > ) => void;
	onClose: () => void;
}

/**
 * Modal component for inline AI image generation in the block editor.
 *
 * Supports a generate → preview → refine → insert flow. When refining,
 * the current preview image is sent as a reference to the generation
 * ability so that models supporting image editing can use it as context.
 *
 * @param {Props}    props               The props for the component.
 * @param {string}   props.blockName     The name of the block.
 * @param {string}   props.clientId      The client ID of the block.
 * @param {Function} props.setAttributes The function to set the attributes of the block.
 * @param {Function} props.onClose       The function to close the modal.
 */
export function GenerateImageInlineModal( {
	blockName,
	clientId,
	setAttributes,
	onClose,
}: Props ) {
	const {
		state,
		setState,
		prompt,
		setPrompt,
		refinePrompt,
		setRefinePrompt,
		progress,
		setProgress,
		error,
		setError,
		history,
		historyIndex,
		activeEntry,
		canGoBack,
		canGoForward,
		goBack,
		goForward,
		resetHistory,
		generate,
		previewSrc,
		showComparison,
		comparisonLeftLabel,
		comparisonRightLabel,
	} = useImageGeneration();

	const focusOnMountRef = useFocusOnMount( 'firstElement' );

	async function handleUseImage(): Promise< void > {
		if ( ! activeEntry ) {
			return;
		}

		setError( null );
		setState( 'generating' );
		setProgress( __( 'Uploading image…', 'ai' ) );

		try {
			const uploaded: UploadedImage = await uploadImage(
				activeEntry.generatedData,
				{
					onProgress: setProgress,
					altTextEnabled: aiImageGenerationData?.altTextEnabled,
				}
			);

			if ( blockName === 'core/gallery' ) {
				const openedMediaLibrary = openGalleryMediaLibraryWithImage(
					clientId,
					uploaded
				);
				if ( ! openedMediaLibrary ) {
					insertIntoBlock(
						blockName,
						clientId,
						setAttributes,
						uploaded
					);
				}
			} else {
				insertIntoBlock( blockName, clientId, setAttributes, uploaded );
			}
			onClose();
		} catch ( err: any ) {
			setError( err?.message || __( 'Failed to upload image.', 'ai' ) );
			setState( 'preview' );
		}
	}

	return (
		<Modal
			title={ __( 'Generate Image', 'ai' ) }
			onRequestClose={ onClose }
			icon={ image }
			size="large"
			className="ai-generate-image-inline-modal"
		>
			{ state === 'idle' && (
				<PromptForm
					prompt={ prompt }
					onPromptChange={ setPrompt }
					onGenerate={ () => generate( prompt.trim() ) }
					error={ error }
				/>
			) }

			{ state === 'generating' && (
				<GeneratingState
					progress={ progress }
					previewSrc={ previewSrc }
					previewAlt={ activeEntry?.generatedData?.prompt ?? '' }
				/>
			) }

			{ state === 'preview' && previewSrc && (
				<div className="ai-generate-image-inline-modal__preview">
					<ImageHistoryNav
						previewSrc={ previewSrc }
						activeEntry={ activeEntry }
						canGoBack={ canGoBack }
						canGoForward={ canGoForward }
						onGoBack={ goBack }
						onGoForward={ goForward }
						historyLength={ history.length }
						historyIndex={ historyIndex }
						showComparison={ showComparison }
						comparisonLeftLabel={ comparisonLeftLabel }
						comparisonRightLabel={ comparisonRightLabel }
					/>
					<div
						className="ai-image-generation__actions"
						ref={ focusOnMountRef }
					>
						<Button variant="primary" onClick={ handleUseImage }>
							{ __( 'Use Image', 'ai' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ () => {
								setRefinePrompt( '' );
								setState( 'refining' );
							} }
						>
							{ __( 'Refine Image', 'ai' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ () =>
								generate(
									activeEntry?.generatedData.prompt ??
										prompt.trim(),
									activeEntry?.referenceSrc,
									activeEntry?.referenceHistoryIndex
								)
							}
						>
							{ __( 'Generate Another Image', 'ai' ) }
						</Button>
						<Button
							variant="tertiary"
							onClick={ () => {
								resetHistory();
								setState( 'idle' );
								setError( null );
							} }
						>
							{ __( 'Edit Prompt', 'ai' ) }
						</Button>
					</div>
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
				</div>
			) }

			{ state === 'refining' && previewSrc && (
				<RefinePromptForm
					previewSrc={ previewSrc }
					previewAlt={ activeEntry?.generatedData?.prompt ?? '' }
					refinePrompt={ refinePrompt }
					onRefinePromptChange={ setRefinePrompt }
					onRefine={ () =>
						generate(
							refinePrompt.trim(),
							previewSrc,
							historyIndex
						)
					}
					onCancel={ () => {
						setState( 'preview' );
						setError( null );
					} }
					error={ error }
				/>
			) }
		</Modal>
	);
}
