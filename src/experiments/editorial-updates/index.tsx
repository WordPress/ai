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
import { exposeToDevTools } from '../../utils/devtools';

exposeToDevTools( {
	name: 'Editorial Updates',
	description:
		'Rewrites selected post content based on editorial instructions using AI.',
	abilitySlug: 'ai/editorial-updates',
} );

if ( ( window as any ).aiEditorialUpdatesData?.enabled ) {
	registerPlugin( 'ai-editorial-updates', {
		render: () => (
			<PostTypeSupportCheck supportKeys="editor.notes">
				<EditorialUpdatesPlugin />
			</PostTypeSupportCheck>
		),
	} );
}
