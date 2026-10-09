/**
 * Title generation plugin registration.
 */

/**
 * WordPress dependencies
 */
import { BlockControls } from '@wordpress/block-editor';
import { createHigherOrderComponent } from '@wordpress/compose';
import { addFilter } from '@wordpress/hooks';
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import './index.scss';
import TitleToolbar from './components/TitleToolbar';
import { TitleToolbarWrapper } from './components/TitleToolbarWrapper';
import { RenameModalWrapper } from './components/RenameModalWrapper';

// For template preview mode (when title is a block)
// Use filter to add toolbar to post-title block
const withTitleToolbar = createHigherOrderComponent( ( BlockEdit ) => {
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
}, 'withTitleToolbar' );

addFilter( 'editor.BlockEdit', 'ai/title-generation', withTitleToolbar );

// For normal editing mode (when title is not a block)
// Register a plugin that uses DOM manipulation to attach toolbar
registerPlugin( 'ai-title-generation-normal-mode', {
	render: TitleToolbarWrapper,
} );

// For post rename modal (accessible from Post inspector 3-dots menu)
registerPlugin( 'ai-title-generation-rename-modal', {
	render: RenameModalWrapper,
} );
