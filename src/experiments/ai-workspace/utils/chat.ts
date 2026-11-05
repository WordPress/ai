/**
 * Narrows the chat history routes' untrusted payloads to the shapes the
 * workspace renders.
 */

/**
 * Internal dependencies
 */
import { toProposalExecution } from './proposal';
import type {
	ChatSummary,
	ContextScope,
	EntryStatus,
	ProposalExecution,
	StoredChat,
	ToolCallRecord,
	TranscriptEntry,
} from '../types';

/**
 * Statuses a stored turn may carry. Anything else is shown as an error.
 */
const STORED_STATUSES: EntryStatus[] = [
	'complete',
	'max_rounds',
	'cancelled',
	'tools_unavailable',
	'model_unavailable',
	'error',
];

/**
 * Narrows a value to a plain record.
 *
 * @param value The value.
 * @return The record, or null.
 */
function toRecord( value: unknown ): Record< string, unknown > | null {
	if ( ! value || typeof value !== 'object' || Array.isArray( value ) ) {
		return null;
	}

	return value as Record< string, unknown >;
}

/**
 * Reads a property from an untrusted record.
 *
 * @param record The record.
 * @param key    The property to read.
 * @return The raw value.
 */
function field( record: Record< string, unknown >, key: string ): unknown {
	return record[ key ];
}

/**
 * Reads a string property from an untrusted record.
 *
 * @param record The record.
 * @param key    The property to read.
 * @return The string value, or an empty string.
 */
function readString( record: Record< string, unknown >, key: string ): string {
	const value = record[ key ];

	return typeof value === 'string' ? value : '';
}

/**
 * Reads an integer property from an untrusted record.
 *
 * @param record The record.
 * @param key    The property to read.
 * @return The number, or zero.
 */
function readNumber( record: Record< string, unknown >, key: string ): number {
	const value = record[ key ];

	return typeof value === 'number' && Number.isFinite( value ) ? value : 0;
}

/**
 * Reads a list property from an untrusted record.
 *
 * @param record The record.
 * @param key    The property to read.
 * @return The list, or an empty one.
 */
function listOf( record: Record< string, unknown >, key: string ): unknown[] {
	const value = field( record, key );

	return Array.isArray( value ) ? value : [];
}

/**
 * Narrows one chat summary.
 *
 * @param value The raw summary.
 * @return The summary, or null when it has no ID.
 */
export function toChatSummary( value: unknown ): ChatSummary | null {
	const record = toRecord( value );
	const id = record ? readString( record, 'id' ) : '';

	if ( ! record || '' === id ) {
		return null;
	}

	return {
		id,
		name: readString( record, 'name' ),
		archived: true === field( record, 'archived' ),
		created: readNumber( record, 'created' ),
		updated: readNumber( record, 'updated' ),
	};
}

/**
 * Narrows the list route's response to its chats.
 *
 * @param value The raw response.
 * @return The chats, or null when the response is not a list.
 */
export function toChatList( value: unknown ): ChatSummary[] | null {
	const record = toRecord( value );
	const rows = record ? field( record, 'conversations' ) : null;

	if ( ! Array.isArray( rows ) ) {
		return null;
	}

	return rows
		.map( toChatSummary )
		.filter( ( chat ): chat is ChatSummary => null !== chat );
}

/**
 * Narrows the single-chat route's response.
 *
 * @param value The raw response.
 * @return The chat, or null when the response is not one.
 */
export function toStoredChat( value: unknown ): StoredChat | null {
	const summary = toChatSummary( value );
	const record = toRecord( value );

	if ( ! summary || ! record ) {
		return null;
	}

	return {
		...summary,
		scope: readString( record, 'scope' ),
		entries: listOf( record, 'entries' ),
		write_results: listOf( record, 'write_results' ),
	};
}

/**
 * Rebuilds the transcript from a chat's stored turn records.
 *
 * Each record already holds what the transcript shows for a finished turn: the
 * prompt, the reply text, the status and the tool calls. The model messages are
 * never read here.
 *
 * @param chat The stored chat.
 * @return The transcript entries, oldest first.
 */
export function toEntries( chat: StoredChat ): TranscriptEntry[] {
	const scope: ContextScope = 'general' === chat.scope ? 'general' : 'site';
	const entries: TranscriptEntry[] = [];

	chat.entries.forEach( ( value, index ) => {
		const record = toRecord( value );

		if ( ! record ) {
			return;
		}

		const stored = readString( record, 'status' ) as EntryStatus;
		const status = STORED_STATUSES.includes( stored ) ? stored : 'error';

		entries.push( {
			id: chat.id + '-' + index,
			prompt: readString( record, 'prompt' ),
			text: readString( record, 'text' ),
			status,
			detail: '',
			scope,
			toolCalls: listOf( record, 'tool_calls' ) as ToolCallRecord[],
			rounds: readNumber( record, 'rounds' ),
			maxRounds: 0,
			streamed: false,
		} );
	} );

	return entries;
}

/**
 * Collects the outcomes reported into a chat, keyed by proposal ID.
 *
 * A proposal approved more than once keeps its last reported outcome.
 *
 * @param chat The stored chat.
 * @return The outcomes.
 */
export function toOutcomes(
	chat: StoredChat
): Record< string, ProposalExecution > {
	const outcomes: Record< string, ProposalExecution > = {};

	chat.write_results.forEach( ( value ) => {
		const execution = toProposalExecution( value );

		if ( execution && '' !== execution.proposal_id ) {
			outcomes[ execution.proposal_id ] = execution;
		}
	} );

	return outcomes;
}

/**
 * Reads the chat a workspace URL names.
 *
 * @param search The location's query string.
 * @return The chat ID, or an empty string.
 */
export function chatFromUrl( search: string ): string {
	const id = new URLSearchParams( search ).get( 'chat' ) ?? '';

	return /^[A-Za-z0-9-]+$/.test( id ) ? id : '';
}

/**
 * Builds the workspace URL for a chat, keeping every other parameter.
 *
 * @param href The current URL.
 * @param id   The chat ID, or an empty string for a new chat.
 * @return The URL.
 */
export function urlForChat( href: string, id: string ): string {
	const url = new URL( href );

	if ( '' === id ) {
		url.searchParams.delete( 'chat' );
	} else {
		url.searchParams.set( 'chat', id );
	}

	return url.toString();
}
