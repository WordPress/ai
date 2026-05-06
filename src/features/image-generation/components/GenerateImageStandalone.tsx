/**
 * WordPress dependencies
 */
import { Button, Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { uploadImage } from '../functions/upload-image';
import { useImageGeneration } from '../hooks/useImageGeneration';
import type { UploadedImage } from '../types';
import {
	GeneratingState,
	ImageHistoryNav,
	PromptForm,
	RefinePromptForm,
} from './shared';

const { aiImageGenerationData } = window as any;

/**
 * Standalone component for AI image generation in the Media Library.
 *
 * Supports a generate → preview → refine → save flow. Multiple versions
 * can be saved while remaining in the preview state with navigation.
 */
export function GenerateImageStandalone() {
	const [ savedUploads, setSavedUploads ] = useState< UploadedImage[] >( [] );

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

	async function handleSaveImage(): Promise< void > {
		if ( ! activeEntry ) {
			return;
		}

		setError( null );
		setState( 'generating' );
		setProgress( __( 'Uploading image to Media Library…', 'ai' ) );

		try {
			const uploaded: UploadedImage = await uploadImage(
				activeEntry.generatedData,
				{
					onProgress: setProgress,
					altTextEnabled: aiImageGenerationData?.altTextEnabled,
				}
			);
			setSavedUploads( ( prev ) => [ ...prev, uploaded ] );
			setState( 'preview' );
		} catch ( err: unknown ) {
			setError(
				err instanceof Error
					? err.message
					: __( 'Failed to upload image.', 'ai' )
			);
			setState( 'preview' );
		}
	}

	const lastSaved = savedUploads[ savedUploads.length - 1 ] ?? null;

	return (
		<div className="ai-generate-image-standalone">
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
				<div className="ai-generate-image-standalone__preview">
					{ lastSaved && (
						<Notice
							status="success"
							onDismiss={ () =>
								setSavedUploads( ( prev ) =>
									prev.filter(
										( u ) => u.id !== lastSaved.id
									)
								)
							}
						>
							{ __(
								'Image successfully added to the Media Library.',
								'ai'
							) }{ ' ' }
							<a href={ `upload.php?item=${ lastSaved.id }` }>
								{ __( 'View in Media Library', 'ai' ) }
							</a>
						</Notice>
					) }
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
					<div className="ai-image-generation__actions">
						<Button variant="primary" onClick={ handleSaveImage }>
							{ __( 'Save to Media Library', 'ai' ) }
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
						<Button
							variant="tertiary"
							isDestructive
							onClick={ () => {
								resetHistory();
								setSavedUploads( [] );
								setPrompt( '' );
								setState( 'idle' );
								setError( null );
							} }
							style={ { marginLeft: 'auto' } }
						>
							{ __( 'Cancel', 'ai' ) }
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
					cancelIsDestructive
					error={ error }
				/>
			) }
		</div>
	);
}
