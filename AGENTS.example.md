# AGENTS.md

Start with [CONTRIBUTING.md](CONTRIBUTING.md). Read the others when the task needs them:

- [docs/DEVELOPER_GUIDE.md](docs/DEVELOPER_GUIDE.md): adding an experiment, hooks, asset loading.
- [docs/ARCHITECTURE_OVERVIEW.md](docs/ARCHITECTURE_OVERVIEW.md): the full directory tree.
- [docs/TESTING.md](docs/TESTING.md): what to test, and at which level.

## Layout

```
includes/      PHP, PSR-4 under WordPress\AI (Experiments/, Abilities/, REST/, Settings/ ...)
src/           JS and TS built by wp-scripts
routes/        Admin pages built by wp-build
tests/         Integration/ (PHPUnit), e2e/ (Playwright), e2e-testing/ (fixture plugin)
docs/          Developer docs, plus one page per experiment in docs/experiments/
```

## Scripts

Prefer the npm scripts in `package.json` over calling tools directly. The PHP ones run inside the `wp-env` test environment.

```bash
npm run wp-env:test start            # Start the test environment
npm run build                        # Build JS and CSS
npm run lint:php                     # PHPCS
npm run lint:php:stan                # PHPStan
npm run lint:js && npm run typecheck
npm run test:php                     # PHPUnit
npm run test:e2e                     # Playwright
```

## Code style

- **Keep comments short.** A docblock is the one-line summary plus the tags [CONTRIBUTING.md](CONTRIBUTING.md#documentation-standards) requires. Comment only a non-obvious why, in one sentence. Don't restate the code, narrate the next line, or record history.
- **Test behavior, not wiring.** Don't add tests that only check a hook is attached, a menu label, or a fixed array. Reuse the existing fixture plugin in `tests/e2e-testing/` before adding a new one.

## Pull requests

Fill in every section of the PR template, including the AI disclosure.
