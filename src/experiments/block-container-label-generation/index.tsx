/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import BlockContainerLabelGenerationListViewHeader from './components/BlockContainerLabelGenerationListViewHeader';
import RenameModalWrapper from './components/RenameModalWrapper';
import './index.scss';

function BlockContainerLabelGeneration() {
	return (
		<>
			<BlockContainerLabelGenerationListViewHeader />
			<RenameModalWrapper />
		</>
	);
}

registerPlugin( 'ai-block-container-label-generation', {
	render: BlockContainerLabelGeneration,
} );
