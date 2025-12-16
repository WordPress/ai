/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

const { aiImageGenerationData } = window as any;

/**
 * Gets the context for the given post ID.
 *
 * @param {number} postId The ID of the post to get the context for.
 * @return {Promise<{ title: string; type: string }>} A promise that resolves to the context.
 */
export async function getContext(
	postId: number
): Promise< { title: string; type: string } > {
	return apiFetch( {
		path: aiImageGenerationData?.getContextPath ?? '',
		method: 'POST',
		data: {
			input: {
				post_id: postId,
				fields: [ 'title', 'type' ],
			},
		},
	} )
		.then( ( response ) => {
			if ( response && typeof response === 'object' ) {
				return response as {
					title: string;
					type: string;
				};
			}

			throw new Error( 'Invalid response from get context' );
		} )
		.catch( ( error ) => {
			throw new Error( error.message );
		} );
}
