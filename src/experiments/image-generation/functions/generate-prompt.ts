/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

const { aiImageGenerationData } = window as any;

/**
 * Generates a featured image generation prompt for the given post ID and content.
 *
 * @param {string} content The content to use as inspiration for the generated image.
 * @param {string} context The context to help generate the prompt.
 * @return {Promise<string>} A promise that resolves to the generated featured image prompt.
 */
export async function generatePrompt(
	content: string,
	context: string
): Promise< string > {
	return apiFetch( {
		path: aiImageGenerationData?.generatePromptPath ?? '',
		method: 'POST',
		data: {
			input: {
				content,
				context,
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
