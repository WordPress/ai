/**
 * Narrows untrusted proposal payloads to the shapes the confirmation renders.
 */

/**
 * Internal dependencies
 */
import type {
	Proposal,
	ProposalExecution,
	ProposalItem,
	ProposalItemOutcome,
} from '../types';

/**
 * The abilities whose successful result carries a stored proposal, one per kind.
 *
 * A copy of the server's list, which is built from the proposal kinds
 * (`Proposal_Store::proposal_abilities()`); `Proposal_StoreTest` fails when the
 * two differ, so add a new kind's ability here too.
 */
export const PROPOSAL_ABILITIES = [
	'ai/propose-drafts',
	'ai/propose-template',
	'ai/propose-post-changes',
	'ai/propose-site-identity',
];

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
 * Reads a boolean property from an untrusted record.
 *
 * @param record The record.
 * @param key    The property to read.
 * @return True only for a literal `true`.
 */
function readBoolean(
	record: Record< string, unknown >,
	key: string
): boolean {
	return true === record[ key ];
}

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
 * Reads the proposal identifier out of a proposal ability's tool result.
 *
 * Only the identifier is taken. Everything the confirmation renders is read
 * back from the server's stored copy of the proposal, never from the tool
 * result, so the values shown are the values that will be written (R16).
 *
 * @param value The raw tool result.
 * @return The proposal ID, or an empty string when the result is not one.
 */
export function toProposalId( value: unknown ): string {
	const record = toRecord( value );

	if ( ! record ) {
		return '';
	}

	return readString( record, 'proposal_id' );
}

/**
 * Narrows a proposal response to the shape the confirmation renders.
 *
 * @param value The raw response.
 * @return The proposal, or null when the response is not one.
 */
export function toProposal( value: unknown ): Proposal | null {
	const record = toRecord( value );

	if ( ! record ) {
		return null;
	}

	const { items: rows } = record;

	if ( ! Array.isArray( rows ) ) {
		return null;
	}

	const items: ProposalItem[] = [];

	rows.forEach( ( row ) => {
		const source = toRecord( row );

		if ( ! source ) {
			return;
		}

		const key = readString( source, 'key' );

		if ( '' === key ) {
			return;
		}

		items.push( {
			key,
			post_type: readString( source, 'post_type' ),
			status: readString( source, 'status' ),
			title: readString( source, 'title' ),
			content: readString( source, 'content' ),
			excerpt: readString( source, 'excerpt' ),
			slug: readString( source, 'slug' ),
			theme: readString( source, 'theme' ),
			replaces_theme_template: readBoolean(
				source,
				'replaces_theme_template'
			),
			post_id: readNumber( source, 'post_id' ),
			field: readString( source, 'field' ),
			current: readString( source, 'current' ),
			value: readString( source, 'value' ),
			live: readBoolean( source, 'live' ),
		} );
	} );

	return {
		// A draft proposal carries no kind, as before proposals had kinds.
		kind: readString( record, 'kind' ) || 'draft',
		proposal_id: readString( record, 'proposal_id' ),
		conversation_id: readString( record, 'conversation_id' ),
		status: readString( record, 'status' ),
		expires: readNumber( record, 'expires' ),
		max_items: readNumber( record, 'max_items' ),
		items,
		results: toProposalExecution( record[ 'results' ] ), // eslint-disable-line dot-notation
	};
}

/**
 * Narrows an execution response to the shape the outcome list renders.
 *
 * @param value The raw response.
 * @return The execution result, or null when the response is not one.
 */
export function toProposalExecution(
	value: unknown
): ProposalExecution | null {
	const record = toRecord( value );

	if ( ! record ) {
		return null;
	}

	const { items: rows } = record;

	if ( ! Array.isArray( rows ) ) {
		return null;
	}

	const items: ProposalItemOutcome[] = [];

	rows.forEach( ( row ) => {
		const source = toRecord( row );

		if ( ! source ) {
			return;
		}

		items.push( {
			key: readString( source, 'key' ),
			title: readString( source, 'title' ),
			post_type: readString( source, 'post_type' ),
			status: readString( source, 'status' ),
			outcome: readString( source, 'outcome' ),
			post_id: readNumber( source, 'post_id' ),
			edit_link: readString( source, 'edit_link' ),
			error_code: readString( source, 'error_code' ),
			error_message: readString( source, 'error_message' ),
			field: readString( source, 'field' ),
			value: readString( source, 'value' ),
		} );
	} );

	return {
		proposal_id: readString( record, 'proposal_id' ),
		created: readNumber( record, 'created' ),
		updated: readNumber( record, 'updated' ),
		unchanged: readNumber( record, 'unchanged' ),
		stale: readNumber( record, 'stale' ),
		locked: readNumber( record, 'locked' ),
		failed: readNumber( record, 'failed' ),
		denied: readNumber( record, 'denied' ),
		duplicate: readNumber( record, 'duplicate' ),
		deselected: readNumber( record, 'deselected' ),
		items,
		follow_up: readFollowUp( record ),
		discarded: readBoolean( record, 'discarded' ),
	};
}

/**
 * Reads whether a proposal response says the assistant may follow up.
 *
 * Anything but a literal `true` is no, so a malformed response never starts a
 * model turn.
 *
 * @param value A proposal execute or discard response.
 * @return True when the server owes a follow-up.
 */
export function readFollowUp( value: unknown ): boolean {
	return true === toRecord( value )?.[ 'follow_up' ]; // eslint-disable-line dot-notation
}

/**
 * Reads a refused edit out of an execute error response.
 *
 * The server names the edited item it refused, so its message can sit beside
 * that item's field. An error that names no item is not an edit error.
 *
 * @param value The raw error response.
 * @return The item key and the server's message, or null.
 */
export function toEditError(
	value: unknown
): { key: string; message: string } | null {
	const record = toRecord( value );
	const data = record ? toRecord( record[ 'data' ] ) : null; // eslint-disable-line dot-notation

	if ( ! record || ! data ) {
		return null;
	}

	const key = readString( data, 'item_key' );
	const message = readString( record, 'message' );

	return '' === key || '' === message ? null : { key, message };
}
