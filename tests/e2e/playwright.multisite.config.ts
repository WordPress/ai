/**
 * Playwright configuration for the multisite E2E suite.
 *
 * Runs only `*.multisite.spec.js` files against the network started with
 * `npm run wp-env:test-multisite start`.
 */

/**
 * External dependencies
 */
import { defineConfig } from '@playwright/test';

/**
 * Internal dependencies
 */
import './config/multisite-env';
import config from './playwright.config';

export default defineConfig( {
	...config,
	testMatch: '**/*.multisite.spec.js',
	testIgnore: [],
	webServer: {
		...config.webServer,
		command: 'npm run wp-env:test-multisite start',
		port: Number( new URL( process.env.WP_BASE_URL as string ).port ),
	},
} );
