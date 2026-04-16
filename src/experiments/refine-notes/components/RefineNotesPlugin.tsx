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
import { useRefineNotes } from '../hooks/useRefineNotes';

/**
 * RefineNotesPlugin component.
 *
 * Renders a "Refine from Notes" button in the post status info panel
 * when unresolved Notes exist.
 */
export default function RefineNotesPlugin() {
	const { isRefining, progress, total, hasPendingNotes, runRefinement } =
		useRefineNotes();

	if ( ! hasPendingNotes && ! isRefining ) {
		return null;
	}

	const buttonLabel = isRefining
		? sprintf(
				/* translators: 1: Current block number, 2: Total number of blocks. */
				__( 'Refining block (%1$s of %2$s)…', 'ai' ),
				progress,
				total
		  )
		: __( 'Refine from Notes', 'ai' );

	const buttonDescription = __(
		'Automatically updates blocks using unresolved feedback Notes.',
		'ai'
	);

	return (
		<PluginPostStatusInfo>
			<Flex
				direction="column"
				align="stretch"
				justify="flex-start"
				className="editor-post-refine-notes"
				gap={ 2 }
			>
				<FlexItem>
					<Button
						variant="secondary"
						icon={ commentContent }
						isBusy={ isRefining }
						disabled={ isRefining }
						onClick={ () => void runRefinement() }
						style={ { width: '100%', justifyContent: 'center' } }
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
		</PluginPostStatusInfo>
	);
}
