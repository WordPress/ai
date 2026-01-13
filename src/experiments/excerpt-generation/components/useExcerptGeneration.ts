/**
 * Shared hook for excerpt generation logic.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { dispatch, select, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useState } from '@wordpress/element';
import { store as noticesStore } from '@wordpress/notices';

const { aiExcerptGenerationData } = window as any;

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
	return apiFetch( {
		path: aiExcerptGenerationData?.path ?? '',
		method: 'POST',
		data: {
			input: {
				content,
				post_id: postId,
			},
		},
	} )
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
	const postId = select( editorStore ).getCurrentPostId();
	const content = select( editorStore ).getEditedPostContent();
	const excerpt = select( editorStore ).getEditedPostAttribute( 'excerpt' );
	const { editPost } = useDispatch( editorStore );
	const [ isGenerating, setIsGenerating ] = useState< boolean >( false );

	const handleGenerate = async () => {
		setIsGenerating( true );
		( dispatch( noticesStore ) as any ).removeNotice(
			'ai_excerpt_generation_error'
		);

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
			( dispatch( noticesStore ) as any ).createErrorNotice( error, {
				id: 'ai_excerpt_generation_error',
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
