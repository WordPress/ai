/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

const { aiImageGenerationData } = window as any;

/**
 * Generates a featured image generation prompt for the given post ID and content.
 *
 * @param {string} context The context to generate a featured image prompt for.
 * @return {Promise<string>} A promise that resolves to the generated featured image prompt.
 */
export async function generatePrompt( context: string ): Promise< string > {
	return apiFetch( {
		path: aiImageGenerationData?.generatePromptPath ?? '',
		method: 'POST',
		data: {
			input: {
				purpose: aiImageGenerationData?.generatePromptPurpose,
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
