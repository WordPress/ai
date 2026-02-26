/**
 * AI Review Notes plugin component.
 */

/**
 * WordPress dependencies
 */
import { Button, Flex, FlexItem } from '@wordpress/components';
import { PluginPostStatusInfo } from '@wordpress/editor';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useReviewNotes } from '../hooks/useReviewNotes';

/**
 * ReviewNotesPlugin component.
 *
 * Renders a "Review with AI" button in the post status info panel.
 * Clicking the button triggers a block-by-block AI review that creates
 * Notes on blocks with actionable suggestions.
 */
export default function ReviewNotesPlugin() {
	const { isReviewing, progress, total, lastRunCount, runReview } =
		useReviewNotes();

	if ( ! ( window as any ).aiReviewNotesData?.enabled ) {
		return null;
	}

	const reviewingLabel = sprintf(
		/* translators: 1: number of blocks reviewed so far, 2: total blocks to review. */
		__( 'Reviewing… (%1$d/%2$d)', 'ai' ),
		progress,
		total
	);
	const buttonLabel = isReviewing
		? reviewingLabel
		: __( 'Review with AI', 'ai' );

	return (
		<PluginPostStatusInfo>
			<Flex direction="column" gap={ 2 }>
				<FlexItem>
					<Button
						variant="secondary"
						onClick={ runReview }
						isBusy={ isReviewing }
						disabled={ isReviewing }
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
								: sprintf(
										/* translators: %d: number of suggestions added. */
										_n(
											'%d suggestion added.',
											'%d suggestions added.',
											lastRunCount,
											'ai'
										),
										lastRunCount
								  ) }
						</span>
					</FlexItem>
				) }
			</Flex>
		</PluginPostStatusInfo>
	);
}
