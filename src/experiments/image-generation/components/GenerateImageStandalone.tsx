/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	TextareaControl,
	Spinner,
	Notice,
} from '@wordpress/components';
import { chevronLeft, chevronRight } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';
import { uploadImage } from '../functions/upload-image';
import { useImageHistory } from '../hooks/useImageHistory';
import type {
	GeneratedImageData,
	ImageGenerationAbilityInput,
	UploadedImage,
} from '../types';

const { aiImageGenerationData } = window as any;

type ModalState = 'idle' | 'generating' | 'preview' | 'refining';

/**
 * Standalone component for AI image generation in the Media Library.
 *
 * Supports a generate → preview → refine → save flow. Multiple versions
 * can be saved while remaining in the preview state with navigation.
 */
export function GenerateImageStandalone() {
	const [ state, setState ] = useState< ModalState >( 'idle' );
	const [ prompt, setPrompt ] = useState( '' );
	const [ refinePrompt, setRefinePrompt ] = useState( '' );
	const [ savedUploads, setSavedUploads ] = useState< UploadedImage[] >( [] );
	const [ progress, setProgress ] = useState( '' );
	const [ error, setError ] = useState< string | null >( null );

	const {
		history,
		historyIndex,
		activeEntry,
		canGoBack,
		canGoForward,
		addToHistory,
		goBack,
		goForward,
		resetHistory,
	} = useImageHistory();

	/**
	 * Runs the image generation ability with the given prompt and
	 * optional reference image for the refining flow.
	 *
	 * @param {string}           activePrompt    The prompt to generate an image from.
	 * @param {string|undefined} referenceImage  Optional base64 image for refining.
	 * @param {number|undefined} refHistoryIndex History index of the entry whose image is the reference.
	 */
	async function generate(
		activePrompt: string,
		referenceImage?: string,
		refHistoryIndex?: number
	): Promise< void > {
		setError( null );
		setState( 'generating' );
		setProgress( __( 'Generating image…', 'ai' ) );

		try {
			const input: ImageGenerationAbilityInput = { prompt: activePrompt };
			if ( referenceImage ) {
				input.reference = referenceImage;
			}

			const response = ( await runAbility(
				'ai/image-generation',
				input
			) ) as GeneratedImageData;

			if ( ! response || ! response.image ) {
				throw new Error(
					__( 'Invalid response from image generation', 'ai' )
				);
			}

			const prevData = activeEntry?.generatedData;
			const previousPrompts = referenceImage
				? prevData?.prompts ??
				  ( prevData?.prompt ? [ prevData.prompt ] : [] )
				: [];
			const promptHistory = previousPrompts.filter( Boolean );
			const lastPrompt = promptHistory[ promptHistory.length - 1 ];
			const prompts =
				lastPrompt === activePrompt
					? promptHistory
					: [ ...promptHistory, activePrompt ];

			addToHistory(
				{ ...response, prompt: activePrompt, prompts },
				referenceImage,
				!! referenceImage,
				refHistoryIndex
			);
			setState( 'preview' );
		} catch ( err: any ) {
			const message: string =
				err?.message ||
				__( 'An error occurred during image generation.', 'ai' );

			setError( message );
			setState( referenceImage ? 'refining' : 'idle' );
		}
	}

	/**
	 * Uploads the active generated image to the Media Library.
	 */
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
		} catch ( err: any ) {
			setError( err?.message || __( 'Failed to upload image.', 'ai' ) );
			setState( 'preview' );
		}
	}

	const previewSrc = activeEntry?.generatedData?.image?.data
		? `data:image/png;base64,${ activeEntry.generatedData.image.data }`
		: null;

	// Show comparison only when the active entry was a refinement.
	const showComparison = Boolean( activeEntry?.referenceSrc );
	const comparisonLeftLabel = sprintf(
		/* translators: %d: version number */
		__( 'Version %d', 'ai' ),
		( activeEntry?.referenceHistoryIndex ?? 0 ) + 1
	);
	const comparisonRightLabel = sprintf(
		/* translators: %d: version number */
		__( 'Version %d', 'ai' ),
		historyIndex + 1
	);

	// Most recently saved upload.
	const lastSaved = savedUploads[ savedUploads.length - 1 ] ?? null;

	return (
		<div className="ai-generate-image-standalone">
			{ state === 'idle' && (
				<div className="ai-generate-image-standalone__idle">
					<p className="description">
						{ __(
							'Describe the image you want to generate.',
							'ai'
						) }
					</p>
					<TextareaControl
						label={ __( 'Prompt', 'ai' ) }
						value={ prompt }
						onChange={ setPrompt }
						rows={ 4 }
						hideLabelFromVision
						__nextHasNoMarginBottom
					/>
					<div className="ai-generate-image-standalone__actions">
						<Button
							variant="primary"
							disabled={ ! prompt.trim() }
							onClick={ () => generate( prompt.trim() ) }
						>
							{ __( 'Generate', 'ai' ) }
						</Button>
					</div>
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
				</div>
			) }

			{ state === 'generating' && (
				<div className="ai-generate-image-standalone__generating">
					{ previewSrc && (
						<img
							src={ previewSrc }
							alt={ activeEntry?.generatedData?.prompt ?? '' }
							className="ai-generate-image-standalone__preview-image"
						/>
					) }
					<div className="ai-generate-image-standalone__spinner-row">
						<Spinner />
						<span>{ progress }</span>
					</div>
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
				</div>
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
					<div className="ai-image-history-nav">
						<Button
							className="ai-image-history-nav__arrow"
							icon={ chevronLeft }
							disabled={ ! canGoBack }
							onClick={ goBack }
							label={ __( 'Previous version', 'ai' ) }
						/>
						<div className="ai-image-history-nav__content">
							{ showComparison ? (
								<div className="ai-generate-image-standalone__comparison">
									<div className="ai-generate-image-standalone__comparison-item">
										<p className="ai-generate-image-standalone__comparison-label">
											{ comparisonLeftLabel }
										</p>
										<img
											src={
												activeEntry?.referenceSrc ?? ''
											}
											alt={ comparisonLeftLabel }
											className="ai-generate-image-standalone__preview-image"
										/>
									</div>
									<div className="ai-generate-image-standalone__comparison-item">
										<p className="ai-generate-image-standalone__comparison-label">
											{ comparisonRightLabel }
										</p>
										<img
											src={ previewSrc }
											alt={
												activeEntry?.generatedData
													?.prompt ?? ''
											}
											className="ai-generate-image-standalone__preview-image is-active"
										/>
									</div>
								</div>
							) : (
								<img
									src={ previewSrc }
									alt={
										activeEntry?.generatedData?.prompt ?? ''
									}
									className="ai-generate-image-standalone__preview-image is-active"
								/>
							) }
						</div>
						<Button
							className="ai-image-history-nav__arrow"
							icon={ chevronRight }
							disabled={ ! canGoForward }
							onClick={ goForward }
							label={ __( 'Next version', 'ai' ) }
						/>
					</div>
					{ history.length > 1 && (
						<p className="ai-image-history-nav__counter">
							{ sprintf(
								/* translators: 1: current position, 2: total count */
								__( '%1$d / %2$d', 'ai' ),
								historyIndex + 1,
								history.length
							) }
						</p>
					) }
					<div className="ai-generate-image-standalone__actions">
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
							onClick={ () => {
								generate(
									activeEntry?.generatedData.prompt ??
										prompt.trim(),
									activeEntry?.referenceSrc,
									activeEntry?.referenceHistoryIndex
								);
							} }
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
				<div className="ai-generate-image-standalone__refining">
					<img
						src={ previewSrc }
						alt={ activeEntry?.generatedData?.prompt ?? '' }
						className="ai-generate-image-standalone__preview-image"
					/>
					<TextareaControl
						label={ __(
							'Describe the refinements you want to make to the image.',
							'ai'
						) }
						value={ refinePrompt }
						onChange={ setRefinePrompt }
						rows={ 3 }
						__nextHasNoMarginBottom
					/>
					<div className="ai-generate-image-standalone__actions">
						<Button
							variant="primary"
							disabled={ ! refinePrompt.trim() }
							onClick={ () =>
								generate(
									refinePrompt.trim(),
									previewSrc,
									historyIndex
								)
							}
						>
							{ __( 'Refine', 'ai' ) }
						</Button>
						<Button
							variant="tertiary"
							isDestructive
							onClick={ () => {
								setState( 'preview' );
								setError( null );
							} }
						>
							{ __( 'Cancel Refinement', 'ai' ) }
						</Button>
					</div>
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
				</div>
			) }
		</div>
	);
}
