# Vendored: `wordpress/abilities` package

This directory holds a vendored snapshot of the [`wordpress/abilities` package](https://github.com/WordPress/abilities)
(the canonical core WordPress abilities library, currently staged at
`nikmclaughlin/wordpress-abilities`), consumed by this plugin via the package's
`Registrar::register_all()`.

## Pinned snapshot

- **Pinned SHA: `3afb1e7`** on branch `add/trio-trunk-sync` of
  `nikmclaughlin/wordpress-abilities` (2026-11-08).
- **Provenance:** package `main` + open PRs [#12](https://github.com/nikmclaughlin/wordpress-abilities/pull/12)
  (`add/mcp-public-meta` — the `meta.mcp.public` flag on the 8 media/terms abilities),
  [#15](https://github.com/nikmclaughlin/wordpress-abilities/pull/15)
  (`add/trio-show-in-rest` — explicit `show_in_rest` on the `core/` trio backports), and
  [#16](https://github.com/nikmclaughlin/wordpress-abilities/pull/16)
  (`add/trio-trunk-sync` — content/users fixtures refreshed from WordPress trunk r65075/r65002).
  The pinned ref is the single integration ref shared with the sibling MCP-adapter PoC
  (WordPress/mcp-adapter #430).

## What is included / excluded

Included, and nothing else:

- `src/**` — the package's `WordPress\Abilities\` PSR-4 classes (manifest, registrar,
  core-facing ability classes, support polyfills).
- `wordpress-abilities-loader.php` — the optional multi-copy arbitration loader
  (required from `ai.php` at plugin file scope, before any package class use).
- `composer.json`, `LICENSE`.

Excluded: the package's tests, tooling, docs, CI, and config — the vendored set is the
runtime surface only.

## PoC status and swap-out path

This vendoring is a **proof-of-concept dependency** (roadmap step 2 of
[WordPress/ai #1289](https://github.com/WordPress/ai/issues/1289)). It is not the
production dependency shape: the package is still under personal ownership. The named
swap-out path is

1. the package moves to a WordPress-owned repository (roadmap step 3,
   [WordPress/abilities #6](https://github.com/nikmclaughlin/wordpress-abilities/issues/6)),
   then
2. this plugin swaps the vendored snapshot for a Composer `repositories` pin or WP-org
   distribution in a production PR (roadmap step 4).

Until then, refreshing this snapshot is a manual `git archive` from the pinned ref.
**Do not edit files in this directory** — the snapshot is replaced wholesale.

## Arbitration contract (multi-copy rule)

The plugin bundles this package, and the MCP Adapter companion plugin bundles it too —
both active is the expected site configuration. The arbitration loader makes that safe:

- Every bundler requires **its own copy's** `wordpress-abilities-loader.php` at plugin
  file scope and calls `wordpress_abilities_register( '<version>', <own copy root> )`.
- All registrations are recorded; **one** spl autoloader serves the `WordPress\Abilities\`
  PSR-4 prefix from the **newest** registered copy (`version_compare` semantics), decided
  **lazily** at the first class resolution.
- `wordpress_abilities_register()` / `wordpress_abilities_loaded_version()` are a frozen
  public contract: their bodies must stay identical across package versions.
- Once any package class has actually loaded, the winner is fixed; later registrations are
  ignored with a diagnostic notice. Registration at every boot is safe and expected — the
  loader only records the copy; classes load lazily.
