/**
 * Title generation feature plugin registration.
 *
 * @package WordPress\AI
 */

import * as React from 'react';
import { registerPlugin } from '@wordpress/plugins';
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { BlockControls } from '@wordpress/block-editor';
import TitleToolbar from './components/TitleToolbar';
import TitleToolbarWrapper from './components/TitleToolbarWrapper';

// For template editing mode (when title is a block)
// Use filter to add toolbar to post-title block
const withTitleToolbar = createHigherOrderComponent(
	( BlockEdit ) => {
		return ( props: any ) => {
			// Check if this is the post-title block
			if ( props.name !== 'core/post-title' ) {
				return <BlockEdit { ...props } />;
			}

			return (
				<>
					<BlockEdit { ...props } />
					<BlockControls>
						<TitleToolbar />
					</BlockControls>
				</>
			);
		};
	},
	'withTitleToolbar'
);

addFilter( 'editor.BlockEdit', 'ai/title-generation', withTitleToolbar );

// For normal editing mode (when title is not a block)
// Register a plugin that uses DOM manipulation to attach toolbar
registerPlugin( 'ai-title-generation-normal-mode', {
	render: TitleToolbarWrapper,
} );
