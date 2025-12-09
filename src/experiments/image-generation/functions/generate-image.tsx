/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

const { aiImageGenerationData } = window as any;

/**
 * Generates an image for the given post ID and content.
 *
 * @param {number} postId  The ID of the post to generate a title for.
 * @param {string} content The content of the post to generate an image for.
 * @return {Promise<string>} A promise that resolves to the generated image.
 */
export async function generateImage(
	postId: number,
	content: string
): Promise< string > {
	// TODO: add a call to generate a prompt first and then pass that to the generate image function.

	return apiFetch( {
		path: aiImageGenerationData?.generatePath ?? '',
		method: 'POST',
		data: {
			input: {
				prompt: content,
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
