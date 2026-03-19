/**
 * AI editing panel for the WordPress Media Library image editor.
 *
 * Renders preset action buttons and a Refine Image option directly
 * below the native image editor toolbar. Applies AI edits to the
 * existing attachment and saves the result as a new attachment.
 */

/**
 * WordPress dependencies
 */
import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	TextareaControl,
	Spinner,
	Notice,
	Icon,
} from '@wordpress/components';
import { chevronLeft, chevronRight } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';
import { urlToBase64, prepareExpandCanvas } from '../../../utils/image';
import { uploadImage } from '../functions/upload-image';
import { useImageHistory } from '../hooks/useImageHistory';
import type {
	GeneratedImageData,
	ImageGenerationAbilityInput,
	UploadedImage,
} from '../types';

type EditorState = 'idle' | 'generating' | 'preview' | 'refining' | 'saving';

interface Preset {
	label: string;
	prompt: string;
	icon: JSX.Element;
	prepare?: ( url: string ) => Promise< string >;
}

const PRESETS: Preset[] = [
	{
		label: __( 'Expand Background', 'ai' ),
		prompt: __(
			'Outpaint the image to create a wider panoramic view. Expand the scene outward in all directions to fill the empty transparent border while preserving the original style, lighting, colors, and perspective. Continue textures, structures, and environmental elements naturally so the extension blends seamlessly with the original image. Preserve the original image exactly and only generate content in the empty area.',
			'ai'
		),
		icon: <Icon icon="editor-expand" />,
		prepare: ( url: string ) => prepareExpandCanvas( url ),
	},
	{
		label: __( 'Remove Background', 'ai' ),
		prompt: __(
			'Remove the entire background and isolate the main subject. Replace the background with a pure solid white (#FFFFFF) background. Preserve all details of the subject and maintain natural, clean edges around the silhouette. Ensure there are no remaining environmental elements, textures, gradients, or shadows from the original background. The final result should look like a professional studio product photo with a perfectly clean white backdrop.',
			'ai'
		),
		icon: <Icon icon="remove" />,
	},
];

interface Props {
	postId: number;
	attachmentUrl: string;
	imagePanel?: HTMLElement;
}

/**
 * AI editing panel for the WordPress Media Library image editor.
 *
 * Shows preset action buttons and a Refine Image option directly in the panel
 * below the native image editor toolbar.
 *
 * @param {Props} props Component props.
 */
export function MediaLibraryImageEditor( {
	attachmentUrl,
	imagePanel,
}: Props ) {
	const [ state, setState ] = useState< EditorState >( 'idle' );

	const [ prompt, setPrompt ] = useState( '' );
	const [ refinePrompt, setRefinePrompt ] = useState( '' );
	const [ savedUpload, setSavedUpload ] = useState< UploadedImage | null >(
		null
	);
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

	// Hide the native image canvas once we have a generated image.
	useEffect( () => {
		if ( ! imagePanel ) {
			return;
		}
		const hasGeneratedImage = historyIndex >= 0;
		imagePanel.style.display = hasGeneratedImage ? 'none' : '';
		return () => {
			imagePanel.style.display = '';
		};
	}, [ historyIndex, imagePanel ] );

	/**
	 * Generates an AI-refined version of the image.
	 *
	 * When `referenceOverride` is provided it is used directly as the
	 * reference image. Otherwise the attachment URL is fetched and
	 * converted to a data URI.
	 *
	 * @param {string}           activePrompt      Prompt to use for generation.
	 * @param {string|undefined} referenceOverride Data URI to use as reference; omit for fresh edits.
	 * @param {boolean}          isRefinement      True when refining a previously generated image.
	 * @param {number|undefined} refHistoryIndex   History index of the entry whose image is the reference.
	 */
	async function handleGenerate(
		activePrompt: string = prompt.trim(),
		referenceOverride?: string,
		isRefinement: boolean = false,
		refHistoryIndex?: number
	): Promise< void > {
		setError( null );
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

			const prevData = activeEntry?.generatedData;
			const previousPrompts = referenceOverride
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
				referenceOverride,
				isRefinement,
				refHistoryIndex
			);

			setSavedUpload( null );
			setState( 'preview' );
		} catch ( err: any ) {
			setError(
				err?.message ||
					__( 'An error occurred during image generation.', 'ai' )
			);
			// Return to whichever state triggered the generation.
			setState( isRefinement ? 'refining' : 'idle' );
		}
	}

	/**
	 * Saves the active generated image to the Media Library.
	 */
	async function handleSave(): Promise< void > {
		if ( ! activeEntry ) {
			return;
		}

		setError( null );
		setState( 'saving' );

		try {
			const uploaded = await uploadImage( activeEntry.generatedData );
			setSavedUpload( uploaded );
			setState( 'preview' );
		} catch ( err: any ) {
			setError( err?.message || __( 'Failed to save image.', 'ai' ) );
			setState( 'preview' );
		}
	}

	/**
	 * Resets the panel back to the idle state.
	 */
	function handleReset(): void {
		resetHistory();
		setSavedUpload( null );
		setPrompt( '' );
		setRefinePrompt( '' );
		setError( null );
		setState( 'idle' );
	}

	const previewSrc = activeEntry?.generatedData?.image?.data
		? `data:image/png;base64,${ activeEntry.generatedData.image.data }`
		: null;

	// Left comparison image = the reference used to generate the active entry,
	// falling back to the original attachment URL.
	const comparisonLeftSrc = activeEntry?.referenceSrc ?? attachmentUrl;
	const comparisonLeftLabel =
		activeEntry?.referenceHistoryIndex === undefined
			? __( 'Original image', 'ai' )
			: sprintf(
					/* translators: %d: version number */
					__( 'Version %d', 'ai' ),
					activeEntry.referenceHistoryIndex + 1
			  );
	const comparisonRightLabel = sprintf(
		/* translators: %d: version number */
		__( 'Version %d', 'ai' ),
		historyIndex + 1
	);

	const [ showPrompt, setShowPrompt ] = useState( false );

	return (
		<div className="imgedit-panel-content ai-media-library-editor">
			{ state === 'idle' && (
				<div className="ai-media-library-editor__idle">
					<div className="ai-media-library-editor__presets">
						<Button
							variant="secondary"
							icon={ <Icon icon="format-image" /> }
							onClick={ () =>
								setShowPrompt( ( show ) => ! show )
							}
						>
							{ __( 'Refine Image', 'ai' ) }
						</Button>
						{ PRESETS.map( ( preset ) => (
							<Button
								key={ preset.label }
								variant="secondary"
								icon={ preset.icon }
								onClick={ async () => {
									const reference = preset.prepare
										? await preset.prepare( attachmentUrl )
										: undefined;
									handleGenerate( preset.prompt, reference );
								} }
							>
								{ preset.label }
							</Button>
						) ) }
					</div>
					{ showPrompt && (
						<>
							<TextareaControl
								label={ __(
									'Describe the refinements you want to make to the image',
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
						</>
					) }
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
							alt={ activeEntry?.generatedData?.prompt ?? '' }
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
					<div className="ai-media-library-editor__presets">
						{ PRESETS.map( ( preset ) => (
							<Button
								key={ preset.label }
								variant="secondary"
								icon={ preset.icon }
								onClick={ async () => {
									const reference = preset.prepare
										? await preset.prepare(
												previewSrc as string
										  )
										: previewSrc ?? undefined;
									handleGenerate(
										preset.prompt,
										reference,
										true,
										historyIndex
									);
								} }
							>
								{ preset.label }
							</Button>
						) ) }
					</div>
					{ savedUpload && (
						<Notice
							status="success"
							onDismiss={ () => setSavedUpload( null ) }
						>
							{ __( 'Image saved!', 'ai' ) }{ ' ' }
							<a href={ `upload.php?item=${ savedUpload.id }` }>
								{ __( 'View new image', 'ai' ) }
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
										alt={
											activeEntry?.generatedData
												?.prompt ?? ''
										}
										className="ai-media-library-editor__preview-image is-active"
									/>
								</div>
							</div>
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
					<div className="ai-media-library-editor__actions">
						<Button variant="primary" onClick={ handleSave }>
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
									activeEntry?.generatedData.prompt ?? '',
									activeEntry?.referenceSrc,
									activeEntry?.isRefinement ?? false,
									activeEntry?.referenceHistoryIndex
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
						alt={ activeEntry?.generatedData?.prompt ?? '' }
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
										previewSrc,
										true,
										historyIndex
									)
								}
							>
								{ preset.label }
							</Button>
						) ) }
					</div>
					<TextareaControl
						label={ __(
							'Describe the refinements you want to make to the image',
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
									previewSrc,
									true,
									historyIndex
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
						<span>{ __( 'Saving to Media Library…', 'ai' ) }</span>
					</div>
				</div>
			) }
		</div>
	);
}
