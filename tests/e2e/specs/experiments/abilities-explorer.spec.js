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
		await enableExperiment( admin, page, 'Abilities Explorer' );
	} );

	test( 'Can access the Abilities Explorer page when enabled', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Abilities Explorer Experiment.
		await enableExperiment( admin, page, 'Abilities Explorer' );

		// Ensure the Abilities Explorer page is visible in the admin sidebar.
		await admin.visitAdminPage( 'tools.php' );
		await expect(
			page.locator( '#adminmenu .wp-menu-open .wp-submenu a', {
				hasText: 'Abilities Explorer',
			} )
		).toBeVisible();

		// Visit the Abilities Explorer page.
		await admin.visitAdminPage( 'tools.php?page=ai-abilities-explorer' );

		// Ensure the abilities stats section is visible.
		await expect(
			page.locator( '.ability-explorer-wrap .ability-explorer-stats' )
		).toBeVisible();

		// Ensure the abilities table is visible.
		await expect(
			page.locator( '.ability-explorer-wrap .wp-list-table' )
		).toBeVisible();
	} );

	test( 'Category filter dropdown renders on the Abilities Explorer list page', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Abilities Explorer Experiment.
		await enableExperiment( admin, page, 'abilities-explorer' );

		// Visit the Abilities Explorer page.
		await admin.visitAdminPage( 'tools.php?page=ai-abilities-explorer' );

		// Ensure the category filter dropdown is visible.
		await expect( page.locator( '#filter-by-category' ) ).toBeVisible();

		// Ensure the dropdown contains the "All Categories" option.
		await expect(
			page.locator( '#filter-by-category option[value="all"]' )
		).toHaveText( 'All Categories' );
	} );

	test( 'Category filter dropdown filters abilities by category', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Abilities Explorer Experiment.
		await enableExperiment( admin, page, 'abilities-explorer' );

		// Visit the Abilities Explorer page.
		await admin.visitAdminPage( 'tools.php?page=ai-abilities-explorer' );

		// Get the initial row count.
		const allRows = page.locator( '.wp-list-table tbody tr' );
		const initialCount = await allRows.count();

		// Get the first non-"all" option from the category dropdown (if one exists).
		const firstOption = page.locator(
			'#filter-by-category option:not([value="all"])'
		);
		const optionCount = await firstOption.count();

		if ( optionCount > 0 ) {
			const categoryValue = await firstOption
				.first()
				.getAttribute( 'value' );

			// Select that category from the dropdown.
			await page.selectOption( '#filter-by-category', categoryValue );

			// Submit the filter form.
			await page.click( '#filter_action' );
			await page.waitForLoadState( 'load' );

			// The filtered row count should be less than or equal to the initial count.
			const filteredCount = await page
				.locator( '.wp-list-table tbody tr' )
				.count();
			expect( filteredCount ).toBeLessThanOrEqual( initialCount );

			// The category dropdown should show the selected value.
			await expect( page.locator( '#filter-by-category' ) ).toHaveValue(
				categoryValue
			);
		}
	} );

	test( 'Can access the Abilities Explorer detail page when enabled', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Abilities Explorer Experiment.
		await enableExperiment( admin, page, 'Abilities Explorer' );

		// Visit the Abilities Explorer page.
		await admin.visitAdminPage( 'tools.php?page=ai-abilities-explorer' );

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
		await enableExperiment( admin, page, 'Abilities Explorer' );

		// Visit the Abilities Explorer page.
		await admin.visitAdminPage( 'tools.php?page=ai-abilities-explorer' );

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
		await enableExperiment( admin, page, 'Abilities Explorer' );

		// Globally turn off Experiments.
		await disableExperiments( admin, page );

		// Ensure the Abilities Explorer page is not visible in the admin Tools sidebar.
		await admin.visitAdminPage( 'tools.php' );
		await expect(
			page.locator( '#adminmenu .wp-menu-open .wp-submenu a', {
				hasText: 'Abilities Explorer',
			} )
		).not.toBeVisible();
	} );

	test( 'Ensure the Abilities Explorer Experiment UI is not visible when the experiment is disabled', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Disable the Abilities Explorer Experiment.
		await disableExperiment( admin, page, 'Abilities Explorer' );

		// Ensure the Abilities Explorer page is not visible in the admin Tools sidebar.
		await admin.visitAdminPage( 'tools.php' );
		await expect(
			page.locator( '#adminmenu .wp-menu-open .wp-submenu a', {
				hasText: 'Abilities Explorer',
			} )
		).not.toBeVisible();
	} );
} );
