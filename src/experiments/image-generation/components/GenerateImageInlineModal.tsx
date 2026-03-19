/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Modal,
	Button,
	TextareaControl,
	Spinner,
	Notice,
} from '@wordpress/components';
import { image } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';
import { uploadImage } from '../functions/upload-image';
import { insertIntoBlock } from '../functions/insert-into-block';
import { openGalleryMediaLibraryWithImage } from '../functions/open-gallery-media-library';
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
	const [ generatedData, setGeneratedData ] =
		useState< GeneratedImageData | null >( null );
	const [ originalImageSrc, setOriginalImageSrc ] = useState< string | null >(
		null
	);
	const [ progress, setProgress ] = useState( '' );
	const [ error, setError ] = useState< string | null >( null );

	/**
	 * Runs the image generation ability with the given prompt and optional
	 * reference image for the refining flow.
	 *
	 * @param {string}           activePrompt   The prompt to generate an image from.
	 * @param {string|undefined} referenceImage Optional base64 image for refining.
	 */
	async function generate(
		activePrompt: string,
		referenceImage?: string
	): Promise< void > {
		setError( null );
		setState( 'generating' );
		setProgress( __( 'Generating image…', 'ai' ) );

		try {
			const input: ImageGenerationAbilityInput = { prompt: activePrompt };
			if ( referenceImage ) {
				input.reference = referenceImage;
			} else {
				setOriginalImageSrc( null );
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

			setGeneratedData( ( previousData ) => {
				const previousPrompts = referenceImage
					? previousData?.prompts ?? [ previousData?.prompt ?? '' ]
					: [];
				const promptHistory = previousPrompts.filter( Boolean );
				const lastPrompt = promptHistory[ promptHistory.length - 1 ];
				return {
					...response,
					prompt: activePrompt,
					prompts:
						lastPrompt === activePrompt
							? promptHistory
							: [ ...promptHistory, activePrompt ],
				};
			} );
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
		if ( ! generatedData ) {
			return;
		}

		setError( null );
		setState( 'generating' );
		setProgress( __( 'Uploading image…', 'ai' ) );

		try {
			const uploaded: UploadedImage = await uploadImage( generatedData, {
				onProgress: setProgress,
				altTextEnabled: aiImageGenerationData?.altTextEnabled,
			} );

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

	const previewSrc = generatedData?.image?.data
		? `data:image/png;base64,${ generatedData.image.data }`
		: null;
	const hasRefinedResult = Boolean(
		originalImageSrc &&
			generatedData?.prompts &&
			generatedData.prompts.length > 1
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
							alt={ generatedData?.prompt ?? '' }
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
					{ hasRefinedResult ? (
						<div className="ai-generate-image-inline-modal__comparison">
							<div className="ai-generate-image-inline-modal__comparison-item">
								<p className="ai-generate-image-inline-modal__comparison-label">
									{ __( 'Original image', 'ai' ) }
								</p>
								<img
									src={ originalImageSrc ?? '' }
									alt={ __(
										'Original generated image',
										'ai'
									) }
									className="ai-generate-image-inline-modal__preview-image"
								/>
							</div>
							<div className="ai-generate-image-inline-modal__comparison-item">
								<p className="ai-generate-image-inline-modal__comparison-label">
									{ __( 'Refined image', 'ai' ) }
								</p>
								<img
									src={ previewSrc }
									alt={ generatedData?.prompt ?? '' }
									className="ai-generate-image-inline-modal__preview-image"
								/>
							</div>
						</div>
					) : (
						<img
							src={ previewSrc }
							alt={ generatedData?.prompt ?? '' }
							className="ai-generate-image-inline-modal__preview-image"
						/>
					) }
					<div className="ai-generate-image-inline-modal__actions">
						<Button variant="primary" onClick={ handleUseImage }>
							{ __( 'Use Image', 'ai' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ () => {
								setOriginalImageSrc( previewSrc );
								setRefinePrompt( '' );
								setState( 'refining' );
							} }
						>
							{ __( 'Refine Image', 'ai' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ () => {
								if ( hasRefinedResult ) {
									setOriginalImageSrc( originalImageSrc );
									generate(
										refinePrompt.trim(),
										originalImageSrc ?? undefined
									);
								} else {
									generate( prompt.trim() );
								}
							} }
						>
							{ __( 'Generate Another Image', 'ai' ) }
						</Button>
						<Button
							variant="tertiary"
							onClick={ () => {
								setGeneratedData( null );
								setOriginalImageSrc( null );
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
						alt={ generatedData?.prompt ?? '' }
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
								generate( refinePrompt.trim(), previewSrc )
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
