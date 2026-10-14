/**
 * External dependencies
 */
import { request } from '@playwright/test';
import type { FullConfig } from '@playwright/test';

/**
 * WordPress dependencies
 */
import { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

async function globalSetup( config: FullConfig ) {
	const { storageState, baseURL } = config.projects[ 0 ].use;
	const storageStatePath =
		typeof storageState === 'string' ? storageState : undefined;

	const requestContext = await request.newContext( {
		baseURL,
	} );

	const requestUtils = new RequestUtils( requestContext, {
		storageStatePath,
	} );

	// Authenticate and save the storageState to disk.
	await requestUtils.setupRest();

	// Reset the test environment before running the tests.
	await Promise.all( [
		requestUtils.activateTheme( 'twentytwentyone' ),
		requestUtils.activatePlugin( 'e2e-testing' ),
		// The Abilities Explorer field fixture is activated only by the specs that need it.
		// It may be absent from a local environment, which leaves nothing to deactivate.
		requestUtils
			.deactivatePlugin( 'e2e-abilities-explorer-field' )
			.catch( () => undefined ),
		requestUtils.deleteAllPosts(),
		requestUtils.deleteAllBlocks(),
		requestUtils.resetPreferences(),
	] );

	await requestUtils.rest( {
		path: '/ai-e2e/v1/credentials/seed',
		method: 'POST',
	} );

	// A run stopped while AI was switched off must not leave it off for the next run.
	await requestUtils.rest( {
		path: '/ai-e2e/v1/ai-disabled',
		method: 'POST',
		data: { disabled: false },
	} );

	await requestContext.dispose();
}

export default globalSetup;
