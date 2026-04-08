/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { runAbility } from './run-ability';
import { replaceBlockWithPlaceholder } from '../utils/blocks';
import type { AltTextGenerationAbilityInput } from '../experiments/alt-text-generation/types';

const IMAGE_PLACEHOLDER = '[[IMAGE_GOES_HERE]]';

/**
 * Data about how the image block is used in the editor.
 */
export interface ImageBlockMetaData {
	linkDestination?: string | undefined;
	href?: string | undefined;
	linkTarget?: string | undefined;
	caption?: string | undefined;
}

/**
 * Result of alt text generation.
 */
export interface AltTextGenerationResult {
	alt_text: string;
	is_decorative?: boolean | undefined;
}

/**
 * Builds a human-readable image block meta string from block attributes.
 *
 * @param {ImageBlockMetaData} meta The block meta data.
 * @return {string} A description of how the image block is used.
 */
function buildImageBlockMeta( meta: ImageBlockMetaData ): string {
	const parts: string[] = [];

	if ( meta.linkDestination && meta.linkDestination !== 'none' ) {
		parts.push(
			'In the WordPress editor this image block is hyperlinked.'
		);
		if ( meta.href ) {
			parts.push( `Link URL: ${ meta.href }` );
		}
		parts.push( `Link destination setting: ${ meta.linkDestination }` );
		if ( meta.linkTarget === '_blank' ) {
			parts.push( 'Link opens in a new tab.' );
		}
		parts.push(
			'When the image is the only content inside the link, alternative text should describe the link purpose or destination (not a visual description of the image).',
			'If visible text in the same link already describes that purpose, respond with exactly [[DECORATIVE_ALT]] for empty alternative text.'
		);
	} else {
		parts.push( 'Image is not linked.' );
	}

	if ( meta.caption && typeof meta.caption === 'string' ) {
		// Strip HTML tags to get plain text from RichText values.
		const plainCaption = meta.caption.replace( /<[^>]+>/g, '' ).trim();
		if ( plainCaption ) {
			parts.push( `Image has a visible caption: "${ plainCaption }"` );
		}
	}

	return parts.join( '\n' );
}

/**
 * Generates alt text for an image using the AI ability.
 *
 * @param {number|undefined}             attachmentId       The attachment ID.
 * @param {string|undefined}             imageUrl           The image URL (fallback if no attachment ID).
 * @param {string|undefined}             content            The content of the post.
 * @param {string|undefined}             clientId           The client ID of the current image block.
 * @param {ImageBlockMetaData|undefined} imageBlockMetaData Data about how the image block is used.
 * @return {Promise<AltTextGenerationResult>} The generated alt text result.
 */
export async function generateAltText(
	attachmentId?: number | undefined,
	imageUrl?: string | undefined,
	content?: string | undefined,
	clientId?: string | undefined,
	imageBlockMetaData?: ImageBlockMetaData | undefined
): Promise< AltTextGenerationResult > {
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
				? replaceBlockWithPlaceholder(
						content,
						clientId,
						IMAGE_PLACEHOLDER
				  )
				: content;

		// Prepare the context.
		params.context = `Full article content, where the image has been replaced with the placeholder ${ IMAGE_PLACEHOLDER }: \n\n${ contentWithPlaceholder }`;
	}

	if ( imageBlockMetaData ) {
		params.image_meta = buildImageBlockMeta( imageBlockMetaData );
	}

	const response = await runAbility( 'ai/alt-text-generation', params );

	if ( response && typeof response === 'object' && 'alt_text' in response ) {
		const data = response as Record< string, unknown >;
		return {
			/* eslint-disable dot-notation -- index signature requires bracket notation */
			alt_text: data[ 'alt_text' ] as string,
			is_decorative: data[ 'is_decorative' ] as boolean | undefined,
			/* eslint-enable dot-notation */
		};
	}

	throw new Error( __( 'Failed to generate alt text.', 'ai' ) );
}
