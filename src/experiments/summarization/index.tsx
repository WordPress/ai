/**
 * Summarization plugin registration.
 */

/**
 * WordPress dependencies
 */
import { registerBlockVariation } from '@wordpress/blocks';
import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import SummarizationPlugin from './components/SummarizationPlugin';
import SummarizationBlockControls from './components/SummarizationBlockControls';
import { registerSummaryBlockAttribute } from './utils';
import './index.scss';

// Register the plugin.
registerPlugin( 'classifai-plugin-summarization', {
	render: SummarizationPlugin,
} );

// Register our custom attribute.
registerSummaryBlockAttribute();

// Register the block variation.
registerBlockVariation( 'core/group', {
	name: 'ai-summarization-summary',
	title: __( 'Content Summary', 'ai' ),
	description: __( 'A generated summary of the post content.', 'ai' ),
	attributes: {
		className: 'ai-summarization-summary',
		aiGeneratedSummary: true,
	},
	scope: [ 'block' ],
	isActive: [ 'aiGeneratedSummary' ],
} );

// Add the custom block controls.
addFilter(
	'editor.BlockEdit',
	'ai/summarization-block-controls',
	SummarizationBlockControls
);
