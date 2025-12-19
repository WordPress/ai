/**
 * Comment Moderation experiment entry point.
 *
 * Initializes lazy analysis for pending comments and handles
 * the AI Reply modal functionality.
 */

/**
 * WordPress dependencies
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { LazyAnalysisController } from './components/LazyAnalysisController';
import { ReplyModalController } from './components/ReplyModalController';

declare global {
	interface Window {
		aiCommentModerationData?: {
			enabled: boolean;
			nonce: string;
		};
	}
}

/**
 * Initialize the comment moderation experiment.
 */
function init(): void {
	const data = window.aiCommentModerationData;

	if ( ! data?.enabled ) {
		return;
	}

	// Create a container for the React components.
	const container = document.createElement( 'div' );
	container.id = 'ai-comment-moderation-root';
	document.body.appendChild( container );

	// Mount the React components.
	const root = createRoot( container );
	root.render(
		<>
			<LazyAnalysisController />
			<ReplyModalController />
		</>
	);
}

domReady( init );
