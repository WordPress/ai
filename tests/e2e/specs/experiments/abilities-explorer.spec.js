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

test.describe( 'Abilities Explorer Experiment', () => {
	test( 'Can enable the Abilities Explorer Experiment', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Abilities Explorer Experiment.
		await enableExperiment( admin, page, 'abilities-explorer' );
	} );

	test( 'Can access the Abilities Explorer page when enabled', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Abilities Explorer Experiment.
		await enableExperiment( admin, page, 'abilities-explorer' );

		// Ensure the Abilities Explorer page is visible in the admin sidebar.
		await expect(
			page.locator( '#adminmenu .toplevel_page_ai-abilities-explorer a' )
		).toBeVisible();

		// Visit the Abilities Explorer page.
		await admin.visitAdminPage( 'admin.php?page=ai-abilities-explorer' );

		// Ensure the abilities stats section is visible.
		await expect(
			page.locator( '.ability-explorer-wrap .ability-explorer-stats' )
		).toBeVisible();

		// Ensure the abilities table is visible.
		await expect(
			page.locator( '.ability-explorer-wrap .wp-list-table' )
		).toBeVisible();
	} );

	test( 'Can access the Abilities Explorer detail page when enabled', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Abilities Explorer Experiment.
		await enableExperiment( admin, page, 'abilities-explorer' );

		// Visit the Abilities Explorer page.
		await admin.visitAdminPage( 'admin.php?page=ai-abilities-explorer' );

		// Find the Get Environment Info ability and click the View button.
		const abilityRow = page
			.locator( '.wp-list-table tr' )
			.filter( { hasText: 'Get Environment Info' } );

		const viewButton = abilityRow
			.locator( '.actions a' )
			.filter( { hasText: 'View' } );
		await viewButton.click();

		// Ensure the ability detail section is visible.
		await expect(
			page.locator( '.ability-explorer-wrap .ability-explorer-detail' )
		).toBeVisible();

		// Click the Copy button.
		const copyButton = page.locator( '.ability-copy-btn' ).first();
		await copyButton.click();
	} );

	test( 'Can access the Abilities Explorer test runner page when enabled', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Abilities Explorer Experiment.
		await enableExperiment( admin, page, 'abilities-explorer' );

		// Visit the Abilities Explorer page.
		await admin.visitAdminPage( 'admin.php?page=ai-abilities-explorer' );

		// Find the Get Environment Info ability and click the Test button.
		const abilityRow = page
			.locator( '.wp-list-table tr' )
			.filter( { hasText: 'Get Environment Info' } );

		const testButton = abilityRow
			.locator( '.actions a' )
			.filter( { hasText: 'Test' } );
		await testButton.click();

		// Ensure the ability test runner section is visible.
		await expect(
			page.locator(
				'.ability-explorer-wrap .ability-explorer-test-runner'
			)
		).toBeVisible();

		// Click the Validate Input button.
		const validateButton = page.locator( '#ability-test-validate' );
		await validateButton.click();

		// Ensure the ability test validation is visible.
		await expect(
			page.locator( '#ability-test-validation' )
		).toBeVisible();

		// Click the Invoke Ability button.
		const invokeButton = page.locator( '#ability-test-invoke' );
		await invokeButton.click();

		// Ensure the ability test result is visible.
		await expect( page.locator( '#ability-test-result' ) ).toBeVisible();

		// Click the Clear button.
		const clearButton = page.locator( '#ability-test-clear' );
		await clearButton.click();

		// Ensure the ability test result is not visible.
		await expect(
			page.locator( '#ability-test-result' )
		).not.toBeVisible();
	} );

	test( 'Ensure the Abilities Explorer Experiment UI is not visible when Experiments are globally disabled', async ( {
		admin,
		page,
	} ) => {
		// Enable the Abilities Explorer Experiment.
		await enableExperiment( admin, page, 'abilities-explorer' );

		// Globally turn off Experiments.
		await disableExperiments( admin, page );

		// Ensure the Abilities Explorer page is not visible in the admin sidebar.
		await expect(
			page.locator( '#adminmenu .toplevel_page_ai-abilities-explorer a' )
		).not.toBeVisible();
	} );

	test( 'Ensure the Abilities Explorer Experiment UI is not visible when the experiment is disabled', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Disable the Abilities Explorer Experiment.
		await disableExperiment( admin, page, 'abilities-explorer' );

		// Ensure the Abilities Explorer page is not visible in the admin sidebar.
		await expect(
			page.locator( '#adminmenu .toplevel_page_ai-abilities-explorer a' )
		).not.toBeVisible();
	} );
} );
