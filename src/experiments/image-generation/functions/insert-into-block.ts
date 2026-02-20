/**
 * WordPress dependencies
 */
import { createBlock } from '@wordpress/blocks';
import { select, dispatch } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import type { UploadedImage } from '../types';

/**
 * Inserts an uploaded image into the target block by updating its attributes.
 *
 * For `core/gallery`, a new inner `core/image` block is appended. For all
 * other supported blocks the relevant attributes are set directly.
 *
 * @param {string}        blockName     The name of the target block.
 * @param {string}        clientId      The client ID of the target block.
 * @param {Function}      setAttributes The block's setAttributes callback.
 * @param {UploadedImage} uploadedImage The image returned by uploadImage.
 */
export function insertIntoBlock(
	blockName: string,
	clientId: string,
	setAttributes: ( attrs: Record< string, unknown > ) => void,
	uploadedImage: UploadedImage
): void {
	const { id, url, title: alt } = uploadedImage;

	switch ( blockName ) {
		case 'core/image':
			setAttributes( { id, url, alt } );
			break;

		case 'core/cover':
			setAttributes( {
				id,
				url,
				alt,
				dimRatio: 50,
				isDark: false,
				sizeSlug: 'full',
			} );
			break;

		case 'core/media-text':
			setAttributes( { mediaId: id, mediaUrl: url, mediaType: 'image' } );
			break;

		case 'core/gallery': {
			const { getBlocks } = select( blockEditorStore );
			// eslint-disable-next-line @typescript-eslint/no-explicit-any
			const replaceInnerBlocks = ( dispatch( blockEditorStore ) as any )
				?.replaceInnerBlocks as
				| ( ( clientId: string, blocks: unknown[] ) => void )
				| undefined;
			if ( replaceInnerBlocks ) {
				const existing = getBlocks( clientId );
				replaceInnerBlocks( clientId, [
					...existing,
					createBlock( 'core/image', { id, url, alt } ),
				] );
			}
			break;
		}
	}
}
