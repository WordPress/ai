/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

const { aiImageGenerationData } = window as any;

/**
 * Generates an image for the given content.
 *
 * @param {string} content The content of the post to generate an image for.
 * @return {Promise<string>} A promise that resolves to the generated image.
 */
export async function generateImage( content: string ): Promise< string > {
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
