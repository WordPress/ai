/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

const { aiSummarizationData } = window as any;

/**
 * Generates a summary for the given post ID and content.
 *
 * @param {number} postId  The ID of the post to generate a summary for.
 * @param {string} content The content of the post to generate a summary for.
 * @return {Promise<string>} A promise that resolves to the generated summary.
 */
export async function generateSummary(
	postId: number,
	content: string
): Promise< string > {
	return apiFetch( {
		path: aiSummarizationData?.path ?? '',
		method: 'POST',
		data: {
			input: {
				context: postId.toString(),
				content,
			},
		},
	} )
		.then( ( response ) => {
			if ( response && typeof response === 'string' ) {
				return response as string;
			}

			throw new Error( 'Invalid response from API' );
		} )
		.catch( ( error ) => {
			throw new Error( error.message );
		} );
}
