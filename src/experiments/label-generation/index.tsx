/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import LabelGenerationListViewHeader from './components/LabelGenerationListViewHeader';
import RenameModalWrapper from './components/RenameModalWrapper';
import { useLabelGeneration } from './hooks/useLabelGeneration';
import './index.scss';

function LabelGeneration() {
	const labelGeneration = useLabelGeneration();

	return (
		<>
			<LabelGenerationListViewHeader
				labelGeneration={ labelGeneration }
			/>
			<RenameModalWrapper />
		</>
	);
}

registerPlugin( 'ai-label-generation', {
	render: LabelGeneration,
} );
