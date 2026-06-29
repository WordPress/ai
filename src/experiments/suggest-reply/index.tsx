/**
 * Suggest reply experiment plugin registration.
 */

/**
 * WordPress dependencies
 */
import domReady from '@wordpress/dom-ready';

/**
 * Internal dependencies
 */
import { init } from './components/SuggestReply';

declare global {
	interface Window {
		aiSuggestReplyData?: {
			enabled: boolean;
			nonce: string;
		};
	}
}

domReady( () => {
	const data = window.aiSuggestReplyData;

	if ( ! data?.enabled ) {
		return;
	}

	init();
} );
