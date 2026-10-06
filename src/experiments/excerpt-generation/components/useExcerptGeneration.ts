/**
 * Shared hook for excerpt generation logic.
 */

/**
 * WordPress dependencies
 */
import { dispatch, useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useSyncExternalStore } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';
import { ensureProvider } from '../../../utils/provider-status';
import { hasMinimumContent } from '../../../utils/character-count';
import type {
	ExcerptGenerationAbilityInput,
	ExcerptGenerationData,
} from '../types';

const NOTICE_ID = 'ai_excerpt_generation_error';
const MINIMUM_CONTENT_COUNT_DEFAULT = 250;

// Module-level so the state survives the panel collapsing or the sidebar
// switching tabs, both of which unmount the component.
let globalIsGenerating = false;
let globalLastGeneratedExcerpt: string | null = null;
const listeners = new Set< () => void >();

function subscribe( callback: () => void ): () => void {
	listeners.add( callback );
	return () => {
		listeners.delete( callback );
	};
}

function notify(): void {
	listeners.forEach( ( listener ) => listener() );
}

function getIsGenerating(): boolean {
	return globalIsGenerating;
}

function getLastGeneratedExcerpt(): string | null {
	return globalLastGeneratedExcerpt;
}

function setGlobalIsGenerating( isGenerating: boolean ): void {
	globalIsGenerating = isGenerating;
	notify();
}

function setGlobalLastGeneratedExcerpt( value: string | null ): void {
	globalLastGeneratedExcerpt = value;
	notify();
}

const getSettings = (): ExcerptGenerationData => {
	const settings = ( window as any ).aiExcerptGenerationData ?? {};

	return {
		enabled: settings.enabled ?? false,
		minContentLength:
			settings.minContentLength ?? MINIMUM_CONTENT_COUNT_DEFAULT,
	};
};

/**
 * Generates an excerpt for the given post ID and content.
 *
 * @param postId  The ID of the post to generate an excerpt for.
 * @param content The content of the post to generate an excerpt for.
 * @return A promise that resolves to the generated excerpt.
 */
async function generateExcerpt(
	postId: number,
	content: string
): Promise< string > {
	const params: ExcerptGenerationAbilityInput = {
		content,
		context: postId.toString(),
	};

	return runAbility< string >( 'ai/excerpt-generation', params )
		.then( ( response ) => {
			if ( response && typeof response === 'string' ) {
				return response;
			}
			return '';
		} )
		.catch( ( error ) => {
			throw new Error( error.message );
		} );
}

/**
 * Hook for excerpt generation functionality.
 *
 * @return Object with generation state and handler.
 */
export function useExcerptGeneration(): {
	isGenerating: boolean;
	generatedExcerpt: string | null;
	hasExcerpt: boolean;
	isContentTooShort: boolean;
	minContentLength: number;
	tooShortLabel: string;
	handleGenerate: () => Promise< void >;
} {
	const { postId, content, excerpt } = useSelect( ( select ) => {
		return {
			postId: select( editorStore ).getCurrentPostId(),
			content: select( editorStore ).getEditedPostContent(),
			excerpt: select( editorStore ).getEditedPostAttribute( 'excerpt' ),
		};
	} );
	const { editPost } = useDispatch( editorStore );
	const isGenerating = useSyncExternalStore( subscribe, getIsGenerating );
	const lastGeneratedExcerpt = useSyncExternalStore(
		subscribe,
		getLastGeneratedExcerpt
	);

	// The preview shows the generated text only while it is still the post's
	// excerpt. Once it is edited by hand, the excerpt field is the source of
	// truth and the preview retires.
	const generatedExcerpt =
		lastGeneratedExcerpt !== null && lastGeneratedExcerpt === excerpt
			? lastGeneratedExcerpt
			: null;

	const { minContentLength } = getSettings();
	const isContentTooShort = ! hasMinimumContent( content, minContentLength );

	// Minimum-length requirement message, surfaced as the button tooltip when
	// the content is too short to generate from.
	const tooShortLabel = sprintf(
		/* translators: %d: minimum number of characters required */
		__(
			'Excerpt generation will be available when the post content has at least %d characters.',
			'ai'
		),
		minContentLength
	);

	const handleGenerate = async () => {
		if ( globalIsGenerating ) {
			return;
		}

		if ( ! ensureProvider( NOTICE_ID ) ) {
			return;
		}

		setGlobalIsGenerating( true );
		dispatch( noticesStore ).removeNotice( NOTICE_ID );

		try {
			const result = await generateExcerpt( postId as number, content );

			editPost( { excerpt: result } );
			setGlobalLastGeneratedExcerpt( result );
		} catch ( error: any ) {
			const message =
				typeof error === 'string'
					? error
					: error?.message ??
					  __( 'Failed to generate excerpt.', 'ai' );
			dispatch( noticesStore ).createErrorNotice( message, {
				id: NOTICE_ID,
				isDismissible: true,
			} );
		} finally {
			setGlobalIsGenerating( false );
		}
	};

	return {
		isGenerating,
		generatedExcerpt,
		hasExcerpt: excerpt && excerpt.trim().length > 0,
		isContentTooShort,
		minContentLength,
		tooShortLabel,
		handleGenerate,
	};
}
