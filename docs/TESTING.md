# Testing Strategy

This document outlines the testing philosophy and strategy for the AI plugin, adhering to the "pyramid way of testing" to ensure comprehensive coverage and maintainability.

---

## Testing Philosophy

**Principle**: Test behavior, not implementation. Focus on what users experience.

**Pyramid Structure**:
- **70% Unit Tests**: Fast, isolated logic testing
- **25% Integration Tests**: WordPress + Plugin interactions
- **5% E2E Tests**: Real user workflows

---

## Test Categories

### 1. Unit Tests (WordPress + Plugin Interactions)

**Purpose**: Test interactions between different parts of the plugin, and between the plugin and WordPress core, database, or other plugin components. These tests run within a WordPress test environment.

**Location**: `tests/Integration/`

**Example Test Suite**: `tests/Integration/Includes/MainTest.php`

```php
class MainTest extends WP_UnitTestCase {

    /**
     * Test that the plugin main file exists.
     */
    public function test_main_file_exists() {
        $this->assertFileExists( dirname( __DIR__, 3 ) . '/includes/Main.php' );
    }
}
```

### 2. Edge Cases and Error Scenarios

While specific examples are provided in the "Post Duplication Feature" strategy, for our plugin, we would focus on:

*   **Data Integrity**: Ensuring data is handled correctly (e.g., special characters, large data sets).
*   **Performance**: Testing for memory limits and execution time for critical operations.
*   **Security**: Verifying permission checks and input sanitization.
*   **WordPress Integration**: Ensuring correct interaction with WordPress APIs (actions, filters, post types, etc.).
*   **Third-Party Compatibility**: If applicable, testing interactions with other plugins (e.g., WooCommerce, ACF).

---

## Test Execution Strategy

### Local Development

```bash
# Run the PHP suite. This is the command to use.
npm run test:php

# Run static analysis (fast, focuses on type safety)
composer phpstan
```

**Run the PHP suite through `npm run test:php`, not through `phpunit` directly.**
That script scopes the run to the `.wp-env.test.json` environment, which has its
own database. Invoking `vendor/bin/phpunit` or `composer test` inside the default
`wp-env` container instead resolves `DB_NAME` to the development site's own
database, and the WordPress test bootstrap then reinstalls WordPress over it —
deactivating plugins, deleting posts, and clearing options including provider
credentials. The suite passes while doing it, so the damage is silent.

### Running both suites in one session

`npm run test:php` reinstalls WordPress in the shared `wp-env` test environment, which **deactivates the plugins**. A `npm run test:e2e` run that follows it then fails in its `enableExperiments()` helper, because the settings screen it relies on is gone. Re-activate the plugins between the two suites:

```bash
npm run wp-env:test run cli -- wp plugin activate ai ai-provider-for-anthropic ai-provider-for-google ai-provider-for-openai
```

Running the e2e suite first, as [CONTRIBUTING.md](../CONTRIBUTING.md) does, avoids the problem entirely.

### CI/CD Pipeline

Automated testing in CI should run the currently configured unit and end-to-end suites on every push and pull request.

---

## Coverage Targets

**Quality Gates**:
- **Unit tests**: Aim for 90%+ code coverage for pure logic.
- **Integration tests**: Aim for 80%+ critical path coverage.

---

## Summary

By adhering to this testing strategy, we ensure that the AI Plugin is robust, reliable, and maintainable, with a clear focus on testing behavior and user experience.
