/**
 * External dependencies
 */
import type { Locator, Page } from '@playwright/test';

/**
 * WordPress dependencies
 */
import {
	type Admin,
	type Editor,
	type RequestUtils,
	expect,
} from '@wordpress/e2e-test-utils-playwright';

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
 * Visits the AI Request Logs page under Tools.
 *
 * @param admin The admin fixture from the test context.
 */
export const visitRequestLogsPage = async ( admin: Admin ) => {
	await admin.visitAdminPage( 'tools.php', 'page=ai-request-logs' );
};

/**
 * Empties the AI request log table via the REST endpoint.
 *
 * @param requestUtils The request utils fixture from the test context.
 */
export const purgeRequestLogs = async ( requestUtils: RequestUtils ) => {
	await requestUtils.rest( {
		method: 'DELETE',
		path: '/ai/v1/logs',
	} );
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
 * Gets the section master toggle for a specific experiment group.
 *
 * @param page      The page object.
 * @param groupName The name of the experiment group (e.g., 'Editor Experiments').
 * @return The section master toggle locator.
 */
export const getSectionMasterToggle = ( page: Page, groupName: string ) => {
	return page.getByRole( 'checkbox', {
		name: `Enable ${ groupName }`,
		exact: true,
	} );
};

/**
 * Globally disables experiments.
 *
 * @param admin The admin fixture from the test context.
 * @param page  The page object.
 */
export const disableExperiments = async ( admin: Admin, page: Page ) => {
	await disableAllExperimentsInGroup(
		admin,
		page,
		'Editor Experiments'
	);
	await disableAllExperimentsInGroup( admin, page, 'Admin Experiments' );
};

/**
 * Globally enables experiments.
 *
 * @param admin The admin fixture from the test context.
 * @param page  The page object.
 */
export const enableExperiments = async ( admin: Admin, page: Page ) => {
	await enableAllExperimentsInGroup(
		admin,
		page,
		'Editor Experiments'
	);
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

	const toggle = page.getByLabel( experimentLabel );
	await expect( toggle ).toBeVisible( { timeout: 10000 } );
	await expect( toggle ).toBeEnabled( { timeout: 10000 } );

	// Nothing to do if this experiment is already enabled.
	if ( await toggle.isChecked() ) {
		return;
	}

	await toggle.check();

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

	const toggle = page.getByLabel( experimentLabel );
	await expect( toggle ).toBeVisible( { timeout: 10000 } );
	await expect( toggle ).toBeEnabled( { timeout: 10000 } );

	// Nothing to do if this experiment is already disabled.
	if ( ! ( await toggle.isChecked() ) ) {
		return;
	}

	await toggle.uncheck();

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

	const disableAllButton = getDisableAllButton( page, groupName );

	await enableAllButton.click();
	await expect( enableAllButton ).toBeDisabled();
	await expect( disableAllButton ).toBeEnabled();
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

	const enableAllButton = getEnableAllButton( page, groupName );

	await disableAllButton.click();
	await expect( disableAllButton ).toBeDisabled();
	await expect( enableAllButton ).toBeEnabled();
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
	const allToggles = section.getByRole( 'checkbox' );
	const count = await allToggles.count();
	const experimentToggles: Locator[] = [];

	for ( let i = 0; i < count; i++ ) {
		const toggle = allToggles.nth( i );
		experimentToggles.push( toggle );
	}

	return experimentToggles;
};

/**
 * Selects the first paragraph block in the editor canvas so its block toolbar renders.
 *
 * Uses `editor.selectBlocks()` rather than a raw click so selection is reliable
 * regardless of where the click lands within the block's text.
 *
 * @param editor The editor fixture from the test context.
 * @return The paragraph block locator.
 */
export const selectFirstParagraph = async ( editor: Editor ) => {
	const paragraph = editor.canvas
		.locator( '[data-type="core/paragraph"]' )
		.first();
	await editor.selectBlocks( paragraph );
	return paragraph;
};

/**
 * Seeds a dummy OpenAI API key.
 *
 * @param requestUtils The requestUtils fixture from the test context.
 */
export const seedCredentials = async ( requestUtils: RequestUtils ) => {
	await requestUtils.rest( {
		path: '/ai-e2e/v1/credentials/seed',
		method: 'POST',
	} );
};

/**
 * Clears the dummy OpenAI API key.
 *
 * @param requestUtils The requestUtils fixture from the test context.
 */
export const clearCredentials = async ( requestUtils: RequestUtils ) => {
	await requestUtils.rest( {
		path: '/ai-e2e/v1/credentials/clear',
		method: 'POST',
	} );
};
