/**
 * Table caption generation integration with the block editor.
 */

/**
 * WordPress dependencies
 */
import { createHigherOrderComponent } from '@wordpress/compose';
import { addFilter } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import CaptionControls from './components/CaptionControls';
import { getSettings } from './settings';
import './index.scss';

interface BlockEditProps {
	clientId: string;
	name: string;
	isSelected: boolean;
}

/** Adds controls only for a selected Table block in the enabled experiment. */
const withTableCaption = createHigherOrderComponent( ( BlockEdit ) => {
	return ( props: BlockEditProps ) => (
		<>
			<BlockEdit { ...props } />
			{ props.name === 'core/table' &&
				props.isSelected &&
				getSettings().enabled && (
					<CaptionControls
						key={ props.clientId }
						clientId={ props.clientId }
					/>
				) }
		</>
	);
}, 'withTableCaption' );

addFilter(
	'editor.BlockEdit',
	'ai/table-caption-generation',
	withTableCaption
);
