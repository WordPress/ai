/**
 * AI Review Notes block toolbar controls.
 */

/**
 * External dependencies
 */
import type { ComponentType } from 'react';

/**
 * WordPress dependencies
 */
import { BlockControls } from '@wordpress/block-editor';
import { ToolbarGroup, ToolbarButton } from '@wordpress/components';
import { createHigherOrderComponent } from '@wordpress/compose';
import { __ } from '@wordpress/i18n';
import { commentContent } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import {
	REVIEWABLE_BLOCK_TYPES,
	useReviewBlock,
} from '../hooks/useReviewNotes';

/**
 * Inner button component — only mounts when a reviewable block is selected.
 *
 * @param root0
 * @param root0.clientId
 */
function ReviewBlockButton( { clientId }: { clientId: string } ) {
	const { isReviewing, reviewBlock } = useReviewBlock();

	return (
		<BlockControls>
			<ToolbarGroup>
				<ToolbarButton
					label={ __( 'Generate Review Note', 'ai' ) }
					icon={ commentContent }
					onClick={ () => reviewBlock( clientId ) }
					isBusy={ isReviewing }
					disabled={ isReviewing }
					style={ { marginTop: '1px', height: '46px' } }
				/>
			</ToolbarGroup>
		</BlockControls>
	);
}

/**
 * HOC that adds the Review Notes toolbar button to reviewable blocks.
 */
const ReviewBlockControls = createHigherOrderComponent(
	( BlockEdit: ComponentType< any > ) => {
		return ( props: any ) => {
			if ( ! REVIEWABLE_BLOCK_TYPES.includes( props.name ) ) {
				return <BlockEdit { ...props } />;
			}

			if ( ! ( window as any ).aiReviewNotesData?.enabled ) {
				return <BlockEdit { ...props } />;
			}

			return (
				<>
					<BlockEdit { ...props } />
					{ props.isSelected && (
						<ReviewBlockButton clientId={ props.clientId } />
					) }
				</>
			);
		};
	},
	'reviewNotesBlockControls'
);

export default ReviewBlockControls;
