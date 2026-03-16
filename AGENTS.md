# AI Agent Instructions

WordPress AI Experiments plugin — canonical WordPress plugin for testing AI capabilities.

## References

Read these before making changes:

- [CONTRIBUTING.md](CONTRIBUTING.md) — setup, coding standards, naming conventions, PHPDoc rules, quality checks
- [docs/DEVELOPER_GUIDE.md](docs/DEVELOPER_GUIDE.md) — creating experiments, plugin API, asset loading
- [docs/ARCHITECTURE_OVERVIEW.md](docs/ARCHITECTURE_OVERVIEW.md) — plugin architecture
- [docs/TESTING.md](docs/TESTING.md) — testing strategy
- [docs/TESTING_REST_API.md](docs/TESTING_REST_API.md) — REST API testing
- [docs/EXPERIMENT_LIFECYCLE.md](docs/EXPERIMENT_LIFECYCLE.md) — how experiments graduate toward core

## Workflow

- PHP-related npm scripts wrap `wp-env`; some JavaScript/tooling scripts call `wp-scripts`/`tsc` directly. Prefer `npm run` over direct `composer`, `vendor/bin`, or `wp-env` calls.
- Run `npm run lint:php`, `npm run lint:php:stan`, `npm run lint:js`, and `npm run typecheck` before submitting PRs.
- Use `npm run lint:php:fix` and `npm run lint:js:fix` to auto-fix.

## Helping Contributors Get Started

When a contributor asks for help getting started:

1. Read the relevant doc from the references list based on their topic. If no topic is given, read `CONTRIBUTING.md` and walk them through setup, dev environment, and first experiment creation.
2. Give a concise, actionable answer with exact commands.
3. When asked to set up the project, run: `composer install`, `npm i`, `npm run build`, and `npm run test:e2e:env:start`. Run independent steps in parallel where possible.
