/**
 * Shared hook for excerpt generation logic.
 */

/**
 * WordPress dependencies
 */
import { dispatch, useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';
import { ensureProvider } from '../../../utils/provider-status';
import type { ExcerptGenerationAbilityInput } from '../types';

const NOTICE_ID = 'ai_excerpt_generation_error';

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
	hasExcerpt: boolean;
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
	const [ isGenerating, setIsGenerating ] = useState< boolean >( false );

	const handleGenerate = async () => {
		if ( ! ensureProvider( NOTICE_ID ) ) {
			return;
		}

		setIsGenerating( true );
		( dispatch( noticesStore ) as any ).removeNotice( NOTICE_ID );

		try {
			const generatedExcerpt = await generateExcerpt(
				postId as number,
				content
			);

			// Update the editor store first.
			editPost( {
				excerpt: generatedExcerpt,
			} );

			// Find the textarea element and update it.
			const excerptInput = document.querySelector(
				'.editor-post-excerpt .editor-post-excerpt__textarea textarea'
			) as HTMLTextAreaElement | null;

			if ( excerptInput ) {
				const nativeInputValueSetter = Object.getOwnPropertyDescriptor(
					window.HTMLTextAreaElement.prototype,
					'value'
				)?.set;

				if ( nativeInputValueSetter ) {
					nativeInputValueSetter.call(
						excerptInput,
						generatedExcerpt
					);
				} else {
					excerptInput.value = generatedExcerpt;
				}

				excerptInput.focus();

				const changeEvent = new Event( 'change', {
					bubbles: true,
					cancelable: true,
				} );
				excerptInput.dispatchEvent( changeEvent );
			}
		} catch ( error: any ) {
			const message =
				typeof error === 'string'
					? error
					: error?.message ??
					  __( 'Failed to generate excerpt.', 'ai' );
			( dispatch( noticesStore ) as any ).createErrorNotice( message, {
				id: NOTICE_ID,
				isDismissible: true,
			} );
		} finally {
			setIsGenerating( false );
		}
	};

	return {
		isGenerating,
		hasExcerpt: excerpt && excerpt.trim().length > 0,
		handleGenerate,
	};
}
