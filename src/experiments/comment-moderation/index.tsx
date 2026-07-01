/**
 * Comment Moderation experiment entry point.
 *
 * Initializes lazy analysis for pending comments.
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
import { exposeToDevTools } from '../../utils/devtools';

exposeToDevTools( {
	name: 'Comment Moderation',
	description: 'Analyses pending comments for spam and quality using AI.',
	abilitySlug: 'ai/comment-analysis',
} );

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
		</>
	);
}

domReady( init );
