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
import './index.scss';

declare global {
	interface Window {
		aiSuggestReplyData?: {
			enabled: boolean;
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
