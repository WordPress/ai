/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	disableExperiment,
	enableExperiment,
} = require( '../../utils/helpers' );

test.describe( 'Connector Approval Experiment', () => {
	// Later specs make AI requests without approving connectors, so leave the
	// experiment off once each test finishes.
	test.afterEach( async ( { admin, page } ) => {
		await disableExperiment( admin, page, 'Connector Approval' );
	} );

	test( 'Can enable the connector approval experiment', async ( {
		admin,
		page,
	} ) => {
		// Enable the Connector Approval Experiment.
		await enableExperiment( admin, page, 'Connector Approval' );
	} );

	test( 'Can use the Connector Approval Experiment', async ( {
		admin,
		page,
	} ) => {
		// Enable the Connector Approval Experiment.
		await enableExperiment( admin, page, 'Connector Approval' );

		await admin.visitAdminPage( 'tools.php' );

		// Ensure there's a page under Tools.
		await expect(
			page
				.getByRole( 'navigation', { name: 'Main menu', exact: true } )
				.getByRole( 'link', {
					name: 'Connector Approvals',
					exact: true,
				} )
		).toBeVisible();

		// Visit the Connector Approval page.
		await admin.visitAdminPage( 'tools.php?page=ai-connector-approval' );

		// Ensure the Connector Approval page is visible.
		await expect(
			page.getByRole( 'heading', {
				name: 'Connector Approvals',
				exact: true,
			} )
		).toBeVisible();

		// Wait for the approval matrix and its labelled toggle to finish loading.
		const aiOpenAiToggle = page.getByRole( 'checkbox', {
			name: 'Allow AI to use OpenAI',
			exact: true,
		} );
		const approvalMatrix = page
			.getByRole( 'table' )
			.filter( { has: aiOpenAiToggle } );
		await expect( approvalMatrix ).toBeVisible();

		// Remove any previous approvals.
		if ( await aiOpenAiToggle.isChecked() ) {
			await aiOpenAiToggle.click( { force: true } );
			await expect( aiOpenAiToggle ).not.toBeChecked();
		}

		// Trigger a fresh request if no pending row exists yet.
		const pendingRow = page
			.getByRole( 'row' )
			.filter( { has: page.getByText( 'ai/ai.php', { exact: true } ) } )
			.filter( {
				has: page.getByRole( 'cell', { name: 'OpenAI', exact: true } ),
			} )
			.filter( {
				has: page.getByRole( 'button', {
					name: 'Approve',
					exact: true,
				} ),
			} );

		if ( ( await pendingRow.count() ) === 0 ) {
			await admin.visitAdminPage( 'index.php' );
			await admin.visitAdminPage(
				'tools.php?page=ai-connector-approval'
			);
		}

		await admin.visitAdminPage( 'tools.php' );

		// Ensure the admin notice is visible and has the correct text.
		await expect(
			page
				.getByRole( 'main' )
				.getByText(
					'1 plugin or theme is requesting access to an AI connector.'
				)
		).toBeVisible();

		await admin.visitAdminPage( 'tools.php?page=ai-connector-approval' );

		// Ensure we can approve the pending request.
		await expect( pendingRow ).toHaveCount( 1 );
		await pendingRow.getByRole( 'button', { name: 'Approve' } ).click();

		await expect( pendingRow ).toHaveCount( 0 );

		await expect(
			page.getByLabel( 'Allow AI to use OpenAI', { exact: true } )
		).toBeChecked();
	} );

	test( 'Ensure the Connector Approval Experiment UI is not visible when the experiment is disabled', async ( {
		admin,
		page,
	} ) => {
		// Disable the Connector Approval Experiment.
		await disableExperiment( admin, page, 'Connector Approval' );

		await admin.visitAdminPage( 'tools.php' );

		// Ensure there's not a page under Tools.
		await expect(
			page
				.getByRole( 'navigation', { name: 'Main menu', exact: true } )
				.getByRole( 'link', {
					name: 'Connector Approvals',
					exact: true,
				} )
		).not.toBeVisible();
	} );

	test( 'Approval error message appears when connector is unapproved', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Enable both experiments.
		await enableExperiment( admin, page, 'Connector Approval' );
		await enableExperiment( admin, page, 'Title Generation' );

		// Visit the Connector Approval page.
		await admin.visitAdminPage( 'tools.php?page=ai-connector-approval' );

		// Wait for the approval matrix to finish loading before changing approval.
		const aiOpenAiToggle = page.getByRole( 'checkbox', {
			name: 'Allow AI to use OpenAI',
			exact: true,
		} );
		await expect(
			page.getByRole( 'table' ).filter( { has: aiOpenAiToggle } )
		).toBeVisible();

		if ( await aiOpenAiToggle.isChecked() ) {
			await aiOpenAiToggle.click( { force: true } );
			await expect( aiOpenAiToggle ).not.toBeChecked();
		}

		// Create a new post.
		const LONG_CONTENT =
			'Artificial intelligence is rapidly changing how content is created, edited, and published across the web today. Writers increasingly rely on automated tools to draft outlines, summarize research, and suggest improvements to their work. These systems analyze large amounts of text and surface patterns that would take a human many hours to find on their own. As the technology matures, editors are learning to combine their own judgment with machine generated suggestions to produce stronger results. This paragraph exists only to provide enough characters for the title generation experiment to run, because the feature now requires a reasonable amount of content before it will offer to generate a brand new title for the post.';
		await admin.createNewPost( {
			postType: 'post',
			title: '',
			content: LONG_CONTENT,
		} );

		// Save the post.
		await editor.saveDraft();

		// Click into the title field.
		await editor.canvas
			.getByRole( 'textbox', { name: 'Add Title' } )
			.click();

		const generateTitleToolbar = editor.canvas.getByRole( 'toolbar', {
			name: 'Generate title toolbar',
		} );

		// Ensure the title toolbar is visible.
		await expect(
			generateTitleToolbar.filter( { hasText: 'Generate' } )
		).toBeVisible();

		// Click the Generate button.
		await generateTitleToolbar
			.getByRole( 'button', { name: 'Generate' } )
			.click();

		// Ensure the approval error notice appears.
		await expect(
			page
				.getByRole( 'main' )
				.getByText(
					'The OpenAI connector is pending approval. Please approve it under Tools > Connector Approvals.',
					{ exact: true }
				)
		).toBeVisible();
	} );
} );
