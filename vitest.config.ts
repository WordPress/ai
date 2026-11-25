/**
 * External dependencies
 */
import { defineConfig } from 'vitest/config';

/**
 * Unit tests for pure logic, run with `npm run test:unit:js`.
 *
 * The include is explicit because Vitest's default glob also matches the
 * Playwright `*.spec` files under `tests/e2e`, which need a browser and a site.
 */
export default defineConfig( {
	test: {
		include: [ 'src/**/test/*.test.ts' ],
		environment: 'node',
	},
} );
