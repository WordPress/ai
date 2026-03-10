/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	TextareaControl,
	Spinner,
	Notice,
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';
import { uploadImage } from '../functions/upload-image';
import type {
	GeneratedImageData,
	ImageGenerationAbilityInput,
	UploadedImage,
} from '../types';

const { aiImageGenerationData } = window as any;

type ModalState = 'idle' | 'generating' | 'preview' | 'refining' | 'success';

/**
 * Standalone component for AI image generation in the Media Library.
 *
 * Supports a generate → preview → refine → save flow. After saving,
 * the user can generate another image or navigate to the saved attachment
 * in the Media Library.
 */
export function GenerateImageStandalone() {
	const [ state, setState ] = useState< ModalState >( 'idle' );
	const [ prompt, setPrompt ] = useState( '' );
	const [ refinePrompt, setRefinePrompt ] = useState( '' );
	const [ generatedData, setGeneratedData ] =
		useState< GeneratedImageData | null >( null );
	const [ uploadedData, setUploadedData ] = useState< UploadedImage | null >(
		null
	);
	const [ originalImageSrc, setOriginalImageSrc ] = useState< string | null >(
		null
	);
	const [ progress, setProgress ] = useState( '' );
	const [ error, setError ] = useState< string | null >( null );

	/**
	 * Runs the image generation ability with the given prompt and
	 * optional reference image for the refining flow.
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
			setState( referenceImage ? 'refining' : 'idle' );
		}
	}

	/**
	 * Uploads the generated image and saves it to the Media Library.
	 */
	async function handleSaveImage(): Promise< void > {
		if ( ! generatedData ) {
			return;
		}

		setError( null );
		setState( 'generating' );
		setProgress( __( 'Uploading image to Media Library…', 'ai' ) );

		try {
			const uploaded: UploadedImage = await uploadImage( generatedData, {
				onProgress: setProgress,
				altTextEnabled: aiImageGenerationData?.altTextEnabled,
			} );

			setUploadedData( uploaded );
			setState( 'success' );
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
		<div className="ai-generate-image-standalone">
			{ state === 'success' && uploadedData && (
				<div className="ai-generate-image-standalone__success">
					<Notice status="success" isDismissible={ false }>
						{ __(
							'Image successfully added to the Media Library.',
							'ai'
						) }
					</Notice>
					<img
						src={ uploadedData.url }
						alt={ uploadedData.title }
						className="ai-generate-image-standalone__preview-image"
					/>
					<div className="ai-generate-image-standalone__actions">
						<Button
							variant="secondary"
							onClick={ () => {
								setGeneratedData( null );
								setUploadedData( null );
								setPrompt( '' );
								setState( 'idle' );
								setError( null );
							} }
						>
							{ __( 'Generate Another Image', 'ai' ) }
						</Button>
						<Button
							variant="secondary"
							href={ `upload.php?item=${ uploadedData.id }` }
						>
							{ __( 'View in Media Library', 'ai' ) }
						</Button>
					</div>
				</div>
			) }

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
							alt={ generatedData?.prompt ?? '' }
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
					{ hasRefinedResult ? (
						<div className="ai-generate-image-standalone__comparison">
							<div className="ai-generate-image-standalone__comparison-item">
								<p className="ai-generate-image-standalone__comparison-label">
									{ __( 'Original image', 'ai' ) }
								</p>
								<img
									src={ originalImageSrc ?? '' }
									alt={ __(
										'Original generated image',
										'ai'
									) }
									className="ai-generate-image-standalone__preview-image"
								/>
							</div>
							<div className="ai-generate-image-standalone__comparison-item">
								<p className="ai-generate-image-standalone__comparison-label">
									{ __( 'Refined image', 'ai' ) }
								</p>
								<img
									src={ previewSrc }
									alt={ generatedData?.prompt ?? '' }
									className="ai-generate-image-standalone__preview-image"
								/>
							</div>
						</div>
					) : (
						<img
							src={ previewSrc }
							alt={ generatedData?.prompt ?? '' }
							className="ai-generate-image-standalone__preview-image"
						/>
					) }
					<div className="ai-generate-image-standalone__actions">
						<Button variant="primary" onClick={ handleSaveImage }>
							{ __( 'Save to Media Library', 'ai' ) }
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
						<Button
							variant="tertiary"
							isDestructive
							onClick={ () => {
								setGeneratedData( null );
								setOriginalImageSrc( null );
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
						alt={ generatedData?.prompt ?? '' }
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
								generate( refinePrompt.trim(), previewSrc )
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
