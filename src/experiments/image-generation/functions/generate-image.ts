/**
 * WordPress dependencies
 */
import { select } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatContext } from './format-context';
import { generatePrompt } from './generate-prompt';
import { runAbility } from '../../../utils/run-ability';
import type {
	GeneratedImageData,
	ImageGenerationAbilityInput,
	ImageProgressCallback,
	PostContext,
} from '../types';

/**
 * Generates an image for the given post ID and content.
 *
 * @param {string}   content            The content of the post to generate an image for.
 * @param {Object}   options            Optional settings.
 * @param {Function} options.onProgress Callback invoked with progress messages.
 * @return {Promise<GeneratedImageData>} A promise that resolves to the generated image data.
 */
export async function generateImage(
	content: string,
	options?: { onProgress?: ImageProgressCallback }
): Promise< GeneratedImageData > {
	const onProgress = options?.onProgress;

	const context: PostContext = {
		title: select( editorStore ).getEditedPostAttribute( 'title' ),
		type: select( editorStore ).getEditedPostAttribute( 'type' ),
	};

	let prompt: string;

	try {
		onProgress?.( __( 'Generating image prompt', 'ai' ) );
		prompt = await generatePrompt( content, formatContext( context ) );
	} catch ( error: any ) {
		throw new Error(
			`Failed to generate prompt: ${ error.message || error }`
		);
	}

	onProgress?.( __( 'Generating image', 'ai' ) );

	const params: ImageGenerationAbilityInput = {
		prompt,
	};

	return runAbility< GeneratedImageData >( 'ai/image-generation', params )
		.then( ( response ) => {
			if ( response && typeof response === 'object' ) {
				const result = response as {
					prompt?: string;
					prompts?: string[];
				};
				result.prompt = prompt;
				result.prompts = [ prompt ];
				return result as GeneratedImageData;
			}

			throw new Error( 'Invalid response from generate image' );
		} )
		.catch( ( error ) => {
			throw new Error( error.message );
		} );
}
