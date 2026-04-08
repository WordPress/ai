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
 * @param admin The admin fixture from the test context.
 * @param path  The path to the admin page.
 */
export const visitAdminPage = async ( admin: Admin, path: string ) => {
	await admin.visitAdminPage( path );
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
	const globalToggle = page.getByRole( 'checkbox', {
		name: 'Enable AI',
	} );
	await expect( globalToggle ).toBeVisible( { timeout: 10000 } );

	// Nothing to do if experiments are already disabled.
	if ( ! ( await globalToggle.isChecked() ) ) {
		return;
	}
	await globalToggle.uncheck();
	await expect( page.getByTestId( 'snackbar' ) ).toBeVisible();
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
	const globalToggle = page.getByRole( 'checkbox', {
		name: 'Enable AI',
	} );
	await expect( globalToggle ).toBeVisible( { timeout: 10000 } );

	// Nothing to do if experiments are already enabled.
	if ( await globalToggle.isChecked() ) {
		return;
	}
	await globalToggle.check();
	await expect( page.getByTestId( 'snackbar' ) ).toBeVisible();
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
	const checkbox = page.getByRole( 'checkbox', {
		name: experimentLabel,
	} );
	await expect( checkbox ).toBeVisible( { timeout: 10000 } );

	// Nothing to do if this experiment is already enabled.
	if ( await checkbox.isChecked() ) {
		return;
	}

	await checkbox.check();

	// Ensure the save was successful.
	await expect( page.getByTestId( 'snackbar' ) ).toBeVisible();
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
	const checkbox = page.getByRole( 'checkbox', {
		name: experimentLabel,
	} );
	await expect( checkbox ).toBeVisible( { timeout: 10000 } );

	// Nothing to do if this experiment is already disabled.
	if ( ! ( await checkbox.isChecked() ) ) {
		return;
	}

	await checkbox.uncheck();

	// Ensure the save was successful.
	await expect( page.getByTestId( 'snackbar' ) ).toBeVisible();
};

/**
 * Gets the "Enable all" / "Disable all" toggle for a specific experiment group.
 *
 * @param page      The page object.
 * @param groupName The name of the experiment group (e.g., 'Editor Experiments').
 * @return The select-all toggle locator.
 */
export const getSelectAllToggle = ( page: Page, groupName: string ) => {
	return page.getByRole( 'checkbox', {
		name: new RegExp( `(Enable|Disable) all ${ groupName }`, 'i' ),
	} );
};

/**
 * Enables all experiments in a specific group using the select-all toggle.
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

	const selectAllToggle = getSelectAllToggle( page, groupName );
	await expect( selectAllToggle ).toBeVisible( { timeout: 10000 } );

	// Nothing to do if this is already enabled.
	if ( await selectAllToggle.isChecked() ) {
		return;
	}

	await selectAllToggle.check();
	await expect( page.getByTestId( 'snackbar' ) ).toBeVisible();
};

/**
 * Disables all experiments in a specific group using the select-all toggle.
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

	const selectAllToggle = getSelectAllToggle( page, groupName );
	await expect( selectAllToggle ).toBeVisible( { timeout: 10000 } );

	// Nothing to do if this is already disabled.
	if ( ! ( await selectAllToggle.isChecked() ) ) {
		return;
	}

	await selectAllToggle.uncheck();
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
		.locator( '[class*="dataviews-card"]' )
		.filter( { has: page.getByText( groupName, { exact: true } ) } );

	// Get all checkboxes in that section, excluding the select-all toggle.
	const allToggles = section.getByRole( 'checkbox' );
	const count = await allToggles.count();
	const experimentToggles = [];

	for ( let i = 0; i < count; i++ ) {
		const toggle = allToggles.nth( i );
		const label = await toggle.textContent();
		// Exclude the select-all toggle.
		if ( ! label?.match( /^(Enable|Disable) all/ ) ) {
			experimentToggles.push( toggle );
		}
	}

	return experimentToggles;
};
