/**
 * Refine Notes Experiment.
 */

/**
 * WordPress dependencies
 */
import { PostTypeSupportCheck } from '@wordpress/editor';
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import RefineNotesPlugin from './components/RefineNotesPlugin';

if ( ( window as any ).aiRefineNotesData?.enabled ) {
	registerPlugin( 'ai-refine-notes', {
		render: () => (
			<PostTypeSupportCheck supportKeys="editor.notes">
				<RefineNotesPlugin />
			</PostTypeSupportCheck>
		),
	} );
}
