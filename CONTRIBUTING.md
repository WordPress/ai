# Contributing Guidelines

Welcome to the WordPress AI Experiments Plugin! Here you find some information on how to get started contributing to the plugin.

## Coding standards

All code must follow the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/). This ensures consistency across the WordPress ecosystem and makes the codebase maintainable.

All parameters, return values, and properties should use explicit type hints where possible, following WordPress best practices for PHP 7.4+ compatibility.

## Naming conventions

The following naming conventions must be followed for consistency and autoloading:

- Interfaces are suffixed with `_Interface` (e.g., `Experiment_Interface`).
- Traits are suffixed with `_Trait` (e.g., `Validation_Trait`).
- File names are the same as the class, trait, and interface name for PSR-4 autoloading.
- Classes use WordPress naming conventions with underscores (e.g., `Experiment_Loader`).
- Namespaces follow the pattern `WordPress\AI\{Component}`.

## Documentation standards

All code must be properly documented with PHPDoc blocks following these standards:

### General rules

- All descriptions must end with a period.
- Use `@since 0.1.0` for new code (version will be updated on release).
- Place `@since` tags below the description and above `@param` tags, with blank comment lines around it.

### Method documentation

- Method descriptions must start with a third-person verb (e.g., "Creates", "Returns", "Checks").
- Exceptions: Constructors and magic methods may use different phrasing.
- All `@return` annotations must include a description.

### Interface implementations

- Use `{@inheritDoc}` instead of duplicating descriptions when implementing interface methods.
- Only provide a unique description if it adds value beyond the interface documentation.

### Example

```php
/**
 * Class for handling experiment registration.
 *
 * @since 0.1.0
 */
class Experiment_Registry {
	/**
	 * Registers a new experiment with the plugin.
	 *
	 * @since 0.1.0
	 *
	 * @param Experiment $experiment The experiment instance to register.
	 * @return bool True if registered successfully, false otherwise.
	 */
	public function register_experiment( Experiment $experiment ): bool {
		// Implementation
	}
}
```

### Array Lists

When an array is a list — that is, an array where the keys are sequential, starting at 0 — use the `list` generic type within the docblock. For example, a parameter that is a list of strings would be documented as `@param list<string> $variable`.

Note that `list<string>` and `string[]` _are not_ the same. The latter is an alias for `array<int, string>` which does not enforce that the keys are sequential.

## PHP Compatibility

All code must be backward compatible with PHP 7.4, which is the minimum required PHP version for this project.

## WordPress Compatibility

The plugin requires WordPress 6.8 or higher. Ensure all WordPress functions and hooks used are available in this version.

## Branch naming conventions

There are a few protected branch naming conventions:

* `trunk`: The main development branch.
* `release/*`: A branch for a specific release, useful e.g. for applying a hotfix.
* `feature/*`: A branch for a larger feature that takes multiple iterative PRs towards completion.

These special branches are protected and are configured more strictly in regards to GitHub workflow configuration.

Branches that you use for implementing a pull request or experimenting can use any naming convention you prefer, _except_ the above. Additionally, please do not use branch names that would easily cause confusion, such as other common main branch names like `main` or `develop`.

Ideally, the branch name is in some form or shape descriptive of what it is for.

## Development workflow

### Local setup and testing

Run `composer install` so that you can install and activate the plugin.

Soon we'll start having other assets (like JS and CSS files) and at that point you'll also need to run `npm i && npm run build`. You can run those commands now but the build command won't actually do anything yet as we don't have any files to build. If you're wanting to run tests locally though, you will need to at least run `npm i` to bring in those dependencies.

### Quality checks

Before submitting a pull request, run the following commands:

```bash
# Check coding standards
composer lint

# Run static analysis
composer stan

# Auto-fix coding standards issues
composer format

# Run tests
composer test
```

### Internationalization

All user-facing strings must be translatable using WordPress i18n functions:

```php
// Good
__( 'Hello World', 'ai' );
_e( 'Hello World', 'ai' );
esc_html__( 'Hello World', 'ai' );

// Bad
echo 'Hello World';
```

## Guidelines

- As with all WordPress projects, we want to ensure a welcoming environment for everyone. With that in mind, all contributors are expected to follow our [Code of Conduct](https://make.wordpress.org/handbook/community-code-of-conduct/).
- All WordPress projects are licensed under the GPLv2+, and all contributions to the WordPress AI Plugin will be released under the GPLv2+ license. You maintain copyright over any contribution you make, and by submitting a pull request, you are agreeing to release that contribution under the GPLv2+ license.

## Additional resources

For more detailed information on plugin architecture, creating experiments, and development workflows, see:

- [Developer Guide](docs/DEVELOPER_GUIDE.md) - Comprehensive guide to plugin architecture and experiment development
- [Testing Strategy](docs/TESTING.md) - Testing philosophy and guidelines
- [WordPress AI Team](https://make.wordpress.org/ai/) - Community and discussion
