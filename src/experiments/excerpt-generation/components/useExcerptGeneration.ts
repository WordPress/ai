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

let globalIsGenerating = false;
const listeners = new Set< () => void >();

function subscribe( callback: () => void ): () => void {
	listeners.add( callback );
	return () => {
		listeners.delete( callback );
	};
}

function getSnapshot(): boolean {
	return globalIsGenerating;
}

function setGlobalIsGenerating( isGenerating: boolean ): void {
	globalIsGenerating = isGenerating;
	listeners.forEach( ( listener ) => listener() );
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
 * Shows the generated excerpt in the legacy excerpt panel's textarea.
 *
 * The panel (WordPress < 7.2) keeps the textarea's value in its own state,
 * so editing the post is not enough to refresh it.
 *
 * @param excerpt The generated excerpt.
 */
function syncLegacyExcerptTextarea( excerpt: string ): void {
	const excerptInput = document.querySelector(
		'.editor-post-excerpt .editor-post-excerpt__textarea textarea'
	) as HTMLTextAreaElement | null;

	if ( ! excerptInput ) {
		return;
	}

	const nativeInputValueSetter = Object.getOwnPropertyDescriptor(
		window.HTMLTextAreaElement.prototype,
		'value'
	)?.set;

	if ( nativeInputValueSetter ) {
		nativeInputValueSetter.call( excerptInput, excerpt );
	} else {
		excerptInput.value = excerpt;
	}

	excerptInput.focus();

	const changeEvent = new Event( 'change', {
		bubbles: true,
		cancelable: true,
	} );
	excerptInput.dispatchEvent( changeEvent );
}

/**
 * Options of the excerpt generation hook.
 */
export interface UseExcerptGenerationOptions {
	/**
	 * Applies the generated excerpt. Defaults to editing the post in the
	 * editor store and refreshing the legacy excerpt panel's textarea.
	 */
	onApply?: ( ( excerpt: string ) => void ) | undefined;
}

/**
 * Hook for excerpt generation functionality.
 *
 * @param options         Options of the hook.
 * @param options.onApply Applies the generated excerpt. Defaults to editing the post.
 * @return Object with generation state and handler.
 */
export function useExcerptGeneration( {
	onApply,
}: UseExcerptGenerationOptions = {} ): {
	isGenerating: boolean;
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
	const isGenerating = useSyncExternalStore( subscribe, getSnapshot );

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
			const generatedExcerpt = await generateExcerpt(
				postId as number,
				content
			);

			if ( onApply ) {
				onApply( generatedExcerpt );
			} else {
				editPost( {
					excerpt: generatedExcerpt,
				} );
				syncLegacyExcerptTextarea( generatedExcerpt );
			}
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
		hasExcerpt: excerpt && excerpt.trim().length > 0,
		isContentTooShort,
		minContentLength,
		tooShortLabel,
		handleGenerate,
	};
}
