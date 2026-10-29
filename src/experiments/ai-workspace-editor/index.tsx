/**
 * Block editor handoff into the AI Workspace.
 *
 * The action carries the post's identity to the workspace and nothing else.
 * The body is never handed over: the workspace reads content through the
 * permission-checked tool path, so there is one enforcement path and no trust
 * is placed in a client-supplied body.
 */

/**
 * WordPress dependencies
 */
import { useDispatch, useSelect } from '@wordpress/data';
import { PluginMoreMenuItem, store as editorStore } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import { registerPlugin } from '@wordpress/plugins';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import AIIcon from '../../../routes/ai-home/ai-icon';

/**
 * Data the server localizes onto this bundle.
 */
interface HandoffData {
	/** Absolute admin URL of the workspace screen. */
	workspaceUrl: string;
	/** Query argument the workspace reads the seeded post from. */
	postArg: string;
}

declare global {
	interface Window {
		aiWorkspaceHandoff?: HandoffData;
	}
}

const NOTICE_ID = 'ai_workspace_handoff_error';

/**
 * Renders the "Open in AI Workspace" action in the editor's options menu.
 *
 * @return The menu item, or null when the server localized no handoff target.
 */
function OpenInWorkspace(): JSX.Element | null {
	const handoff = window.aiWorkspaceHandoff;

	const postId = useSelect( ( select ) => {
		/* eslint-disable-next-line dot-notation */
		return select( editorStore )[ 'getCurrentPostId' ]() as
			| number
			| undefined;
	}, [] );

	const noticesDispatch = useDispatch( noticesStore );

	if ( ! handoff?.workspaceUrl || ! handoff.postArg ) {
		return null;
	}

	const openWorkspace = () => {
		noticesDispatch.removeNotice( NOTICE_ID );

		// A post that has never been persisted has no identity to hand over.
		if ( ! postId ) {
			noticesDispatch.createErrorNotice(
				__(
					'Save this post before opening it in the AI Workspace.',
					'ai'
				),
				{ id: NOTICE_ID, isDismissible: true }
			);
			return;
		}

		window.location.assign(
			addQueryArgs( handoff.workspaceUrl, {
				[ handoff.postArg ]: postId,
			} )
		);
	};

	return (
		<PluginMoreMenuItem
			icon={ <AIIcon size={ 24 } /> }
			onClick={ openWorkspace }
		>
			{ __( 'Open in AI Workspace', 'ai' ) }
		</PluginMoreMenuItem>
	);
}

registerPlugin( 'ai-workspace-handoff', {
	render: OpenInWorkspace,
} );
