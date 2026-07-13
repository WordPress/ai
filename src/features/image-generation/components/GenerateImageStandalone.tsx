/**
 * WordPress dependencies
 */
import { Button, Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useFocusOnMount } from '@wordpress/compose';

/**
 * Internal dependencies
 */
import { uploadImage } from '../functions/upload-image';
import { useImageGeneration } from '../hooks/useImageGeneration';
import { isProviderAvailable } from '../../../utils/provider-status';
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
	const [ savedHistoryIndices, setSavedHistoryIndices ] = useState<
		Set< number >
	>( new Set() );

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
			setSavedHistoryIndices(
				( prev ) => new Set( [ ...prev, historyIndex ] )
			);
			setState( 'preview' );
		} catch ( err: any ) {
			setError( err?.message || __( 'Failed to upload image.', 'ai' ) );
			setState( 'preview' );
		}
	}

	/**
	 * Wraps generate() with an inline provider availability check.
	 *
	 * Outside the block editor the @wordpress/notices store has no renderer,
	 * so ensureProvider()'s dispatched notice is invisible. This guard uses
	 * isProviderAvailable() and sets component-level error state instead,
	 * ensuring the user sees a Notice in the standalone UI.
	 *
	 * @param activePrompt    The prompt text to generate an image from.
	 * @param referenceImage  Optional base64 data URI of a reference image for refinement.
	 * @param refHistoryIndex Optional history index of the reference image entry.
	 */
	function safeGenerate(
		activePrompt: string,
		referenceImage?: string,
		refHistoryIndex?: number
	): void {
		if ( ! isProviderAvailable() ) {
			setError(
				__(
					'This feature requires an AI Connector to function properly. Please set up a provider in Settings → Connectors.',
					'ai'
				)
			);
			return;
		}
		generate( activePrompt, referenceImage, refHistoryIndex );
	}

	const lastSaved = savedUploads[ savedUploads.length - 1 ] ?? null;

	return (
		<div className="ai-generate-image-standalone">
			{ state === 'idle' && (
				<PromptForm
					prompt={ prompt }
					onPromptChange={ setPrompt }
					onGenerate={ () => safeGenerate( prompt.trim() ) }
					error={ error }
					hasImageGenerationSupport={ Boolean(
						aiImageGenerationData?.hasImageGenerationSupport
					) }
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
						<Button
							variant="primary"
							onClick={ handleSaveImage }
							disabled={ savedHistoryIndices.has( historyIndex ) }
							accessibleWhenDisabled
							__next40pxDefaultSize
						>
							{ __( 'Save to Media Library', 'ai' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ () => {
								setRefinePrompt( '' );
								setState( 'refining' );
							} }
							__next40pxDefaultSize
						>
							{ __( 'Refine Image', 'ai' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ () =>
								safeGenerate(
									activeEntry?.generatedData.prompt ??
										prompt.trim(),
									activeEntry?.referenceSrc,
									activeEntry?.referenceHistoryIndex
								)
							}
							__next40pxDefaultSize
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
							__next40pxDefaultSize
						>
							{ __( 'Edit Prompt', 'ai' ) }
						</Button>
						<Button
							variant="tertiary"
							isDestructive
							onClick={ () => {
								resetHistory();
								setSavedUploads( [] );
								setSavedHistoryIndices( new Set() );
								setPrompt( '' );
								setState( 'idle' );
								setError( null );
							} }
							style={ { marginInlineStart: 'auto' } }
							__next40pxDefaultSize
						>
							{ __( 'Cancel', 'ai' ) }
						</Button>
					</div>
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
						safeGenerate(
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
