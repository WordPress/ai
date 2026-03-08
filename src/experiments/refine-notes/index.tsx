/**
 * AI Refine Notes Experiment.
 */

/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import RefineNotesPlugin from './components/RefineNotesPlugin';

if ( ( window as any ).aiRefineNotesData?.enabled ) {
	registerPlugin( 'ai-refine-notes', {
		render: RefineNotesPlugin,
	} );
}
