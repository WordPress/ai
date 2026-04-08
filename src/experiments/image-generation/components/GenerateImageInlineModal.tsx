/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Modal,
	Button,
	TextareaControl,
	Spinner,
	Notice,
} from '@wordpress/components';
import { image, chevronLeft, chevronRight } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';
import { uploadImage } from '../functions/upload-image';
import { insertIntoBlock } from '../functions/insert-into-block';
import { openGalleryMediaLibraryWithImage } from '../functions/open-gallery-media-library';
import { useImageHistory } from '../hooks/useImageHistory';
import type {
	GeneratedImageData,
	ImageGenerationAbilityInput,
	UploadedImage,
} from '../types';

const { aiImageGenerationData } = window as any;

type ModalState = 'idle' | 'generating' | 'preview' | 'refining';

interface Props {
	blockName: string;
	clientId: string;
	setAttributes: ( attrs: Record< string, unknown > ) => void;
	onClose: () => void;
}

/**
 * Modal component for inline AI image generation in the block editor.
 *
 * Supports a generate → preview →  refine → insert flow. When refining,
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
	const [ state, setState ] = useState< ModalState >( 'idle' );
	const [ prompt, setPrompt ] = useState( '' );
	const [ refinePrompt, setRefinePrompt ] = useState( '' );
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
	 * Runs the image generation ability with the given prompt and optional
	 * reference image for the refining flow.
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

			// Return to the previous state so the user can try again.
			setState( referenceImage ? 'refining' : 'idle' );
		}
	}

	/**
	 * Uploads the generated image and inserts it into the block.
	 */
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

	return (
		<Modal
			title={ __( 'Generate Image', 'ai' ) }
			onRequestClose={ onClose }
			icon={ image }
			size="large"
			className="ai-generate-image-inline-modal"
		>
			{ /* IDLE — initial prompt input */ }
			{ state === 'idle' && (
				<div className="ai-generate-image-inline-modal__idle">
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
					<div className="ai-generate-image-inline-modal__actions">
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

			{ /* GENERATING — spinner + progress message */ }
			{ state === 'generating' && (
				<div className="ai-generate-image-inline-modal__generating">
					{ previewSrc && (
						<img
							src={ previewSrc }
							alt={ activeEntry?.generatedData?.prompt ?? '' }
							className="ai-generate-image-inline-modal__preview-image"
						/>
					) }
					<div className="ai-generate-image-inline-modal__spinner-row">
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

			{ /* PREVIEW — show the generated image with action buttons */ }
			{ state === 'preview' && previewSrc && (
				<div className="ai-generate-image-inline-modal__preview">
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
								<div className="ai-generate-image-inline-modal__comparison">
									<div className="ai-generate-image-inline-modal__comparison-item">
										<p className="ai-generate-image-inline-modal__comparison-label">
											{ comparisonLeftLabel }
										</p>
										<img
											src={
												activeEntry?.referenceSrc ?? ''
											}
											alt={ comparisonLeftLabel }
											className="ai-generate-image-inline-modal__preview-image"
										/>
									</div>
									<div className="ai-generate-image-inline-modal__comparison-item">
										<p className="ai-generate-image-inline-modal__comparison-label">
											{ comparisonRightLabel }
										</p>
										<img
											src={ previewSrc }
											alt={
												activeEntry?.generatedData
													?.prompt ?? ''
											}
											className="ai-generate-image-inline-modal__preview-image is-active"
										/>
									</div>
								</div>
							) : (
								<img
									src={ previewSrc }
									alt={
										activeEntry?.generatedData?.prompt ?? ''
									}
									className="ai-generate-image-inline-modal__preview-image is-active"
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
					<div className="ai-generate-image-inline-modal__actions">
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
					</div>
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
				</div>
			) }

			{ /* REFINING — show current image + follow-up prompt */ }
			{ state === 'refining' && previewSrc && (
				<div className="ai-generate-image-inline-modal__refining">
					<img
						src={ previewSrc }
						alt={ activeEntry?.generatedData?.prompt ?? '' }
						className="ai-generate-image-inline-modal__preview-image"
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
					<div className="ai-generate-image-inline-modal__actions">
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
		</Modal>
	);
}
