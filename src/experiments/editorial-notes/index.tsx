/**
 * Editorial Notes plugin registration.
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
import { exposeToDevTools } from '../../utils/devtools';

exposeToDevTools( {
	name: 'Editorial Notes',
	description: 'Reviews the post and provides editorial feedback using AI.',
	abilitySlug: 'ai/editorial-notes',
} );

registerPlugin( 'ai-editorial-notes', {
	render: () => (
		<PostTypeSupportCheck supportKeys="editor.notes">
			<EditorialNotesPlugin />
		</PostTypeSupportCheck>
	),
} );
