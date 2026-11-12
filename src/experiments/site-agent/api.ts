/**
 * Site Agent REST client.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type {
	ApprovalResponse,
	Conversation,
	ConversationSummary,
	ConversationsResponse,
	DeclineResponse,
	LocalizedData,
	Proposal,
	TurnResponse,
} from './types';

export class ApiError extends Error {
	readonly code: string;

	constructor( message: string, code = 'site_agent_request_failed' ) {
		super( message );
		this.name = 'ApiError';
		this.code = code;
	}
}

/**
 * Builds an absolute REST URL.
 *
 * @param data  Localized data.
 * @param route Route, relative to the REST root.
 */
function endpoint( data: LocalizedData, route: string ): string {
	return data.rest.root.replace( /\/?$/, '/' ) + route.replace( /^\//, '' );
}

/**
 * Sends a request to a Site Agent route.
 *
 * @param data  Localized data.
 * @param route Route, relative to the REST root.
 * @param init  Fetch options.
 */
async function request< T >(
	data: LocalizedData,
	route: string,
	init: RequestInit = {}
): Promise< T > {
	const headers: Record< string, string > = {
		'X-WP-Nonce': data.rest.nonce,
	};

	if ( init.body ) {
		headers[ 'Content-Type' ] = 'application/json';
	}

	const response = await fetch( endpoint( data, route ), {
		credentials: 'same-origin',
		...init,
		headers,
	} );

	const payload = await response.json().catch( () => null );

	if ( ! response.ok ) {
		const message =
			payload && typeof payload.message === 'string'
				? payload.message
				: __( 'The Site Agent request failed.', 'ai' );
		const code =
			payload && typeof payload.code === 'string'
				? payload.code
				: 'site_agent_request_failed';

		throw new ApiError( message, code );
	}

	return payload as T;
}

/**
 * Posts JSON to a Site Agent route.
 *
 * @param data  Localized data.
 * @param route Route, relative to the REST root.
 * @param body  Request body.
 */
function post< T >(
	data: LocalizedData,
	route: string,
	body: Record< string, unknown >
): Promise< T > {
	return request< T >( data, route, {
		method: 'POST',
		body: JSON.stringify( body ),
	} );
}

/**
 * Runs one conversation turn.
 *
 * @param data           Localized data.
 * @param prompt         The person's message.
 * @param conversationId Existing conversation ID, or an empty string.
 */
export function sendTurn(
	data: LocalizedData,
	prompt: string,
	conversationId: string
): Promise< TurnResponse > {
	return post< TurnResponse >( data, data.rest.routes.turn, {
		prompt,
		conversation_id: conversationId,
	} );
}

/**
 * Approves a stored proposal.
 *
 * @param data           Localized data.
 * @param proposalId     The proposal ID.
 * @param conversationId The conversation ID.
 */
export function approveProposal(
	data: LocalizedData,
	proposalId: string,
	conversationId: string
): Promise< ApprovalResponse > {
	return post< ApprovalResponse >( data, data.rest.routes.approve, {
		proposal_id: proposalId,
		conversation_id: conversationId,
	} );
}

/**
 * Saves edited values on a pending proposal.
 *
 * @param data           Localized data.
 * @param proposalId     The proposal ID.
 * @param conversationId The conversation ID.
 * @param args           The edited arguments.
 */
export function updateProposal(
	data: LocalizedData,
	proposalId: string,
	conversationId: string,
	args: Record< string, unknown >
): Promise< Proposal > {
	return post< Proposal >( data, data.rest.routes.updateProposal, {
		proposal_id: proposalId,
		conversation_id: conversationId,
		args,
	} );
}

/**
 * Declines a proposal.
 *
 * @param data           Localized data.
 * @param proposalId     The proposal ID.
 * @param conversationId The conversation ID.
 */
export function declineProposal(
	data: LocalizedData,
	proposalId: string,
	conversationId: string
): Promise< DeclineResponse > {
	return post< DeclineResponse >( data, data.rest.routes.decline, {
		proposal_id: proposalId,
		conversation_id: conversationId,
	} );
}

/**
 * Lists the current user's conversations.
 *
 * @param data Localized data.
 */
export function listConversations(
	data: LocalizedData
): Promise< ConversationsResponse > {
	return request< ConversationsResponse >(
		data,
		data.rest.routes.conversations
	);
}

/**
 * Loads one conversation.
 *
 * @param data Localized data.
 * @param id   Conversation ID.
 */
export function getConversation(
	data: LocalizedData,
	id: string
): Promise< Conversation > {
	return request< Conversation >(
		data,
		`${ data.rest.routes.conversations }/${ id }`
	);
}

/**
 * Renames one conversation.
 *
 * @param data  Localized data.
 * @param id    Conversation ID.
 * @param title The new title.
 */
export function renameConversation(
	data: LocalizedData,
	id: string,
	title: string
): Promise< ConversationSummary > {
	return post< ConversationSummary >(
		data,
		`${ data.rest.routes.conversations }/${ id }/rename`,
		{ title }
	);
}

/**
 * Archives or unarchives one conversation.
 *
 * @param data     Localized data.
 * @param id       Conversation ID.
 * @param archived Whether to archive the conversation.
 */
export function archiveConversation(
	data: LocalizedData,
	id: string,
	archived: boolean
): Promise< ConversationSummary > {
	return post< ConversationSummary >(
		data,
		`${ data.rest.routes.conversations }/${ id }/archive`,
		{ archived }
	);
}

/**
 * Deletes one conversation.
 *
 * @param data Localized data.
 * @param id   Conversation ID.
 */
export function deleteConversation(
	data: LocalizedData,
	id: string
): Promise< { deleted: boolean } > {
	return request< { deleted: boolean } >(
		data,
		`${ data.rest.routes.conversations }/${ id }`,
		{ method: 'DELETE' }
	);
}
