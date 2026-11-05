/**
 * The list of a person's stored chats, with the controls that manage them.
 */

/**
 * WordPress dependencies
 */
import {
	Button,
	__experimentalConfirmDialog as ConfirmDialog, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	DropdownMenu,
	MenuGroup,
	MenuItem,
	Notice,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { moreVertical, plus as plusIcon } from '@wordpress/icons';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import { toChatList } from '../utils/chat';
import type { ChatSummary, RestData } from '../types';

/**
 * How the list is currently behaving.
 */
type ListState = 'loading' | 'ready' | 'error';

/**
 * One request to the conversations route, never throwing.
 *
 * @param rest   The REST transport data.
 * @param path   The path under the conversations route.
 * @param method The HTTP method.
 * @param body   Optional JSON body.
 * @param query  Optional query arguments.
 * @return Whether it succeeded, and the decoded payload.
 */
async function request(
	rest: RestData,
	path: string,
	method: string,
	body?: Record< string, unknown >,
	query?: Record< string, unknown >
): Promise< { ok: boolean; payload: unknown } > {
	// The REST root may already carry a query string (`?rest_route=`).
	const url = addQueryArgs(
		rest.root + ( rest.routes[ 'conversations' ] ?? '' ) + path, // eslint-disable-line dot-notation
		query ?? {}
	);

	try {
		const response = await window.fetch( url, {
			method,
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': rest.nonce,
			},
			...( undefined === body ? {} : { body: JSON.stringify( body ) } ),
		} );

		let payload: unknown = null;

		try {
			payload = await response.json();
		} catch {
			payload = null;
		}

		return { ok: response.ok, payload };
	} catch {
		return { ok: false, payload: null };
	}
}

/**
 * Renders one chat row, which becomes a name field while it is renamed.
 *
 * @param props           Component props.
 * @param props.chat      The chat.
 * @param props.isCurrent Whether it is the chat on screen.
 * @param props.onOpen    Opens the chat.
 * @param props.onRename  Saves a new name.
 * @param props.onArchive Archives or restores the chat.
 * @param props.onDelete  Asks to delete the chat.
 * @return The row.
 */
function ChatRow( {
	chat,
	isCurrent,
	onOpen,
	onRename,
	onArchive,
	onDelete,
}: {
	chat: ChatSummary;
	isCurrent: boolean;
	onOpen: () => void;
	onRename: ( name: string ) => void;
	onArchive: () => void;
	onDelete: () => void;
} ) {
	const [ renaming, setRenaming ] = useState( false );
	const [ name, setName ] = useState( chat.name );
	const field = useRef< HTMLInputElement | null >( null );

	useEffect( () => {
		if ( renaming ) {
			field.current?.focus();
			field.current?.select();
		}
	}, [ renaming ] );

	// An empty name keeps the old one rather than being sent to be refused.
	const finish = ( save: boolean ): void => {
		setRenaming( false );

		const trimmed = name.trim();

		if ( save && '' !== trimmed && trimmed !== chat.name ) {
			onRename( trimmed );
			return;
		}

		setName( chat.name );
	};

	if ( renaming ) {
		return (
			<li className="ai-workspace__history-row">
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					ref={ field }
					label={ __( 'Chat name', 'ai' ) }
					hideLabelFromVision
					value={ name }
					onChange={ setName }
					onBlur={ () => finish( true ) }
					onKeyDown={ ( event: React.KeyboardEvent ) => {
						if ( 'Enter' === event.key ) {
							event.preventDefault();
							finish( true );
						}

						if ( 'Escape' === event.key ) {
							event.preventDefault();
							event.stopPropagation();
							finish( false );
						}
					} }
				/>
			</li>
		);
	}

	return (
		<li className="ai-workspace__history-row">
			<Button
				__next40pxDefaultSize
				className="ai-workspace__history-open"
				variant="tertiary"
				isPressed={ isCurrent }
				aria-current={ isCurrent ? 'true' : undefined }
				onClick={ onOpen }
			>
				{ chat.name }
			</Button>
			<DropdownMenu
				icon={ moreVertical }
				label={ sprintf(
					/* translators: %s: the chat's name. */
					__( 'Actions for “%s”', 'ai' ),
					chat.name
				) }
				toggleProps={ { size: 'small' } }
			>
				{ ( { onClose } ) => (
					<MenuGroup>
						{ ! chat.archived && (
							<MenuItem
								onClick={ () => {
									onClose();
									setName( chat.name );
									setRenaming( true );
								} }
							>
								{ __( 'Rename', 'ai' ) }
							</MenuItem>
						) }
						<MenuItem
							onClick={ () => {
								onClose();
								onArchive();
							} }
						>
							{ chat.archived
								? __( 'Restore', 'ai' )
								: __( 'Archive', 'ai' ) }
						</MenuItem>
						<MenuItem
							isDestructive
							onClick={ () => {
								onClose();
								onDelete();
							} }
						>
							{ __( 'Delete', 'ai' ) }
						</MenuItem>
					</MenuGroup>
				) }
			</DropdownMenu>
		</li>
	);
}

/**
 * Renders the history panel.
 *
 * The list is the server's, refetched when a turn settles so a new chat, or one
 * that moved to the top, shows without a reload. Rename, archive and delete
 * each go to the server first and change the list only once it agrees, so the
 * panel never shows a state the server does not hold.
 *
 * @param props            Component props.
 * @param props.rest       The REST transport data.
 * @param props.currentId  The chat on screen, or an empty string.
 * @param props.refreshKey Changes whenever the list may be out of date.
 * @param props.onOpen     Opens a chat.
 * @param props.onNew      Starts a new chat.
 * @param props.onRemoved  Called with a chat's ID once it is deleted.
 * @return The panel contents.
 */
export default function ChatHistory( {
	rest,
	currentId,
	refreshKey,
	onOpen,
	onNew,
	onRemoved,
}: {
	rest: RestData;
	currentId: string;
	refreshKey: number;
	onOpen: ( id: string ) => void;
	onNew: () => void;
	onRemoved: ( id: string ) => void;
} ) {
	const [ archived, setArchived ] = useState( false );
	const [ chats, setChats ] = useState< ChatSummary[] >( [] );
	const [ state, setState ] = useState< ListState >( 'loading' );
	const [ notice, setNotice ] = useState( '' );
	const [ deleting, setDeleting ] = useState< ChatSummary | null >( null );
	const [ attempt, setAttempt ] = useState( 0 );

	useEffect( () => {
		let cancelled = false;

		setState( ( current ) =>
			'ready' === current ? current : 'loading'
		);

		void ( async () => {
			const { ok, payload } = await request( rest, '', 'GET', undefined, {
				archived: archived ? 1 : 0,
				per_page: 50,
			} );

			if ( cancelled ) {
				return;
			}

			const list = ok ? toChatList( payload ) : null;

			if ( ! list ) {
				setState( 'error' );
				return;
			}

			setChats( list );
			setState( 'ready' );
		} )();

		return () => {
			cancelled = true;
		};
	}, [ archived, attempt, refreshKey, rest ] );

	const rename = useCallback(
		async ( chat: ChatSummary, name: string ): Promise< void > => {
			const { ok, payload } = await request(
				rest,
				'/' + chat.id,
				'POST',
				{
					name,
				}
			);
			const saved =
				ok && payload && typeof payload === 'object'
					? ( payload as Record< string, unknown > )[ 'name' ] // eslint-disable-line dot-notation
					: null;

			if ( typeof saved !== 'string' ) {
				setNotice( __( 'The chat could not be renamed.', 'ai' ) );
				return;
			}

			setNotice( '' );
			setChats( ( current ) =>
				current.map( ( item ) =>
					item.id === chat.id ? { ...item, name: saved } : item
				)
			);
		},
		[ rest ]
	);

	const archive = useCallback(
		async ( chat: ChatSummary ): Promise< void > => {
			const { ok } = await request( rest, '/' + chat.id, 'POST', {
				archived: ! chat.archived,
			} );

			if ( ! ok ) {
				setNotice(
					chat.archived
						? __( 'The chat could not be restored.', 'ai' )
						: __( 'The chat could not be archived.', 'ai' )
				);
				return;
			}

			setNotice( '' );
			// It now belongs to the other list.
			setChats( ( current ) =>
				current.filter( ( item ) => item.id !== chat.id )
			);
		},
		[ rest ]
	);

	const remove = useCallback(
		async ( chat: ChatSummary ): Promise< void > => {
			const { ok } = await request( rest, '/' + chat.id, 'DELETE' );

			if ( ! ok ) {
				setNotice( __( 'The chat could not be deleted.', 'ai' ) );
				return;
			}

			setNotice( '' );
			setChats( ( current ) =>
				current.filter( ( item ) => item.id !== chat.id )
			);
			onRemoved( chat.id );
		},
		[ onRemoved, rest ]
	);

	let body: React.ReactNode;

	if ( 'loading' === state ) {
		body = (
			<p className="ai-workspace__history-state">
				<Spinner />
				{ __( 'Loading chats…', 'ai' ) }
			</p>
		);
	} else if ( 'error' === state ) {
		body = (
			<Notice status="error" isDismissible={ false }>
				<p>{ __( 'Your chats could not be loaded.', 'ai' ) }</p>
				<Button
					variant="secondary"
					size="compact"
					onClick={ () => setAttempt( ( count ) => count + 1 ) }
				>
					{ __( 'Retry', 'ai' ) }
				</Button>
			</Notice>
		);
	} else if ( 0 === chats.length ) {
		body = (
			<p className="ai-workspace__history-state">
				{ archived
					? __( 'No archived chats.', 'ai' )
					: __( 'No chats yet.', 'ai' ) }
			</p>
		);
	} else {
		body = (
			<ul className="ai-workspace__history-list">
				{ chats.map( ( chat ) => (
					<ChatRow
						key={ chat.id }
						chat={ chat }
						isCurrent={ chat.id === currentId }
						onOpen={ () => onOpen( chat.id ) }
						onRename={ ( name ) => {
							void rename( chat, name );
						} }
						onArchive={ () => {
							void archive( chat );
						} }
						onDelete={ () => setDeleting( chat ) }
					/>
				) ) }
			</ul>
		);
	}

	return (
		<>
			<div className="ai-workspace__history-actions">
				<Button
					__next40pxDefaultSize
					variant="secondary"
					icon={ plusIcon }
					onClick={ onNew }
				>
					{ __( 'New chat', 'ai' ) }
				</Button>
				<Button
					size="compact"
					variant="tertiary"
					isPressed={ archived }
					aria-pressed={ archived }
					onClick={ () => setArchived( ( current ) => ! current ) }
				>
					{ __( 'Archived', 'ai' ) }
				</Button>
			</div>

			{ '' !== notice && (
				<Notice status="error" onRemove={ () => setNotice( '' ) }>
					{ notice }
				</Notice>
			) }

			{ body }

			<ConfirmDialog
				isOpen={ null !== deleting }
				confirmButtonText={ __( 'Delete', 'ai' ) }
				onConfirm={ () => {
					const chat = deleting;
					setDeleting( null );

					if ( chat ) {
						void remove( chat );
					}
				} }
				onCancel={ () => setDeleting( null ) }
			>
				{ sprintf(
					/* translators: %s: the chat's name. */
					__( 'Delete “%s” for good? This cannot be undone.', 'ai' ),
					deleting?.name ?? ''
				) }
			</ConfirmDialog>
		</>
	);
}
