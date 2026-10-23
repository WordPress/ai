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
	LocalizedData,
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
	return (
		data.rest.root.replace( /\/?$/, '/' ) + route.replace( /^\//, '' )
	);
}

/**
 * Posts JSON to a Site Agent route.
 *
 * @param data  Localized data.
 * @param route Route, relative to the REST root.
 * @param body  Request body.
 */
async function post< T >(
	data: LocalizedData,
	route: string,
	body: Record< string, unknown >
): Promise< T > {
	const response = await fetch( endpoint( data, route ), {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': data.rest.nonce,
		},
		body: JSON.stringify( body ),
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
 * Runs one conversation turn.
 *
 * @param data            Localized data.
 * @param prompt          The person's message.
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
 * @param data            Localized data.
 * @param proposalId      The proposal ID.
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
 * Declines a proposal.
 *
 * @param data            Localized data.
 * @param proposalId      The proposal ID.
 * @param conversationId The conversation ID.
 */
export function declineProposal(
	data: LocalizedData,
	proposalId: string,
	conversationId: string
): Promise< { declined: boolean } > {
	return post< { declined: boolean } >( data, data.rest.routes.decline, {
		proposal_id: proposalId,
		conversation_id: conversationId,
	} );
}
