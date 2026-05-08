/**
 * AI Editorial Notes plugin registration.
 */

/**
 * WordPress dependencies
 */
import { PostTypeSupportCheck } from '@wordpress/editor';
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import EditorialNotesPlugin from './components/EditorialNotesPlugin';

registerPlugin( 'ai-editorial-notes', {
	render: () => (
		<PostTypeSupportCheck supportKeys="editor.notes">
			<EditorialNotesPlugin />
		</PostTypeSupportCheck>
	),
} );
