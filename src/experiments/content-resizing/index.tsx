/**
 * Content resizing plugin registration.
 */

/**
 * WordPress dependencies
 */
import { BlockControls } from '@wordpress/block-editor';
import { createHigherOrderComponent } from '@wordpress/compose';
import { addFilter } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import ContentResizingToolbar from './components/ContentResizingToolbar';
import './index.scss';

const { aiContentResizingData } = window as any;

const withContentResizing = createHigherOrderComponent( ( BlockEdit ) => {
	return ( props: any ) => {
		if (
			props.name !== 'core/paragraph' ||
			! aiContentResizingData?.enabled
		) {
			return <BlockEdit { ...props } />;
		}

		return (
			<>
				{ props.isSelected && (
					<BlockControls>
						<ContentResizingToolbar
							clientId={ props.clientId }
							blockName={ props.name }
						/>
					</BlockControls>
				) }
				<BlockEdit { ...props } />
			</>
		);
	};
}, 'withContentResizing' );

addFilter( 'editor.BlockEdit', 'ai/content-resizing', withContentResizing );
