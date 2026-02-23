/**
 * Opens the WordPress media library with a pre-selected image for gallery block insertion.
 *
 * When inserting a generated image into a gallery, this flow lets users land in the
 * media library modal where they can add more images before confirming, matching the
 * native "Add to gallery" experience.
 *
 * @since x.x.x
 */

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
 * Minimal type for wp.media frame used when opening the gallery flow.
 * The Library state defaults to content: 'upload' (Upload tab). We switch to
 * content: 'browse' (Media Library tab) in the open handler.
 */
type WPMediaFrame = {
	on: ( event: string, callback: ( ...args: unknown[] ) => void ) => void;
	open: () => void;
	state: ( id?: string ) => {
		get: ( key: string ) => unknown;
		on?: ( event: string, cb: ( lib: unknown ) => void ) => void;
	};
};

interface WPMediaAttachment {
	fetch: () => Promise< unknown >;
	get: ( key: string ) => unknown;
}

type WPMedia = {
	( options: Record< string, unknown > ): WPMediaFrame;
	attachment: ( id: number ) => WPMediaAttachment;
	view?: { l10n?: { createGalleryTitle?: string } };
};

function getWpMedia(): WPMedia | null {
	const wp = ( window as unknown as { wp?: { media?: unknown } } ).wp;
	return typeof wp?.media === 'function' ? ( wp.media as WPMedia ) : null;
}

type SelectionItem = {
	id: number;
	url?: string | undefined;
	caption?: string | { raw?: string } | undefined;
	alt?: string | undefined;
};

/**
 * Adds selected media from the media library to the gallery block.
 *
 * Mirrors the gallery block's updateImages logic: merges selection with existing
 * inner blocks, preserves order, and creates new image blocks for newly selected items.
 *
 * @param {string}          clientId  The gallery block's client ID.
 * @param {SelectionItem[]} selection Array of attachment-like objects with id, url, caption, alt.
 */
function addSelectionToGallery(
	clientId: string,
	selection: SelectionItem[]
): void {
	const { getBlock } = select( blockEditorStore );
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	const { replaceInnerBlocks, selectBlock } = dispatch(
		blockEditorStore
	) as any;

	const innerBlockImages = getBlock( clientId )?.innerBlocks ?? [];

	const newOrderMap: Record< number, number > = {};
	selection.forEach( ( item, index ) => {
		newOrderMap[ item.id ] = index;
	} );

	const existingImageBlocks = innerBlockImages.filter(
		( block: { attributes: { id?: number } } ) =>
			selection.find( ( img ) => img.id === block.attributes.id )
	);

	const newImageList = selection.filter(
		( img ) =>
			! innerBlockImages.find(
				( existingImg: { attributes: { id?: number } } ) =>
					existingImg.attributes.id === img.id
			)
	);

	const newBlocks = newImageList.map( ( image ) => {
		const caption =
			typeof image.caption === 'object' &&
			image.caption?.raw !== undefined
				? image.caption.raw
				: ( image.caption as string ) ?? '';
		return createBlock( 'core/image', {
			id: image.id,
			url: image.url ?? '',
			caption: caption || '',
			alt: image.alt ?? '',
		} );
	} );

	const merged = existingImageBlocks
		.concat( newBlocks )
		.sort(
			(
				a: { attributes: { id?: number } },
				b: { attributes: { id?: number } }
			) =>
				( newOrderMap[ a.attributes.id ?? 0 ] ?? 0 ) -
				( newOrderMap[ b.attributes.id ?? 0 ] ?? 0 )
		);

	replaceInnerBlocks( clientId, merged );

	const firstNewBlock = newBlocks[ 0 ];
	if ( firstNewBlock ) {
		selectBlock( firstNewBlock.clientId );
	}
}

/**
 * Opens the WordPress media library with the given image pre-selected for gallery insertion.
 *
 * The user can add more images from the library before confirming. On confirm, the
 * selection is merged into the gallery block's inner blocks.
 *
 * @param {string}        clientId      The gallery block's client ID.
 * @param {UploadedImage} uploadedImage The uploaded image to pre-select (id, url, title as alt).
 * @return {boolean} True if the media library was opened; false if wp.media is unavailable
 *                  (caller should use insertIntoBlock).
 */
export function openGalleryMediaLibraryWithImage(
	clientId: string,
	uploadedImage: UploadedImage
): boolean {
	const wpMedia = getWpMedia();
	if ( ! wpMedia ) {
		return false;
	}

	const { id } = uploadedImage;

	// Use frame: 'post' + state: 'gallery' to get the "Create gallery" UI with:
	// - Additive multi-select (click to add, no Shift required)
	// - Media Library tab (not Upload)
	// - Actions sidebar with "Create gallery"
	const frame = wpMedia( {
		frame: 'post',
		state: 'gallery',
		title: wpMedia.view?.l10n?.createGalleryTitle ?? 'Create gallery',
		library: { type: 'image' },
	} );

	frame.on( 'open', function () {
		const frameWithEl = frame as {
			$el?: {
				find: ( sel: string ) => { hide: () => void; length: number };
			};
			content?: { mode: ( m?: string ) => void };
		};

		// Hide other Actions so only "Create gallery" appears (matches native gallery flow).
		// Menu items use #menu-item-{stateId}; modal may be in parent doc when editor is iframed.
		const stateIdsToHide = [
			'insert',
			'embed',
			'featured-image',
			'playlist',
			'video-playlist',
		];
		const hideMenuItems = () => {
			const docs = [
				document,
				( window.parent && window.parent !== window
					? window.parent.document
					: null ) as Document | null,
			].filter( Boolean ) as Document[];
			stateIdsToHide.forEach( ( stateId ) => {
				const menuItemId = `menu-item-${ stateId }`;
				for ( const doc of docs ) {
					const el = doc.getElementById( menuItemId );
					if ( el ) {
						el.style.display = 'none';
						break;
					}
				}
			} );
		};
		hideMenuItems();
		// Retry; menu can render asynchronously after the frame opens.
		window.setTimeout( hideMenuItems, 100 );
		window.setTimeout( hideMenuItems, 300 );

		// Switch to Media Library tab (browse) instead of Upload Files (upload).
		const content = frameWithEl.content;
		if ( content?.mode ) {
			window.setTimeout( () => content.mode( 'browse' ), 0 );
		}

		const selection = frame.state().get( 'selection' ) as {
			add: ( model: unknown ) => void;
			reset: () => void;
		};
		const attachment = wpMedia.attachment( id );
		attachment.fetch().then( () => {
			selection.reset();
			selection.add( attachment ? [ attachment ] : [] );
		} );
	} );

	// MediaFrame.Post uses 'update' on gallery-edit state when user confirms.
	// The gallery state transitions to gallery-edit when user clicks "Create gallery".
	const galleryEditState = frame.state( 'gallery-edit' );
	if ( galleryEditState?.on ) {
		galleryEditState.on( 'update', function ( library: unknown ) {
			const collection = library as {
				toArray?: () => Array< {
					get?: ( key: string ) => unknown;
					toJSON?: () => Record< string, unknown >;
				} >;
				models?: Array< {
					get?: ( key: string ) => unknown;
					toJSON?: () => Record< string, unknown >;
				} >;
			};
			const models = collection?.toArray?.() ?? collection?.models ?? [];
			const selected: SelectionItem[] = models.map( ( model ) => {
				const json = ( model.toJSON?.() ?? {} ) as {
					id?: unknown;
					url?: unknown;
					caption?: unknown;
					alt?: unknown;
				};
				return {
					id: ( model.get?.( 'id' ) ?? json.id ) as number,
					url: ( model.get?.( 'url' ) ?? json.url ) as
						| string
						| undefined,
					caption: ( model.get?.( 'caption' ) ?? json.caption ) as
						| string
						| { raw?: string }
						| undefined,
					alt: ( model.get?.( 'alt' ) ?? json.alt ) as
						| string
						| undefined,
				};
			} );

			if ( selected.length > 0 ) {
				addSelectionToGallery( clientId, selected );
			}
		} );
	}

	frame.open();
	return true;
}
