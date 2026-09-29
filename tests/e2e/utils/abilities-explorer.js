/**
 * Shared helpers for the Abilities Explorer end-to-end specs.
 */

/**
 * WordPress dependencies
 */
const { expect } = require( '@wordpress/e2e-test-utils-playwright' );

const PAGE_QUERY = 'page=ai-abilities-explorer';
const VIEW_STORAGE_KEY = 'ai.abilitiesExplorer.view';
const FIELD_PLUGIN = 'e2e-abilities-explorer-field';
const FIXTURE_CATEGORY = 'E2E Fixtures & Friends';

const REMOVED = 'Ability removed from the assistant.';
const RETURNED = 'Ability returned to the assistant.';
const POLICY_OFF =
	'Assistant admission policy switched off. Only the built-in abilities are offered.';
const POLICY_ON = 'Assistant admission policy switched on.';
const WITHHELD_REASON =
	'Held back by this plugin: it reads personal data, settings or environment detail.';
const POLICY_OFF_REASON =
	'The assistant admission policy is off, so only the built-in abilities are offered.';

/**
 * Sets the plugin's feature switches through the settings REST route.
 *
 * @param {Object} requestUtils The requestUtils fixture.
 * @param {Object} settings     Map of option name to boolean.
 */
const setSettings = ( requestUtils, settings ) =>
	requestUtils.rest( {
		path: '/wp/v2/settings',
		method: 'POST',
		data: settings,
	} );

/**
 * Switches the E2E Testing plugin's Explorer fixtures, and clears the
 * assistant's owner exclusions and policy switch either way.
 *
 * @param {Object}  requestUtils The requestUtils fixture.
 * @param {boolean} enabled      Whether the fixtures are registered.
 */
const setFixtures = ( requestUtils, enabled ) =>
	requestUtils.rest( {
		path: '/ai-e2e/v1/explorer-fixtures',
		method: 'POST',
		data: { enabled },
	} );

/**
 * Reads the Explorer's list route, which reports the stored surface state.
 *
 * @param {Object} requestUtils The requestUtils fixture.
 * @return {Promise<Object>} The list payload.
 */
const readList = ( requestUtils ) =>
	requestUtils.rest( { path: '/ai/v1/abilities', method: 'GET' } );

/**
 * Returns one row of the list payload.
 *
 * @param {Object} list The list payload.
 * @param {string} slug The ability name.
 * @return {Object|undefined} The row.
 */
const payloadRow = ( list, slug ) =>
	list.items.find( ( item ) => item.slug === slug );

/**
 * Matches requests to one of the Explorer's REST routes, whether the site uses
 * pretty permalinks (`/wp-json/ai/v1/...`) or plain ones (`?rest_route=...`).
 *
 * @param {string} route The route path, such as `/ai/v1/abilities/surface`.
 * @return {Function} A URL predicate for `page.route()` and `waitForResponse()`.
 */
const restRoute = ( route ) => {
	const pattern = new RegExp(
		`${ route.replace( /\//g, '\\/' ) }(?:[?&]|$)`
	);

	return ( url ) => pattern.test( decodeURIComponent( url.toString() ) );
};

/**
 * Opens the Explorer, with optional extra query arguments.
 *
 * @param {Object} admin The admin fixture.
 * @param {Object} page  The page.
 * @param {string} extra Extra query arguments, without a leading `&`.
 */
const openExplorer = async ( admin, page, extra = '' ) => {
	await admin.visitAdminPage(
		'tools.php',
		extra ? `${ PAGE_QUERY }&${ extra }` : PAGE_QUERY
	);
};

/**
 * The data rows of the ability table.
 *
 * @param {Object} page The page.
 * @return {Object} The rows locator.
 */
const dataRows = ( page ) =>
	page
		.getByRole( 'table' )
		.getByRole( 'row' )
		.filter( { has: page.getByRole( 'cell' ) } );

/**
 * One data row, found by the text it contains.
 *
 * @param {Object} page The page.
 * @param {string} text Text in the row, such as the ability label.
 * @return {Object} The row locator.
 */
const row = ( page, text ) => dataRows( page ).filter( { hasText: text } );

/**
 * A filter chip. The chips precede the table, whose column headers carry the
 * same names, so the first match is the chip.
 *
 * @param {Object} page  The page.
 * @param {string} label The filter's field label.
 * @return {Object} The chip locator.
 */
const filterChip = ( page, label ) =>
	page.getByRole( 'button', { name: new RegExp( `^${ label }` ) } ).first();

/**
 * Picks one option from a filter.
 *
 * @param {Object} page   The page.
 * @param {string} label  The filter's field label.
 * @param {string} option The option to pick.
 */
const pickFilter = async ( page, label, option ) => {
	await filterChip( page, label ).click();
	await page
		.getByRole( 'listbox', { name: `List of: ${ label }` } )
		.getByRole( 'option', { name: option, exact: true } )
		.click();
	await page.keyboard.press( 'Escape' );
};

/**
 * Opens a column's header menu and picks an item.
 *
 * @param {Object} page   The page.
 * @param {string} column The column label.
 * @param {string} item   The menu item.
 */
const columnMenu = async ( page, column, item ) => {
	await page
		.getByRole( 'table' )
		.getByRole( 'button', { name: column, exact: true } )
		.click();
	const menuItem = page
		.getByRole( 'menuitemradio', { name: item } )
		.or( page.getByRole( 'menuitem', { name: item } ) );

	await menuItem.click();

	// Sorting leaves the menu open; close it so it cannot cover the next control.
	if ( await menuItem.isVisible() ) {
		await page.keyboard.press( 'Escape' );
	}
	await expect( menuItem ).toBeHidden();
};

/**
 * The confirmation snackbar with the given text.
 *
 * @param {Object} page The page.
 * @param {string} text The message.
 * @return {Object} The snackbar locator.
 */
const snackbar = ( page, text ) =>
	page.getByTestId( 'snackbar' ).filter( { hasText: text } );

/**
 * Reads the saved view from local storage.
 *
 * @param {Object} page The page.
 * @return {Promise<Object|null>} The stored record.
 */
const savedView = ( page ) =>
	page.evaluate(
		( key ) => JSON.parse( window.localStorage.getItem( key ) ?? 'null' ),
		VIEW_STORAGE_KEY
	);

/**
 * Searches the list, so a row is on the first page however many abilities
 * the site registers.
 *
 * @param {Object} page The page.
 * @param {string} text The search text.
 */
const search = async ( page, text ) => {
	await page
		.getByRole( 'searchbox', { name: 'Search Abilities' } )
		.fill( text );
};

/**
 * Searches for one row and returns it once it is shown.
 *
 * @param {Object} page The page.
 * @param {string} text The row's label or slug.
 * @return {Promise<Object>} The row locator.
 */
const findRow = async ( page, text ) => {
	await search( page, text );
	const found = row( page, text );
	await expect( found ).toBeVisible();
	// Row actions show on hover, as in WP_List_Table.
	await found.hover();

	return found;
};

/**
 * The number of rows the unfiltered first page shows, from the list route.
 *
 * @param {Object} requestUtils The requestUtils fixture.
 * @return {Promise<number>} The row count.
 */
const firstPageRowCount = async ( requestUtils ) =>
	Math.min( ( await readList( requestUtils ) ).items.length, 20 );

/**
 * The runner's JSON input, by its accessible name.
 *
 * @param {Object} page The page.
 * @return {Object} The textarea locator.
 */
const runnerInput = ( page ) =>
	page.getByRole( 'textbox', { name: 'Ability test input (JSON)' } );

/**
 * One message in the runner's validation panel. The same words are also
 * spoken through a live region, so the panel's list item is matched.
 *
 * @param {Object} page The page.
 * @param {string} text The message.
 * @return {Object} The message locator.
 */
const validationMessage = ( page, text ) =>
	page.getByRole( 'listitem' ).filter( { hasText: text } );

/**
 * Asserts the view has exactly one `h1`, named "Abilities Explorer".
 *
 * @param {Object} page The page.
 */
const expectSingleHeading = async ( page ) => {
	await expect( page.getByRole( 'heading', { level: 1 } ) ).toHaveCount( 1 );
	await expect(
		page.getByRole( 'heading', { level: 1, name: 'Abilities Explorer' } )
	).toBeVisible();
};

/**
 * Turns the Explorer on, registers the E2E Testing plugin's fixtures, and
 * clears any owner exclusion or policy switch left behind.
 *
 * @param {Object} requestUtils The requestUtils fixture.
 */
const enableExplorer = async ( requestUtils ) => {
	await setSettings( requestUtils, {
		wpai_features_enabled: true,
		'wpai_feature_abilities-explorer_enabled': true,
	} );
	await setFixtures( requestUtils, true );
};

module.exports = {
	FIELD_PLUGIN,
	FIXTURE_CATEGORY,
	POLICY_OFF,
	POLICY_OFF_REASON,
	POLICY_ON,
	REMOVED,
	RETURNED,
	VIEW_STORAGE_KEY,
	WITHHELD_REASON,
	columnMenu,
	dataRows,
	enableExplorer,
	expectSingleHeading,
	filterChip,
	findRow,
	firstPageRowCount,
	openExplorer,
	payloadRow,
	pickFilter,
	readList,
	restRoute,
	row,
	runnerInput,
	savedView,
	search,
	setFixtures,
	setSettings,
	snackbar,
	validationMessage,
};
