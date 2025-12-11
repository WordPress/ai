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
- Use `@since x.x.x` for new code (version will be updated on release).
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
 * @since x.x.x
 */
class Experiment_Registry {
	/**
	 * Registers a new experiment with the plugin.
	 *
	 * @since x.x.x
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

The plugin requires WordPress 6.9 or higher. Ensure all WordPress functions and hooks used are available in this version.

## Branch naming conventions

There are a few protected branch naming conventions:

* `develop`: The main development branch.
* `trunk`: The stable release branch.
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

## Release instructions

The following can be copied into a [new, blank GitHub issue](https://github.com/WordPress/ai/issues) who's title is formatted as `Release version X.Y.Z`.  Once the issue is submitted, the checklist in the body of the issue should be followed to release a new version of the WordPress AI Experiments plugin.  All references to `X.Y.Z` below should be updated to the actual release version number.

```
This issue is for tracking changes for the X.Y.Z release.  Target release date: **DD Month YYYY.**

## Pre-release steps

- [ ] Review and merge #.

## [Release steps](https://github.com/wordpress/ai/blob/develop/CONTRIBUTING.md#release-instructions)

- [ ] Branch: Starting from `develop`, cut a release branch named `release/X.Y.Z` for your changes.
- [ ] Version bump: Bump the version number in `ai.php`, `package-lock.json` (twice), `package.json`, and `readme.txt` if it does not already reflect the version being released.  In `includes/bootstrap.php`, ensure you're updating the `AI_EXPERIMENTS_VERSION` version constant.
- [ ] Update `@since`: Find all new `@since x.x.x` lines and update those with the new version number in place of `x.x.x`.
- [ ] Changelog: Add/update the changelog in `CHANGELOG.md` and in `readme.txt`.
- [ ] Props: update `CREDITS.md` file with any new contributors, confirm maintainers are accurate.
- [ ] Readme updates: Make any other readme changes as necessary in `README.md` and `readme.txt`.
- [ ] Roadmap updates: Update `ROADMAP.md` based on what's in/out of this release and planned for upcoming releases.
- [ ] New files: Check to be sure any new files/paths that are unnecessary in the production version are included in `.gitattributes`.
- [ ] Merge: Make a non-fast-forward merge from your release branch to `develop` (or merge the pull request), then do the same for `develop` into `trunk` (`git checkout trunk && git merge --no-ff develop`).  `trunk` now contains the stable development version.
- [ ] Push: Push your trunk branch to GitHub (e.g. `git push origin trunk`).
- [ ] [Wait for build](https://xkcd.com/303/): Head to the [Actions](https://github.com/wordpress/ai/actions) tab in the repo and wait for it to finish if it hasn't already.  If it doesn't succeed, figure out why and start over.
- [ ] Check the build: Check out the `trunk` branch and test for functionality locally.
- [ ] Test: Check the [end-to-end tests](https://github.com/WordPress/ai/actions/workflows/test.yml) are passing.  Only proceed if everything tests successfully.
- [ ] Release: Create a [new release](https://github.com/wordpress/ai/releases/new), naming the tag and the release with the new version number, and targeting the `trunk` branch.  Paste the changelog for the release from [`CHANGELOG.md`](https://github.com/WordPress/ai/blob/develop/CHANGELOG.md) into the body of the release and include a link to `[View all items closed in the milestone](https://github.com/wordpress/ai/milestone/#?closed=1)`.  The release should now appear under [releases](https://github.com/wordpress/ai/releases).

## Post-release steps

- [ ] Close milestone: Edit the [milestone](https://github.com/wordpress/ai/milestone/#) with release date (in the `Due date (optional)` field) and link to GitHub release (in the `Description field`), then close the milestone.
- [ ] Punt incomplete items: If any open issues or PRs which were milestoned for `X.Y.Z` do not make it into the release, update their milestone to `X.Y.Z+1`, `X.Y+1.0`, `X+1.0.0` or `Future Release`.
- [ ] Announce: Publish release announcement post on Make/AI, cross-posting to Make/Core and Make/Test ([example](https://make.wordpress.org/ai/2025/11/27/announcing-the-ai-experiments-plugin-v0-1-0/)).
- [ ] Profile badges: Grant new contributors the `Core AI Contributor` [profile badge](https://make.wordpress.org/ai/wp-admin/tools.php?page=profile-badges).
```

## Guidelines

- As with all WordPress projects, we want to ensure a welcoming environment for everyone. With that in mind, all contributors are expected to follow our [Code of Conduct](https://make.wordpress.org/handbook/community-code-of-conduct/).
- All WordPress projects are licensed under the GPLv2+, and all contributions to the WordPress AI Experiments Plugin will be released under the GPLv2+ license. You maintain copyright over any contribution you make, and by submitting a pull request, you are agreeing to release that contribution under the GPLv2+ license.

## Additional resources

For more detailed information on plugin architecture, creating experiments, and development workflows, see:

- [Developer Guide](docs/DEVELOPER_GUIDE.md) - Comprehensive guide to plugin architecture and experiment development
- [Testing Strategy](docs/TESTING.md) - Testing philosophy and guidelines
- [WordPress AI Team](https://make.wordpress.org/ai/) - Community and discussion
