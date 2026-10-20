/**
 * Revision change summary plugin for the visual revisions screen.
 */

/**
 * WordPress dependencies
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { createPortal } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useRevisionSummary } from '../hooks/useRevisionSummary';
import { useVisualRevision } from '../hooks/useVisualRevision';
import SummaryPanel from './SummaryPanel';

export const PLUGIN_NAME = 'ai-revision-change-summary';

// Same order as the revisions slider and the ability, so the first result is the oldest revision.
const OLDEST_REVISION_QUERY = {
	per_page: 1,
	orderby: 'date',
	order: 'asc',
	context: 'edit',
	_fields: 'id',
};

/**
 * Adds a "Change summary" panel to the Revision tab of the editor sidebar.
 *
 * The summary state lives here rather than in the panel, so a running
 * request and its result are kept when the sidebar is closed or switched
 * to another tab.
 */
export default function RevisionChangeSummaryPlugin() {
	const { postId, postType, isPreviewMode } = useSelect(
		( select ) => ( {
			postId: select( editorStore ).getCurrentPostId() as number | null,
			postType: select( editorStore ).getCurrentPostType() as
				| string
				| null,
			isPreviewMode: !! (
				select( blockEditorStore ).getSettings() as {
					isPreviewMode?: boolean;
				}
			 ).isPreviewMode,
		} ),
		[]
	);
	const { anchor, revisionId } = useVisualRevision( isPreviewMode );
	const summary = useRevisionSummary( postId ?? 0, revisionId );

	// Only fetched while revisions are shown.
	const { oldestRevisionId, isCheckingOldest } = useSelect(
		( select ) => {
			if (
				! window.aiRevisionChangeSummaryData?.enabled ||
				! anchor ||
				! postId ||
				! postType
			) {
				return { oldestRevisionId: null, isCheckingOldest: false };
			}

			const revisions = select( coreStore ).getRevisions(
				'postType',
				postType,
				postId,
				OLDEST_REVISION_QUERY
			) as Array< { id: number } > | null;

			return {
				oldestRevisionId: revisions?.[ 0 ]?.id ?? null,
				// If the request fails, the ability still reports the oldest revision.
				isCheckingOldest: ! select( coreStore ).hasFinishedResolution(
					'getRevisions',
					[ 'postType', postType, postId, OLDEST_REVISION_QUERY ]
				),
			};
		},
		[ anchor, postId, postType ]
	);

	// Matches the classic screen, which can't compare its oldest revision either.
	const unavailableMessage =
		revisionId && revisionId === oldestRevisionId
			? __( 'There is no earlier revision to compare with.', 'ai' )
			: undefined;

	if (
		! window.aiRevisionChangeSummaryData?.enabled ||
		! postId ||
		! anchor
	) {
		return null;
	}

	return createPortal(
		<PanelBody title={ __( 'Change summary', 'ai' ) }>
			<SummaryPanel
				summary={ summary }
				unavailableMessage={ unavailableMessage }
				isDisabled={ isCheckingOldest }
			/>
		</PanelBody>,
		anchor
	);
}
