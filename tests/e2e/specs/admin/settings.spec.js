/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	clearConnectors,
	seedCredentials,
	disableExperiments,
	disableExperiment,
	enableExperiment,
	enableExperiments,
	visitConnectorsPage,
	visitSettingsPage,
	enableAllExperimentsInGroup,
	disableAllExperimentsInGroup,
	getExperimentTogglesInGroup,
	getEnableAllButton,
	getDisableAllButton,
} = require( '../../utils/helpers' );

const EXPERIMENT_GROUPS = {
	editor: 'Editor Experiments',
	admin: 'Admin Experiments',
};
const RAG_STATUS_ROUTE = /\/wp-json\/ai\/v1\/rag\/status(?:\?.*)?$/;
const RAG_REINDEX_ROUTE = /\/wp-json\/ai\/v1\/rag\/reindex(?:\?.*)?$/;
const RAG_DELETE_ROUTE = /\/wp-json\/ai\/v1\/rag\/index(?:\?.*)?$/;

const createRagStatus = ( overrides = {} ) => ( {
	available: true,
	unavailable_reason: '',
	backend: 'memory',
	backend_label: 'Fallback in-memory search backed by PHP',
	available_backends: [ 'memory' ],
	backend_labels: {
		memory: 'Fallback in-memory search backed by PHP',
	},
	storage_ready: true,
	has_index_data: true,
	counts: {
		dirty: 1,
		processing: 0,
		clean: 2,
		error: 0,
	},
	next_scheduled_run: null,
	embedding_model: 'text-embedding-3-small',
	embedding_dimensions: 1536,
	...overrides,
} );

const mockRagMaintenanceRoutes = async ( page, initialStatus ) => {
	let status = initialStatus;

	await page.route( RAG_STATUS_ROUTE, async ( route ) => {
		await route.fulfill( {
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify( status ),
		} );
	} );
	await page.route( RAG_REINDEX_ROUTE, async ( route ) => {
		status = {
			...status,
			counts: {
				...status.counts,
				dirty: 3,
			},
		};
		await route.fulfill( {
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify( status ),
		} );
	} );
	await page.route( RAG_DELETE_ROUTE, async ( route ) => {
		status = createRagStatus( {
			has_index_data: false,
			counts: {
				dirty: 0,
				processing: 0,
				clean: 0,
				error: 0,
			},
		} );
		await route.fulfill( {
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify( status ),
		} );
	} );
};

test.describe( 'Plugin settings', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.deactivatePlugin( 'e2e-test-request-mocking' );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'e2e-test-request-mocking' );
		await seedCredentials( requestUtils );
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
		await expect( page.getByLabel( 'Enable AI' ) ).not.toBeChecked();

		// Ensure feature toggles are disabled when AI is disabled.
		await expect(
			page
				.locator(
					'#ai-wp-admin-app .components-form-toggle.is-disabled'
				)
				.first()
		).toBeVisible();

		// Globally turn on experiments.
		await enableExperiments( admin, page );

		// Ensure global AI setting is enabled.
		await expect( page.getByLabel( 'Enable AI' ) ).toBeChecked();

		// Ensure we see the editor experiments section.
		await expect(
			page.getByText( 'Editor Experiments', { exact: true } )
		).toBeVisible();

		// Ensure we see the admin experiments section.
		await expect(
			page.getByText( 'Admin Experiments', { exact: true } )
		).toBeVisible();
	} );

	test( 'Inline settings retain pending edits when another toggle auto-saves', async ( {
		admin,
		page,
	} ) => {
		// Setup: Enable AI.
		await enableExperiments( admin, page );

		// Ensure the other experiment is disabled to start.
		await disableExperiment( admin, page, 'Title Generation' );

		// Setup: Enable Content Classification.
		await enableExperiment( admin, page, 'Content Classification' );

		// Visit settings page fresh to ensure no stale snackbars.
		await visitSettingsPage( admin );

		// Wait for Content Classification inline settings to render.
		const strategySelect = page.getByLabel( 'Taxonomy strategy' );
		await expect( strategySelect ).toBeVisible( { timeout: 10000 } );

		// Change the strategy to create a pending local edit.
		const originalValue = await strategySelect.inputValue();
		const newValue =
			originalValue === 'existing_only' ? 'allow_new' : 'existing_only';
		await strategySelect.selectOption( newValue );

		// Verify the Save button appears (confirms pending edits exist).
		const saveButton = page
			.locator( '.ai-feature-settings-form' )
			.getByRole( 'button', { name: 'Save' } );
		await expect( saveButton ).toBeVisible();

		// Toggle another experiment to trigger auto-save (changes siteSettings).
		const otherToggle = page.getByLabel( 'Title Generation' );
		await otherToggle.click();

		// Wait for the auto-save snackbar to confirm siteSettings changed.
		await expect(
			page.locator( '.components-snackbar__content', {
				hasText: 'Title Generation enabled.',
			} )
		).toBeVisible();

		// Assert: inline settings must still show the pending edit (not reset).
		await expect( strategySelect ).toHaveValue( newValue );
		await expect( saveButton ).toBeVisible();
	} );

	test( 'RAG settings show status and schedule reindexing', async ( {
		admin,
		page,
	} ) => {
		await mockRagMaintenanceRoutes( page, createRagStatus() );
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, 'RAG Search' );

		await expect(
			page.getByText( 'Fallback in-memory search backed by PHP' )
		).toBeVisible();
		await expect( page.getByText( '1 dirty' ) ).toBeVisible();
		await expect( page.getByText( '2 clean' ) ).toBeVisible();

		await page.getByRole( 'button', { name: 'Reindex' } ).click();

		await expect(
			page.locator( '.components-snackbar__content', {
				hasText: 'RAG indexing scheduled.',
			} )
		).toBeVisible();
		await expect( page.getByText( '3 dirty' ) ).toBeVisible();
	} );

	test( 'RAG cleanup remains available when disabled and data exists', async ( {
		admin,
		page,
	} ) => {
		await mockRagMaintenanceRoutes( page, createRagStatus() );
		await enableExperiments( admin, page );
		await disableExperiment( admin, page, 'RAG Search' );

		await expect(
			page.getByText( 'RAG index data exists.' )
		).toBeVisible();

		page.once( 'dialog', async ( dialog ) => {
			expect( dialog.message() ).toContain( 'Delete all RAG index data' );
			await dialog.accept();
		} );
		await page.getByRole( 'button', { name: 'Delete index data' } ).click();

		await expect(
			page.locator( '.components-snackbar__content', {
				hasText: 'RAG index data deleted.',
			} )
		).toBeVisible();
		await expect(
			page.getByText( 'RAG index data exists.' )
		).not.toBeVisible();
	} );

	test( 'RAG cleanup is hidden when disabled and no data exists', async ( {
		admin,
		page,
	} ) => {
		await mockRagMaintenanceRoutes(
			page,
			createRagStatus( {
				has_index_data: false,
				counts: {
					dirty: 0,
					processing: 0,
					clean: 0,
					error: 0,
				},
			} )
		);
		await enableExperiments( admin, page );
		await disableExperiment( admin, page, 'RAG Search' );

		await expect(
			page.getByText( 'RAG index data exists.' )
		).not.toBeVisible();
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

		// Find the "Enable all" button.
		const enableAllButton = getEnableAllButton(
			page,
			EXPERIMENT_GROUPS.editor
		);

		await expect( enableAllButton ).toBeVisible( { timeout: 10000 } );

		// Ensure button is enabled.
		await expect( enableAllButton ).toBeEnabled();

		// Click the button to enable all experiments.
		await enableAllButton.click();

		// Verify the success message appears with the count.
		const experimentToggles = await getExperimentTogglesInGroup(
			page,
			EXPERIMENT_GROUPS.editor
		);
		const count = experimentToggles.length;

		await expect(
			page.locator( '.components-snackbar__content', {
				hasText: `${ count } experiments enabled`,
			} )
		).toBeVisible();

		// Verify all experiments in the group are now enabled.
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

		// Find the "Disable all" button.
		const disableAllButton = getDisableAllButton(
			page,
			EXPERIMENT_GROUPS.editor
		);

		await expect( disableAllButton ).toBeVisible();

		// Ensure button is enabled.
		await expect( disableAllButton ).toBeEnabled();

		// Click the button to disable all experiments.
		await disableAllButton.click();

		// Verify the success message appears with the count.
		const experimentToggles = await getExperimentTogglesInGroup(
			page,
			EXPERIMENT_GROUPS.editor
		);
		const count = experimentToggles.length;

		await expect(
			page.locator( '.components-snackbar__content', {
				hasText: `${ count } experiments disabled`,
			} )
		).toBeVisible();

		// Verify all experiments in the group are now disabled.
		for ( const toggle of experimentToggles ) {
			await expect( toggle ).not.toBeChecked();
		}
	} );

	test( 'Cannot bulk manage experiments when global AI is disabled', async ( {
		admin,
		page,
	} ) => {
		// Disable global AI.
		await disableExperiments( admin, page );

		// Verify both buttons are disabled.
		const enableAllButton = getEnableAllButton(
			page,
			EXPERIMENT_GROUPS.editor
		);

		const disableAllButton = getDisableAllButton(
			page,
			EXPERIMENT_GROUPS.editor
		);

		await expect( enableAllButton ).toBeDisabled();
		await expect( disableAllButton ).toBeDisabled();
	} );

	test( 'Each experiment group has its own bulk action buttons', async ( {
		admin,
		page,
	} ) => {
		// Ensure AI is enabled.
		await enableExperiments( admin, page );

		// Verify all groups have enable/disable all buttons.
		const editorEnableAll = getEnableAllButton(
			page,
			EXPERIMENT_GROUPS.editor
		);

		const adminEnableAll = getEnableAllButton(
			page,
			EXPERIMENT_GROUPS.admin
		);

		await expect( editorEnableAll ).toBeVisible();
		await expect( adminEnableAll ).toBeVisible();

		const editorToggles = await getExperimentTogglesInGroup(
			page,
			EXPERIMENT_GROUPS.editor
		);

		// Enable all Editor Experiments.
		await editorEnableAll.click();

		// Verify Editor Experiments are enabled.
		for ( const toggle of editorToggles ) {
			await expect( toggle ).toBeChecked();
		}

		// Admin Experiments should remain unchanged (disabled).
		const adminToggles = await getExperimentTogglesInGroup(
			page,
			EXPERIMENT_GROUPS.admin
		);
		for ( const toggle of adminToggles ) {
			await expect( toggle ).not.toBeChecked();
		}
	} );

	test( 'Enable all button is disabled when all experiments are already enabled', async ( {
		admin,
		page,
	} ) => {
		// Ensure AI is enabled.
		await enableExperiments( admin, page );

		// Enable all experiments in the group.
		await enableAllExperimentsInGroup(
			admin,
			page,
			EXPERIMENT_GROUPS.editor
		);

		// Find the "Enable all" button.
		const enableAllButton = getEnableAllButton(
			page,
			EXPERIMENT_GROUPS.editor
		);

		// Verify the "Enable all" button is disabled since all are already enabled.
		await expect( enableAllButton ).toBeDisabled();

		// Verify the "Disable all" button is enabled.
		const disableAllButton = getDisableAllButton(
			page,
			EXPERIMENT_GROUPS.editor
		);

		await expect( disableAllButton ).toBeEnabled();
	} );

	test( 'Disable all button is disabled when all experiments are already disabled', async ( {
		admin,
		page,
	} ) => {
		// Ensure AI is enabled.
		await enableExperiments( admin, page );

		// Disable all experiments in the group.
		await disableAllExperimentsInGroup(
			admin,
			page,
			EXPERIMENT_GROUPS.editor
		);

		// Find the "Disable all" button.
		const disableAllButton = getDisableAllButton(
			page,
			EXPERIMENT_GROUPS.editor
		);

		// Verify the "Disable all" button is disabled since all are already disabled.
		await expect( disableAllButton ).toBeDisabled();

		// Verify the "Enable all" button is enabled.
		const enableAllButton = getEnableAllButton(
			page,
			EXPERIMENT_GROUPS.editor
		);

		await expect( enableAllButton ).toBeEnabled();
	} );

	test( 'Both buttons are enabled when experiments are in mixed state', async ( {
		admin,
		page,
	} ) => {
		// Ensure AI is enabled.
		await enableExperiments( admin, page );

		// Disable all experiments first.
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
		}

		// Both buttons should be enabled in mixed state.
		const enableAllButton = getEnableAllButton(
			page,
			EXPERIMENT_GROUPS.editor
		);

		const disableAllButton = getDisableAllButton(
			page,
			EXPERIMENT_GROUPS.editor
		);

		await expect( enableAllButton ).toBeEnabled();
		await expect( disableAllButton ).toBeEnabled();
	} );

	test( 'Can use developer mode', async ( { admin, page } ) => {
		// Globally turn on experiments.
		await enableExperiments( admin, page );

		// Enable the Excerpt Generation Experiment.
		await enableExperiment( admin, page, 'Excerpt Generation' );

		// Open the settings menu and verify model selection is described.
		await page.getByRole( 'button', { name: 'Developer Tools' } ).click();
		await expect( page.getByText( 'DEVELOPER TOOLS' ) ).toBeVisible();
		await expect(
			page.getByRole( 'menuitemcheckbox', { name: /Model selection/ } )
		).toBeVisible();
		await expect(
			page.getByText( 'Select a specific provider and model per feature' )
		).toBeVisible();

		// Toggle on model selection.
		await page
			.getByRole( 'menuitemcheckbox', { name: /Model selection/ } )
			.click();

		// Verify the menu remains open after toggling the option.
		await expect(
			page.getByRole( 'menuitemcheckbox', { name: /Model selection/ } )
		).toBeVisible();

		// Verify the Excerpt Generation Experiment has developer settings.
		await expect(
			page.locator( '.ai-developer-mode-fields' ).first()
		).toBeVisible();

		// Verify the selected option shows a checkmark.
		await expect(
			page
				.getByRole( 'menuitemcheckbox', { name: /Model selection/ } )
				.locator( 'svg' )
		).toBeVisible();

		// Toggle off model selection.
		await page
			.getByRole( 'menuitemcheckbox', { name: /Model selection/ } )
			.click();

		// Verify the developer settings are no longer visible.
		await expect(
			page.locator( '.ai-developer-mode-fields' )
		).not.toBeVisible();

		// Disable the Excerpt Generation Experiment.
		await disableExperiment( admin, page, 'Excerpt Generation' );
	} );
} );
