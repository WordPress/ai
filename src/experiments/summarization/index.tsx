/**
 * Summarization experiment plugin registration.
 */

/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import SummarizationPlugin from './components/SummarizationPlugin';

registerPlugin( 'classifai-plugin-summarization', {
	render: SummarizationPlugin,
} );
