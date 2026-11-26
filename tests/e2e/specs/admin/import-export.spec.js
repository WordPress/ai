/**
 * External dependencies
 */
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );

/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	disableExperiment,
	visitSettingsPage,
} = require( '../../utils/helpers' );

/**
 * Opens the "Developer Tools" actions dropdown menu on the settings page.
 *
 * @param {import('@playwright/test').Page} page The page object.
 * @return {Promise<import('@playwright/test').Locator>} The Settings menu group.
 */
async function openSettingsMenu( page ) {
	await page.getByRole( 'button', { name: 'Developer Tools' } ).click();
	const menu = page.getByRole( 'group', { name: 'Settings', exact: true } );
	await expect( menu ).toBeVisible();
	return menu;
}

/**
 * Selects a settings export through the Import settings action.
 *
 * @param {import('@playwright/test').Page} page     The page object.
 * @param {string}                          filePath The settings file path.
 */
async function selectImportFile( page, filePath ) {
	const [ fileChooser ] = await Promise.all( [
		page.waitForEvent( 'filechooser' ),
		page
			.getByRole( 'group', { name: 'Settings', exact: true } )
			.getByRole( 'menuitem', { name: 'Import settings', exact: true } )
			.click(),
	] );
	await fileChooser.setFiles( filePath );
}

/**
 * Writes a valid AI settings export payload to a temporary file and returns
 * its path.
 *
 * @param {Record<string, unknown>} overrides Settings to merge into the payload.
 * @return {string} The path to the temporary JSON file.
 */
function writeTempExportFile( overrides = {} ) {
	const payload = {
		version: 1,
		exported_at: new Date().toISOString(),
		plugin_version: '0.0.0-test',
		providers: {},
		settings: {
			...overrides,
		},
	};

	const filePath = path.join(
		os.tmpdir(),
		`ai-settings-import-${ Date.now() }.json`
	);
	fs.writeFileSync( filePath, JSON.stringify( payload ) );
	return filePath;
}

test.describe( 'Settings import/export', () => {
	/** @type {string[]} */
	const tempFiles = [];

	test.afterEach( () => {
		while ( tempFiles.length ) {
			const file = tempFiles.pop();
			try {
				fs.unlinkSync( file );
			} catch {
				// File may not exist; ignore cleanup failures.
			}
		}
	} );

	test( 'Export and Import settings options are available in the Settings menu', async ( {
		admin,
		page,
	} ) => {
		await visitSettingsPage( admin );

		const settingsMenu = await openSettingsMenu( page );

		// Both actions should be visible within the Settings menu group.
		const exportItem = settingsMenu.getByRole( 'menuitem', {
			name: 'Export settings',
		} );
		const importItem = settingsMenu.getByRole( 'menuitem', {
			name: 'Import settings',
		} );

		await expect( exportItem ).toBeVisible();
		await expect( exportItem ).toBeEnabled();
		await expect( importItem ).toBeVisible();
		await expect( importItem ).toBeEnabled();

		// Close the menu.
		await page.keyboard.press( 'Escape' );
	} );

	test( 'Can export settings as a downloadable JSON file', async ( {
		admin,
		page,
	} ) => {
		await visitSettingsPage( admin );
		await openSettingsMenu( page );

		const downloadPromise = page.waitForEvent( 'download' );
		await page.getByRole( 'menuitem', { name: 'Export settings' } ).click();
		const download = await downloadPromise;

		expect( download.suggestedFilename() ).toBe(
			'ai-settings-export.json'
		);

		const downloadPath = await download.path();
		const contents = fs.readFileSync( downloadPath, 'utf-8' );
		const data = JSON.parse( contents );

		// Verify the exported schema shape.
		expect( data ).toHaveProperty( 'version', 1 );
		expect( data ).toHaveProperty( 'exported_at' );
		expect( data ).toHaveProperty( 'plugin_version' );
		expect( data ).toHaveProperty( 'providers' );
		expect( data ).toHaveProperty( 'settings' );

		// Sensitive values must never be present in the export.
		const serialized = JSON.stringify( data ).toLowerCase();
		expect( serialized ).not.toMatch( /api_?key|token|secret|password/ );
	} );

	test( 'Selecting a file to import shows a confirmation modal', async ( {
		admin,
		page,
	} ) => {
		await visitSettingsPage( admin );

		const filePath = writeTempExportFile();
		tempFiles.push( filePath );

		await openSettingsMenu( page );
		await selectImportFile( page, filePath );

		// The confirmation modal should appear before anything is imported.
		const dialog = page.getByRole( 'dialog', {
			name: 'Import AI Settings',
		} );
		await expect( dialog ).toBeVisible();
		await expect(
			dialog.getByText(
				'This will overwrite your existing AI settings. Are you sure?'
			)
		).toBeVisible();
		await expect(
			dialog.getByRole( 'button', { name: 'Cancel' } )
		).toBeVisible();
		await expect(
			dialog.getByRole( 'button', { name: 'Import' } )
		).toBeVisible();
	} );

	test( 'Cancelling the import confirmation does not change settings', async ( {
		admin,
		page,
	} ) => {
		// Ensure Title Generation is disabled so we can confirm cancelling leaves it untouched.
		await disableExperiment( admin, page, 'Title Generation' );

		const filePath = writeTempExportFile( {
			'wpai_feature_title-generation_enabled': true,
		} );
		tempFiles.push( filePath );

		await openSettingsMenu( page );
		await selectImportFile( page, filePath );

		const dialog = page.getByRole( 'dialog', {
			name: 'Import AI Settings',
		} );
		await expect( dialog ).toBeVisible();

		await dialog.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect( dialog ).not.toBeVisible();

		// Title Generation toggle must remain disabled since the import was cancelled.
		await expect(
			page.getByRole( 'checkbox', { name: 'Title Generation' } )
		).not.toBeChecked();
	} );

	test( 'Confirming the import applies settings without a page reload', async ( {
		admin,
		page,
	} ) => {
		// Ensure Title Generation is disabled so the imported value is a visible change.
		await disableExperiment( admin, page, 'Title Generation' );
		await expect(
			page.getByRole( 'checkbox', { name: 'Title Generation' } )
		).not.toBeChecked();

		const filePath = writeTempExportFile( {
			'wpai_feature_title-generation_enabled': true,
		} );
		tempFiles.push( filePath );

		await openSettingsMenu( page );
		await selectImportFile( page, filePath );

		const dialog = page.getByRole( 'dialog', {
			name: 'Import AI Settings',
		} );
		await expect( dialog ).toBeVisible();

		await dialog.getByRole( 'button', { name: 'Import' } ).click();

		// Success notice confirms the request completed.
		await expect(
			page
				.getByRole( 'main' )
				.getByText( 'Settings imported successfully.', { exact: true } )
		).toBeVisible();

		await expect( dialog ).not.toBeVisible();

		// The toggle should reflect the imported value immediately, with no
		// manual page reload required (regression guard for the stale
		// core-data cache/infinite-spinner issue).
		await expect(
			page.getByRole( 'checkbox', { name: 'Title Generation' } )
		).toBeChecked( {
			timeout: 10000,
		} );

		// Cleanup: restore Title Generation to disabled.
		await disableExperiment( admin, page, 'Title Generation' );
	} );

	test( 'Rejects an invalid file and shows an error notice', async ( {
		admin,
		page,
	} ) => {
		await visitSettingsPage( admin );

		const filePath = path.join(
			os.tmpdir(),
			`ai-settings-invalid-${ Date.now() }.json`
		);
		fs.writeFileSync( filePath, JSON.stringify( { foo: 'bar' } ) );
		tempFiles.push( filePath );

		await openSettingsMenu( page );
		await selectImportFile( page, filePath );

		// No confirmation modal should appear for a malformed export file.
		await expect(
			page.getByRole( 'dialog', { name: 'Import AI Settings' } )
		).not.toBeVisible();

		await expect(
			page
				.getByRole( 'main' )
				.getByText( 'not a valid AI settings export' )
		).toBeVisible();
	} );
} );
