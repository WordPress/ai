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

type ModalState = 'idle' | 'generating' | 'preview' | 'success';

/**
 * Standalone component for AI image generation in the Media Library.
 *
 * Supports a generate → preview → save flow. After saving, the user can
 * generate another image or navigate to the saved attachment in the
 * Media Library.
 */
export function GenerateImageStandalone() {
	const [ state, setState ] = useState< ModalState >( 'idle' );
	const [ prompt, setPrompt ] = useState( '' );

	const [ generatedData, setGeneratedData ] =
		useState< GeneratedImageData | null >( null );
	const [ uploadedData, setUploadedData ] = useState< UploadedImage | null >(
		null
	);
	const [ progress, setProgress ] = useState( '' );
	const [ error, setError ] = useState< string | null >( null );

	/**
	 * Runs the image generation ability with the given prompt.
	 *
	 * @param {string} activePrompt The prompt to generate an image from.
	 */
	async function generate( activePrompt: string ): Promise< void > {
		setError( null );
		setState( 'generating' );
		setProgress( __( 'Generating image…', 'ai' ) );

		try {
			const input: ImageGenerationAbilityInput = { prompt: activePrompt };

			const response = ( await runAbility(
				'ai/image-generation',
				input
			) ) as GeneratedImageData;

			if ( ! response || ! response.image ) {
				throw new Error(
					__( 'Invalid response from image generation', 'ai' )
				);
			}

			setGeneratedData( {
				...response,
				prompt: activePrompt,
				prompts: [ activePrompt ],
			} );
			setState( 'preview' );
		} catch ( err: any ) {
			const message: string =
				err?.message ||
				__( 'An error occurred during image generation.', 'ai' );

			setError( message );
			setState( 'idle' );
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
						style={ {
							maxWidth: '400px',
							display: 'block',
							margin: '20px 0',
						} }
					/>
					<div
						style={ {
							display: 'flex',
							gap: '10px',
							alignItems: 'center',
							marginTop: '10px',
						} }
					>
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
				<div
					className="ai-generate-image-standalone__idle"
					style={ { maxWidth: '600px' } }
				>
					<p
						className="description"
						style={ { marginBottom: '10px' } }
					>
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
					<div
						className="ai-generate-image-standalone__actions"
						style={ { marginTop: '15px' } }
					>
						<Button
							variant="primary"
							disabled={ ! prompt.trim() }
							onClick={ () => generate( prompt.trim() ) }
						>
							{ __( 'Generate', 'ai' ) }
						</Button>
					</div>
					{ error && (
						<div style={ { marginTop: '15px' } }>
							<Notice status="error" isDismissible={ false }>
								{ error }
							</Notice>
						</div>
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
							style={ {
								maxWidth: '400px',
								opacity: 0.5,
								display: 'block',
								margin: '20px 0',
							} }
						/>
					) }
					<div
						className="ai-generate-image-standalone__spinner-row"
						style={ {
							display: 'flex',
							alignItems: 'center',
							gap: '10px',
						} }
					>
						<Spinner />
						<span>{ progress }</span>
					</div>
					{ error && (
						<div style={ { marginTop: '15px' } }>
							<Notice status="error" isDismissible={ false }>
								{ error }
							</Notice>
						</div>
					) }
				</div>
			) }

			{ state === 'preview' && previewSrc && (
				<div className="ai-generate-image-standalone__preview">
					<img
						src={ previewSrc }
						alt={ generatedData?.prompt ?? '' }
						className="ai-generate-image-standalone__preview-image"
						style={ {
							maxWidth: '600px',
							display: 'block',
							margin: '20px 0',
							border: '1px solid #ddd',
						} }
					/>
					<div
						className="ai-generate-image-standalone__actions"
						style={ {
							display: 'flex',
							gap: '10px',
							marginTop: '15px',
						} }
					>
						<Button variant="primary" onClick={ handleSaveImage }>
							{ __( 'Save to Media Library', 'ai' ) }
						</Button>

						<Button
							variant="secondary"
							onClick={ () => generate( prompt.trim() ) }
						>
							{ __( 'Regenerate', 'ai' ) }
						</Button>
						<Button
							variant="tertiary"
							onClick={ () => {
								setGeneratedData( null );
								setState( 'idle' );
								setError( null );
							} }
						>
							{ __( 'Cancel', 'ai' ) }
						</Button>
					</div>
					{ error && (
						<div style={ { marginTop: '15px' } }>
							<Notice status="error" isDismissible={ false }>
								{ error }
							</Notice>
						</div>
					) }
				</div>
			) }
		</div>
	);
}
