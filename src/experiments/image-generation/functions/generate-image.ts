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
 * @return {Promise<string>} A promise that resolves to the generated image.
 */
export async function generateImage(
	postId: number,
	content: string
): Promise< string > {
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
			if ( response && typeof response === 'string' ) {
				return response;
			}

			return '';
		} )
		.catch( ( error ) => {
			throw new Error( error.message );
		} );
}
