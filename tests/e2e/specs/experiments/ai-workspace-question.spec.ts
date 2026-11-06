/**
 * Unit coverage for the AI Workspace question narrowing.
 *
 * The narrowing decides which choices a question card shows, so it is asserted
 * directly rather than through the browser. These tests use no browser fixture
 * and run in any Playwright project.
 */
/**
 * External dependencies
 */
import { expect, test } from '@playwright/test';

/**
 * Internal dependencies
 */
import { toQuestion } from '../../../../src/experiments/ai-workspace/utils/question';

test.describe( 'AI Workspace question narrowing', () => {
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

	test( 'refuses a question left with fewer than two choices', () => {
		expect(
			toQuestion( { question: 'Pick one.', choices: [ 'Yes', 'Yes' ] } )
		).toBeNull();
		expect(
			toQuestion( { question: 'Pick one.', choices: [ 'Yes', '' ] } )
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
