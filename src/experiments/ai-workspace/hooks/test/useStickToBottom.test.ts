/**
 * Unit coverage for when the transcript counts as scrolled to its newest content.
 */
/**
 * External dependencies
 */
import { describe, expect, test } from 'vitest';

/**
 * Internal dependencies
 */
import { NEAR_BOTTOM_THRESHOLD, isNearBottom } from '../useStickToBottom';

const metrics = ( fromBottom: number ) => ( {
	scrollHeight: 2000,
	clientHeight: 600,
	scrollTop: 2000 - 600 - fromBottom,
} );

describe( 'isNearBottom', () => {
	test( 'is true at the bottom', () => {
		expect( isNearBottom( metrics( 0 ) ) ).toBe( true );
	} );

	test( 'is true within the threshold', () => {
		expect( isNearBottom( metrics( NEAR_BOTTOM_THRESHOLD ) ) ).toBe( true );
	} );

	test( 'is false just beyond the threshold', () => {
		expect( isNearBottom( metrics( NEAR_BOTTOM_THRESHOLD + 1 ) ) ).toBe(
			false
		);
	} );

	test( 'is false when scrolled to the top of long content', () => {
		expect(
			isNearBottom( {
				scrollHeight: 2000,
				clientHeight: 600,
				scrollTop: 0,
			} )
		).toBe( false );
	} );

	test( 'is true when the content fits without scrolling', () => {
		expect(
			isNearBottom( {
				scrollHeight: 400,
				clientHeight: 600,
				scrollTop: 0,
			} )
		).toBe( true );
	} );

	test( 'honours a custom threshold', () => {
		expect( isNearBottom( metrics( 10 ), 5 ) ).toBe( false );
		expect( isNearBottom( metrics( 10 ), 10 ) ).toBe( true );
	} );
} );
