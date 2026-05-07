/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';
import type { SummarizationAbilityInput } from '../types';

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
	const params: SummarizationAbilityInput = {
		context: postId.toString(),
		content,
	};

	return runAbility< string >( 'ai/summarization', params )
		.then( ( response ) => {
			if ( response && typeof response === 'string' ) {
				return response as string;
			}

			throw new Error( __( 'Invalid response from API.', 'ai' ) );
		} )
		.catch( ( error ) => {
			throw new Error( error.message );
		} );
}
