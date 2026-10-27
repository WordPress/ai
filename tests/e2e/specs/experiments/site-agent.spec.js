/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	disableExperiment,
	enableExperiment,
} = require( '../../utils/helpers' );

test.describe( 'Site Agent Experiment', () => {
	test.afterEach( async ( { admin, page } ) => {
		await disableExperiment( admin, page, 'Site Agent' );
	} );

	test( 'Can enable the experiment and open the Site Agent page', async ( {
		admin,
		page,
	} ) => {
		await enableExperiment( admin, page, 'Site Agent' );

		await admin.visitAdminPage( 'tools.php?page=ai-site-agent' );

		await expect(
			page.getByRole( 'heading', { name: 'Site Agent', level: 1 } )
		).toBeVisible();

		await expect( page.locator( '#ai-site-agent-root' ) ).toBeVisible();

		// The screen either offers the composer or explains that no usable
		// model is configured in this environment. Match the heading so the
		// accessibility live region does not create a strict-mode collision.
		const unavailableHeading = page.getByRole( 'heading', {
			name: /No AI credentials configured|No compatible model available/,
		} );

		await expect(
			page.locator( '#ai-site-agent-prompt' ).or( unavailableHeading )
		).toBeVisible( { timeout: 10000 } );
	} );
} );
