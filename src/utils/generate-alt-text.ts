/**
 * WordPress dependencies
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { select } from '@wordpress/data';
/* eslint-disable import/no-extraneous-dependencies -- @wordpress/blocks is in dependencies; types are in devDependencies */
import { serialize } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { runAbility } from './run-ability';
import type { AltTextGenerationAbilityInput } from '../experiments/alt-text-generation/types';

const IMAGE_PLACEHOLDER = '[[IMAGE_GOES_HERE]]';

/**
 * Replaces the current image block markup in post content with a placeholder.
 *
 * @param {string} content  Full post content.
 * @param {string} clientId Client ID of the current image block.
 * @return {string} Content with this image block replaced by the placeholder.
 */
function replaceImageBlockWithPlaceholder(
	content: string,
	clientId: string
): string {
	// eslint-disable-next-line dot-notation -- getBlock from store index signature
	const block = select( blockEditorStore )[ 'getBlock' ]( clientId );
	if ( ! block ) {
		return content;
	}

	const serializedBlock = serialize( block );
	if ( ! serializedBlock || ! content.includes( serializedBlock ) ) {
		return content;
	}

	return content.replace( serializedBlock, IMAGE_PLACEHOLDER );
}

/**
 * Generates alt text for an image using the AI ability.
 *
 * @param {number|undefined} attachmentId The attachment ID.
 * @param {string|undefined} imageUrl     The image URL (fallback if no attachment ID).
 * @param {string|undefined} content      The content of the post.
 * @param {string|undefined} clientId     The client ID of the current image block.
 * @return {Promise<string>} The generated alt text.
 */
export async function generateAltText(
	attachmentId?: number | undefined,
	imageUrl?: string | undefined,
	content?: string | undefined,
	clientId?: string | undefined
): Promise< string > {
	const params: AltTextGenerationAbilityInput = {};

	if ( attachmentId ) {
		params.attachment_id = attachmentId;
	} else if ( imageUrl ) {
		params.image_url = imageUrl;
	} else {
		throw new Error(
			__( 'No image available to generate alt text for.', 'ai' )
		);
	}

	if ( content ) {
		// Replace the image block with the placeholder.
		const contentWithPlaceholder =
			clientId !== undefined
				? replaceImageBlockWithPlaceholder( content, clientId )
				: content;

		// Prepare the context.
		params.context = `What follows is the full article content, where the image has been replaced with the placeholder ${ IMAGE_PLACEHOLDER }. Use the surrounding text to understand the purpose, subject, and relevance of the image within the article. Be sure to describe only information not already conveyed in nearby text. CONTENT: \n\n${ contentWithPlaceholder }`;
	}

	const response = await runAbility( 'ai/alt-text-generation', params );

	if ( response && typeof response === 'object' && 'alt_text' in response ) {
		return response.alt_text as string;
	}

	throw new Error( __( 'Failed to generate alt text.', 'ai' ) );
}
