/**
 * AI editing panel for the WordPress Media Library image editor.
 *
 * Renders an "AI Edit" toggle button inside the native image editor toolbar
 * (via a React portal) and an expandable panel between the toolbar and the
 * image. Applies AI edits to the existing attachment and saves the result
 * as a new attachment.
 */

/**
 * WordPress dependencies
 */
import { useState, createPortal } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	TextareaControl,
	Spinner,
	Notice,
	Icon,
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
	| 'refining'
	| 'saving'
	| 'success';

const PRESETS = [
	{
		label: __( 'Expand background', 'ai' ),
		prompt: __(
			'Expand the background of this image, extending it naturally in all directions while keeping the main subject intact. Do not make any edits to the main subject. Only expand the background.',
			'ai'
		),
		icon: <Icon icon="editor-expand" />,
	},
	{
		label: __( 'Remove background', 'ai' ),
		prompt: __(
			'Remove the background from this image, leaving only the main subject on a transparent or white background. Do not make any edits to the main subject. Only remove the background.',
			'ai'
		),
		icon: <Icon icon="remove" />,
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
	const [ refinePrompt, setRefinePrompt ] = useState( '' );
	const [ generatedData, setGeneratedData ] =
		useState< GeneratedImageData | null >( null );
	const [ uploadedData, setUploadedData ] = useState< UploadedImage | null >(
		null
	);
	const [ error, setError ] = useState< string | null >( null );
	// Tracks the reference data URI used for the last generation.
	// `undefined` means the original attachment URL was used (fresh edit).
	// A string means a previously generated image was used (refinement).
	const [ lastReference, setLastReference ] = useState< string | undefined >(
		undefined
	);

	/**
	 * Generates an AI-refined version of the image.
	 *
	 * When `referenceOverride` is provided it is used directly as the
	 * reference image. Otherwise the attachment URL is fetched and
	 * converted to a data URI.
	 *
	 * @param {string}           activePrompt      Prompt to use for generation.
	 * @param {string|undefined} referenceOverride Data URI to refine; omit for fresh edits.
	 */
	async function handleGenerate(
		activePrompt: string = prompt.trim(),
		referenceOverride?: string
	): Promise< void > {
		setError( null );
		setLastReference( referenceOverride );
		setState( 'generating' );

		try {
			const reference =
				referenceOverride ?? ( await urlToBase64( attachmentUrl ) );

			const input: ImageGenerationAbilityInput = {
				prompt: activePrompt,
				reference,
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

			// Track prompt history for upload metadata.
			setGeneratedData( ( previousData ) => {
				const previousPrompts = referenceOverride
					? previousData?.prompts ??
					  ( previousData?.prompt ? [ previousData.prompt ] : [] )
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
			setError(
				err?.message ||
					__( 'An error occurred during image generation.', 'ai' )
			);
			// Return to whichever state triggered the generation.
			setState( referenceOverride ? 'refining' : 'idle' );
		}
	}

	/**
	 * Saves the AI-generated image as a new media library attachment.
	 */
	async function handleSave(): Promise< void > {
		if ( ! generatedData ) {
			return;
		}

		setError( null );
		setState( 'saving' );

		try {
			const uploaded = await uploadImage( generatedData );
			setUploadedData( uploaded );
			setState( 'success' );
		} catch ( err: any ) {
			setError( err?.message || __( 'Failed to save image.', 'ai' ) );
			setState( 'preview' );
		}
	}

	/**
	 * Resets the panel back to the idle state.
	 */
	function handleReset(): void {
		setGeneratedData( null );
		setUploadedData( null );
		setPrompt( '' );
		setRefinePrompt( '' );
		setError( null );
		setLastReference( undefined );
		setState( 'idle' );
	}

	const previewSrc = generatedData?.image?.data
		? `data:image/png;base64,${ generatedData.image.data }`
		: null;

	// Left comparison image = whatever was used as the reference for the last
	// generation. For a fresh edit that's the original attachment; for a
	// refinement it's the previously generated result.
	const comparisonLeftSrc = lastReference ?? attachmentUrl;
	// Refinement depth: 0 = fresh edit, 1 = first refinement, etc.
	const refinementDepth = ( generatedData?.prompts?.length ?? 1 ) - 1;
	const comparisonLeftLabel =
		lastReference === undefined
			? __( 'Original image', 'ai' )
			: sprintf(
					/* translators: %d: the refinement iteration number */
					__( 'Refined image #%d', 'ai' ),
					refinementDepth
			  );
	const comparisonRightLabel = sprintf(
		/* translators: %d: the refinement iteration number */
		__( 'Refined image #%d', 'ai' ),
		refinementDepth + 1
	);

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
										icon={ preset.icon }
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
									'Describe the refinements you want to make to the image.',
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
							{ error && (
								<Notice status="error" isDismissible={ false }>
									{ error }
								</Notice>
							) }
						</div>
					) }

					{ state === 'generating' && (
						<div className="ai-media-library-editor__generating">
							{ previewSrc && (
								<img
									src={ previewSrc }
									alt={ generatedData?.prompt ?? '' }
									className="ai-media-library-editor__preview-image"
								/>
							) }
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
										{ comparisonLeftLabel }
									</p>
									<img
										src={ comparisonLeftSrc }
										alt={ comparisonLeftLabel }
										className="ai-media-library-editor__preview-image"
									/>
								</div>
								<div className="ai-media-library-editor__comparison-item">
									<p className="ai-media-library-editor__comparison-label">
										{ comparisonRightLabel }
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
									{ __( 'Save to Media Library', 'ai' ) }
								</Button>
								<Button
									variant="secondary"
									onClick={ () => {
										setRefinePrompt( '' );
										setError( null );
										setState( 'refining' );
									} }
								>
									{ __( 'Refine Image', 'ai' ) }
								</Button>
								<Button
									variant="secondary"
									onClick={ () =>
										handleGenerate(
											generatedData?.prompt ?? '',
											lastReference
										)
									}
								>
									{ __( 'Generate Another Image', 'ai' ) }
								</Button>
								<Button
									variant="tertiary"
									isDestructive
									onClick={ handleReset }
								>
									{ __( 'Start over', 'ai' ) }
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
						<div className="ai-media-library-editor__refining">
							<img
								src={ previewSrc }
								alt={ generatedData?.prompt ?? '' }
								className="ai-media-library-editor__preview-image"
							/>
							<div className="ai-media-library-editor__presets">
								{ PRESETS.map( ( preset ) => (
									<Button
										key={ preset.label }
										variant="secondary"
										icon={ preset.icon }
										onClick={ () =>
											handleGenerate(
												preset.prompt,
												previewSrc
											)
										}
									>
										{ preset.label }
									</Button>
								) ) }
							</div>
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
							<div className="ai-media-library-editor__actions">
								<Button
									variant="primary"
									disabled={ ! refinePrompt.trim() }
									onClick={ () =>
										handleGenerate(
											refinePrompt.trim(),
											previewSrc
										)
									}
								>
									{ __( 'Apply', 'ai' ) }
								</Button>
								<Button
									variant="tertiary"
									onClick={ () => {
										setError( null );
										setState( 'preview' );
									} }
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
				</div>
			) }
		</>
	);
}
