/**
 * Editorial Notes plugin component.
 */

/**
 * WordPress dependencies
 */
import {
	Button,
	Flex,
	FlexItem,
	MenuItem,
	Spinner,
} from '@wordpress/components';
import {
	BlockSettingsMenuControls,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editPostStore } from '@wordpress/edit-post';
import { PluginPostStatusInfo } from '@wordpress/editor';
import { createInterpolateElement } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { commentContent } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { REVIEWABLE_BLOCK_TYPES } from '../../../utils/notes';
import {
	useEditorialBlock,
	useEditorialNotes,
} from '../hooks/useEditorialNotes';

/**
 * EditorialNotesPlugin component.
 *
 * Renders a "Generate Editorial Notes" button in the post status info panel,
 * and a "Generate Editorial Note" item in the block settings menu for
 * reviewable blocks.
 */
export default function EditorialNotesPlugin() {
	const { isReviewing, progress, total, lastRunCount, runReview } =
		useEditorialNotes();
	const { isReviewing: isReviewingBlock, reviewBlock } = useEditorialBlock();
	const { openGeneralSidebar } = useDispatch( editPostStore );
	const openNotesPanel = () =>
		openGeneralSidebar?.( 'edit-post/collab-sidebar' );

	const isPreviewMode = useSelect( ( select ) => {
		return ( select( blockEditorStore ) as any ).getSettings()
			.isPreviewMode;
	}, [] );

	if ( ! ( window as any ).aiEditorialNotesData?.enabled ) {
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
		: __( 'Generate Editorial Notes', 'ai' );
	const buttonDescription = __(
		'This analyzes the content of this post block-by-block and adds editorial Notes with suggestions on each block.',
		'ai'
	);

	return (
		<>
			<PluginPostStatusInfo>
				<Flex direction="column" gap={ 2 }>
					<FlexItem>
						<Button
							variant="secondary"
							icon={ commentContent }
							onClick={ runReview }
							isBusy={ isReviewing }
							disabled={ isReviewing }
							style={ {
								justifyContent: 'center',
								width: '100%',
							} }
							accessibleWhenDisabled
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
														onClick={
															openNotesPanel
														}
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
			<BlockSettingsMenuControls>
				{ ( { selectedBlocks, selectedClientIds } ) => {
					if (
						! selectedBlocks.every( ( name ) =>
							REVIEWABLE_BLOCK_TYPES.includes( name )
						) ||
						isPreviewMode
					) {
						return null;
					}

					const clientId = selectedClientIds[ 0 ] ?? null;

					return (
						<MenuItem
							icon={
								isReviewingBlock ? <Spinner /> : commentContent
							}
							disabled={ isReviewingBlock }
							onClick={ () => {
								if ( clientId ) {
									reviewBlock( clientId );
								}
							} }
						>
							{ isReviewingBlock
								? __( 'Reviewing…', 'ai' )
								: __( 'Generate Editorial Note', 'ai' ) }
						</MenuItem>
					);
				} }
			</BlockSettingsMenuControls>
		</>
	);
}
