/**
 * Shared helpers for the Agent Users E2E specs.
 */

/**
 * WordPress dependencies
 */
const {
	expect,
	RequestUtils,
} = require( '@wordpress/e2e-test-utils-playwright' );

const PASSWORD = 'password';

let suffixCount = 0;

/**
 * Returns a suffix that keeps usernames unique across tests and retries.
 *
 * @return {string} Unique suffix.
 */
const uniqueSuffix = () => `${ Date.now() }${ ++suffixCount }`;

/**
 * Creates a human user who can sign in with the shared test password.
 *
 * @param {RequestUtils} requestUtils Request utils of the administrator.
 * @param {string}       role         Role slug.
 * @return {Promise<{id: number, username: string}>} The user.
 */
const createHuman = async ( requestUtils, role ) => {
	// Multisite only allows lowercase letters and numbers in usernames.
	const username = `ai${ role }${ uniqueSuffix() }`;
	const user = await requestUtils.createUser( {
		username,
		email: `${ username }@example.com`,
		password: PASSWORD,
		roles: [ role ],
	} );

	return { id: user.id, username };
};

/**
 * Deletes every user except the root user and the signed-in administrator.
 *
 * Deleting a parent also deletes its agents. `RequestUtils::deleteAllUsers()`
 * sends every request at once, so an agent's own request races its parent's
 * and fails once the agent is already gone. The requests run one at a time
 * here, skipping agents deleted along with their parent.
 *
 * @param {RequestUtils} requestUtils Request utils of the administrator.
 */
const deleteAllUsers = async ( requestUtils ) => {
	const users = await requestUtils.rest( {
		path: '/wp/v2/users',
		params: { per_page: 100 },
	} );

	for ( const user of users ) {
		if ( user.id === 1 || user.name === requestUtils.user.username ) {
			continue;
		}

		try {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/wp/v2/users/${ user.id }`,
				params: { force: true, reassign: 1 },
			} );
		} catch ( error ) {
			// `RequestUtils::rest()` throws the decoded error response.
			if ( 'rest_user_invalid_id' !== error?.code ) {
				throw error;
			}
		}
	}
};

/**
 * Creates an agent through the Add Agent screen.
 *
 * @param {Object} admin            Admin fixture.
 * @param {Object} page             Page fixture.
 * @param {Object} options          Agent details.
 * @param {number} options.parentId Parent user ID.
 * @param {string} options.role     Role slug.
 * @return {Promise<{id: number, login: string}>} The agent.
 */
const createAgent = async ( admin, page, { parentId, role } ) => {
	const login = `e2e_${ uniqueSuffix() }_agent`;

	await admin.visitAdminPage( 'user-new.php', 'wpai_agent=1' );
	await page.locator( '#user_login' ).fill( login );
	await page.locator( '#email' ).fill( `${ login }@example.com` );
	await page.locator( '#role' ).selectOption( role );
	await page.getByLabel( 'Parent user' ).selectOption( String( parentId ) );
	await page.locator( '#createusersub' ).click();

	await expect( page ).toHaveURL( /user-edit\.php\?user_id=\d+/ );
	const id = Number( new URL( page.url() ).searchParams.get( 'user_id' ) );

	return { id, login };
};

/**
 * Creates an Application Password for a user as the administrator.
 *
 * @param {RequestUtils} requestUtils Request utils of the administrator.
 * @param {number}       userId       User ID.
 * @return {Promise<string>} The plaintext password.
 */
const createApplicationPassword = async ( requestUtils, userId ) => {
	const created = await requestUtils.rest( {
		method: 'POST',
		path: `/wp/v2/users/${ userId }/application-passwords`,
		data: { name: `E2E ${ uniqueSuffix() }` },
	} );

	return created.password;
};

/**
 * Sends a REST request authenticated only by an Application Password.
 *
 * No cookies are sent, so the request is exactly what an external agent
 * would make.
 *
 * @param {Object} playwright           Playwright fixture.
 * @param {string} baseURL              Site URL.
 * @param {Object} credentials          Login and Application Password.
 * @param {string} credentials.login    Username.
 * @param {string} credentials.password Application Password.
 * @param {Object} request              The request.
 * @param {string} [request.method]     HTTP method. Default `GET`.
 * @param {string} request.route        REST route, such as `/wp/v2/users/me`.
 * @param {Object} [request.data]       JSON body.
 * @param {string} [request.sitePath]   Path of a network site, such as `/site-b`. Default the main site.
 * @return {Promise<{status: number, body: Object}>} Status and decoded body.
 */
const agentRequest = async (
	playwright,
	baseURL,
	{ login, password },
	{ method = 'GET', route, data, sitePath = '' }
) => {
	const context = await playwright.request.newContext( {
		baseURL,
		// The test config signs every context in as the administrator; a
		// valid login cookie would take precedence over the credential.
		storageState: { cookies: [], origins: [] },
		extraHTTPHeaders: {
			Authorization: `Basic ${ Buffer.from(
				`${ login }:${ password }`
			).toString( 'base64' ) }`,
		},
	} );

	const response = await context.fetch(
		`${ sitePath }/index.php?rest_route=${ encodeURIComponent( route ) }`,
		{ method, data }
	);
	const result = { status: response.status(), body: await response.json() };
	await context.dispose();

	return result;
};

/**
 * Signs the page in as another user.
 *
 * @param {Object} page     Page fixture.
 * @param {string} username Username.
 */
const loginAs = async ( page, username ) => {
	const userRequestUtils = await RequestUtils.setup( {
		user: { username, password: PASSWORD },
	} );
	await userRequestUtils.login();
	await page
		.context()
		.addCookies(
			( await userRequestUtils.request.storageState() ).cookies
		);
	await userRequestUtils.request.dispose();
};

/**
 * Adds an Application Password on the profile screen that is open.
 *
 * @param {Object} page Page fixture.
 * @param {string} name Credential name.
 * @return {Promise<string>} The plaintext password, shown once.
 */
const addApplicationPasswordOnProfile = async ( page, name ) => {
	await page.locator( '#new_application_password_name' ).fill( name );
	await page.locator( '#do_new_application_password' ).click();

	const value = page.locator( '#new-application-password-value' );
	await expect( value ).toBeVisible();

	return value.inputValue();
};

/**
 * Sends an administrator REST request to a site of the network.
 *
 * `RequestUtils::rest()` only reaches the main site. The nonce is per user
 * and session, so it is valid on every site.
 *
 * @param {RequestUtils} requestUtils     Request utils of the administrator.
 * @param {string}       sitePath         Path of the site, such as `/site-b`.
 * @param {Object}       request          The request.
 * @param {string}       [request.method] HTTP method. Default `GET`.
 * @param {string}       request.path     REST route.
 * @param {Object}       [request.data]   JSON body.
 * @param {Object}       [request.params] Query parameters.
 * @return {Promise<Object>} Decoded response body.
 */
const siteRest = async (
	requestUtils,
	sitePath,
	{ method = 'GET', path, data, params = {} }
) => {
	if ( ! requestUtils.storageState?.nonce ) {
		await requestUtils.setupRest();
	}

	const response = await requestUtils.request.fetch(
		`${ sitePath }/index.php`,
		{
			method,
			data,
			params: { ...params, rest_route: path },
			headers: { 'X-WP-Nonce': requestUtils.storageState.nonce },
			failOnStatusCode: false,
		}
	);
	const body = await response.json();
	if ( ! response.ok() ) {
		throw new Error( `${ response.status() } ${ JSON.stringify( body ) }` );
	}

	return body;
};

module.exports = {
	PASSWORD,
	uniqueSuffix,
	createHuman,
	deleteAllUsers,
	createAgent,
	createApplicationPassword,
	agentRequest,
	siteRest,
	loginAs,
	addApplicationPasswordOnProfile,
};
