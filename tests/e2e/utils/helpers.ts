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
	await admin.visitAdminPage( 'options-general.php?page=ai-experiments' );
};

/**
 * Visits the credentials page.
 *
 * @param admin The admin fixture from the test context.
 */
export const visitCredentialsPage = async ( admin: Admin ) => {
	await admin.visitAdminPage( 'options-general.php?page=wp-ai-client' );
};

/**
 * Clears out any existing credentials.
 *
 * @param admin The admin fixture from the test context.
 * @param page  The page object.
 */
export const clearCredentials = async ( admin: Admin, page: Page ) => {
	await visitCredentialsPage( admin );
	const passwordFields = page.locator( '.form-table input[type="password"]' );
	const count = await passwordFields.count();
	for ( let i = 0; i < count; i++ ) {
		await passwordFields.nth( i ).fill( '' );
	}
	await page.locator( '#submit' ).click();
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
	// Click the disable button (when enabled, it says "Disable")
	const buttons = await page.$$( 'button.ai-experiments__toggle-button' );
	if ( buttons.length > 0 ) {
		await buttons[ 0 ].click();
	}

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
	// Click the enable button (when disabled, it says "Enable")
	const buttons = await page.$$( 'button.ai-experiments__toggle-button' );
	if ( buttons.length > 0 ) {
		await buttons[ 0 ].click();
	}

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
	await page.locator( `#ai_experiment_${ experimentId }_enabled` ).check();
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
	await page.locator( `#ai_experiment_${ experimentId }_enabled` ).uncheck();
	await page.locator( '#submit' ).click();

	// Ensure the save was successful.
	await expect(
		page.locator( '.wrap .notice-success', {
			hasText: 'Settings saved',
		} )
	).toHaveCount( 1 );
};
