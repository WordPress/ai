/**
 * Media Library Image Editor — AI panel injection.
 *
 * Uses a MutationObserver to detect when the WordPress image editor opens then
 * mounts the MediaLibraryImageEditor React component in a panel between the
 * toolbar row and the image canvas. Unmounts and removes the panel when the
 * editor closes.
 */

/**
 * WordPress dependencies
 */
import { createRoot, createElement } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { MediaLibraryImageEditor } from './components/MediaLibraryImageEditor';

let currentRoot: ReturnType< typeof createRoot > | null = null;
let currentContainer: HTMLElement | null = null;

/**
 * Extracts the attachment post ID from the imgedit-panel element ID.
 *
 * The native WordPress image editor injects `<div id="imgedit-panel-{postId}">`.
 *
 * @param {Element} imgeditWrap The `.imgedit-wrap` element.
 * @return {number|null} The post ID, or null if not found.
 */
function getPostIdFromWrap( imgeditWrap: Element ): number | null {
	const panel = imgeditWrap.querySelector( '[id^="imgedit-panel-"]' );

	if ( ! panel ) {
		return null;
	}

	const match = panel.id.match( /imgedit-panel-(\d+)/ );
	return match?.[ 1 ] ? parseInt( match[ 1 ], 10 ) : null;
}

/**
 * Retrieves the full-size URL for an attachment using the wp.media API.
 *
 * @param {number} postId The attachment post ID.
 * @return {Promise<string|null>} The attachment URL, or null if unavailable.
 */
async function getAttachmentUrl( postId: number ): Promise< string | null > {
	const wp = ( window as any ).wp;

	if ( ! wp?.media?.attachment ) {
		return null;
	}

	const attachment = wp.media.attachment( postId );

	// The URL may already be cached; fetch only if needed.
	if ( ! attachment.get( 'url' ) ) {
		await new Promise< void >( ( resolve ) => {
			attachment.fetch( { success: resolve, error: resolve } );
		} );
	}

	return attachment.get( 'url' ) ?? null;
}

/**
 * Mounts the AI editing UI into the WordPress image editor.
 *
 * Appends a button slot to the `.imgedit-menu` toolbar and inserts a panel
 * container between the toolbar row and the image canvas row.
 *
 * @param {Element} imgeditWrap The `.imgedit-wrap` element that just appeared.
 */
async function mountPanel( imgeditWrap: Element ): Promise< void > {
	// Unmount any existing panel first.
	unmountPanel();

	const postId = getPostIdFromWrap( imgeditWrap );
	if ( ! postId ) {
		return;
	}

	const attachmentUrl = await getAttachmentUrl( postId );
	if ( ! attachmentUrl ) {
		return;
	}

	// Query the image canvas + settings row.
	const imagePanel = imgeditWrap.querySelector< HTMLElement >(
		'.imgedit-panel-content:not(.imgedit-panel-tools)'
	);

	// Inject panel container between the toolbar row and the image canvas row.
	const toolbarRow = imgeditWrap.querySelector(
		'.imgedit-panel-content.imgedit-panel-tools'
	);

	currentContainer = document.createElement( 'div' );
	currentContainer.className = 'ai-media-library-editor-root';

	if ( toolbarRow ) {
		toolbarRow.insertAdjacentElement( 'afterend', currentContainer );
	} else {
		// Fallback: append after imgedit-wrap.
		imgeditWrap.parentElement?.insertBefore(
			currentContainer,
			imgeditWrap.nextSibling
		);
	}

	const props = {
		postId,
		attachmentUrl,
		...( imagePanel ? { imagePanel } : {} ),
	};

	currentRoot = createRoot( currentContainer );
	currentRoot.render( createElement( MediaLibraryImageEditor, props ) );
}

/**
 * Unmounts the AI panel, removes the panel container, and removes the
 * button slot from the toolbar.
 */
function unmountPanel(): void {
	if ( currentRoot ) {
		currentRoot.unmount();
		currentRoot = null;
	}

	if ( currentContainer ) {
		currentContainer.remove();
		currentContainer = null;
	}
}

/**
 * Starts observing the document body for the image editor appearing/disappearing.
 */
function observeImageEditor(): void {
	const observer = new MutationObserver( ( mutations ) => {
		for ( const mutation of mutations ) {
			// Check added nodes for .imgedit-wrap.
			for ( const node of Array.from( mutation.addedNodes ) ) {
				if ( ! ( node instanceof Element ) ) {
					continue;
				}

				// The node itself may be .imgedit-wrap, or it may contain one.
				const wrap = node.classList.contains( 'imgedit-wrap' )
					? node
					: node.querySelector( '.imgedit-wrap' );

				if ( wrap ) {
					mountPanel( wrap );
					return;
				}
			}

			// Check removed nodes — unmount if .imgedit-wrap was removed.
			for ( const node of Array.from( mutation.removedNodes ) ) {
				if ( ! ( node instanceof Element ) ) {
					continue;
				}

				const hadWrap =
					node.classList.contains( 'imgedit-wrap' ) ||
					node.querySelector( '.imgedit-wrap' );

				if ( hadWrap && currentRoot ) {
					unmountPanel();
					return;
				}
			}
		}
	} );

	observer.observe( document.body, { childList: true, subtree: true } );
}

document.addEventListener( 'DOMContentLoaded', observeImageEditor );
