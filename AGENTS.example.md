# AGENTS.md

Start with [CONTRIBUTING.md](CONTRIBUTING.md). Read the others when the task needs them:

- [docs/DEVELOPER_GUIDE.md](docs/DEVELOPER_GUIDE.md): adding an experiment, hooks, asset loading.
- [docs/ARCHITECTURE_OVERVIEW.md](docs/ARCHITECTURE_OVERVIEW.md): the full directory tree.
- [docs/TESTING.md](docs/TESTING.md): what to test, and at which level.

## Layout

```
includes/      PHP, PSR-4 under WordPress\AI (Experiments/, Abilities/, REST/, Settings/ ...)
src/           JS and TS built by wp-scripts into build-scripts/ (entries are listed in webpack.config.js)
routes/        Admin pages built by wp-build into build/
packages/      Script modules (@ai/<name>) built by wp-build into build/
tests/         Integration/ (PHPUnit), e2e/ (Playwright), e2e-testing/ (fixture plugin)
docs/          Developer docs, plus one page per experiment in docs/experiments/
```

## Scripts

Prefer the npm scripts in `package.json` over calling tools directly. The PHP ones run inside the `wp-env` test environment.

```bash
npm run build                        # Build JS and CSS
npm run wp-env:test start            # Start the test environment
npm run lint:php                     # PHPCS
npm run lint:php:stan                # PHPStan
npm run lint:js && npm run typecheck
npm run test:e2e                     # Playwright
npm run test:php                     # PHPUnit
```

Order matters:

- Build before starting the environment or running tests. The plugin won't load without `build/build.php`.
- Run `test:e2e` before `test:php`. PHPUnit reinstalls WordPress on the same test site and wipes the state the E2E tests rely on.

## Code style

- **Keep comments short.** Follow the docblock rules in [CONTRIBUTING.md](CONTRIBUTING.md#general-rules). Don't narrate the next line or record history in comments.
- **Test behavior, not wiring.** Don't add tests that only check a hook is attached, a menu label, or a fixed array. Reuse the existing fixture plugin in `tests/e2e-testing/` before adding a new one. It picks a mocked AI response by matching phrases in the prompt, so rewording a `system-instruction.php` can break E2E tests.

## Pull requests

Fill in every section of the PR template, including the AI disclosure.
