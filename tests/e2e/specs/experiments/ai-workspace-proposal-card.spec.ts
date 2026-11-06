/**
 * Unit coverage for what the AI Workspace proposal card says and links to.
 *
 * These rules decide what a person approves and where they land afterwards, so
 * they are asserted directly rather than through the browser. These tests use
 * no browser fixture and run in any Playwright project.
 */
/**
 * External dependencies
 */
import { expect, test } from '@playwright/test';

/**
 * Internal dependencies
 */
import {
	canOpen,
	createLabel,
	createdLabel,
	initialSelection,
	listLink,
} from '../../../../src/experiments/ai-workspace/utils/proposal-card';
import type {
	Proposal,
	ProposalItem,
	ProposalItemOutcome,
} from '../../../../src/experiments/ai-workspace/types';

const item = ( overrides: Partial< ProposalItem > = {} ): ProposalItem => ( {
	key: 'a',
	post_type: 'post',
	status: 'draft',
	title: 'A draft',
	content: '',
	excerpt: '',
	...overrides,
} );

const proposal = ( items: ProposalItem[], status = 'pending' ): Proposal => ( {
	proposal_id: 'p1',
	conversation_id: 'c1',
	status,
	expires: 0,
	max_items: 20,
	items,
} );

const outcome = (
	overrides: Partial< ProposalItemOutcome > = {}
): ProposalItemOutcome => ( {
	key: 'a',
	title: 'A draft',
	post_type: 'post',
	status: 'draft',
	outcome: 'created',
	post_id: 12,
	edit_link: 'https://example.test/wp-admin/post.php?post=12&action=edit',
	error_code: '',
	error_message: '',
	...overrides,
} );

test.describe( 'AI Workspace proposal card selection', () => {
	test( 'starts a single draft or pending item checked', () => {
		expect( initialSelection( proposal( [ item() ] ) ) ).toEqual( [ 'a' ] );
		expect(
			initialSelection( proposal( [ item( { status: 'pending' } ) ] ) )
		).toEqual( [ 'a' ] );
	} );

	test( 'starts a batch unchecked', () => {
		expect(
			initialSelection( proposal( [ item(), item( { key: 'b' } ) ] ) )
		).toEqual( [] );
	} );

	test( 'never preselects an item that would publish or go private', () => {
		expect(
			initialSelection( proposal( [ item( { status: 'publish' } ) ] ) )
		).toEqual( [] );
		expect(
			initialSelection( proposal( [ item( { status: 'private' } ) ] ) )
		).toEqual( [] );
	} );

	test( 'never preselects a proposal that was already executed', () => {
		expect(
			initialSelection( proposal( [ item() ], 'executed' ) )
		).toEqual( [] );
	} );
} );

test.describe( 'AI Workspace proposal card labels', () => {
	test( 'names drafts as drafts', () => {
		expect( createLabel( [ item() ], 1 ) ).toBe( 'Create 1 draft' );
		expect( createLabel( [ item(), item() ], 2 ) ).toBe(
			'Create 2 drafts'
		);
	} );

	test( 'names publishing whenever it is part of it', () => {
		expect(
			createLabel( [ item(), item( { status: 'publish' } ) ], 2 )
		).toBe( 'Create and publish 2 items' );
	} );

	test( 'falls back to items for any other mix', () => {
		expect(
			createLabel( [ item(), item( { status: 'pending' } ) ], 2 )
		).toBe( 'Create 2 items' );
	} );

	test( 'names what was created, or that nothing was', () => {
		expect( createdLabel( [] ) ).toBe( 'Nothing was created' );
		expect( createdLabel( [ outcome() ] ) ).toBe( 'Created 1 draft' );
		expect(
			createdLabel( [ outcome(), outcome( { status: 'pending' } ) ] )
		).toBe( 'Created 2 items' );
	} );
} );

test.describe( 'AI Workspace proposal card links', () => {
	test( 'opens any outcome that points at a post', () => {
		expect( canOpen( outcome() ) ).toBe( true );
		expect( canOpen( outcome( { outcome: 'duplicate' } ) ) ).toBe( true );
		expect(
			canOpen(
				outcome( { outcome: 'failed', post_id: 0, edit_link: '' } )
			)
		).toBe( false );
	} );

	test( 'lists posts of one status in Posts, newest first', () => {
		const link = listLink( [ outcome(), outcome( { key: 'b' } ) ] );
		const url = new URL( link?.url ?? '' );

		expect( link?.label ).toBe( 'Open all in Posts' );
		expect( url.pathname ).toBe( '/wp-admin/edit.php' );
		expect( url.searchParams.get( 'post_type' ) ).toBe( 'post' );
		expect( url.searchParams.get( 'post_status' ) ).toBe( 'draft' );
		expect( url.searchParams.get( 'orderby' ) ).toBe( 'date' );
		expect( url.searchParams.get( 'order' ) ).toBe( 'desc' );
	} );

	test( 'leaves the status out when the items do not share one', () => {
		const link = listLink( [
			outcome(),
			outcome( { status: 'pending' } ),
		] );

		expect(
			new URL( link?.url ?? '' ).searchParams.has( 'post_status' )
		).toBe( false );
	} );

	test( 'names the list for pages and other post types', () => {
		expect( listLink( [ outcome( { post_type: 'page' } ) ] )?.label ).toBe(
			'Open all in Pages'
		);
		expect(
			listLink( [ outcome( { post_type: 'product' } ) ] )?.label
		).toBe( 'Open all in the list' );
	} );

	test( 'offers no list for items of different post types', () => {
		expect(
			listLink( [ outcome(), outcome( { post_type: 'page' } ) ] )
		).toBeNull();
	} );

	test( 'offers no list when the edit link is not a URL', () => {
		expect(
			listLink( [ outcome( { edit_link: 'not a url' } ) ] )
		).toBeNull();
	} );
} );
