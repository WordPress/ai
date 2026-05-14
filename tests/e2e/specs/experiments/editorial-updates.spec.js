/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	disableExperiment,
	disableExperiments,
	enableExperiments,
	enableExperiment,
} from '../../utils/helpers';

const EXPERIMENT_LABEL = 'Editorial Updates';

test.describe( 'Editorial Updates Experiment', () => {
	test.beforeEach( async ( { admin, page } ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Refine Notes Experiment.
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
	} );

	test( 'Button is hidden when there are no notes', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( { title: 'Refine Notes Test' } );

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		// The button should NOT be visible initially since there are no notes.
		await expect(
			page.getByRole( 'button', { name: 'Apply Editorial Updates' } )
		).toBeHidden();
	} );

	test( 'Shows the button and runs refinement when pending notes exist', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( { title: 'Refine with Notes Test' } );

		// Add a block to attach a note to.
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: {
				content: 'This paragraph needs refinement.',
			},
		} );
		await editor.saveDraft();

		// Create a note via API and attach it to the block.
		const noteId = await page.evaluate( async () => {
			const postId = window.wp.data
				.select( 'core/editor' )
				.getCurrentPostId();
			const blocks = window.wp.data
				.select( 'core/block-editor' )
				.getBlocks();
			const blockClientId = blocks[ 0 ].clientId;

			// Ensure comments are open on the post (draft posts default to closed).
			await window.wp.apiFetch( {
				path: `/wp/v2/posts/${ postId }`,
				method: 'POST',
				data: { comment_status: 'open' },
			} );

			const note = await window.wp.apiFetch( {
				path: '/wp/v2/comments',
				method: 'POST',
				data: {
					post: postId,
					content: 'Make this better.',
					type: 'note',
					status: 'hold',
					meta: {
						ai_note: true,
					},
				},
			} );

			window.wp.data
				.dispatch( 'core/block-editor' )
				.updateBlockAttributes( blockClientId, {
					metadata: { noteId: note.id },
				} );

			return note.id;
		} );

		await editor.saveDraft();

		// Track whether refinement has completed so the checkPendingNotes
		// mock can switch from returning a note to returning empty.
		let refinementComplete = false;

		// Mock note for the checkPendingNotes response.
		const mockNote = {
			id: noteId,
			parent: 0,
			content: { rendered: '<p>Make this better.</p>' },
			meta: { ai_note: true },
		};

		// Intercept all /wp/v2/comments requests to guarantee stable results.
		// WordPress may return 500 for type=note queries in some environments.
		await page.route( /\/wp\/v2\/comments/, async ( route ) => {
			const url = route.request().url();
			const method = route.request().method();
			const hasTypeNote =
				url.includes( 'type=note' ) || url.includes( 'type%3Dnote' );
			const isResolveNote =
				method === 'PUT' && /\/wp\/v2\/comments\/\d+/.test( url );

			if ( hasTypeNote ) {
				// fetchPendingNotes (type=note queries from WP core or hook).
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify(
						refinementComplete ? [] : [ mockNote ]
					),
					headers: {
						'X-WP-Total': refinementComplete ? '0' : '1',
						'X-WP-TotalPages': refinementComplete ? '0' : '1',
					},
				} );
			} else if ( isResolveNote ) {
				// Resolving a note marks refinement complete so that subsequent
				// getEntityRecords calls return [] and the button permanently
				// disappears (not just transiently during the refetch null state).
				refinementComplete = true;
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( { id: noteId, status: 'approve' } ),
				} );
			} else {
				await route.continue();
			}
		} );

		await page.reload();

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		// WordPress doesn't persist custom `metadata` keys (like `noteId`)
		// through block serialization. After reload, re-inject the noteId
		// so `refineableBlocks` can find the matching block.
		await page.evaluate( ( id ) => {
			const blocks = window.wp.data
				.select( 'core/block-editor' )
				.getBlocks();
			const blockClientId = blocks[ 0 ].clientId;
			window.wp.data
				.dispatch( 'core/block-editor' )
				.updateBlockAttributes( blockClientId, {
					metadata: { noteId: id },
				} );
		}, noteId );

		// The button should be visible now.
		const refineButton = page.getByRole( 'button', {
			name: 'Apply Editorial Updates',
		} );
		await expect( refineButton ).toBeVisible( { timeout: 10000 } );

		// Set up the ability-request waiter BEFORE clicking so there is no race
		// between the click and the Playwright network listener.  waitForRequest
		// intercepts at the network layer (fetch, XHR, etc.) regardless of how
		// wp.apiFetch internally dispatches the call.
		const abilityRequestPromise = page.waitForRequest(
			( req ) => {
				const url = decodeURIComponent( req.url() );
				return (
					url.includes( 'wp-abilities' ) &&
					url.includes( 'editorial-updates' )
				);
			},
			{ timeout: 15000 }
		);

		// Click the button and check for loading text.
		await refineButton.click();
		// Button accessible name changes while loading, so use a separate locator.
		await expect(
			page.getByRole( 'button', { name: /Refining block/ } )
		).toBeVisible( { timeout: 5000 } );

		// Verify the ability endpoint was called.
		await abilityRequestPromise;

		// Verify the block content was updated by the refinement.
		// waitForFunction polls until the update lands (the response is async).
		await page.waitForFunction(
			() => {
				const blocks = window.wp.data
					.select( 'core/block-editor' )
					.getBlocks();
				return ( blocks[ 0 ]?.attributes?.content ?? '' ).includes(
					'refined block content'
				);
			},
			{ timeout: 20000 }
		);

		const blockContent = await page.evaluate( () => {
			const blocks = window.wp.data
				.select( 'core/block-editor' )
				.getBlocks();
			return blocks[ 0 ]?.attributes?.content ?? '';
		} );
		expect( blockContent ).toContain( 'refined block content' );
	} );

	test( 'Button is hidden when experiments are globally disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn off Experiments.
		await disableExperiments( admin, page );

		// Create a new post and verify button is absent.
		await admin.createNewPost( { title: 'Disabled Experiment Test' } );

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		await expect(
			page.getByRole( 'button', { name: 'Apply Editorial Updates' } )
		).toHaveCount( 0 );
	} );

	test( 'Button is hidden when experiment is disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Disable the Refine Notes Experiment.
		await disableExperiment( admin, page, EXPERIMENT_LABEL );

		// Create a new post and verify button is absent.
		await admin.createNewPost( { title: 'Disabled Experiment Test' } );

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		await expect(
			page.getByRole( 'button', { name: 'Apply Editorial Updates' } )
		).toHaveCount( 0 );
	} );

	test( 'Shows an error snackbar when the refine ability fails', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( { title: 'Refine Error Test' } );

		// Add a block with a note.
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: {
				content: 'Content that will fail refinement.',
			},
		} );
		await editor.saveDraft();

		const noteId = await page.evaluate( async () => {
			const postId = window.wp.data
				.select( 'core/editor' )
				.getCurrentPostId();
			const blocks = window.wp.data
				.select( 'core/block-editor' )
				.getBlocks();
			const blockClientId = blocks[ 0 ].clientId;

			await window.wp.apiFetch( {
				path: `/wp/v2/posts/${ postId }`,
				method: 'POST',
				data: { comment_status: 'open' },
			} );

			const note = await window.wp.apiFetch( {
				path: '/wp/v2/comments',
				method: 'POST',
				data: {
					post: postId,
					content: 'Fix this.',
					type: 'note',
					status: 'hold',
					meta: { ai_note: true },
				},
			} );

			window.wp.data
				.dispatch( 'core/block-editor' )
				.updateBlockAttributes( blockClientId, {
					metadata: { noteId: note.id },
				} );

			return note.id;
		} );

		await editor.saveDraft();

		// Mock note for responses.
		const mockNote = {
			id: noteId,
			parent: 0,
			content: { rendered: '<p>Fix this.</p>' },
			meta: { ai_note: true },
		};

		// Intercept note queries — always return the note so the button stays visible.
		await page.route( /\/wp\/v2\/comments/, async ( route ) => {
			const url = route.request().url();
			const hasTypeNote =
				url.includes( 'type=note' ) || url.includes( 'type%3Dnote' );

			if ( hasTypeNote ) {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( [ mockNote ] ),
					headers: {
						'X-WP-Total': '1',
						'X-WP-TotalPages': '1',
					},
				} );
			} else if (
				url.includes( 'post=' ) &&
				url.includes( 'per_page=' )
			) {
				// checkPendingNotes: return the note so hasPendingNotes=true.
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( [ mockNote ] ),
				} );
			} else {
				await route.continue();
			}
		} );

		// Mock the AI ability call to return a 500 error. Use a URL predicate
		// instead of a regex so the match is reliable regardless of URL encoding.
		await page.route(
			( url ) =>
				url.href.includes( 'wp-abilities' ) &&
				url.href.includes( 'editorial-updates' ),
			async ( route ) => {
				await route.fulfill( {
					status: 500,
					contentType: 'application/json',
					body: JSON.stringify( {
						code: 'internal_error',
						message: 'AI service unavailable',
						data: { status: 500 },
					} ),
				} );
			}
		);

		await page.reload();

		await editor.openDocumentSettingsSidebar();

		// Re-inject noteId after reload.
		await page.evaluate( ( id ) => {
			const blocks = window.wp.data
				.select( 'core/block-editor' )
				.getBlocks();
			const blockClientId = blocks[ 0 ].clientId;
			window.wp.data
				.dispatch( 'core/block-editor' )
				.updateBlockAttributes( blockClientId, {
					metadata: { noteId: id },
				} );
		}, noteId );

		const refineButton = page.getByRole( 'button', {
			name: 'Apply Editorial Updates',
		} );
		await expect( refineButton ).toBeVisible( { timeout: 10000 } );

		// Override the ability at the JavaScript level so it always fails.
		// This works regardless of whether wp.abilities or the REST path is used.
		await page.evaluate( () => {
			// Override executeAbility to throw.
			window.wp = window.wp || {};
			window.wp.abilities = window.wp.abilities || {};
			window.wp.abilities.executeAbility = async function () {
				throw Object.assign( new Error( 'AI service unavailable' ), {
					code: 'ability_error_test',
				} );
			};

			// Also override fetch for the REST fallback path.
			const origFetch = window.fetch.bind( window );
			window.fetch = function ( resource, init ) {
				const url =
					typeof resource === 'string'
						? resource
						: resource?.url ?? '';
				const decoded = decodeURIComponent( url );
				if (
					decoded.includes( 'wp-abilities' ) &&
					decoded.includes( 'editorial-updates' )
				) {
					return Promise.resolve(
						new Response(
							JSON.stringify( {
								code: 'internal_error',
								message: 'AI service unavailable',
								data: { status: 500 },
							} ),
							{
								status: 500,
								headers: { 'Content-Type': 'application/json' },
							}
						)
					);
				}
				return origFetch( resource, init );
			};
		} );

		await refineButton.click();

		// Button should return to idle state after the error.
		await expect( refineButton ).toHaveText( 'Apply Editorial Updates', {
			timeout: 15000,
		} );

		// Verify createErrorNotice was called — check the `core/notices` store
		// rather than the DOM, since panel notices (non-snackbar) may not
		// render visibly in all editor environments.
		const errorNotice = await page.evaluate( () => {
			const notices = window.wp.data
				.select( 'core/notices' )
				.getNotices();
			return notices.find(
				( n ) => n.id === 'wpai_editorial_updates_error'
			);
		} );
		expect( errorNotice ).toBeDefined();
		expect( errorNotice.status ).toBe( 'error' );

		// Verify the block content was not changed.
		const blockContent = await page.evaluate( () => {
			const blocks = window.wp.data
				.select( 'core/block-editor' )
				.getBlocks();
			return blocks[ 0 ]?.attributes?.content ?? '';
		} );
		expect( blockContent ).toContain( 'Content that will fail refinement' );
	} );
} );
