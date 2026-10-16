/**
 * Revision change summary plugin registration for the block editor.
 */

/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import RevisionChangeSummaryPlugin, {
	PLUGIN_NAME,
} from './components/RevisionChangeSummaryPlugin';
import './index.scss';

registerPlugin( PLUGIN_NAME, {
	render: RevisionChangeSummaryPlugin,
} );
