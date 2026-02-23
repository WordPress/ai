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

type ModalState = 'idle' | 'generating' | 'preview' | 'editing';

interface Props {
	blockName: string;
	clientId: string;
	setAttributes: ( attrs: Record< string, unknown > ) => void;
	onClose: () => void;
}

/**
 * Modal component for inline AI image generation in the block editor.
 *
 * Supports a generate → preview → edit flow. When editing, the current
 * preview image is sent as a reference to the generation ability so that
 * models supporting image editing can use it as context.
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
	const [ editPrompt, setEditPrompt ] = useState( '' );
	const [ generatedData, setGeneratedData ] =
		useState< GeneratedImageData | null >( null );
	const [ progress, setProgress ] = useState( '' );
	const [ error, setError ] = useState< string | null >( null );

	/**
	 * Runs the image generation ability with the given prompt and optional
	 * reference image for the edit flow.
	 *
	 * @param {string}           activePrompt   The prompt to generate an image from.
	 * @param {string|undefined} referenceImage Optional base64 image for editing.
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
				input.reference_image = referenceImage;
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

			setGeneratedData( { ...response, prompt: activePrompt } );
			setState( 'preview' );
		} catch ( err: any ) {
			const message: string =
				err?.message ||
				__( 'An error occurred during image generation.', 'ai' );

			setError( message );

			// Return to the previous state so the user can try again.
			setState( referenceImage ? 'editing' : 'idle' );
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
					<img
						src={ previewSrc }
						alt={ generatedData?.prompt ?? '' }
						className="ai-generate-image-inline-modal__preview-image"
					/>
					<div className="ai-generate-image-inline-modal__actions">
						<Button variant="primary" onClick={ handleUseImage }>
							{ __( 'Use Image', 'ai' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ () => {
								setEditPrompt( '' );
								setState( 'editing' );
							} }
						>
							{ __( 'Refine Image', 'ai' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ () => generate( prompt.trim() ) }
						>
							{ __( 'Generate Another Image', 'ai' ) }
						</Button>
						<Button
							variant="tertiary"
							onClick={ () => {
								setGeneratedData( null );
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

			{ /* EDITING — show current image + follow-up prompt */ }
			{ state === 'editing' && previewSrc && (
				<div className="ai-generate-image-inline-modal__editing">
					<img
						src={ previewSrc }
						alt={ generatedData?.prompt ?? '' }
						className="ai-generate-image-inline-modal__preview-image"
					/>
					<TextareaControl
						label={ __(
							'Describe the edits you want to make to the image.',
							'ai'
						) }
						value={ editPrompt }
						onChange={ setEditPrompt }
						rows={ 3 }
						__nextHasNoMarginBottom
					/>
					<div className="ai-generate-image-inline-modal__actions">
						<Button
							variant="primary"
							disabled={ ! editPrompt.trim() }
							onClick={ () =>
								generate(
									editPrompt.trim(),
									generatedData?.image?.data
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
