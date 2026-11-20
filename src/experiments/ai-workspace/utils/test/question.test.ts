/**
 * Unit coverage for the AI Workspace question narrowing.
 */
/**
 * External dependencies
 */
import { describe, expect, test } from 'vitest';

/**
 * Internal dependencies
 */
import { questionState, toQuestion } from '../question';
import type { EntryStatus, TranscriptEntry } from '../../types';

const entry = (
	status: EntryStatus,
	prompt = '',
	followUp = false
): TranscriptEntry => ( {
	id: status + prompt,
	turnId: '',
	prompt,
	text: '',
	status,
	detail: '',
	scope: 'site',
	toolCalls: [],
	rounds: 1,
	maxRounds: 0,
	streamed: false,
	followUp,
} );

describe( 'AI Workspace question narrowing', () => {
	test( 'accepts a question with its choices', () => {
		expect(
			toQuestion( {
				question: 'How would you like to go on?',
				choices: [ 'Create these 5 drafts', 'Fewer parts' ],
			} )
		).toEqual( {
			question: 'How would you like to go on?',
			choices: [ 'Create these 5 drafts', 'Fewer parts' ],
		} );
	} );

	test( 'trims choices to the text a click sends', () => {
		expect(
			toQuestion( {
				question: ' Go ahead? ',
				choices: [ ' Yes ', 'No' ],
			} )
		).toEqual( { question: 'Go ahead?', choices: [ 'Yes', 'No' ] } );
	} );

	test( 'drops blank, repeated and non-string choices', () => {
		expect(
			toQuestion( {
				question: 'Pick one.',
				choices: [ 'Yes', ' ', 'Yes ', 7, null, 'No' ],
			} )
		).toEqual( { question: 'Pick one.', choices: [ 'Yes', 'No' ] } );
	} );

	test( 'accepts a single choice, since the card can always be closed', () => {
		expect(
			toQuestion( {
				question: 'Create them?',
				choices: [ 'Yes', 'Yes' ],
			} )
		).toEqual( { question: 'Create them?', choices: [ 'Yes' ] } );
	} );

	test( 'refuses a question left with no choices', () => {
		expect(
			toQuestion( { question: 'Pick one.', choices: [ ' ', '' ] } )
		).toBeNull();
		expect(
			toQuestion( { question: 'Pick one.', choices: [] } )
		).toBeNull();
	} );

	test( 'refuses results that are not a question', () => {
		expect( toQuestion( null ) ).toBeNull();
		expect( toQuestion( [ 'Yes', 'No' ] ) ).toBeNull();
		expect(
			toQuestion( { question: '  ', choices: [ 'Yes', 'No' ] } )
		).toBeNull();
		expect(
			toQuestion( { question: 'Go?', choices: 'Yes, No' } )
		).toBeNull();
	} );
} );

describe( 'AI Workspace question state', () => {
	test( 'a question with nothing after it is live', () => {
		expect( questionState( [ entry( 'awaiting_user' ) ], 0 ) ).toEqual( {
			isLive: true,
			isDismissed: false,
			reply: null,
		} );
	} );

	test( 'the next message answers it; a follow-up does not', () => {
		const entries = [
			entry( 'awaiting_user' ),
			entry( 'complete', '', true ),
			entry( 'complete', 'Fewer parts' ),
		];

		expect( questionState( entries.slice( 0, 2 ), 0 ).isLive ).toBe( true );
		expect( questionState( entries, 0 ) ).toEqual( {
			isLive: false,
			isDismissed: false,
			reply: 'Fewer parts',
		} );
	} );

	test( 'a dismissal closes it, and a later message is not its answer', () => {
		expect(
			questionState(
				[
					entry( 'awaiting_user' ),
					entry( 'dismissed' ),
					entry( 'complete', 'Fewer parts' ),
				],
				0
			)
		).toEqual( { isLive: false, isDismissed: true, reply: null } );
	} );

	test( 'a turn still streaming holds it closed', () => {
		expect(
			questionState(
				[ entry( 'awaiting_user' ), entry( 'streaming', '', true ) ],
				0
			).isLive
		).toBe( false );
	} );
} );
