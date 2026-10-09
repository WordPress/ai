/**
 * Hook for excerpt generation logic.
 */

/**
 * WordPress dependencies
 */
import { dispatch, useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useState, useCallback, useRef, useEffect } from '@wordpress/element';
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

const getSettings = (): ExcerptGenerationData => {
	const settings = ( window as any ).aiExcerptGenerationData ?? {};

	return {
		enabled: settings.enabled ?? false,
		minContentLength:
			settings.minContentLength ?? MINIMUM_CONTENT_COUNT_DEFAULT,
	};
};

interface UseExcerptGenerationReturn {
	isGenerating: boolean;
	suggestion: string | null;
	currentExcerpt: string;
	isContentTooShort: boolean;
	tooShortLabel: string;
	ensureProviderAvailable: () => boolean;
	generateExcerpt: () => Promise< void >;
	cancelGeneration: () => void;
	applyExcerpt: ( text: string ) => void;
	clearSuggestion: () => void;
}

/**
 * Hook providing excerpt generation state and actions.
 *
 * @return Object with generation state, suggestion, and handlers.
 */
export function useExcerptGeneration(): UseExcerptGenerationReturn {
	const { editPost } = useDispatch( editorStore );
	const { removeNotice, createErrorNotice } = dispatch( noticesStore );

	const [ isGenerating, setIsGenerating ] = useState( false );
	const [ suggestion, setSuggestion ] = useState< string | null >( null );

	const abortControllerRef = useRef< AbortController | null >( null );
	const requestIdRef = useRef( 0 );

	const ensureProviderAvailable = useCallback(
		() => ensureProvider( NOTICE_ID ),
		[]
	);

	const { postId, content, currentExcerpt } = useSelect( ( select ) => {
		const editor = select( editorStore );

		return {
			postId: editor.getCurrentPostId() as number,
			content: editor.getEditedPostContent(),
			currentExcerpt:
				( editor.getEditedPostAttribute( 'excerpt' ) as
					| string
					| undefined ) ?? '',
		};
	}, [] );

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

	const cancelGeneration = useCallback( () => {
		if ( ! abortControllerRef.current ) {
			return;
		}

		requestIdRef.current += 1;
		abortControllerRef.current.abort();
		abortControllerRef.current = null;
		setIsGenerating( false );
	}, [] );

	const generateExcerpt = useCallback( async () => {
		if ( ! ensureProvider( NOTICE_ID ) ) {
			return;
		}

		if ( abortControllerRef.current ) {
			abortControllerRef.current.abort();
		}

		const controller = new AbortController();
		abortControllerRef.current = controller;
		const currentRequestId = ++requestIdRef.current;

		setIsGenerating( true );
		setSuggestion( null );

		// Clear any existing notices.
		removeNotice( NOTICE_ID );

		try {
			const params: ExcerptGenerationAbilityInput = {
				content,
				context: postId.toString(),
			};

			const response = await runAbility< string >(
				'ai/excerpt-generation',
				params,
				{ signal: controller.signal }
			);

			if ( currentRequestId !== requestIdRef.current ) {
				return;
			}

			if ( typeof response === 'string' && response.trim().length > 0 ) {
				setSuggestion( response );
			} else {
				createErrorNotice( __( 'No excerpt was generated.', 'ai' ), {
					id: NOTICE_ID,
					isDismissible: true,
				} );
			}
		} catch ( error: any ) {
			if ( currentRequestId !== requestIdRef.current ) {
				return;
			}

			const message =
				typeof error === 'string'
					? error
					: error?.message ??
					  __( 'Failed to generate excerpt.', 'ai' );

			createErrorNotice( message, {
				id: NOTICE_ID,
				isDismissible: true,
			} );
		} finally {
			if ( abortControllerRef.current === controller ) {
				abortControllerRef.current = null;
			}

			if ( currentRequestId === requestIdRef.current ) {
				setIsGenerating( false );
			}
		}
	}, [ content, postId, removeNotice, createErrorNotice ] );

	useEffect( () => {
		return () => {
			requestIdRef.current += 1;
			if ( abortControllerRef.current ) {
				abortControllerRef.current.abort();
				abortControllerRef.current = null;
			}
		};
	}, [] );

	const applyExcerpt = useCallback(
		( text: string ) => {
			editPost( { excerpt: text } );
		},
		[ editPost ]
	);

	const clearSuggestion = useCallback( () => {
		setSuggestion( null );
	}, [] );

	return {
		isGenerating,
		suggestion,
		currentExcerpt,
		isContentTooShort,
		tooShortLabel,
		ensureProviderAvailable,
		generateExcerpt,
		cancelGeneration,
		applyExcerpt,
		clearSuggestion,
	};
}
