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
} );
