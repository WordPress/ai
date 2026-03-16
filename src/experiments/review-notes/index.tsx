/**
 * AI Review Notes experiment plugin registration.
 */

/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import ReviewNotesPlugin from './components/ReviewNotesPlugin';

registerPlugin( 'ai-review-notes', {
	render: ReviewNotesPlugin,
} );
