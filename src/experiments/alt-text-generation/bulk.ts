/**
 * Bulk alt text generation for the Media Library.
 */

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { runAbility } from '../../utils/run-ability';
import { isProviderAvailable } from '../../utils/provider-status';

type AbilityResponse = {
	alt_text?: string;
};

type BulkData = {
	attachmentIds: number[];
};

declare global {
	interface Window {
		aiAltTextGenerationBulkData?: BulkData;
	}
}

const ABILITY_NAME = 'ai/alt-text-generation';

/**
 * Creates and injects a dismissible admin notice into the page.
 *
 * @since 0.7.0
 *
 * @param message The initial message to display in the notice.
 * @return The paragraph element used to update the notice message.
 */
function createNotice( message: string ): HTMLParagraphElement {
	const notice = document.createElement( 'div' );
	notice.className = 'notice notice-info is-dismissible';

	const paragraph = document.createElement( 'p' );
	paragraph.textContent = message;
	notice.appendChild( paragraph );

	const dismissButton = document.createElement( 'button' );
	dismissButton.type = 'button';
	dismissButton.className = 'notice-dismiss';
	dismissButton.innerHTML = `<span class="screen-reader-text">${ __(
		'Dismiss this notice.',
		'ai'
	) }</span>`;
	dismissButton.addEventListener( 'click', () => notice.remove() );
	notice.appendChild( dismissButton );

	const container =
		document.querySelector< HTMLDivElement >( '#wpbody-content' );
	if ( container ) {
		container.prepend( notice );
	}

	return paragraph;
}

/**
 * Processes the bulk alt text generation for all selected attachments.
 *
 * @since 0.7.0
 */
async function processBulkAltText(): Promise< void > {
	const data = window.aiAltTextGenerationBulkData;

	if ( ! data || ! data.attachmentIds || data.attachmentIds.length === 0 ) {
		return;
	}

	const noProviderMessage = __( 'This feature requires a valid AI Connector to function properly. Please set up a provider to use this feature in Settings → Connectors.', 'ai' );

	if ( ! isProviderAvailable() ) {
		createNotice( noProviderMessage );
		return;
	}

	const { attachmentIds } = data;
	const total = attachmentIds.length;
	let processed = 0;
	const failedIds: number[] = [];

	const initialMessage = sprintf(
		// translators: 1: number processed so far, 2: total number of images
		__( 'Generating alt text: %1$d / %2$d\u2026', 'ai' ),
		0,
		total
	);
	const statusParagraph = createNotice( initialMessage );

	for ( const id of attachmentIds ) {
		try {
			const response = await runAbility< AbilityResponse >(
				ABILITY_NAME,
				{
					attachment_id: id,
				}
			);

			const altText = response?.alt_text;

			if ( ! altText ) {
				throw new Error( __( 'Empty response received.', 'ai' ) );
			}

			await apiFetch( {
				path: `/wp/v2/media/${ id }`,
				method: 'POST',
				data: { alt_text: altText },
			} );
		} catch {
			failedIds.push( id );
		}

		processed++;

		statusParagraph.textContent = sprintf(
			// translators: 1: number processed so far, 2: total number of images
			__( 'Generating alt text: %1$d / %2$d\u2026', 'ai' ),
			processed,
			total
		);
	}

	const successCount = processed - failedIds.length;

	if ( failedIds.length === 0 ) {
		statusParagraph.textContent = sprintf(
			// translators: %d: number of images
			_n(
				'Alt text generated for %d image.',
				'Alt text generated for all %d images.',
				successCount,
				'ai'
			),
			successCount
		);
	} else {
		statusParagraph.textContent = sprintf(
			// translators: 1: number successfully processed, 2: total images, 3: comma-separated list of failed attachment IDs
			__(
				'Alt text generated for %1$d of %2$d images. Failed attachment IDs: %3$s.',
				'ai'
			),
			successCount,
			total,
			failedIds.join( ', ' )
		);
	}
}

document.addEventListener( 'DOMContentLoaded', () => {
	void processBulkAltText().then( () => {
		const url = new URL( window.location.href );
		url.searchParams.delete( 'wpai_bulk_alt_text' );
		url.searchParams.delete( 'wpai_attachment_ids' );
		window.history.replaceState( {}, '', url.toString() );
	} );
} );
