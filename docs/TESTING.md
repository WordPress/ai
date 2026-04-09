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

### 1. Unit Tests (Logic Layer)

**Purpose**: Test pure functions and business logic in isolation, without loading the WordPress environment.

**Status**: The current repository does not include a `tests/Unit/` suite yet. When introducing isolated logic that can be tested without WordPress, add unit tests in a dedicated `tests/Unit/` directory and update the PHPUnit configuration accordingly.

### 2. Integration Tests (WordPress + Plugin Interactions)

**Purpose**: Test interactions between different parts of the plugin, and between the plugin and WordPress core, database, or other plugin components. These tests run within a WordPress test environment.

**Location**: `tests/Integration/`

**Example Test Suite**: `tests/Integration/Includes/BootstrapTest.php`

```php
class BootstrapTest extends WP_UnitTestCase {

    /**
     * Test that the plugin bootstrap file exists.
     */
    public function test_bootstrap_file_exists() {
        $this->assertFileExists( dirname( __DIR__, 3 ) . '/includes/bootstrap.php' );
    }
}
```

### 3. Edge Cases and Error Scenarios

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
# Run all tests
composer test

# Run static analysis (fast, focuses on type safety)
composer phpstan

# Run the current PHPUnit suite defined in phpunit.xml.dist
vendor/bin/phpunit -c phpunit.xml.dist

# Run the current integration suite directly
vendor/bin/phpunit -c phpunit.xml.dist --testsuite integration
```

### CI/CD Pipeline

Automated testing in CI should run the currently configured integration and end-to-end suites on every push and pull request. If a dedicated unit test suite is introduced later, it should be added to the pipeline as well.

---

## Coverage Targets

**Quality Gates**:
- **Unit tests**: Aim for 90%+ code coverage for pure logic.
- **Integration tests**: Aim for 80%+ critical path coverage.

---

## Summary

By adhering to this testing strategy, we ensure that the AI Plugin is robust, reliable, and maintainable, with a clear focus on testing behavior and user experience.
