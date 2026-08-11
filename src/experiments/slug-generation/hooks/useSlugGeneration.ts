/**
 * Shared slug generation logic for the permalink modal and the pre-publish panel.
 */

/**
 * WordPress dependencies
 */
import { dispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import { cleanForSlug } from '@wordpress/url';

/**
 * Internal dependencies
 */
import { ensureProvider } from '../../../utils/provider-status';
import { runAbility } from '../../../utils/run-ability';
import { getSettings } from '../settings';
import type {
	GeneratedSlugData,
	SlugGenerationAbilityInput,
	SlugSource,
} from '../types';

/**
 * Reads the post fields the slug generation ability needs from the editor store.
 *
 * @return The current post ID, title, content, and effective slug.
 */
export function useSlugSource(): SlugSource & { currentSlug: string } {
	return useSelect( ( select ) => {
		const editor = select( editorStore );
		const rawSlug =
			( editor.getEditedPostAttribute( 'slug' ) as string ) ?? '';
		const generatedSlug =
			( editor.getEditedPostAttribute( 'generated_slug' ) as string ) ??
			'';

		return {
			postId: editor.getCurrentPostId(),
			title: ( editor.getEditedPostAttribute( 'title' ) as string ) ?? '',
			content: ( editor.getEditedPostContent() as string ) ?? '',
			currentSlug: rawSlug || generatedSlug,
		};
	}, [] );
}

/**
 * Normalizes a slug and writes it to the post being edited.
 *
 * Slugs can be hand-edited before they are applied, so they are run through
 * `cleanForSlug()` the same way the core permalink field does.
 *
 * @param slug The slug to apply.
 */
export function applySlug( slug: string ): void {
	const cleanSlug = cleanForSlug( slug );

	if ( ! cleanSlug ) {
		return;
	}

	dispatch( editorStore ).editPost( { slug: cleanSlug } );
}

interface UseSlugGenerationOptions {
	/**
	 * Unique ID for the error notice, so repeat failures replace rather than stack.
	 */
	noticeId: string;

	/**
	 * Optional callback invoked when generation fails, after the notice is created.
	 */
	onError?: () => void;
}

interface UseSlugGeneration {
	suggestions: string[];
	isGenerating: boolean;
	generate: ( source: SlugSource ) => Promise< void >;
	reset: () => void;
}

/**
 * Runs the `ai/slug-generation` ability and tracks its suggestions and pending state.
 *
 * @param options          Hook options.
 * @param options.noticeId Unique ID for the error notice.
 * @param options.onError  Optional callback invoked when generation fails.
 * @return Suggestions, pending state, and the generate/reset callbacks.
 */
export function useSlugGeneration( {
	noticeId,
	onError,
}: UseSlugGenerationOptions ): UseSlugGeneration {
	const [ suggestions, setSuggestions ] = useState< string[] >( [] );
	const [ isGenerating, setIsGenerating ] = useState( false );

	const reset = useCallback( () => {
		setSuggestions( [] );
		setIsGenerating( false );
	}, [] );

	const generate = useCallback(
		async ( { title, content, postId }: SlugSource ) => {
			if ( ! ensureProvider( noticeId ) ) {
				onError?.();
				return;
			}

			setIsGenerating( true );
			dispatch( noticesStore ).removeNotice( noticeId );

			try {
				const params: SlugGenerationAbilityInput = {
					title,
					content,
					context: postId ? postId.toString() : '',
					number_of_suggestions: getSettings().numberOfSuggestions,
				};

				const response = await runAbility< GeneratedSlugData >(
					'ai/slug-generation',
					params
				);

				if (
					! response ||
					typeof response !== 'object' ||
					! Array.isArray( response.slugs ) ||
					response.slugs.length === 0
				) {
					throw new Error(
						__( 'No slug suggestion was generated.', 'ai' )
					);
				}

				setSuggestions( response.slugs );
			} catch ( error: any ) {
				const message =
					typeof error === 'string'
						? error
						: error?.message ??
						  __( 'Failed to generate slug.', 'ai' );

				dispatch( noticesStore ).createErrorNotice( message, {
					id: noticeId,
					isDismissible: true,
				} );

				onError?.();
			} finally {
				setIsGenerating( false );
			}
		},
		[ noticeId, onError ]
	);

	return { suggestions, isGenerating, generate, reset };
}
