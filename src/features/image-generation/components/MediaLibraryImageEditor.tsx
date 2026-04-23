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
import { useState, useRef, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	TextareaControl,
	RangeControl,
	Spinner,
	Notice,
	Icon,
} from '@wordpress/components';
import { chevronLeft, chevronRight } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';
import {
	urlToBase64,
	prepareExpandCanvas,
	compositeDrawing,
} from '../../../utils/image';
import { uploadImage } from '../functions/upload-image';
import { useImageHistory } from '../hooks/useImageHistory';
import type {
	GeneratedImageData,
	ImageGenerationAbilityInput,
	UploadedImage,
} from '../types';
import { MaskCanvas } from './MaskCanvas';
import type { MaskCanvasHandle } from './MaskCanvas';

type EditorState =
	| 'idle'
	| 'masking'
	| 'generating'
	| 'preview'
	| 'refining'
	| 'saving';

type MaskMode = 'remove' | 'replace';

interface MaskingSource {
	src: string;
	fromState: 'idle' | 'preview' | 'refining';
	historyIndex?: number | undefined;
}

const REMOVE_ITEM_PROMPT = __(
	'Remove the item circled/marked in red from the image. Replace the marked area naturally by extending the surrounding background, textures, and patterns. Seamlessly match the lighting, colors, perspective, and style. Do not introduce any new objects. The red marking is only an annotation and should not appear in the result.',
	'ai'
);

const REPLACE_PROMPT_PREFIX = __(
	'Replace the item circled/marked in red in the image with:',
	'ai'
);

const REPLACE_PROMPT_SUFFIX = __(
	'. Blend the replacement naturally with the surrounding image, matching lighting, perspective, and style. The red marking is only an annotation and should not appear in the result.',
	'ai'
);

interface Preset {
	label: string;
	prompt: string;
	icon: React.JSX.Element;
	prepare?: ( url: string ) => Promise< string >;
	requiresMask?: MaskMode;
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
	{
		label: __( 'Remove Item', 'ai' ),
		prompt: REMOVE_ITEM_PROMPT,
		icon: <Icon icon="editor-removeformatting" />,
		requiresMask: 'remove',
	},
	{
		label: __( 'Replace Item', 'ai' ),
		prompt: '',
		icon: <Icon icon="migrate" />,
		requiresMask: 'replace',
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

	// Mask editing state.
	const [ maskMode, setMaskMode ] = useState< MaskMode | null >( null );
	const [ brushSize, setBrushSize ] = useState( 15 );
	const [ replacePrompt, setReplacePrompt ] = useState( '' );
	const [ hasMask, setHasMask ] = useState( false );
	const [ maskingSource, setMaskingSource ] =
		useState< MaskingSource | null >( null );
	const maskCanvasRef = useRef< MaskCanvasHandle >( null );

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
	 * @param {string|undefined} displayReference  Unmodified image to show in comparison; defaults to referenceOverride.
	 */
	async function handleGenerate(
		activePrompt: string = prompt.trim(),
		referenceOverride?: string,
		isRefinement: boolean = false,
		refHistoryIndex?: number,
		displayReference?: string
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

			const historyReference = displayReference ?? referenceOverride;
			const prevData = activeEntry?.generatedData;
			const previousPrompts = historyReference
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
				historyReference,
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
		setReplacePrompt( '' );
		setMaskMode( null );
		setMaskingSource( null );
		setHasMask( false );
		setError( null );
		setState( 'idle' );
	}

	/**
	 * Enters masking mode for a mask-based preset.
	 *
	 * @param {MaskMode}                    mode      Whether this is a remove or replace operation.
	 * @param {'idle'|'preview'|'refining'} fromState The editor state to return to on cancel.
	 * @param {string}                      src       Image source to draw the mask on.
	 * @param {number|undefined}            hIndex    History index when entering from refining.
	 */
	function enterMasking(
		mode: MaskMode,
		fromState: 'idle' | 'preview' | 'refining',
		src: string,
		hIndex?: number
	): void {
		setMaskMode( mode );
		setMaskingSource( { src, fromState, historyIndex: hIndex } );
		setReplacePrompt( '' );
		setHasMask( false );
		setError( null );
		setState( 'masking' );
	}

	/**
	 * Handles the "Apply" action in masking mode.
	 *
	 * Composites the user's red drawing onto the source image and sends
	 * the annotated image to the AI with a prompt describing the intent.
	 */
	const handleMaskApply = useCallback( async () => {
		if ( ! maskingSource || ! maskCanvasRef.current ) {
			return;
		}

		const canvas = maskCanvasRef.current.getCanvas();
		if ( ! canvas ) {
			return;
		}

		try {
			const annotatedImage = await compositeDrawing(
				maskingSource.src,
				canvas
			);

			const activePrompt =
				maskMode === 'remove'
					? REMOVE_ITEM_PROMPT
					: `${ REPLACE_PROMPT_PREFIX } ${ replacePrompt.trim() }${ REPLACE_PROMPT_SUFFIX }`;

			const isRefinement = maskingSource.fromState === 'refining';

			handleGenerate(
				activePrompt,
				annotatedImage,
				isRefinement,
				maskingSource.historyIndex,
				maskingSource.src
			);
		} catch ( err: any ) {
			setError(
				err?.message ?? __( 'Failed to apply drawing to image.', 'ai' )
			);
		}
	}, [ maskingSource, maskMode, replacePrompt ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const handleMaskChange = useCallback( ( value: boolean ) => {
		setHasMask( value );
	}, [] );

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
									if ( preset.requiresMask ) {
										enterMasking(
											preset.requiresMask,
											'idle',
											attachmentUrl
										);
										return;
									}
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

			{ state === 'masking' && maskingSource && (
				<div className="ai-media-library-editor__masking">
					<MaskCanvas
						ref={ maskCanvasRef }
						imageSrc={ maskingSource.src }
						brushSize={ brushSize }
						onMaskChange={ handleMaskChange }
					/>
					<div className="ai-media-library-editor__masking-sidebar">
						<RangeControl
							__nextHasNoMarginBottom
							label={ __( 'Brush size', 'ai' ) }
							value={ brushSize }
							onChange={ ( value ) =>
								setBrushSize( value ?? 15 )
							}
							min={ 5 }
							max={ 100 }
							__next40pxDefaultSize
						/>
						<div className="ai-media-library-editor__masking-sidebar-buttons">
							<Button
								variant="secondary"
								onClick={ () => maskCanvasRef.current?.undo() }
							>
								{ __( 'Undo', 'ai' ) }
							</Button>
							<Button
								variant="secondary"
								onClick={ () => maskCanvasRef.current?.clear() }
							>
								{ __( 'Clear', 'ai' ) }
							</Button>
						</div>
						{ maskMode === 'replace' && (
							<TextareaControl
								label={ __(
									'Describe what to replace with',
									'ai'
								) }
								value={ replacePrompt }
								onChange={ setReplacePrompt }
								rows={ 2 }
								__nextHasNoMarginBottom
							/>
						) }
						<div className="ai-media-library-editor__masking-sidebar-actions">
							<Button
								variant="primary"
								disabled={
									! hasMask ||
									( maskMode === 'replace' &&
										! replacePrompt.trim() )
								}
								onClick={ handleMaskApply }
							>
								{ maskMode === 'remove'
									? __( 'Remove', 'ai' )
									: __( 'Replace', 'ai' ) }
							</Button>
							<Button
								variant="tertiary"
								onClick={ () => {
									setError( null );
									setState( maskingSource.fromState );
									setMaskMode( null );
									setMaskingSource( null );
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
									if ( preset.requiresMask ) {
										enterMasking(
											preset.requiresMask,
											'preview',
											previewSrc,
											historyIndex
										);
										return;
									}
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
								onClick={ () => {
									if ( preset.requiresMask && previewSrc ) {
										enterMasking(
											preset.requiresMask,
											'refining',
											previewSrc,
											historyIndex
										);
										return;
									}
									handleGenerate(
										preset.prompt,
										previewSrc,
										true,
										historyIndex
									);
								} }
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
