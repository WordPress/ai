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
	applyLabel,
	canOpen,
	changeLabel,
	createLabel,
	createdLabel,
	initialSelection,
	isUpdateExecution,
	listLink,
	liveNotice,
	openChangeLabel,
	openLabel,
	reviewLabel,
	templateNotice,
	updatedLabel,
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
	slug: '',
	theme: '',
	replaces_theme_template: false,
	post_id: 0,
	field: '',
	current: '',
	value: '',
	live: false,
	...overrides,
} );

const proposal = (
	items: ProposalItem[],
	status = 'pending',
	kind = 'draft'
): Proposal => ( {
	kind,
	proposal_id: 'p1',
	conversation_id: 'c1',
	status,
	expires: 0,
	max_items: 20,
	items,
	results: null,
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
	field: '',
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

	test( 'selects the one template of a pending template proposal', () => {
		const template = item( {
			post_type: 'wp_template',
			status: 'publish',
			slug: '404',
		} );

		expect(
			initialSelection( proposal( [ template ], 'pending', 'template' ) )
		).toEqual( [ 'a' ] );
		expect(
			initialSelection( proposal( [ template ], 'executed', 'template' ) )
		).toEqual( [] );
	} );
} );

test.describe( 'AI Workspace template card', () => {
	test( 'says a replaced theme template is replaced, and that it goes live', () => {
		const notice = templateNotice(
			item( { slug: '404', replaces_theme_template: true } )
		);

		expect( notice ).toContain( 'Replaces your theme’s 404 template' );
		expect( notice ).toContain( 'live for visitors' );
	} );

	test( 'says a new template goes live', () => {
		expect( templateNotice( item( { slug: 'page-about' } ) ) ).toBe(
			'The template is live for visitors as soon as you create it.'
		);
	} );

	test( 'names the button for templates, drafts and anything else', () => {
		expect(
			openLabel(
				outcome( { post_type: 'wp_template', status: 'publish' } )
			)
		).toBe( 'Open template' );
		expect( openLabel( outcome() ) ).toBe( 'Open draft' );
		expect( openLabel( outcome( { status: 'publish' } ) ) ).toBe(
			'Open in the editor'
		);
	} );

	test( 'names a created template as a template', () => {
		expect(
			createdLabel( [
				outcome( { post_type: 'wp_template', status: 'publish' } ),
			] )
		).toBe( 'Created 1 template' );
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

const change = ( overrides: Partial< ProposalItem > = {} ): ProposalItem =>
	item( {
		post_type: 'post',
		status: 'publish',
		title: 'Hello world!',
		post_id: 1,
		field: 'title',
		current: 'Hello world!',
		value: 'Welcome',
		live: true,
		...overrides,
	} );

test.describe( 'AI Workspace update card', () => {
	test( 'starts every change of a pending update checked', () => {
		const changes = [ change(), change( { key: 'b', field: 'excerpt' } ) ];

		expect(
			initialSelection( proposal( changes, 'pending', 'post-field' ) )
		).toEqual( [ 'a', 'b' ] );
		expect(
			initialSelection( proposal( changes, 'executed', 'post-field' ) )
		).toEqual( [] );
	} );

	test( 'names a post field by the post, and a setting by its label', () => {
		expect( changeLabel( change() ) ).toBe( 'Title of “Hello world!”' );
		expect( changeLabel( change( { field: 'content' } ) ) ).toBe(
			'Content of “Hello world!”'
		);
		expect(
			changeLabel(
				change( { field: 'blogdescription', title: 'Tagline' } )
			)
		).toBe( 'Tagline' );
	} );

	test( 'says a change goes live only when it does', () => {
		expect( liveNotice( change() ) ).toContain( 'live for visitors' );
		expect( liveNotice( change( { live: false } ) ) ).toBe( '' );
		expect(
			liveNotice( change( { field: 'blogname', title: 'Site Title' } ) )
		).toContain( 'live for visitors' );
	} );

	test( 'counts the changes it holds and the ones it applies', () => {
		expect( reviewLabel( 1 ) ).toBe( 'Review 1 change' );
		expect( reviewLabel( 3 ) ).toBe( 'Review 3 changes' );
		expect( applyLabel( 1 ) ).toBe( 'Apply 1 change' );
		expect( applyLabel( 2 ) ).toBe( 'Apply 2 changes' );
	} );

	test( 'names what was changed, or that nothing was', () => {
		expect(
			updatedLabel( [
				outcome( { outcome: 'updated', field: 'title' } ),
				outcome( {
					key: 'b',
					outcome: 'deselected',
					field: 'excerpt',
				} ),
			] )
		).toBe( 'Updated 1 change' );
		expect(
			updatedLabel( [ outcome( { outcome: 'stale', field: 'title' } ) ] )
		).toBe( 'Nothing was changed' );
	} );

	test( 'tells an update outcome from a creation', () => {
		const base = {
			proposal_id: 'p1',
			created: 0,
			updated: 0,
			unchanged: 0,
			stale: 0,
			locked: 0,
			failed: 0,
			denied: 0,
			duplicate: 0,
			deselected: 0,
			follow_up: false,
			discarded: false,
		};

		expect(
			isUpdateExecution( {
				...base,
				items: [ outcome( { outcome: 'stale', field: 'title' } ) ],
			} )
		).toBe( true );
		expect(
			isUpdateExecution( { ...base, created: 1, items: [ outcome() ] } )
		).toBe( false );
	} );

	test( 'opens a post in the editor and a setting in Settings', () => {
		expect( openChangeLabel( outcome( { field: 'title' } ) ) ).toBe(
			'Open in the editor'
		);
		expect(
			openChangeLabel(
				outcome( { post_type: 'option', field: 'blogname' } )
			)
		).toBe( 'Open settings' );
	} );
} );
