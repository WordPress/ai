/**
 * AI editing panel for the WordPress Media Library image editor.
 *
 * Renders an "AI Edit" toggle button inside the native image editor toolbar
 * (via a React portal) and an expandable panel between the toolbar and the
 * image. Applies AI edits to the existing attachment and saves the result
 * as a new attachment (non-destructive).
 */

/**
 * WordPress dependencies
 */
import { useState, createPortal } from '@wordpress/element';
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
import { urlToBase64 } from '../../../utils/image';
import { uploadImage } from '../functions/upload-image';
import type {
	GeneratedImageData,
	ImageGenerationAbilityInput,
	UploadedImage,
} from '../types';

type EditorState =
	| 'idle'
	| 'generating'
	| 'preview'
	| 'saving'
	| 'success'
	| 'error';

const PRESETS = [
	{
		label: __( 'Expand background', 'ai' ),
		prompt: __(
			'Expand the background of this image, extending it naturally in all directions while keeping the main subject intact. Do not make any edits to the main subject. Only expand the background.',
			'ai'
		),
	},
	{
		label: __( 'Remove background', 'ai' ),
		prompt: __(
			'Remove the background from this image, leaving only the main subject on a transparent or white background. Do not make any edits to the main subject. Only remove the background.',
			'ai'
		),
	},
];

interface Props {
	postId: number;
	attachmentUrl: string;
	buttonContainer: HTMLElement;
}

/**
 * AI editing panel for the WordPress Media Library image editor.
 *
 * Renders a toggle button into the native toolbar via a React portal, and an
 * expandable panel between the toolbar and the image canvas.
 *
 * @param {Props} props Component props.
 */
export function MediaLibraryImageEditor( {
	attachmentUrl,
	buttonContainer,
}: Props ) {
	const [ panelOpen, setPanelOpen ] = useState( false );
	const [ state, setState ] = useState< EditorState >( 'idle' );
	const [ prompt, setPrompt ] = useState( '' );
	const [ generatedData, setGeneratedData ] =
		useState< GeneratedImageData | null >( null );
	const [ uploadedData, setUploadedData ] = useState< UploadedImage | null >(
		null
	);
	const [ errorMessage, setErrorMessage ] = useState< string | null >( null );

	/**
	 * Generates an AI-edited version of the attachment image.
	 *
	 * @param {string} activePrompt The prompt to use; defaults to the textarea value.
	 */
	async function handleGenerate(
		activePrompt: string = prompt.trim()
	): Promise< void > {
		setErrorMessage( null );
		setState( 'generating' );

		try {
			const base64 = await urlToBase64( attachmentUrl );
			const input: ImageGenerationAbilityInput = {
				prompt: activePrompt,
				reference: base64,
			};

			const response = ( await runAbility(
				'ai/image-generation',
				input
			) ) as GeneratedImageData;

			if ( ! response?.image ) {
				throw new Error(
					__( 'Invalid response from image generation.', 'ai' )
				);
			}

			setGeneratedData( { ...response, prompt: activePrompt } );
			setState( 'preview' );
		} catch ( err: any ) {
			setErrorMessage(
				err?.message ||
					__( 'An error occurred during image generation.', 'ai' )
			);
			setState( 'error' );
		}
	}

	/**
	 * Saves the AI-generated image as a new media library attachment.
	 */
	async function handleSave(): Promise< void > {
		if ( ! generatedData ) {
			return;
		}

		setErrorMessage( null );
		setState( 'saving' );

		try {
			const uploaded = await uploadImage( generatedData );
			setUploadedData( uploaded );
			setState( 'success' );
		} catch ( err: any ) {
			setErrorMessage(
				err?.message || __( 'Failed to save image.', 'ai' )
			);
			setState( 'error' );
		}
	}

	/**
	 * Resets the panel back to the idle state.
	 */
	function handleReset(): void {
		setGeneratedData( null );
		setUploadedData( null );
		setPrompt( '' );
		setErrorMessage( null );
		setState( 'idle' );
	}

	const previewSrc = generatedData?.image?.data
		? `data:image/png;base64,${ generatedData.image.data }`
		: null;

	const toggleButton = (
		<button
			type="button"
			className={ `button ai-media-library-editor__toggle-btn${
				panelOpen ? ' active' : ''
			}` }
			aria-expanded={ panelOpen }
			onClick={ () => setPanelOpen( ( open ) => ! open ) }
		>
			{ __( 'AI Edit', 'ai' ) }
		</button>
	);

	return (
		<>
			{ createPortal( toggleButton, buttonContainer ) }

			{ panelOpen && (
				<div className="ai-media-library-editor">
					{ state === 'idle' && (
						<div className="ai-media-library-editor__idle">
							<div className="ai-media-library-editor__presets">
								{ PRESETS.map( ( preset ) => (
									<Button
										key={ preset.label }
										variant="secondary"
										onClick={ () =>
											handleGenerate( preset.prompt )
										}
									>
										{ preset.label }
									</Button>
								) ) }
							</div>
							<TextareaControl
								label={ __(
									'Describe the edits you want to make',
									'ai'
								) }
								value={ prompt }
								onChange={ setPrompt }
								rows={ 3 }
								__nextHasNoMarginBottom
							/>
							<div className="ai-media-library-editor__actions">
								<Button
									variant="primary"
									disabled={ ! prompt.trim() }
									onClick={ () => handleGenerate() }
								>
									{ __( 'Generate', 'ai' ) }
								</Button>
							</div>
						</div>
					) }

					{ state === 'generating' && (
						<div className="ai-media-library-editor__generating">
							<div className="ai-media-library-editor__spinner-row">
								<Spinner />
								<span>{ __( 'Generating image…', 'ai' ) }</span>
							</div>
						</div>
					) }

					{ state === 'preview' && previewSrc && (
						<div className="ai-media-library-editor__preview">
							<div className="ai-media-library-editor__comparison">
								<div className="ai-media-library-editor__comparison-item">
									<p className="ai-media-library-editor__comparison-label">
										{ __( 'Original', 'ai' ) }
									</p>
									<img
										src={ attachmentUrl }
										alt={ __( 'Original image', 'ai' ) }
										className="ai-media-library-editor__preview-image"
									/>
								</div>
								<div className="ai-media-library-editor__comparison-item">
									<p className="ai-media-library-editor__comparison-label">
										{ __( 'Edited', 'ai' ) }
									</p>
									<img
										src={ previewSrc }
										alt={ generatedData?.prompt ?? '' }
										className="ai-media-library-editor__preview-image"
									/>
								</div>
							</div>
							<div className="ai-media-library-editor__actions">
								<Button
									variant="primary"
									onClick={ handleSave }
								>
									{ __( 'Save as new image', 'ai' ) }
								</Button>
								<Button
									variant="secondary"
									onClick={ handleReset }
								>
									{ __( 'Try again', 'ai' ) }
								</Button>
							</div>
						</div>
					) }

					{ state === 'saving' && (
						<div className="ai-media-library-editor__saving">
							<div className="ai-media-library-editor__spinner-row">
								<Spinner />
								<span>
									{ __( 'Saving to Media Library…', 'ai' ) }
								</span>
							</div>
						</div>
					) }

					{ state === 'success' && uploadedData && (
						<div className="ai-media-library-editor__success">
							<Notice status="success" isDismissible={ false }>
								{ __( 'Image saved!', 'ai' ) }{ ' ' }
								<a
									href={ `upload.php?item=${ uploadedData.id }` }
								>
									{ __( 'View new image', 'ai' ) }
								</a>
							</Notice>
							<div className="ai-media-library-editor__actions">
								<Button
									variant="secondary"
									onClick={ handleReset }
								>
									{ __( 'Edit again', 'ai' ) }
								</Button>
							</div>
						</div>
					) }

					{ state === 'error' && (
						<div className="ai-media-library-editor__error">
							{ errorMessage && (
								<Notice status="error" isDismissible={ false }>
									{ errorMessage }
								</Notice>
							) }
							<div className="ai-media-library-editor__actions">
								<Button
									variant="secondary"
									onClick={ handleReset }
								>
									{ __( 'Try again', 'ai' ) }
								</Button>
							</div>
						</div>
					) }
				</div>
			) }
		</>
	);
}
