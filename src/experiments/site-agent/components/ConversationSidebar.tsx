/**
 * Conversation history sidebar.
 */

/**
 * WordPress dependencies
 */
import {
	Button,
	CheckboxControl,
	DropdownMenu,
	MenuItem,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { archive, close, moreVertical, pencil, trash } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import type { ConversationSummary } from '../types';

interface ConversationSidebarProps {
	conversations: ConversationSummary[];
	activeId: string;
	showArchived: boolean;
	showNewTopic: boolean;
	open: boolean;
	onSelect: ( id: string ) => void;
	onNewTopic: () => void;
	onRename: ( conversation: ConversationSummary ) => void;
	onArchive: ( conversation: ConversationSummary, archived: boolean ) => void;
	onDelete: ( conversation: ConversationSummary ) => void;
	onShowArchivedChange: ( show: boolean ) => void;
	onClose: () => void;
}

/**
 * Renders the list of saved conversations.
 *
 * @param props                      Component props.
 * @param props.conversations        The conversation summaries.
 * @param props.activeId             The active conversation ID.
 * @param props.showArchived         Whether archived conversations are shown.
 * @param props.showNewTopic         Whether the new topic button is shown.
 * @param props.open                 Whether the mobile drawer is open.
 * @param props.onSelect             Called when a conversation is selected.
 * @param props.onNewTopic           Called when a new topic starts.
 * @param props.onRename             Called when a conversation is renamed.
 * @param props.onArchive            Called when a conversation is archived.
 * @param props.onDelete             Called when a conversation is deleted.
 * @param props.onShowArchivedChange Called when the archived filter changes.
 * @param props.onClose              Called when the drawer closes.
 */
export default function ConversationSidebar( {
	conversations,
	activeId,
	showArchived,
	showNewTopic,
	open,
	onSelect,
	onNewTopic,
	onRename,
	onArchive,
	onDelete,
	onShowArchivedChange,
	onClose,
}: ConversationSidebarProps ): React.JSX.Element {
	const visible = conversations.filter(
		( conversation ) => showArchived || ! conversation.archived
	);

	return (
		<aside
			className={ `ai-site-agent__sidebar${ open ? ' is-open' : '' }` }
			aria-label={ __( 'Conversation history', 'ai' ) }
		>
			<div className="ai-site-agent__sidebar-header">
				<h2>{ __( 'Conversations', 'ai' ) }</h2>
				<Button
					className="ai-site-agent__sidebar-close"
					icon={ close }
					label={ __( 'Close conversation history', 'ai' ) }
					onClick={ onClose }
					size="compact"
				/>
			</div>

			{ showNewTopic && (
				<Button
					__next40pxDefaultSize
					className="ai-site-agent__new-topic"
					variant="secondary"
					onClick={ onNewTopic }
				>
					{ __( 'New topic', 'ai' ) }
				</Button>
			) }

			<div className="ai-site-agent__conversation-list">
				{ 0 === visible.length && (
					<p className="ai-site-agent__conversation-empty">
						{ __( 'No conversations yet.', 'ai' ) }
					</p>
				) }

				{ visible.map( ( conversation ) => (
					<div
						key={ conversation.id }
						className={ `ai-site-agent__conversation${
							conversation.id === activeId ? ' is-active' : ''
						}` }
					>
						<button
							type="button"
							className="ai-site-agent__conversation-main"
							onClick={ () => onSelect( conversation.id ) }
						>
							<span className="ai-site-agent__conversation-title">
								{ conversation.title ||
									__( 'Untitled conversation', 'ai' ) }
								{ conversation.archived && (
									<span className="ai-site-agent__conversation-badge">
										{ __( 'Archived', 'ai' ) }
									</span>
								) }
							</span>
							{ conversation.preview && (
								<span className="ai-site-agent__conversation-preview">
									{ conversation.preview }
								</span>
							) }
						</button>

						<DropdownMenu
							icon={ moreVertical }
							label={ sprintf(
								/* translators: %s: conversation title. */
								__( 'Actions for %s', 'ai' ),
								conversation.title ||
									__( 'Untitled conversation', 'ai' )
							) }
						>
							{ ( { onClose: closeMenu } ) => (
								<>
									<MenuItem
										icon={ pencil }
										onClick={ () => {
											onRename( conversation );
											closeMenu();
										} }
									>
										{ __( 'Rename', 'ai' ) }
									</MenuItem>
									<MenuItem
										icon={ archive }
										onClick={ () => {
											onArchive(
												conversation,
												! conversation.archived
											);
											closeMenu();
										} }
									>
										{ conversation.archived
											? __( 'Unarchive', 'ai' )
											: __( 'Archive', 'ai' ) }
									</MenuItem>
									<MenuItem
										icon={ trash }
										onClick={ () => {
											onDelete( conversation );
											closeMenu();
										} }
									>
										{ __( 'Delete', 'ai' ) }
									</MenuItem>
								</>
							) }
						</DropdownMenu>
					</div>
				) ) }
			</div>

			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __( 'Show archived', 'ai' ) }
				checked={ showArchived }
				onChange={ onShowArchivedChange }
			/>
		</aside>
	);
}
