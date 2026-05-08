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
	const { isSummarizing, hasSummary, handleSummarize, isContentTooShort } =
		useSummaryGeneration();
	let buttonLabel: string = __( 'Generate Summary', 'ai' );

	if ( isSummarizing ) {
		buttonLabel = __( 'Generating…', 'ai' );
	} else if ( hasSummary ) {
		buttonLabel = __( 'Regenerate Summary', 'ai' );
	}

	// Don't render if disabled.
	if ( ! aiSummarizationData?.enabled ) {
		return null;
	}

	return (
		<BlockControls>
			<ToolbarGroup>
				<ToolbarButton
					label={ buttonLabel }
					icon={ update }
					className="ai-summarization-block-controls-button"
					onClick={ handleSummarize }
					disabled={ isSummarizing || isContentTooShort }
					isBusy={ isSummarizing }
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

			if ( name !== 'core/group' || ! aiGeneratedSummary ) {
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
