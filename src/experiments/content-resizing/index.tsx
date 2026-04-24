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

// Register the aiResized attribute on paragraph blocks so we can visually flag
// blocks whose content was generated via the content-resizing ability.
addFilter(
	'blocks.registerBlockType',
	'ai/content-resizing-attribute',
	( settings, name ) => {
		if ( name !== 'core/paragraph' || ! aiContentResizingData?.enabled ) {
			return settings;
		}

		return {
			...settings,
			attributes: {
				...settings.attributes,
				aiResized: {
					type: 'boolean',
					default: false,
				},
			},
		};
	}
);

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
