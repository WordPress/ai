/**
 * External dependencies
 */
import type { Locator, Page } from '@playwright/test';

/**
 * WordPress dependencies
 */
import { type Admin, expect } from '@wordpress/e2e-test-utils-playwright';

const CONNECTOR_LABELS: Record< string, string > = {
	'ai-provider-for-openai': 'OpenAI',
	'ai-provider-for-google': 'Google',
	'ai-provider-for-anthropic': 'Anthropic',
};

const getConnectorItem = ( page: Page, connectorId: string ) => {
	const label = CONNECTOR_LABELS[ connectorId ];

	if ( ! label ) {
		return null;
	}

	return page.locator( '[role="listitem"]', {
		has: page.getByRole( 'heading', { name: label, exact: true } ),
	} );
};

const clearConnectorFromItem = async ( connectorItem: Locator ) => {
	const editBtn = connectorItem.getByRole( 'button', { name: 'Edit' } );
	if ( ( await editBtn.count() ) === 0 ) {
		return;
	}

	await editBtn.click();

	const candidate = connectorItem.getByRole( 'button', { name: /Remove/i } );
	if ( ( await candidate.count() ) > 0 ) {
		await candidate.first().click();
	}
};

/**
 * Visits a specific admin page.
 *
 * @param admin       The admin fixture from the test context.
 * @param path        The path to the admin page.
 * @param queryParams The query parameters to add to the URL.
 */
export const visitAdminPage = async (
	admin: Admin,
	path: string,
	queryParams?: string
) => {
	await admin.visitAdminPage( path, queryParams );
};

/**
 * Visits the settings page.
 *
 * @param admin The admin fixture from the test context.
 */
export const visitSettingsPage = async ( admin: Admin ) => {
	await admin.visitAdminPage( 'options-general.php?page=ai-wp-admin' );
};

/**
 * Visits the Connectors page.
 *
 * @param admin The admin fixture from the test context.
 */
export const visitConnectorsPage = async ( admin: Admin ) => {
	await admin.visitAdminPage( 'options-connectors.php' );
};

/**
 * Clears out any existing Connectors.
 *
 * @param admin The admin fixture from the test context.
 * @param page  The page object.
 */
export const clearConnectors = async ( admin: Admin, page: Page ) => {
	await visitConnectorsPage( admin );

	// Wait for page to fully load before finding button
	await page.waitForTimeout( 1000 );

	const providers = [
		'ai-provider-for-openai',
		'ai-provider-for-google',
		'ai-provider-for-anthropic',
	];

	for ( const provider of providers ) {
		const connectorItem = getConnectorItem( page, provider );
		if ( connectorItem ) {
			await clearConnectorFromItem( connectorItem );
		}
	}

	// Wait for save.
	await page.waitForTimeout( 1000 );
};

/**
 * Clears out a specific existing Connector.
 *
 * @param admin       The admin fixture from the test context.
 * @param page        The page object.
 * @param connectorId The ID of the connector to clear.
 */
export const clearConnector = async (
	admin: Admin,
	page: Page,
	connectorId: string
) => {
	await visitConnectorsPage( admin );

	// Wait for page to fully load before finding button
	await page.waitForTimeout( 1000 );

	const connectorItem = getConnectorItem( page, connectorId );
	if ( connectorItem ) {
		await clearConnectorFromItem( connectorItem );
	}

	// Wait for save.
	await page.waitForTimeout( 1000 );
};

/**
 * Globally disables experiments.
 *
 * @param admin The admin fixture from the test context.
 * @param page  The page object.
 */
export const disableExperiments = async ( admin: Admin, page: Page ) => {
	await visitSettingsPage( admin );

	// Wait for page to fully load before finding the global toggle.
	const globalToggle = page.getByLabel( 'Enable AI' );
	await expect( globalToggle ).toBeVisible( { timeout: 10000 } );

	// Nothing to do if experiments are already disabled.
	if ( ! ( await globalToggle.isChecked() ) ) {
		return;
	}
	await globalToggle.uncheck();
	await expect(
		page.locator( '.components-snackbar__content', {
			hasText: 'AI disabled.',
		} )
	).toBeVisible();
};

/**
 * Globally enables experiments.
 *
 * @param admin The admin fixture from the test context.
 * @param page  The page object.
 */
export const enableExperiments = async ( admin: Admin, page: Page ) => {
	await visitSettingsPage( admin );

	// Wait for page to fully load before finding the global toggle.
	const globalToggle = page.getByLabel( 'Enable AI' );
	await expect( globalToggle ).toBeVisible( { timeout: 10000 } );

	// Nothing to do if experiments are already enabled.
	if ( await globalToggle.isChecked() ) {
		return;
	}
	await globalToggle.check();
	await expect(
		page.locator( '.components-snackbar__content', {
			hasText: 'AI enabled.',
		} )
	).toBeVisible();
};

/**
 * Enables a specific experiment.
 *
 * @param admin           The admin fixture from the test context.
 * @param page            The page object.
 * @param experimentLabel The display label of the experiment (e.g. 'Abilities Explorer').
 */
export const enableExperiment = async (
	admin: Admin,
	page: Page,
	experimentLabel: string
) => {
	await visitSettingsPage( admin );

	// Visual-card features use a showcase card with an Enable/Disable button
	// instead of a toggle input.
	const showcaseCard = page.locator( '.ai-showcase-card', {
		has: page.locator( '.ai-showcase-card__title', {
			hasText: experimentLabel,
		} ),
	} );

	// Wait for either the showcase card or the toggle to appear.
	const toggle = page.getByLabel( experimentLabel );
	await expect( showcaseCard.or( toggle ) ).toBeVisible( {
		timeout: 10000,
	} );

	if ( await showcaseCard.isVisible() ) {
		// Already enabled if the "Enabled" badge is visible.
		if (
			await showcaseCard
				.locator( '.ai-showcase-card__enabled-badge' )
				.isVisible()
		) {
			return;
		}

		await showcaseCard
			.locator( '.ai-showcase-card__actions button' )
			.click();
	} else {
		// Nothing to do if this experiment is already enabled.
		if ( await toggle.isChecked() ) {
			return;
		}

		await toggle.check();
	}

	// Ensure the save was successful.
	await expect(
		page.locator( '.components-snackbar__content', {
			hasText: `${ experimentLabel } enabled.`,
		} )
	).toBeVisible();
};

/**
 * Disables a specific experiment.
 *
 * @param admin           The admin fixture from the test context.
 * @param page            The page object.
 * @param experimentLabel The display label of the experiment (e.g. 'Abilities Explorer').
 */
export const disableExperiment = async (
	admin: Admin,
	page: Page,
	experimentLabel: string
) => {
	await visitSettingsPage( admin );

	// Visual-card features use a showcase card with an Enable/Disable button
	// instead of a toggle input.
	const showcaseCard = page.locator( '.ai-showcase-card', {
		has: page.locator( '.ai-showcase-card__title', {
			hasText: experimentLabel,
		} ),
	} );

	// Wait for either the showcase card or the toggle to appear.
	const toggle = page.getByLabel( experimentLabel );
	await expect( showcaseCard.or( toggle ) ).toBeVisible( {
		timeout: 10000,
	} );

	if ( await showcaseCard.isVisible() ) {
		// Already disabled if there's no "Enabled" badge.
		if (
			! ( await showcaseCard
				.locator( '.ai-showcase-card__enabled-badge' )
				.isVisible() )
		) {
			return;
		}

		await showcaseCard
			.locator( '.ai-showcase-card__actions button' )
			.click();
	} else {
		// Nothing to do if this experiment is already disabled.
		if ( ! ( await toggle.isChecked() ) ) {
			return;
		}

		await toggle.uncheck();
	}

	// Ensure the save was successful.
	await expect(
		page.locator( '.components-snackbar__content', {
			hasText: `${ experimentLabel } disabled.`,
		} )
	).toBeVisible();
};

/**
 * Gets the "Enable all" button for a specific experiment group.
 *
 * @param page      The page object.
 * @param groupName The name of the experiment group (e.g., 'Editor Experiments').
 * @return The "Enable all" button locator.
 */
export const getEnableAllButton = ( page: Page, groupName: string ) => {
	// Find the section by its heading.
	const section = page
		.locator(
			'.ai-settings-page .dataforms-layouts__wrapper .dataforms-layouts-card__field'
		)
		.filter( { has: page.getByText( groupName, { exact: true } ) } );

	return section.getByRole( 'button', { name: 'Enable all' } );
};

/**
 * Gets the "Disable all" button for a specific experiment group.
 *
 * @param page      The page object.
 * @param groupName The name of the experiment group (e.g., 'Editor Experiments').
 * @return The "Disable all" button locator.
 */
export const getDisableAllButton = ( page: Page, groupName: string ) => {
	// Find the section by its heading.
	const section = page
		.locator(
			'.ai-settings-page .dataforms-layouts__wrapper .dataforms-layouts-card__field'
		)
		.filter( { has: page.getByText( groupName, { exact: true } ) } );

	return section.getByRole( 'button', { name: 'Disable all' } );
};

/**
 * Enables all experiments in a specific group using the "Enable all" button.
 *
 * @param admin     The admin fixture from the test context.
 * @param page      The page object.
 * @param groupName The name of the experiment group (e.g., 'Editor Experiments').
 */
export const enableAllExperimentsInGroup = async (
	admin: Admin,
	page: Page,
	groupName: string
) => {
	await visitSettingsPage( admin );

	const enableAllButton = getEnableAllButton( page, groupName );
	await expect( enableAllButton ).toBeVisible( { timeout: 10000 } );

	// Bail if the button is disabled, which indicates all experiments are already enabled.
	if ( await enableAllButton.isDisabled() ) {
		return;
	}

	await enableAllButton.click();
	await expect( page.getByTestId( 'snackbar' ) ).toBeVisible();
};

/**
 * Disables all experiments in a specific group using the "Disable all" button.
 *
 * @param admin     The admin fixture from the test context.
 * @param page      The page object.
 * @param groupName The name of the experiment group (e.g., 'Editor Experiments').
 */
export const disableAllExperimentsInGroup = async (
	admin: Admin,
	page: Page,
	groupName: string
) => {
	await visitSettingsPage( admin );

	const disableAllButton = getDisableAllButton( page, groupName );
	await expect( disableAllButton ).toBeVisible( { timeout: 10000 } );

	// Bail if the button is disabled, which indicates all experiments are already disabled.
	if ( await disableAllButton.isDisabled() ) {
		return;
	}

	await disableAllButton.click();
	await expect( page.getByTestId( 'snackbar' ) ).toBeVisible();
};

/**
 * Gets all experiment toggles within a specific group section.
 *
 * @param page      The page object.
 * @param groupName The name of the experiment group (e.g., 'Editor Experiments').
 * @return Array of toggle locators.
 */
export const getExperimentTogglesInGroup = async (
	page: Page,
	groupName: string
) => {
	// Find the section by its heading.
	const section = page
		.locator(
			'.ai-settings-page .dataforms-layouts__wrapper .dataforms-layouts-card__field'
		)
		.filter( { has: page.getByText( groupName, { exact: true } ) } );

	// Get all checkboxes in that section (experiment toggles are checkboxes, buttons are for bulk actions).
	const allToggles = section.locator( '.components-form-toggle__input' );
	const count = await allToggles.count();
	const experimentToggles = [];

	for ( let i = 0; i < count; i++ ) {
		const toggle = allToggles.nth( i );
		experimentToggles.push( toggle );
	}

	return experimentToggles;
};
