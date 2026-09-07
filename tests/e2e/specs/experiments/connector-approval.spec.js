/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	disableExperiment,
	disableExperiments,
	enableExperiment,
	enableExperiments,
} = require( '../../utils/helpers' );

test.describe( 'Connector Approval Experiment', () => {
	test( 'Can enable the connector approval experiment', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Connector Approval Experiment.
		await enableExperiment( admin, page, 'Connector Approval' );
	} );

	test( 'Can use the Connector Approval Experiment', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Connector Approval Experiment.
		await enableExperiment( admin, page, 'Connector Approval' );

		await admin.visitAdminPage( 'tools.php' );

		// Ensure there's a page under Tools.
		await expect(
			page.locator( '#adminmenu' ).getByRole( 'link', {
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

		// Ensure the Approval matrix table is visible.
		await expect(
			page
				.locator( '.ai-connector-approval__matrix' )
				.getByRole( 'table' )
		).toBeVisible();

		// Remove any previous approvals.
		const aiMatrixRow = page
			.locator( '.ai-connector-approval__matrix tbody tr' )
			.filter( {
				has: page.locator( 'code', { hasText: 'ai/ai.php' } ),
			} );

		const openAiColumnIndex = await page
			.locator( '.ai-connector-approval__matrix thead th', {
				hasText: 'OpenAI',
			} )
			.first()
			.evaluate( ( cell ) =>
				Array.from( cell.parentElement.children ).indexOf( cell )
			);

		const aiOpenAiToggle = aiMatrixRow.locator(
			`td:nth-child(${
				openAiColumnIndex + 1
			}) input.components-form-toggle__input`
		);

		if ( await aiOpenAiToggle.isChecked() ) {
			await aiOpenAiToggle.click( { force: true } );
			await expect( aiOpenAiToggle ).not.toBeChecked();
		}

		// Trigger a fresh request if no pending row exists yet.
		const pendingRow = page
			.locator( 'table.widefat.striped tbody tr' )
			.filter( {
				has: page.locator( 'code', { hasText: 'ai/ai.php' } ),
			} )
			.filter( { hasText: 'OpenAI' } );

		if ( ( await pendingRow.count() ) === 0 ) {
			await admin.visitAdminPage( 'index.php' );
			await admin.visitAdminPage(
				'tools.php?page=ai-connector-approval'
			);
		}

		await admin.visitAdminPage( 'tools.php' );

		// Ensure the admin notice is visible and has the correct text.
		await expect( page.locator( '.notice-warning' ) ).toBeVisible();
		await expect( page.locator( '.notice-warning p' ) ).toHaveText(
			/1 plugin or theme is requesting access to an AI connector./
		);

		await admin.visitAdminPage( 'tools.php?page=ai-connector-approval' );

		// Ensure we can approve the pending request.
		await expect( pendingRow ).toHaveCount( 1 );
		await pendingRow.getByRole( 'button', { name: 'Approve' } ).click();

		await expect(
			page
				.locator( 'table.widefat.striped tbody tr' )
				.filter( {
					has: page.locator( 'code', { hasText: 'ai/ai.php' } ),
				} )
				.filter( { hasText: 'OpenAI' } )
		).toHaveCount( 0 );

		await expect(
			page.getByLabel( 'Allow AI to use OpenAI', { exact: true } )
		).toBeChecked();
	} );

	test( 'Ensure the Connector Approval Experiment UI is not visible when Experiments are globally disabled', async ( {
		admin,
		page,
	} ) => {
		// Enable the Connector Approval Experiment.
		await enableExperiment( admin, page, 'Connector Approval' );

		// Globally turn off Experiments.
		await disableExperiments( admin, page );

		await admin.visitAdminPage( 'tools.php' );

		// Ensure there's not a page under Tools.
		await expect(
			page.locator( '#adminmenu' ).getByRole( 'link', {
				name: 'Connector Approvals',
				exact: true,
			} )
		).not.toBeVisible();
	} );

	test( 'Ensure the Connector Approval Experiment UI is not visible when the experiment is disabled', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Disable the Connector Approval Experiment.
		await disableExperiment( admin, page, 'Connector Approval' );

		await admin.visitAdminPage( 'tools.php' );

		// Ensure there's not a page under Tools.
		await expect(
			page.locator( '#adminmenu' ).getByRole( 'link', {
				name: 'Connector Approvals',
				exact: true,
			} )
		).not.toBeVisible();
	} );

	test( 'Context-aware error message appears when connector is unapproved', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable both experiments.
		await enableExperiment( admin, page, 'Connector Approval' );
		await enableExperiment( admin, page, 'Title Generation' );

		// Visit the Connector Approval page.
		await admin.visitAdminPage( 'tools.php?page=ai-connector-approval' );

		// Ensure the Connector Approval page has finished mounting and hydrating
		// its state from the REST API before interacting with the matrix below.
		await expect(
			page.locator( '#ai-connector-approval-root' )
		).toBeVisible();
		await expect(
			page.locator( '.ai-connector-approval__matrix table' )
		).toBeVisible();

		// Remove approval for OpenAI for the AI plugin.
		const aiMatrixRow = page
			.locator( '.ai-connector-approval__matrix tbody tr' )
			.filter( {
				has: page.locator( 'code', { hasText: 'ai/ai.php' } ),
			} );

		const openAiColumnIndex = await page
			.locator( '.ai-connector-approval__matrix thead th', {
				hasText: 'OpenAI',
			} )
			.first()
			.evaluate( ( cell ) =>
				Array.from( cell.parentElement.children ).indexOf( cell )
			);

		const aiOpenAiToggle = aiMatrixRow.locator(
			`td:nth-child(${
				openAiColumnIndex + 1
			}) input.components-form-toggle__input`
		);

		if ( await aiOpenAiToggle.isChecked() ) {
			await aiOpenAiToggle.uncheck();
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

		// Ensure the context-aware error message snackbar appears.
		const snackbar = page.locator( '.components-snackbar' );
		await expect( snackbar ).toBeVisible();
		await expect( snackbar ).toHaveText(
			/Title Generation failed. The AI connector is currently pending authorization. Please approve the request under Tools > Connector Approvals./i
		);
	} );
} );
