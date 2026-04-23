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

		// Refresh the page.
		await page.reload();

		// Ensure there's a page under Settings.
		await expect(
			page.locator( '#adminmenu .wp-menu-open .wp-submenu a', {
				hasText: 'AI Connector Approvals',
			} )
		).toBeVisible();

		// Visit the Connector Approval page.
		await admin.visitAdminPage(
			'options-general.php?page=ai-connector-approval'
		);

		// Ensure the Connector Approval page is visible.
		await expect(
			page.locator( '#ai-connector-approval-root' )
		).toBeVisible();

		// Ensure the Approval matrix table is visible.
		await expect(
			page.locator( '.ai-connector-approval__matrix table' )
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
			await aiOpenAiToggle.uncheck();
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
			await admin.visitAdminPage(
				'options-general.php?page=ai-wp-admin'
			);
			await admin.visitAdminPage(
				'options-general.php?page=ai-connector-approval'
			);
		}

		// Ensure the admin notice is visible and has the correct text.
		await expect( page.locator( '.notice-warning' ) ).toBeVisible();
		await expect( page.locator( '.notice-warning p' ) ).toHaveText(
			/1 plugin or theme is requesting access to an AI connector./
		);

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
			aiMatrixRow.locator(
				`td:nth-child(${
					openAiColumnIndex + 1
				}) input.components-form-toggle__input`
			)
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

		// Refresh the page.
		await page.reload();

		// Ensure there's not a page under Settings.
		await expect(
			page.locator( '#adminmenu .wp-menu-open .wp-submenu a', {
				hasText: 'AI Connector Approvals',
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

		// Refresh the page.
		await page.reload();

		// Ensure there's not a page under Settings.
		await expect(
			page.locator( '#adminmenu .wp-menu-open .wp-submenu a', {
				hasText: 'AI Connector Approvals',
			} )
		).not.toBeVisible();
	} );
} );
