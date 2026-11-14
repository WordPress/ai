/**
 * Unit coverage for which tool results the AI Workspace transcript reads as proposals.
 *
 * The page passes the propose abilities of every registered kind, including a
 * kind another plugin adds, and the transcript draws a card only for a result
 * from one of them. These tests use no browser fixture and run in any
 * Playwright project.
 */
/**
 * External dependencies
 */
import { expect, test } from '@playwright/test';

/**
 * Internal dependencies
 */
import {
	PROPOSAL_ABILITIES,
	proposalAbilitiesFrom,
} from '../../../../src/experiments/ai-workspace/utils/proposal';

test.describe( 'AI Workspace proposal abilities', () => {
	test( 'uses the list the page passes, including another plugin’s kind', () => {
		const fromPage = [ ...PROPOSAL_ABILITIES, 'vendor/propose-thing' ];

		expect( proposalAbilitiesFrom( fromPage ) ).toEqual( fromPage );
	} );

	test( 'falls back to the built-in kinds when the page passes none', () => {
		expect( proposalAbilitiesFrom( undefined ) ).toEqual(
			PROPOSAL_ABILITIES
		);
		expect( proposalAbilitiesFrom( [] ) ).toEqual( PROPOSAL_ABILITIES );
		expect( proposalAbilitiesFrom( 'ai/propose-drafts' ) ).toEqual(
			PROPOSAL_ABILITIES
		);
	} );

	test( 'keeps only non-empty strings', () => {
		expect(
			proposalAbilitiesFrom( [ 'ai/propose-drafts', 3, null, '', {} ] )
		).toEqual( [ 'ai/propose-drafts' ] );
	} );
} );
