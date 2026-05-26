/**
 * Editorial Updates Experiment.
 */

/**
 * WordPress dependencies
 */
import { PostTypeSupportCheck } from '@wordpress/editor';
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import EditorialUpdatesPlugin from './components/EditorialUpdatesPlugin';
import './index.scss';

if ( ( window as any ).aiEditorialUpdatesData?.enabled ) {
	registerPlugin( 'ai-editorial-updates', {
		render: () => (
			<PostTypeSupportCheck supportKeys="editor.notes">
				<EditorialUpdatesPlugin />
			</PostTypeSupportCheck>
		),
	} );
}
