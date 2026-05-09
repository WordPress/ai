/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';
import { useImageHistory } from './useImageHistory';
import type { GeneratedImageData, ImageGenerationAbilityInput } from '../types';

export type ImageGenerationState =
	| 'idle'
	| 'generating'
	| 'preview'
	| 'refining';

/**
 * Shared state and generation logic for image generation components.
 *
 * Encapsulates the generate() function, all UI state, and derived values
 * (previewSrc, comparison labels) that are identical across the inline modal
 * and standalone page.
 */
export function useImageGeneration() {
	const [ state, setState ] = useState< ImageGenerationState >( 'idle' );
	const [ prompt, setPrompt ] = useState( '' );
	const [ refinePrompt, setRefinePrompt ] = useState( '' );
	const [ progress, setProgress ] = useState( '' );
	const [ error, setError ] = useState< string | null >( null );

	const imageHistory = useImageHistory();
	const { activeEntry, historyIndex, addToHistory } = imageHistory;

	async function generate(
		activePrompt: string,
		referenceImage?: string,
		refHistoryIndex?: number
	): Promise< void > {
		setError( null );
		setState( 'generating' );
		setProgress( __( 'Generating…', 'ai' ) );

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
		} catch ( err: unknown ) {
			const message =
				err instanceof Error
					? err.message
					: __( 'An error occurred during image generation.', 'ai' );
			setError( message );
			setState( referenceImage ? 'refining' : 'idle' );
		}
	}

	const previewSrc = activeEntry?.generatedData?.image?.data
		? `data:image/png;base64,${ activeEntry.generatedData.image.data }`
		: null;

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

	return {
		state,
		setState,
		prompt,
		setPrompt,
		refinePrompt,
		setRefinePrompt,
		progress,
		setProgress,
		error,
		setError,
		...imageHistory,
		generate,
		previewSrc,
		showComparison,
		comparisonLeftLabel,
		comparisonRightLabel,
	};
}
