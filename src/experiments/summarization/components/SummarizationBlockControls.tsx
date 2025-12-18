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
 * Controls component.
 */
const Controls = () => {
	return (
		<BlockControls>
			<ToolbarGroup>
				<ToolbarButton
					icon={ update }
					label={ __( 'Regenerate', 'ai' ) }
					onClick={ () => { console.log( 'Regenerate' ); } }
				/>
			</ToolbarGroup>
		</BlockControls>
	);
};

/**
 * Add Custom Block Controls
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
