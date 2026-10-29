/**
 * Unit coverage for the AI Workspace tool-result narrowing.
 *
 * The narrowing decides what a tool result is allowed to put on screen, so it
 * is asserted directly rather than through the browser. These tests use no
 * browser fixture and run in any Playwright project.
 *
 * External dependencies
 */
/**
 * External dependencies
 */
import { expect, test } from '@playwright/test';

/**
 * Internal dependencies
 */
import { toPostResultSet } from '../../../../src/experiments/ai-workspace/utils/post-results';

const ROW = {
	id: 12,
	post_type: 'post',
	status: 'publish',
	date: '2026-09-03T10:00:00',
	slug: 'quarterly-notes',
	link: 'https://example.test/quarterly-notes',
	title: 'Quarterly notes',
	excerpt: 'A short excerpt.',
};

test.describe( 'AI Workspace post result narrowing', () => {
	test( 'accepts a post list and keeps every returned field', () => {
		const result = toPostResultSet( {
			results: [ ROW ],
			total: 1,
			total_pages: 1,
		} );

		expect( result ).not.toBeNull();
		expect( result?.results ).toEqual( [ ROW ] );
		expect( result?.total ).toBe( 1 );
	} );

	test( 'reports an empty result set rather than refusing it', () => {
		const result = toPostResultSet( {
			results: [],
			total: 0,
			total_pages: 0,
		} );

		expect( result ).not.toBeNull();
		expect( result?.results ).toHaveLength( 0 );
	} );

	test( 'keeps the withheld-row count the ability reported', () => {
		const result = toPostResultSet( {
			results: [ ROW ],
			total: 9,
			total_pages: 1,
		} );

		expect( result?.total ).toBe( 9 );
		expect( result?.results ).toHaveLength( 1 );
	} );

	test( 'drops properties the ability does not declare', () => {
		const result = toPostResultSet( {
			results: [ { ...ROW, content: '<p>The whole body.</p>' } ],
			total: 1,
			total_pages: 1,
		} );

		expect( result?.results[ 0 ] ).toEqual( ROW );
		expect( result?.results[ 0 ] ).not.toHaveProperty( 'content' );
	} );

	test( 'offers no edit target unless the row carries one', () => {
		const without = toPostResultSet( {
			results: [ ROW ],
			total: 1,
			total_pages: 1,
		} );

		expect( without?.results[ 0 ]?.edit_link ).toBeUndefined();

		const withLink = toPostResultSet( {
			results: [
				{ ...ROW, edit_link: 'https://example.test/wp-admin/post.php' },
			],
			total: 1,
			total_pages: 1,
		} );

		expect( withLink?.results[ 0 ]?.edit_link ).toBe(
			'https://example.test/wp-admin/post.php'
		);
	} );

	test( 'drops a row with no usable identity', () => {
		const result = toPostResultSet( {
			results: [ { ...ROW, id: 'twelve' }, ROW ],
			total: 2,
			total_pages: 1,
		} );

		expect( result?.results ).toHaveLength( 1 );
		expect( result?.results[ 0 ]?.id ).toBe( 12 );
	} );

	test( 'refuses results that are not a post list', () => {
		expect( toPostResultSet( null ) ).toBeNull();
		expect( toPostResultSet( 'a string result' ) ).toBeNull();
		expect( toPostResultSet( [ ROW ] ) ).toBeNull();
		expect(
			toPostResultSet( {
				error: 'Sorry, you are not allowed to do that.',
				code: 'ability_invalid_permissions',
			} )
		).toBeNull();
	} );
} );
