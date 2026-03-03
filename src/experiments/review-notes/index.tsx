/**
 * AI Review Notes experiment plugin and filter registration.
 */

/**
 * WordPress dependencies
 */
import { addFilter } from '@wordpress/hooks';
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import ReviewBlockControls from './components/ReviewBlockControls';
import ReviewNotesPlugin from './components/ReviewNotesPlugin';

registerPlugin( 'ai-review-notes', {
	render: ReviewNotesPlugin,
} );

addFilter(
	'editor.BlockEdit',
	'ai/review-notes-block-controls',
	ReviewBlockControls
);
