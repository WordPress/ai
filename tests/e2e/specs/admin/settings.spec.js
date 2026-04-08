/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	clearConnectors,
	disableExperiments,
	enableExperiments,
	visitConnectorsPage,
	visitSettingsPage,
	getSelectAllToggle,
	enableAllExperimentsInGroup,
	disableAllExperimentsInGroup,
	getExperimentTogglesInGroup,
} = require( '../../utils/helpers' );

const EXPERIMENT_GROUPS = {
	editor: 'Editor Experiments',
	admin: 'Admin Experiments',
};

test.describe( 'Plugin settings', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.deactivatePlugin( 'e2e-test-request-mocking' );
	} );

	test( 'Can visit the settings page and see error message', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		// Activate the request mocking plugin.
		await requestUtils.activatePlugin( 'e2e-test-request-mocking' );

		// Clear out any existing Connectors.
		await clearConnectors( admin, page );

		// Visit the settings page.
		await visitSettingsPage( admin );

		// Ensure the page title is correct.
		await expect(
			page.getByText(
				'Configure AI features and experiments for your WordPress site.'
			)
		).toBeVisible();

		// Ensure the no AI Connectors error message is displayed.
		await expect(
			page
				.locator( '#ai-wp-admin-app' )
				.getByText(
					'The AI plugin requires a valid AI Connector to function properly'
				)
		).toBeVisible();
	} );

	test( 'Can visit the Connectors page and add a valid OpenAI Connector', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		// Activate the request mocking plugin.
		await requestUtils.activatePlugin( 'e2e-test-request-mocking' );

		await visitConnectorsPage( admin );

		const openAIConnector = page.locator( '[role="listitem"]', {
			has: page.getByRole( 'heading', { name: 'OpenAI', exact: true } ),
		} );

		// Add dummy credentials for OpenAI.
		await openAIConnector
			.getByRole( 'button', { name: /Set up|Edit/i } )
			.click();
		await openAIConnector
			.getByRole( 'textbox' )
			.first()
			.fill( 'valid-api-key' );

		// Save the credentials.
		await openAIConnector
			.getByRole( 'button', { name: /Save|Update/i } )
			.click();
	} );

	test( 'Can turn on Experiments', async ( { admin, page } ) => {
		// Globally disable experiments.
		await disableExperiments( admin, page );

		// Ensure global AI setting is disabled.
		await expect(
			page.getByRole( 'checkbox', { name: 'Enable AI' } )
		).not.toBeChecked();

		// Ensure feature checkboxes are disabled when AI is disabled.
		await expect(
			page
				.locator( '#ai-wp-admin-app input[type="checkbox"]:disabled' )
				.first()
		).toBeVisible();

		// Globally turn on experiments.
		await enableExperiments( admin, page );

		// Ensure global AI setting is enabled.
		await expect(
			page.getByRole( 'checkbox', { name: 'Enable AI' } )
		).toBeChecked();

		// Ensure we see the editor experiments section.
		await expect(
			page.getByText( 'Editor Experiments', { exact: true } )
		).toBeVisible();

		// Ensure we see the admin experiments section.
		await expect(
			page.getByText( 'Admin Experiments', { exact: true } )
		).toBeVisible();
	} );

	test( 'Can turn on all experiments in a group', async ( {
		admin,
		page,
	} ) => {
		// Ensure AI is enabled first.
		await enableExperiments( admin, page );

		// Ensure all experiments are disabled to start.
		await disableAllExperimentsInGroup(
			admin,
			page,
			EXPERIMENT_GROUPS.editor
		);

		// Find the "Enable all Editor Experiments" toggle.
		const selectAllToggle = getSelectAllToggle(
			page,
			EXPERIMENT_GROUPS.editor
		);
		await expect( selectAllToggle ).toBeVisible( { timeout: 10000 } );

		// Ensure setting is disabled.
		await expect( selectAllToggle ).not.toBeChecked();

		// Click the select-all toggle to enable all experiments.
		await selectAllToggle.check();

		// Verify the success message appears.
		await expect( page.getByTestId( 'snackbar' ) ).toBeVisible();

		// Verify the toggle label changed to "Disable all".
		await expect(
			page.getByRole( 'checkbox', {
				name: new RegExp(
					`Disable all ${ EXPERIMENT_GROUPS.editor }`,
					'i'
				),
			} )
		).toBeVisible();

		// Verify all experiments in the group are now enabled.
		const experimentToggles = await getExperimentTogglesInGroup(
			page,
			EXPERIMENT_GROUPS.editor
		);
		for ( const toggle of experimentToggles ) {
			await expect( toggle ).toBeChecked();
		}
	} );

	test( 'Can turn off all experiments in a group', async ( {
		admin,
		page,
	} ) => {
		// Ensure AI is enabled first.
		await enableExperiments( admin, page );

		// First enable all experiments.
		await enableAllExperimentsInGroup(
			admin,
			page,
			EXPERIMENT_GROUPS.editor
		);

		// Find the "Disable all" toggle.
		const selectAllToggle = getSelectAllToggle(
			page,
			EXPERIMENT_GROUPS.editor
		);
		await expect( selectAllToggle ).toBeVisible();

		// Ensure setting is disabled.
		await expect( selectAllToggle ).toBeChecked();

		// Click the select-all toggle to disable all experiments.
		await selectAllToggle.uncheck();

		// Verify the success message appears.
		await expect( page.getByTestId( 'snackbar' ) ).toBeVisible();

		// Verify the toggle label changed to "Enable all".
		await expect(
			page.getByRole( 'checkbox', {
				name: new RegExp(
					`Enable all ${ EXPERIMENT_GROUPS.editor }`,
					'i'
				),
			} )
		).toBeVisible();

		// Verify all experiments in the group are now disabled.
		const experimentToggles = await getExperimentTogglesInGroup(
			page,
			EXPERIMENT_GROUPS.editor
		);
		for ( const toggle of experimentToggles ) {
			await expect( toggle ).not.toBeChecked();
		}
	} );

	test( 'Can turn on all experiments in a group when experiments are in mixed state', async ( {
		admin,
		page,
	} ) => {
		// Ensure AI is enabled.
		await enableExperiments( admin, page );

		// First disable all experiments in the group.
		await disableAllExperimentsInGroup(
			admin,
			page,
			EXPERIMENT_GROUPS.editor
		);

		// Get all experiment toggles in the group.
		const experimentToggles = await getExperimentTogglesInGroup(
			page,
			EXPERIMENT_GROUPS.editor
		);

		// Enable just the first experiment to create a mixed state.
		if ( experimentToggles.length > 0 ) {
			await experimentToggles[ 0 ].check();
			await expect( page.getByTestId( 'snackbar' ) ).toBeVisible();
		}

		// Verify the select-all toggle shows "Enable all" (because not all are enabled).
		await expect(
			page.getByRole( 'checkbox', {
				name: new RegExp(
					`Enable all ${ EXPERIMENT_GROUPS.editor }`,
					'i'
				),
			} )
		).toBeVisible();

		// Clicking it should enable all experiments.
		const selectAllToggle = getSelectAllToggle(
			page,
			EXPERIMENT_GROUPS.editor
		);
		await selectAllToggle.check();
		await expect( page.getByTestId( 'snackbar' ) ).toBeVisible();

		// Verify all are now enabled.
		for ( const toggle of experimentToggles ) {
			await expect( toggle ).toBeChecked();
		}
	} );

	test( 'Cannot bulk manage experiments when global AI is disabled', async ( {
		admin,
		page,
	} ) => {
		// Disable global AI.
		await disableExperiments( admin, page );

		// Verify the select-all toggle is disabled.
		const selectAllToggle = getSelectAllToggle(
			page,
			EXPERIMENT_GROUPS.editor
		);
		await expect( selectAllToggle ).toBeDisabled();

		// Enable AI again.
		await enableExperiments( admin, page );

		// Verify the select-all toggle is now enabled.
		await expect( selectAllToggle ).toBeEnabled();
	} );

	test( 'Each experiment group has its own select all toggle', async ( {
		admin,
		page,
	} ) => {
		// Ensure AI is enabled.
		await enableExperiments( admin, page );

		// Verify all groups have select-all toggles.
		const editorSelectAll = getSelectAllToggle(
			page,
			EXPERIMENT_GROUPS.editor
		);
		const adminSelectAll = getSelectAllToggle(
			page,
			EXPERIMENT_GROUPS.admin
		);

		await expect( editorSelectAll ).toBeVisible();
		await expect( adminSelectAll ).toBeVisible();

		// Enable all Editor Experiments.
		await editorSelectAll.click();
		await expect( page.getByTestId( 'snackbar' ) ).toBeVisible();

		// Verify Editor Experiments are enabled but Admin Experiments are not affected.
		const editorToggles = await getExperimentTogglesInGroup(
			page,
			EXPERIMENT_GROUPS.editor
		);
		for ( const toggle of editorToggles ) {
			await expect( toggle ).toBeChecked();
		}

		// Admin Experiments should remain unchanged.
		await expect( adminSelectAll ).not.toBeChecked();
	} );
} );
