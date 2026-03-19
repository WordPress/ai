/**
 * External dependencies
 */
import type { Page } from '@playwright/test';

/**
 * WordPress dependencies
 */
import { type Admin, expect } from '@wordpress/e2e-test-utils-playwright';

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
	await admin.visitAdminPage( 'options-general.php?page=ai' );
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
		const editBtn = page.locator( `.connector-item--${ provider } button`, {
			hasText: 'Edit',
		} );

		if ( ( await editBtn.count() ) === 0 ) {
			continue;
		}

		await editBtn.click();
		await page
			.locator(
				`.connector-item--${ provider } .connector-settings button`
			)
			.click();
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

	const editBtn = page.locator( `.connector-item--${ connectorId } button`, {
		hasText: 'Edit',
	} );

	if ( ( await editBtn.count() ) === 0 ) {
		return;
	}

	await editBtn.click();
	await page
		.locator(
			`.connector-item--${ connectorId } .connector-settings button`
		)
		.click();

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

	// Wait for page to fully load before finding button
	await page.waitForSelector( 'button.ai-experiments__toggle-button', {
		timeout: 10000,
	} );

	// Click the disable button if it exists. Otherwise we assume the experiments are already disabled.
	const button = page.locator( 'button.ai-experiments__toggle-button', {
		hasText: 'Disable AI',
	} );
	if ( ( await button.count() ) === 0 ) {
		return;
	}
	await button.click();

	// Wait for page reload and ensure the save was successful.
	await page.waitForLoadState( 'load' );
	await expect(
		page.locator( '.wrap .notice-success', {
			hasText: 'Settings saved',
		} )
	).toHaveCount( 1 );
};

/**
 * Globally enables experiments.
 *
 * @param admin The admin fixture from the test context.
 * @param page  The page object.
 */
export const enableExperiments = async ( admin: Admin, page: Page ) => {
	await visitSettingsPage( admin );

	// Wait for page to fully load before finding button
	await page.waitForSelector( 'button.ai-experiments__toggle-button', {
		timeout: 10000,
	} );

	// Click the enable button if it exists. Otherwise we assume the experiments are already enabled.
	const button = page.locator( 'button.ai-experiments__toggle-button', {
		hasText: 'Enable AI',
	} );
	if ( ( await button.count() ) === 0 ) {
		return;
	}
	await button.click();

	// Wait for page reload and ensure the save was successful.
	await page.waitForLoadState( 'load' );
	await expect(
		page.locator( '.wrap .notice-success', {
			hasText: 'Settings saved',
		} )
	).toHaveCount( 1 );
};

/**
 * Enables a specific experiment.
 *
 * @param admin        The admin fixture from the test context.
 * @param page         The page object.
 * @param experimentId The ID of the experiment to enable.
 */
export const enableExperiment = async (
	admin: Admin,
	page: Page,
	experimentId: string
) => {
	await visitSettingsPage( admin );
	await page.locator( `#wpai_feature_${ experimentId }_enabled` ).check();
	await page.locator( '#submit' ).click();

	// Ensure the save was successful.
	await expect(
		page.locator( '.wrap .notice-success', {
			hasText: 'Settings saved',
		} )
	).toHaveCount( 1 );
};

/**
 * Disables a specific experiment.
 *
 * @param admin        The admin fixture from the test context.
 * @param page         The page object.
 * @param experimentId The ID of the experiment to disable.
 */
export const disableExperiment = async (
	admin: Admin,
	page: Page,
	experimentId: string
) => {
	await visitSettingsPage( admin );
	await page.locator( `#wpai_feature_${ experimentId }_enabled` ).uncheck();
	await page.locator( '#submit' ).click();

	// Ensure the save was successful.
	await expect(
		page.locator( '.wrap .notice-success', {
			hasText: 'Settings saved',
		} )
	).toHaveCount( 1 );
};
