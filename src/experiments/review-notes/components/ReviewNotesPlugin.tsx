/**
 * AI Review Notes plugin component.
 */

/**
 * WordPress dependencies
 */
import { Button, Flex, FlexItem } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { store as editPostStore } from '@wordpress/edit-post';
import { PluginPostStatusInfo } from '@wordpress/editor';
import { createInterpolateElement } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { commentContent } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { useReviewNotes } from '../hooks/useReviewNotes';

/**
 * ReviewNotesPlugin component.
 *
 * Renders a "Generate Review Notes" button in the post status info panel.
 * Clicking the button triggers a block-by-block AI review that creates
 * Notes on blocks with actionable suggestions.
 */
export default function ReviewNotesPlugin() {
	const { isReviewing, progress, total, lastRunCount, runReview } =
		useReviewNotes();
	const { openGeneralSidebar } = useDispatch( editPostStore );
	const openNotesPanel = () =>
		openGeneralSidebar?.( 'edit-post/collab-sidebar' );

	if ( ! ( window as any ).aiReviewNotesData?.enabled ) {
		return null;
	}

	const reviewingLabel = sprintf(
		/* translators: 1: number of blocks reviewed so far, 2: total blocks to review. */
		__( 'Reviewing blocks… (%1$d of %2$d)', 'ai' ),
		progress,
		total
	);
	const buttonLabel = isReviewing
		? reviewingLabel
		: __( 'Generate Review Notes', 'ai' );
	const buttonDescription = __(
		'This will review the content of this post block-by-block, and create Notes attached to each block with suggestions.',
		'ai'
	);

	return (
		<PluginPostStatusInfo>
			<Flex direction="column" gap={ 2 }>
				<FlexItem>
					<Button
						variant="secondary"
						icon={ commentContent }
						onClick={ runReview }
						isBusy={ isReviewing }
						disabled={ isReviewing }
						style={ { justifyContent: 'center', width: '100%' } }
						__next40pxDefaultSize
					>
						{ buttonLabel }
					</Button>
				</FlexItem>
				{ lastRunCount !== null && (
					<FlexItem>
						<span className="description">
							{ lastRunCount === 0
								? __( 'No new suggestions found.', 'ai' )
								: createInterpolateElement(
										sprintf(
											/* translators: %d: number of suggestions added. The <a> tags wrap a link to open the Notes panel. */
											_n(
												'%d suggestion added, view those Notes <a>here</a>.',
												'%d suggestions added, view those Notes <a>here</a>.',
												lastRunCount,
												'ai'
											),
											lastRunCount
										),
										{
											a: (
												<Button
													variant="link"
													onClick={ openNotesPanel }
												/>
											),
										}
								  ) }
						</span>
					</FlexItem>
				) }
				<FlexItem>
					<span
						className="description"
						style={ { color: '#757575' } }
					>
						{ buttonDescription }
					</span>
				</FlexItem>
			</Flex>
		</PluginPostStatusInfo>
	);
}
