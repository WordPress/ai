/**
 * Unit coverage for how a stored chat becomes a transcript.
 *
 * These rules decide what a person sees when they reopen a chat, so they are
 * asserted directly rather than through the browser. These tests use no
 * browser fixture and run in any Playwright project.
 */
/**
 * External dependencies
 */
import { expect, test } from '@playwright/test';

/**
 * Internal dependencies
 */
import {
	chatFromUrl,
	findSavedTurn,
	newUuid,
	toChatList,
	toEntries,
	toOutcomes,
	toStoredChat,
	urlForChat,
} from '../../../../src/experiments/ai-workspace/utils/chat';

const stored = ( overrides: Record< string, unknown > = {} ) =>
	toStoredChat( {
		id: 'abc-123',
		name: 'Spring newsletter',
		archived: false,
		created: 1,
		updated: 2,
		scope: 'site',
		entries: [],
		write_results: [],
		...overrides,
	} );

test.describe( 'AI Workspace stored chats', () => {
	test( 'lists only the chats that have an ID', () => {
		expect(
			toChatList( {
				conversations: [
					{ id: 'a', name: 'One', archived: true },
					{ name: 'No ID' },
					'not a chat',
				],
			} )
		).toEqual( [
			{ id: 'a', name: 'One', archived: true, created: 0, updated: 0 },
		] );
		expect( toChatList( { nope: [] } ) ).toBeNull();
	} );

	test( 'rebuilds a turn from its stored record, never from model messages', () => {
		const chat = stored( {
			scope: 'general',
			entries: [
				{
					prompt: 'Plan it',
					text: 'Here is a plan.',
					status: 'complete',
					rounds: 2,
					tool_calls: [ { ability: 'ai/search-content' } ],
				},
			],
			messages: [ { role: 'user', parts: [ { text: 'ignored' } ] } ],
		} );

		expect( chat ).not.toBeNull();

		const [ entry ] = toEntries( chat! );

		expect( entry ).toMatchObject( {
			id: 'abc-123-0',
			prompt: 'Plan it',
			text: 'Here is a plan.',
			status: 'complete',
			scope: 'general',
			rounds: 2,
			streamed: false,
		} );
		expect( entry?.toolCalls ).toHaveLength( 1 );
	} );

	test( 'shows a stored turn with an unknown status as an error', () => {
		const [ entry ] = toEntries(
			stored( { entries: [ { prompt: 'x', status: 'streaming' } ] } )!
		);

		expect( entry?.status ).toBe( 'error' );
	} );

	test( 'keeps a turn that ended on a proposal or a question, and marks a follow-up', () => {
		const [ proposed, followedUp, typed ] = toEntries(
			stored( {
				entries: [
					{ prompt: 'Plan a series', status: 'awaiting_user' },
					{
						prompt: '',
						text: 'Both drafts are created.',
						status: 'complete',
						follow_up: true,
					},
					{ prompt: 'Thanks', status: 'complete', follow_up: false },
				],
			} )!
		);

		expect( proposed?.status ).toBe( 'awaiting_user' );
		expect( proposed?.followUp ).toBe( false );
		expect( followedUp?.followUp ).toBe( true );
		expect( typed?.followUp ).toBe( false );
	} );

	test( 'keys reported outcomes by proposal, keeping the last', () => {
		const outcomes = toOutcomes(
			stored( {
				write_results: [
					{ proposal_id: 'p1', created: 0, items: [] },
					{
						proposal_id: 'p1',
						created: 1,
						items: [
							{
								key: 'k',
								title: 'Draft',
								outcome: 'created',
								post_id: 9,
								edit_link:
									'https://example.test/wp-admin/post.php?post=9&action=edit',
							},
						],
					},
					{ created: 1, items: [] },
				],
			} )!
		);

		expect( Object.keys( outcomes ) ).toEqual( [ 'p1' ] );
		expect( outcomes.p1?.created ).toBe( 1 );
		expect( outcomes.p1?.items[ 0 ]?.edit_link ).toContain( 'post=9' );
	} );

	test( 'reads a chat from the URL and writes it back', () => {
		expect( chatFromUrl( '?page=ai-workspace&chat=abc-123' ) ).toBe(
			'abc-123'
		);
		expect( chatFromUrl( '?page=ai-workspace&chat=<script>' ) ).toBe( '' );
		expect( chatFromUrl( '?page=ai-workspace' ) ).toBe( '' );

		const href =
			'https://example.test/wp-admin/tools.php?page=ai-workspace';

		expect( urlForChat( href, 'abc-123' ) ).toBe( href + '&chat=abc-123' );
		expect( urlForChat( href + '&chat=abc-123', '' ) ).toBe( href );
	} );
	test( 'finds a saved turn by the ID the client gave it', () => {
		const chat = stored( {
			entries: [
				{ prompt: 'Earlier', text: 'Before.', status: 'complete' },
				{
					turn_id: 'turn-1',
					prompt: 'Plan it',
					text: 'Here is a plan.',
					status: 'awaiting_user',
					rounds: 2,
					tool_calls: [ { ability: 'ai/propose-drafts' } ],
				},
			],
		} );

		expect( chat ).not.toBeNull();
		expect( findSavedTurn( chat!, 'turn-1' ) ).toEqual( {
			response: {
				conversation_id: 'abc-123',
				scope: 'site',
				status: 'awaiting_user',
				rounds: 2,
				tools: [],
				tool_calls: [ { ability: 'ai/propose-drafts' } ],
				messages: [],
				text: 'Here is a plan.',
			},
			errorCode: null,
		} );
	} );

	test( 'reports a saved turn that failed as an error, and a missing one as not ended', () => {
		const chat = stored( {
			entries: [
				{
					turn_id: 'turn-1',
					prompt: 'Plan it',
					status: 'error',
					error_code: 'provider_down',
				},
				{ turn_id: 'turn-2', prompt: 'Odd', status: 'not-a-status' },
			],
		} );

		expect( findSavedTurn( chat!, 'turn-1' ) ).toEqual( {
			response: null,
			errorCode: 'provider_down',
		} );
		expect( findSavedTurn( chat!, 'turn-2' ) ).toEqual( {
			response: null,
			errorCode: 'turn_failed',
		} );
		expect( findSavedTurn( chat!, 'turn-3' ) ).toBeNull();
	} );

	test( 'makes version 4 UUIDs, with or without randomUUID', () => {
		const pattern =
			/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

		expect( newUuid() ).toMatch( pattern );

		const crypto = globalThis.crypto;
		const randomUUID = crypto.randomUUID;

		Object.defineProperty( crypto, 'randomUUID', {
			value: undefined,
			configurable: true,
		} );

		try {
			const ids = new Set(
				Array.from( { length: 50 }, () => newUuid() )
			);

			ids.forEach( ( id ) => expect( id ).toMatch( pattern ) );
			expect( ids.size ).toBe( 50 );
		} finally {
			Object.defineProperty( crypto, 'randomUUID', {
				value: randomUUID,
				configurable: true,
			} );
		}
	} );
} );
