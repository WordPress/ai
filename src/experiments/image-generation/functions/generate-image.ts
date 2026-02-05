/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { formatContext } from './format-context';
import { getContext } from './get-context';
import { generatePrompt } from './generate-prompt';

const { aiImageGenerationData } = window as any;

/**
 * Generates an image for the given post ID and content.
 *
 * @param {number} postId  The ID of the post to generate a featured image for.
 * @param {string} content The content of the post to generate an image for.
 * @return {Promise<{ image: { data: string; provider_metadata: { id: string; name: string; type: string; }; model_metadata: { id: string; name: string; }; }; prompt: string; }>} A promise that resolves to the generated image data.
 */
export async function generateImage(
	postId: number,
	content: string
): Promise< {
	image: {
		data: string;
		provider_metadata: { id: string; name: string; type: string };
		model_metadata: { id: string; name: string };
	};
	prompt: string;
} > {
	let context: {
		title: string;
		type: string;
		content?: string;
	};

	try {
		context = ( await getContext( postId ) ) as {
			title: string;
			type: string;
			content?: string;
		};
	} catch ( error: any ) {
		throw new Error(
			`Failed to get post context: ${ error.message || error }`
		);
	}

	let prompt: string;

	try {
		prompt = await generatePrompt( content, formatContext( context ) );
	} catch ( error: any ) {
		throw new Error(
			`Failed to generate prompt: ${ error.message || error }`
		);
	}

	return apiFetch( {
		path: aiImageGenerationData?.generateImagePath ?? '',
		method: 'POST',
		data: {
			input: {
				prompt,
			},
		},
	} )
		.then( ( response ) => {
			if ( response && typeof response === 'object' ) {
				const result = response as { prompt?: string };
				result.prompt = prompt;
				return result as {
					image: {
						data: string;
						provider_metadata: {
							id: string;
							name: string;
							type: string;
						};
						model_metadata: {
							id: string;
							name: string;
						};
					};
					prompt: string;
				};
			}

			throw new Error( 'Invalid response from generate image' );
		} )
		.catch( ( error ) => {
			throw new Error( error.message );
		} );
}
