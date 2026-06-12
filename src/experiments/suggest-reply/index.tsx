/**
 * WordPress dependencies
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { ReplyModalController } from './components/ReplyModalController';

declare global {
	interface Window {
		aiSuggestReplyData?: {
			enabled: boolean;
			nonce: string;
		};
	}
}

function init(): void {
	const data = window.aiSuggestReplyData;

	if ( ! data?.enabled ) {
		return;
	}

	const container = document.createElement( 'div' );
	container.id = 'wpai-suggest-reply-root';
	document.body.appendChild( container );

	createRoot( container ).render( <ReplyModalController /> );
}

domReady( init );
