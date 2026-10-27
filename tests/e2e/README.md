# E2E Tests

This directory contains end-to-end tests for the project, utilizing [Playwright](https://playwright.dev) and its test runner to run the tests in Chromium by default. [`@wordpress/e2e-test-utils-playwright`](https://github.com/WordPress/gutenberg/tree/HEAD/packages/e2e-test-utils-playwright) is used as a helper package to simplify the usage. See the documentation of both for more information.

## Prerequisites

- **Node.js** (v24)
- **Docker**

## Running Tests

### Test Setup

To prepare the test environment, follow these steps:

1. Run `npm install` to install the required dependencies.
2. Start the environment by running `npm run wp-env:test start`. *(Ensure Docker is running before executing this command.)*

### Test Execution

To execute the tests, use the following commands:

1. Run all tests:
`npm run test:e2e`

2. Run tests in UI mode:
`npm run test:e2e:debug`

3. Stop the environment by running `npm run wp-env:test stop`.

### Multisite Tests

Specs named `*.multisite.spec.js` run in a separate multisite network on port 8890 and are skipped by `npm run test:e2e`.

1. Start the network by running `npm run wp-env:test-multisite start`. It network-activates the plugin, as multisite features such as Agent Users require.
2. Run the multisite tests with `npm run test:e2e:multisite`.
3. Stop the network by running `npm run wp-env:test-multisite stop`.
