/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';
import type { GetPostDetailsAbilityInput, PostContext } from '../types';

/**
 * Gets the context for the given post ID.
 *
 * @param {number} postId The ID of the post to get the context for.
 * @return {Promise<PostContext>} A promise that resolves to the context.
 */
export async function getContext( postId: number ): Promise< PostContext > {
	const params: GetPostDetailsAbilityInput = {
		post_id: postId,
		fields: [ 'title', 'type' ],
	};

	return await runAbility< PostContext >( 'ai/get-post-details', params )
		.then( ( response ) => {
			if ( response && typeof response === 'object' ) {
				return response as PostContext;
			}

			throw new Error( __( 'Invalid response from get context.', 'ai' ) );
		} )
		.catch( ( error ) => {
			throw new Error( error.message );
		} );
}
