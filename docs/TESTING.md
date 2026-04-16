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
# Run all tests
composer test

# Run static analysis (fast, focuses on type safety)
composer phpstan

# Run the current PHPUnit suite defined in phpunit.xml.dist
vendor/bin/phpunit -c phpunit.xml.dist

```

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
