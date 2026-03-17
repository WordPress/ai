/**
 * Contextual tagging experiment plugin registration.
 */

/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import TagPanelWrapper from './components/TagPanelWrapper';
import CategoryPanelWrapper from './components/CategoryPanelWrapper';

// Register plugin for tag suggestions in the Tags sidebar panel.
registerPlugin( 'ai-contextual-tagging-tags', {
	render: TagPanelWrapper,
} );

// Register plugin for category suggestions in the Categories sidebar panel.
registerPlugin( 'ai-contextual-tagging-categories', {
	render: CategoryPanelWrapper,
} );
