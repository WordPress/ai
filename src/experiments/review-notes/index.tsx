/**
 * AI Review Notes plugin registration.
 */

/**
 * WordPress dependencies
 */
import { PostTypeSupportCheck } from '@wordpress/editor';
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import ReviewNotesPlugin from './components/ReviewNotesPlugin';

registerPlugin( 'ai-review-notes', {
	render: () => (
		<PostTypeSupportCheck supportKeys="editor.notes">
			<ReviewNotesPlugin />
		</PostTypeSupportCheck>
	),
} );
