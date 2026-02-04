/**
 * External dependencies
 */
import React from 'react';

/**
 * WordPress dependencies
 */
import { BlockControls } from '@wordpress/block-editor';
import { ToolbarGroup, ToolbarButton } from '@wordpress/components';
import { createHigherOrderComponent } from '@wordpress/compose';
import { __ } from '@wordpress/i18n';
import { update } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { useSummaryGeneration } from '../functions/useSummaryGeneration';

const { aiSummarizationData } = window as any;

/**
 * Block controls component.
 */
const Controls = () => {
	const { isSummarizing, hasSummary, handleSummarize } =
		useSummaryGeneration();
	const buttonLabel = hasSummary
		? __( 'Re-generate AI Summary', 'ai' )
		: __( 'Generate AI Summary', 'ai' );

	// Ensure the experiment is enabled.
	if ( ! aiSummarizationData?.enabled ) {
		return null;
	}

	return (
		<BlockControls>
			<ToolbarGroup>
				<ToolbarButton
					label={ buttonLabel }
					icon={ update }
					onClick={ handleSummarize }
					disabled={ isSummarizing }
					isBusy={ isSummarizing }
					style={ { marginTop: '1px', height: '46px' } }
				/>
			</ToolbarGroup>
		</BlockControls>
	);
};

/**
 * Add custom block controls to the summarization block.
 */
const SummarizationBlockControls = createHigherOrderComponent(
	( BlockEdit: React.ComponentType< any > ) => {
		return ( props: any ) => {
			const {
				name,
				isSelected,
				attributes: { aiGeneratedSummary = false },
			} = props;

			if ( name !== 'core/paragraph' || ! aiGeneratedSummary ) {
				return <BlockEdit { ...props } />;
			}

			return (
				<>
					{ isSelected && <Controls { ...props } /> }
					<BlockEdit { ...props } />
				</>
			);
		};
	},
	'addBlockControls'
);

export default SummarizationBlockControls;
