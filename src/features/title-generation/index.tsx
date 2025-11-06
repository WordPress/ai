/**
 * Title generation feature plugin registration.
 *
 * @package WordPress\AI
 */

import * as React from 'react';
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { BlockControls } from '@wordpress/block-editor';
import TitleToolbar from './components/TitleToolbar';

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
