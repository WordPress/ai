/**
 * Conversation state for the AI Workspace transcript.
 */

/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { toEntries, toOutcomes, toStoredChat } from '../utils/chat';
import type {
	ContextScope,
	LocalizedData,
	ProposalExecution,
	TranscriptEntry,
	TurnResponse,
} from '../types';

/**
 * What came of opening a stored chat.
 */
export type OpenResult = 'opened' | 'not-found' | 'failed' | 'superseded';

/**
 * The turn transport's outcome for one request.
 */
interface TurnOutcome {
	response: TurnResponse | null;
	streamed: boolean;
	error: { code: string; message: string } | null;
	// True when this request was aborted deliberately, which is the person
	// pressing Stop. An aborted request reports a failure of its own -- a
	// rejected fetch, or a read that ended early -- and that failure describes
	// the abort rather than the turn, so it must not be read as one.
	aborted: boolean;
}

const SENTENCE_BOUNDARY = /[.!?…](\s|$)|\n\n/;

/**
 * Monotonic counter used to key transcript entries.
 */
let nextEntryId = 0;

/**
 * Builds the message announced for a terminal status.
 *
 * @param status The entry status.
 * @return The announcement.
 */
function terminalAnnouncement( status: TranscriptEntry[ 'status' ] ): string {
	switch ( status ) {
		case 'cancelled':
			return __( 'Response stopped.', 'ai' );
		case 'max_rounds':
			return __(
				'The assistant reached its step limit and ended the turn.',
				'ai'
			);
		case 'error':
			return __( 'The response failed.', 'ai' );
		case 'tools_unavailable':
		case 'model_unavailable':
			return __( 'The assistant could not answer.', 'ai' );
		default:
			return __( 'Response complete.', 'ai' );
	}
}

/**
 * Reads a value from a record without trusting its shape.
 *
 * @param value The candidate record.
 * @param key   The key to read.
 * @return The string value, or an empty string.
 */
function readString( value: unknown, key: string ): string {
	if ( ! value || typeof value !== 'object' ) {
		return '';
	}

	const found = ( value as Record< string, unknown > )[ key ];

	return typeof found === 'string' ? found : '';
}

/**
 * Drives one conversation: sending turns, streaming them, and stopping them.
 *
 * The turn is consumed with `fetch` and a stream reader rather than
 * `EventSource`, which is GET-only, cannot carry the REST nonce as a header, and
 * would silently re-issue — and re-bill — the turn through its uncancellable
 * auto-reconnect.
 *
 * @param data Localized data provided by the server.
 * @return The conversation state and its controls.
 */
export function useTurn( data: LocalizedData ) {
	const [ entries, setEntries ] = useState< TranscriptEntry[] >( [] );
	const [ scope, setScope ] = useState< ContextScope >( 'site' );
	const [ isRunning, setIsRunning ] = useState( false );
	const [ isStopping, setIsStopping ] = useState( false );
	const [ announcement, setAnnouncement ] = useState( '' );
	// Mirrored into state as well as the ref: the ref keeps the turn transport
	// synchronous, and the confirmation surface needs to re-render when a
	// conversation is first identified by the server.
	const [ conversation, setConversation ] = useState( '' );
	// Outcomes approvals reported into a reopened chat, keyed by proposal ID.
	const [ outcomes, setOutcomes ] = useState<
		Record< string, ProposalExecution >
	>( {} );
	// Bumped whenever a turn settles, so the history list knows to refresh.
	const [ settled, setSettled ] = useState( 0 );
	/*
	 * True while a stored chat loads. Nothing can be sent meanwhile: the screen
	 * is about to show a different chat, and a turn started now would bind the
	 * screen to whichever conversation it ran in.
	 */
	const [ isOpening, setIsOpening ] = useState( false );
	const opening = useRef( false );

	const conversationId = useRef( '' );
	/*
	 * Which conversation the screen is showing. Switching chats, or starting a
	 * new one, bumps it, and a turn or a load that began under an older value
	 * leaves the transcript alone when it settles: its result belongs to a chat
	 * the person has already left.
	 */
	const generation = useRef( 0 );
	const controller = useRef< AbortController | null >( null );
	const announcedTo = useRef( 0 );

	useEffect(
		() => () => {
			controller.current?.abort();
		},
		[]
	);

	const endpoint = useCallback(
		( route: string ): string => {
			const path = data.rest.routes[ route ] ?? '';

			return data.rest.root + path;
		},
		[ data.rest ]
	);

	/**
	 * Announces completed sentences only, never individual chunks.
	 *
	 * @param text The assistant text received so far.
	 */
	const announceProgress = useCallback( ( text: string ): void => {
		const pending = text.slice( announcedTo.current );
		const boundary = pending.search( SENTENCE_BOUNDARY );

		if ( boundary === -1 ) {
			return;
		}

		const upTo = pending.lastIndexOf( '\n\n' ) + 2;
		const end = Math.max( boundary + 1, upTo );

		announcedTo.current += end;
		setAnnouncement( pending.slice( 0, end ).trim() );
	}, [] );

	/**
	 * Runs one turn against the transport, streaming when the host allows it.
	 *
	 * @param entryId   The transcript entry to update.
	 * @param message   The person's message.
	 * @param turnScope The scope for this turn.
	 * @return The outcome.
	 */
	const runTurn = useCallback(
		async (
			entryId: string,
			message: string,
			turnScope: ContextScope
		): Promise< TurnOutcome > => {
			const abort = new AbortController();
			controller.current = abort;

			let response: Response;

			try {
				response = await window.fetch( endpoint( 'messages' ), {
					method: 'POST',
					credentials: 'same-origin',
					signal: abort.signal,
					headers: {
						'Content-Type': 'application/json',
						Accept: 'text/event-stream, application/json',
						'X-WP-Nonce': data.rest.nonce,
						// Asks for the streaming transport. A host that cannot
						// stream answers with plain JSON instead.
						'X-WP-AI-Stream': '1',
					},
					body: JSON.stringify( {
						message,
						conversation_id: conversationId.current,
						scope: turnScope,
					} ),
				} );
			} catch ( error ) {
				return {
					response: null,
					streamed: false,
					aborted: abort.signal.aborted,
					error: {
						code: 'transport_failed',
						message:
							error instanceof Error
								? error.message
								: __( 'The request could not be sent.', 'ai' ),
					},
				};
			}

			const contentType = response.headers.get( 'content-type' ) ?? '';

			if (
				! contentType.includes( 'text/event-stream' ) ||
				! response.body
			) {
				// Buffered fallback: the host could not stream, so the whole
				// turn arrives at once as JSON.
				let payload: unknown = null;

				try {
					payload = await response.json();
				} catch {
					payload = null;
				}

				if ( ! response.ok ) {
					return {
						response: null,
						streamed: false,
						aborted: abort.signal.aborted,
						error: {
							code:
								readString( payload, 'code' ) || 'turn_failed',
							message:
								readString( payload, 'message' ) ||
								__(
									'The assistant could not complete this turn.',
									'ai'
								),
						},
					};
				}

				return {
					response: payload as TurnResponse,
					streamed: false,
					aborted: abort.signal.aborted,
					error: null,
				};
			}

			const reader = response.body.getReader();
			// Streaming mode keeps multi-byte characters intact across chunks.
			const decoder = new TextDecoder( 'utf-8' );
			let buffer = '';
			let text = '';
			let result: TurnResponse | null = null;
			let failure: { code: string; message: string } | null = null;

			const handleFrame = ( frame: string ): void => {
				let event = 'message';
				const dataLines: string[] = [];

				frame.split( '\n' ).forEach( ( line ) => {
					if ( line.startsWith( ':' ) ) {
						return;
					}

					if ( line.startsWith( 'event:' ) ) {
						event = line.slice( 6 ).trim();
						return;
					}

					if ( line.startsWith( 'data:' ) ) {
						dataLines.push( line.slice( 5 ).replace( /^ /, '' ) );
					}
				} );

				if ( dataLines.length === 0 ) {
					return;
				}

				let payload: unknown;

				try {
					payload = JSON.parse( dataLines.join( '\n' ) );
				} catch {
					return;
				}

				if ( 'delta' === event ) {
					text += readString( payload, 'text' );
					setEntries( ( current ) =>
						current.map( ( entry ) =>
							entry.id === entryId
								? { ...entry, text, streamed: true }
								: entry
						)
					);
					announceProgress( text );
					return;
				}

				if ( 'result' === event ) {
					result = payload as TurnResponse;
					return;
				}

				if ( 'error' === event ) {
					failure = {
						code: readString( payload, 'code' ) || 'turn_failed',
						message:
							readString( payload, 'message' ) ||
							__(
								'The assistant could not complete this turn.',
								'ai'
							),
					};
				}
			};

			try {
				for (;;) {
					const { done, value } = await reader.read();

					if ( value ) {
						buffer += decoder.decode( value, { stream: true } );

						let split = buffer.indexOf( '\n\n' );

						while ( split !== -1 ) {
							handleFrame( buffer.slice( 0, split ) );
							buffer = buffer.slice( split + 2 );
							split = buffer.indexOf( '\n\n' );
						}
					}

					if ( done ) {
						buffer += decoder.decode();

						if ( '' !== buffer.trim() ) {
							handleFrame( buffer );
						}

						break;
					}
				}
			} catch ( error ) {
				if ( ! abort.signal.aborted ) {
					failure = {
						code: 'stream_failed',
						message:
							error instanceof Error
								? error.message
								: __( 'The stream ended early.', 'ai' ),
					};
				}
			}

			return {
				response: result,
				streamed: true,
				aborted: abort.signal.aborted,
				error: failure,
			};
		},
		[ announceProgress, data.rest.nonce, endpoint ]
	);

	const send = useCallback(
		async ( message: string, turnScope: ContextScope ): Promise< void > => {
			const trimmed = message.trim();

			if ( '' === trimmed || isRunning || opening.current ) {
				return;
			}

			const started = generation.current;

			nextEntryId += 1;
			const entryId = 'turn-' + nextEntryId;

			announcedTo.current = 0;
			setAnnouncement( __( 'The assistant is responding.', 'ai' ) );
			setIsRunning( true );
			setIsStopping( false );
			setEntries( ( current ) => [
				...current,
				{
					id: entryId,
					prompt: trimmed,
					text: '',
					status: 'streaming',
					detail: '',
					scope: turnScope,
					toolCalls: [],
					rounds: 0,
					maxRounds: 0,
					streamed: false,
				},
			] );

			const outcome = await runTurn( entryId, trimmed, turnScope );

			/*
			 * The person switched chats while this turn ran; nothing of it is
			 * shown. The list still refreshes, because a first turn left this way
			 * finishes on the server as a new chat.
			 */
			if ( started !== generation.current ) {
				setSettled( ( count ) => count + 1 );
				return;
			}

			controller.current = null;

			setEntries( ( current ) =>
				current.map( ( entry ) => {
					if ( entry.id !== entryId ) {
						return entry;
					}

					/*
					 * A deliberate stop is authoritative. The aborted request
					 * settles either as a transport failure or with nothing
					 * read, and rewriting the entry from that would replace the
					 * stopped state the person asked for with an error they did
					 * not cause. The status is asserted rather than left alone
					 * because the abort can also come from `stop()`'s own
					 * fallback, where the entry is still streaming.
					 */
					if ( outcome.aborted ) {
						return { ...entry, status: 'cancelled' };
					}

					if ( outcome.error || ! outcome.response ) {
						return {
							...entry,
							status: 'error',
							detail:
								outcome.error?.message ??
								__(
									'The assistant could not complete this turn.',
									'ai'
								),
						};
					}

					const turn = outcome.response;

					return {
						...entry,
						text: '' !== turn.text ? turn.text : entry.text,
						status: turn.status,
						detail: turn.reason ?? '',
						toolCalls: Array.isArray( turn.tool_calls )
							? turn.tool_calls
							: [],
						rounds: turn.rounds ?? 0,
						maxRounds: turn.max_rounds ?? 0,
						streamed: outcome.streamed,
					};
				} )
			);

			if ( outcome.response?.conversation_id ) {
				conversationId.current = outcome.response.conversation_id;
				setConversation( outcome.response.conversation_id );
			}

			let announced: TranscriptEntry[ 'status' ] = 'error';

			if ( outcome.aborted ) {
				announced = 'cancelled';
			} else if ( outcome.response && ! outcome.error ) {
				announced = outcome.response.status;
			}

			setAnnouncement( terminalAnnouncement( announced ) );
			setIsRunning( false );
			setIsStopping( false );
			setSettled( ( count ) => count + 1 );
		},
		[ isRunning, runTurn ]
	);

	/**
	 * Stops the in-flight turn.
	 *
	 * Stopping calls the cancel route so the loop stops between rounds; closing
	 * the reader alone would leave server-side work running (R9). The reader is
	 * kept open afterwards so the terminated turn still reports its real status.
	 *
	 * A conversation's first turn is the one case where the identifier is not
	 * known yet — the server mints it — so there the reader is closed and the
	 * turn is marked stopped locally while the current round finishes server
	 * side. Every later turn is cancelled at the server.
	 */
	const stop = useCallback( async (): Promise< void > => {
		if ( ! isRunning ) {
			return;
		}

		setIsStopping( true );

		if ( '' === conversationId.current ) {
			controller.current?.abort();
			controller.current = null;
			setEntries( ( current ) =>
				current.map( ( entry, index ) =>
					index === current.length - 1 && entry.status === 'streaming'
						? { ...entry, status: 'cancelled' }
						: entry
				)
			);
			setAnnouncement( terminalAnnouncement( 'cancelled' ) );
			setIsRunning( false );
			setIsStopping( false );
			return;
		}

		try {
			await window.fetch( endpoint( 'cancel' ), {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': data.rest.nonce,
				},
				body: JSON.stringify( {
					conversation_id: conversationId.current,
				} ),
			} );
		} catch {
			// The turn still terminates through its own response; a failed
			// cancel call must not leave the transcript stuck.
			controller.current?.abort();
		}
	}, [ data.rest.nonce, endpoint, isRunning ] );

	/**
	 * Leaves the conversation on screen, stopping any turn still running in it.
	 *
	 * The request is aborted so nothing more reaches the transcript, and a turn
	 * the server already knows the chat for is cancelled there too, so it stops
	 * between rounds rather than running on unseen. A first turn has no chat yet
	 * to cancel; it finishes server-side and appears in the list as a new chat.
	 */
	const leave = useCallback( (): void => {
		generation.current += 1;

		if ( isRunning && '' !== conversationId.current ) {
			void window
				.fetch( endpoint( 'cancel' ), {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': data.rest.nonce,
					},
					body: JSON.stringify( {
						conversation_id: conversationId.current,
					} ),
				} )
				.catch( () => undefined );
		}

		controller.current?.abort();
		controller.current = null;
		announcedTo.current = 0;
		setIsRunning( false );
		setIsStopping( false );
	}, [ data.rest.nonce, endpoint, isRunning ] );

	/**
	 * Starts a new chat. The one on screen stays in the history list.
	 */
	const clear = useCallback( (): void => {
		leave();
		opening.current = false;
		setIsOpening( false );
		conversationId.current = '';
		setConversation( '' );
		setEntries( [] );
		setOutcomes( {} );
		setAnnouncement( __( 'New chat started.', 'ai' ) );
	}, [ leave ] );

	/**
	 * Opens a stored chat, rebuilding its transcript from the turn records.
	 *
	 * @param id The chat ID.
	 * @return What came of it.
	 */
	const open = useCallback(
		async ( id: string ): Promise< OpenResult > => {
			leave();

			const started = generation.current;
			opening.current = true;
			setIsOpening( true );

			let ok = false;
			let status = 0;
			let payload: unknown = null;

			try {
				const response = await window.fetch(
					endpoint( 'conversations' ) +
						'/' +
						encodeURIComponent( id ),
					{
						credentials: 'same-origin',
						headers: { 'X-WP-Nonce': data.rest.nonce },
					}
				);

				ok = response.ok;
				status = response.status;
				payload = await response.json();
			} catch {
				payload = null;
			}

			// A later open or a new chat owns the loading state now.
			if ( started !== generation.current ) {
				return 'superseded';
			}

			opening.current = false;
			setIsOpening( false );

			const chat = ok ? toStoredChat( payload ) : null;

			if ( ! chat ) {
				return 404 === status ? 'not-found' : 'failed';
			}

			conversationId.current = chat.id;
			setConversation( chat.id );
			setEntries( toEntries( chat ) );
			setOutcomes( toOutcomes( chat ) );
			setScope( 'general' === chat.scope ? 'general' : 'site' );
			setAnnouncement(
				sprintf(
					/* translators: %s: the chat's name. */
					__( 'Opened “%s”.', 'ai' ),
					chat.name
				)
			);

			return 'opened';
		},
		[ data.rest.nonce, endpoint, leave ]
	);

	/**
	 * Retries a failed turn by sending its prompt again as a new turn.
	 *
	 * @param entryId The failed entry.
	 */
	const retry = useCallback(
		( entryId: string ): void => {
			const entry = entries.find( ( item ) => item.id === entryId );

			if ( ! entry ) {
				return;
			}

			void send( entry.prompt, entry.scope );
		},
		[ entries, send ]
	);

	const summary = sprintf(
		/* translators: %d: number of turns in the conversation. */
		_n(
			'%d turn in this conversation.',
			'%d turns in this conversation.',
			entries.length,
			'ai'
		),
		entries.length
	);

	return {
		announcement,
		clear,
		conversationId: conversation,
		entries,
		isOpening,
		isRunning,
		isStopping,
		open,
		outcomes,
		retry,
		scope,
		send,
		setScope,
		settled,
		stop,
		summary,
	};
}
