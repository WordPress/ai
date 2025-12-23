/**
 * External dependencies
 */
import type { Page } from '@playwright/test';

/**
 * WordPress dependencies
 */
import type { Admin } from '@wordpress/e2e-test-utils-playwright';

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
export const vistitCredentialsPage = async ( admin: Admin ) => {
	await admin.visitAdminPage( 'options-general.php?page=wp-ai-client' );
};

/**
 * Clears out any existing credentials.
 *
 * @param admin The admin fixture from the test context.
 * @param page  The page object.
 */
export const clearCredentials = async ( admin: Admin, page: Page ) => {
	await vistitCredentialsPage( admin );
	const passwordFields = page.locator( '.form-table input[type="password"]' );
	const count = await passwordFields.count();
	for ( let i = 0; i < count; i++ ) {
		await passwordFields.nth( i ).fill( '' );
	}
	await page.locator( '#submit' ).click();
};
