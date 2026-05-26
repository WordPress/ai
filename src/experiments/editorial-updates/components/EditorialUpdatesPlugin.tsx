/**
 * Refine Notes plugin component.
 */

/**
 * WordPress dependencies
 */
import { Button, Flex, FlexItem } from '@wordpress/components';
import { PluginPostStatusInfo } from '@wordpress/editor';
import { __, sprintf } from '@wordpress/i18n';
import { commentContent } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { useEditorialUpdates } from '../hooks/useEditorialUpdates';

/**
 * EditorialUpdatesPlugin component.
 *
 * Renders a "Editorial Updates" button in the post status info panel
 * when unresolved Notes exist.
 */
export default function EditorialUpdatesPlugin() {
	const { isRefining, progress, total, hasPendingNotes, runRefinement } =
		useEditorialUpdates();
	const shouldShowControl = hasPendingNotes || isRefining;

	const buttonLabel = isRefining
		? sprintf(
				/* translators: 1: Current block number, 2: Total number of blocks. */
				__( 'Refining block (%1$s of %2$s)…', 'ai' ),
				progress,
				total
		  )
		: __( 'Apply Editorial Updates', 'ai' );

	const buttonDescription = __(
		'Automatically applies pending editorial Notes to update your content.',
		'ai'
	);
	const postStatusInfoProps = shouldShowControl
		? {}
		: { className: 'editor-post-editorial-updates--hidden' };

	return (
		<PluginPostStatusInfo { ...postStatusInfoProps }>
			{ shouldShowControl && (
				<Flex
					direction="column"
					align="stretch"
					justify="flex-start"
					className="editor-post-editorial-updates"
					gap={ 2 }
				>
					<FlexItem>
						<Button
							variant="secondary"
							icon={ commentContent }
							isBusy={ isRefining }
							disabled={ isRefining }
							onClick={ () => void runRefinement() }
							style={ {
								width: '100%',
								justifyContent: 'center',
							} }
						>
							{ buttonLabel }
						</Button>
					</FlexItem>
					<FlexItem>
						<span
							className="description"
							style={ { color: '#757575' } }
						>
							{ buttonDescription }
						</span>
					</FlexItem>
				</Flex>
			) }
		</PluginPostStatusInfo>
	);
}
