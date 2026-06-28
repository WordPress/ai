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
	enableModelSelection,
	disableModelSelection,
} = require( '../../utils/helpers' );

const EXPERIMENT_GROUPS = {
	editor: 'Editor Experiments',
	admin: 'Admin Experiments',
};

test.describe( 'Plugin settings', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.deactivatePlugin( 'e2e-testing' );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'e2e-testing' );
		await seedCredentials( requestUtils );
	} );

	test( 'Can visit the settings page and see error message', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		// Activate the request mocking plugin.
		await requestUtils.activatePlugin( 'e2e-testing' );

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
		await requestUtils.activatePlugin( 'e2e-testing' );

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

	test( 'Snackbar notifications do not cover the settings content (#800)', async ( {
		admin,
		page,
	} ) => {
		// Use a fixed desktop viewport so the admin menu is at full width and
		// snackbar placement is deterministic.
		await page.setViewportSize( { width: 1280, height: 800 } );
		await visitSettingsPage( admin );

		// Toggle the global setting to trigger a snackbar.
		const globalToggle = page.getByLabel( 'Enable AI' );
		await expect( globalToggle ).toBeVisible( { timeout: 10000 } );
		await globalToggle.click();

		const snackbar = page.locator( '.components-snackbar' ).first();
		await expect( snackbar ).toBeVisible();

		// The snackbar must sit to the inline-start of the centered settings
		// content, not on top of it (regression guard for #800).
		const snackBox = await snackbar.boundingBox();
		const contentBox = await page
			.locator( '.ai-settings-page' )
			.boundingBox();
		expect( snackBox ).not.toBeNull();
		expect( contentBox ).not.toBeNull();
		expect( snackBox.x + snackBox.width ).toBeLessThanOrEqual(
			contentBox.x
		);
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

		// Enable developer mode (Model selection).
		await enableModelSelection( page );

		// Verify the Excerpt Generation Experiment has developer settings.
		await expect(
			page.locator( '.ai-developer-mode-fields' ).first()
		).toBeVisible();

		// Toggle off model selection.
		await disableModelSelection( page );

		// Verify the developer settings are no longer visible.
		await expect(
			page.locator( '.ai-developer-mode-fields' )
		).not.toBeVisible();

		// Disable the Excerpt Generation Experiment.
		await disableExperiment( admin, page, 'Excerpt Generation' );
	} );

	test( 'Developer settings save button appears, values persist after save, and reset does not requires explicit save', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		// Activate the request mocking plugin and seed a valid connector.
		await requestUtils.activatePlugin( 'e2e-testing' );
		await seedCredentials( requestUtils );

		// Setup: Enable AI, disable all other experiments, then enable only Content Classification.
		await enableExperiments( admin, page );
		await disableAllExperimentsInGroup(
			admin,
			page,
			EXPERIMENT_GROUPS.editor
		);
		await disableAllExperimentsInGroup(
			admin,
			page,
			EXPERIMENT_GROUPS.admin
		);
		await disableExperiment( admin, page, 'Image Generation and Editing' );
		await enableExperiment( admin, page, 'Content Classification' );

		// Enable developer mode (Model selection).
		await enableModelSelection( page );

		// Scope all selectors to the first developer settings form (Content Classification).
		const developerFields = page
			.locator( '.ai-developer-mode-fields' )
			.first();

		await expect( developerFields ).toBeVisible( { timeout: 10000 } );

		const providerSelect = developerFields.getByLabel( 'Provider' );
		const saveButton = developerFields.getByRole( 'button', {
			name: 'Save',
		} );

		// Select provider and model, verify Save button appears.
		await providerSelect.selectOption( 'openai' );
		const modelSelect = developerFields.getByLabel( 'Model' );
		await expect( modelSelect ).toBeVisible( { timeout: 5000 } );
		await modelSelect.selectOption( 'gpt-5.2' );

		await expect( saveButton ).toBeVisible();

		// Click Save, reload, verify values persist.
		await saveButton.click();
		await expect( saveButton ).not.toBeVisible( { timeout: 10000 } );

		await visitSettingsPage( admin );

		const developerFieldsAfterReload = page
			.locator( '.ai-developer-mode-fields' )
			.first();

		await expect(
			developerFieldsAfterReload.getByLabel( 'Provider' )
		).toHaveValue( 'openai' );
		await expect(
			developerFieldsAfterReload.getByLabel( 'Model' )
		).toHaveValue( 'gpt-5.2' );

		// Click Reset to default
		const resetButton = developerFieldsAfterReload.getByRole( 'button', {
			name: 'Reset to default',
		} );
		await expect( resetButton ).toBeVisible();
		await resetButton.click();

		await expect(
			developerFieldsAfterReload.getByLabel( 'Provider' )
		).toHaveValue( '' );

		await expect( resetButton ).not.toBeVisible( {
			timeout: 10000,
		} );

		// Cleanup: Toggle off model selection.
		await disableModelSelection( page );
		await expect(
			page.locator( '.ai-developer-mode-fields' )
		).not.toBeVisible();

		// Cleanup: Disable the Content Classification experiment.
		await disableExperiment( admin, page, 'Content Classification' );
	} );

	test( 'Unsaved developer settings do not persist on page reload', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		// Activate the request mocking plugin and seed a valid connector.
		await requestUtils.activatePlugin( 'e2e-testing' );
		await seedCredentials( requestUtils );

		// Setup: Enable AI, disable all other experiments, then enable only Content Classification.
		await enableExperiments( admin, page );
		await disableAllExperimentsInGroup(
			admin,
			page,
			EXPERIMENT_GROUPS.editor
		);
		await disableAllExperimentsInGroup(
			admin,
			page,
			EXPERIMENT_GROUPS.admin
		);
		await disableExperiment( admin, page, 'Image Generation and Editing' );
		await enableExperiment( admin, page, 'Content Classification' );

		// Ensure the developer settings are cleared from a prior test.
		await visitSettingsPage( admin );

		// Enable developer mode (Model selection).
		await enableModelSelection( page );

		// Scope all selectors to the first developer settings form (Content Classification).
		const developerFields = page
			.locator( '.ai-developer-mode-fields' )
			.first();

		await expect( developerFields ).toBeVisible( { timeout: 10000 } );

		// Select provider and model but do NOT click Save.
		const providerSelect = developerFields.getByLabel( 'Provider' );
		await providerSelect.selectOption( 'openai' );
		const modelSelect = developerFields.getByLabel( 'Model' );
		await expect( modelSelect ).toBeVisible( { timeout: 5000 } );
		await modelSelect.selectOption( 'gpt-5.2' );

		// Confirm Save button is visible (unsaved changes exist).
		await expect(
			developerFields.getByRole( 'button', { name: 'Save' } )
		).toBeVisible();

		// Reload the page WITHOUT saving.
		await visitSettingsPage( admin );

		const developerFieldsAfterReload = page
			.locator( '.ai-developer-mode-fields' )
			.first();

		// Verify the provider has reverted to the default.
		await expect(
			developerFieldsAfterReload.getByLabel( 'Provider' )
		).toHaveValue( '' );

		// Cleanup: Toggle off model selection.
		await disableModelSelection( page );
		await expect(
			page.locator( '.ai-developer-mode-fields' )
		).not.toBeVisible();

		// Cleanup: Disable the Content Classification experiment.
		await disableExperiment( admin, page, 'Content Classification' );
	} );

	test( 'Developer mode settings are hidden for disabled visual feature cards', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on experiments so the Image Generation feature can be enabled.
		await enableExperiments( admin, page );

		// Enable the visual Image Generation feature card.
		await enableExperiment( admin, page, 'Image Generation and Editing' );

		// Turn on model selection while AI is globally enabled.
		await enableModelSelection( page );

		// Globally disable AI. The feature card remains checked, but inactive.
		await disableExperiments( admin, page );

		const disabledImageGenerationCard = page.locator(
			'.ai-showcase-card--disabled',
			{
				has: page.getByText( 'Image Generation and Editing' ),
			}
		);

		await expect( disabledImageGenerationCard ).toBeVisible();

		// The disabled visual feature card should not expose active provider/model controls.
		await expect(
			disabledImageGenerationCard.locator( '.ai-developer-mode-fields' )
		).not.toBeVisible();

		// Restore state.
		await enableExperiments( admin, page );
		await disableModelSelection( page );
		await disableExperiment( admin, page, 'Image Generation and Editing' );
	} );
} );
