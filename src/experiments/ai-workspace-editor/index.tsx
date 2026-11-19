/**
 * Block editor handoff into the AI Workspace. Only the post ID is passed; the workspace reads content via permission-checked tools.
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

interface HandoffData {
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
