/**
 * Points the E2E tooling at the multisite test network.
 *
 * Imported before any other configuration, because the base configuration
 * and the test utilities read these variables when they load. `WP_BASE_URL`
 * is set even when inherited, since an inherited value points at the single
 * site; `WP_MULTISITE_BASE_URL` changes the network URL.
 */

/**
 * External dependencies
 */
import path from 'path';

process.env.WP_BASE_URL =
	process.env.WP_MULTISITE_BASE_URL || 'http://localhost:8890';
process.env.WP_ARTIFACTS_PATH ??= path.join( process.cwd(), 'artifacts' );
process.env.STORAGE_STATE_PATH = path.join(
	process.env.WP_ARTIFACTS_PATH,
	'storage-states/admin-multisite.json'
);
