# Custom Abilities

## Summary

The Custom Abilities experiment gates the plugin's WordPress Abilities behind a single toggle. They are standalone abilities that are not tied to a user-facing editor feature. Because they expose site data through the Abilities API and MCP — and the set is expected to grow — they are registered only when a site owner explicitly enables this experiment, rather than always being on.

Since the `wordpress/abilities` package migration, the core-facing abilities (`core/*` and `wp/*`) are provided by the package (as described in [WordPress/ai #1289](https://github.com/WordPress/ai/issues/1289)) and registered through its `Registrar`; the plugin's own utility abilities stay plugin-local.

## At a Glance

- **Type:** Experiment (may change or be removed in any release; try it on a staging site first)
- **Runs without a user action:** Doesn't call your AI provider
- **When it runs:** When an authorized AI tool calls an ability
- **Sent to your AI provider:** Nothing
- **Requests and data:** Lets authorized tools read posts, users, media, terms, and settings

## Overview

### For End Users

When enabled (Settings → AI → **Custom Abilities**), the plugin registers two sets together:

1. The core-facing abilities from the `wordpress/abilities` package: the readonly queries (`core/content-query`, `core/users-query`, `core/settings-get`), the content create/update/delete set, and the experimental `wp/*` read and write set (media, settings, users, terms). The package's Registrar resolves them through the WordPress Abilities API. On WordPress 7.2+, where core itself ships the readonly queries, the package's copies stand down and core's own versions stay in effect; nothing is overridden or duplicated.
2. The plugin's gated utility abilities: `ai/get-post-terms` and the deprecated `ai/get-post-details`.

When disabled, none of these abilities are registered and they are absent everywhere.

The set can change over time and can be extended by other plugins (see [Extending the Experiment](#extending-the-experiment)).

### For Developers

The experiment is intentionally thin and delegates to two sources so abilities can be added without touching the experiment class:

1. **Custom_Abilities** (`includes/Experiments/Custom_Abilities/Custom_Abilities.php`): the experiment class. On `register()` it pulls every gated ability from the registry, registers them, and then registers the core-facing package abilities through the `Registrar`.
2. **Gated_Abilities** (`includes/Abilities/Gated/Gated_Abilities.php`): the registry of the plugin's gated ability classes, filterable via `wpai_gated_abilities`.
3. **Abstract_Gated_Ability** (`includes/Abstracts/Abstract_Gated_Ability.php`): the base class each gated ability wrapper extends. It exposes `register()` and `requires_core_object_exposure()`.
4. **Gated ability wrappers** (`includes/Abilities/Gated/`): one wrapper per plugin-local gated ability.
5. **The `wordpress/abilities` package** (`includes/Vendor/Abilities/`): the vendored snapshot of the core abilities package (`src/**`, its arbitration loader, package metadata, and license). Its `Registrar::register_all()` does the registering, timing (deferred onto `wp_abilities_api_init`), version gating, provenance stamping (`meta.source`, `meta.package_version`, `meta.stability`), category declarations (`content`, `media`, and the rest), and support-feature polyfill loading on hosts that lack the `show_in_abilities` flags. See `includes/Vendor/Abilities/VENDORED.md` for the pinned snapshot and the arbitration contract.

## Architecture & Implementation

### Key Hooks & Entry Points

- `WordPress\AI\Experiments\Custom_Abilities\Custom_Abilities::register()` runs once the experiment is enabled (via the feature Loader, on `init` at priority 15). It:
  - Calls `Gated_Abilities::get_all()` to resolve the plugin's gated ability instances.
  - Loads the package's support polyfills once **if** any gated ability reports `requires_core_object_exposure()` — i.e. it reads curated core objects that must be flagged before the ability registers. A third-party gated ability can rely on this; the built-in utilities do not need it.
  - Calls `register()` on each gated ability, which hooks `wp_abilities_api_init` and calls `wp_register_ability()`.
  - Registers the core-facing package abilities through `Registrar::register_all()`, which defers to `wp_abilities_api_init` the same way and declares the manifest categories during `wp_abilities_api_categories_init`.

Because registration flows through the standard experiment enable gate, all of these inherit the usual behavior: when the experiment is off, `register()` is never called, the `wp_abilities_api_init` listeners are never attached, and no ability — package or plugin-owned — enters the registry (no `wp_unregister_ability()` needed).

### The gated abilities registry

`Gated_Abilities::get_all()` resolves a list of class names into instances, validating each one:

- Non-string entries are skipped with a `_doing_it_wrong()` notice.
- Entries that are not subclasses of `Abstract_Gated_Ability` are skipped with a notice.
- Instantiation failures are caught and reported.

Duplicate class names are de-duplicated. String entries are collected before de-duplication so a malformed filter value (e.g. a nested array) cannot raise an "Array to string conversion" warning.

### How the package resolves core-facing abilities

The package's `Registrar` owns the register-time contract for the core-facing set:

- **Timing:** the registration defers to `wp_abilities_api_init` (and declares its categories at priority 20 on `wp_abilities_api_categories_init`), exactly where the plugin's own copies used to register.
- **Core-provided stand-down:** on WordPress 7.2+, core ships `core/content-query`, `core/users-query`, and `core/settings-get` itself. The Registrar skips anything the registry already provides, so core's versions (and their security fixes) stay in effect and nothing is overridden.
- **Provenance:** every package ability carries `meta.source → wordpress/abilities`, `meta.package_version`, and its stability tier — visible in the Abilities Explorer, REST, and MCP discovery.
- **Multi-copy safe:** the AI plugin and the MCP Adapter companion plugin can each bundle the package. The vendored `wordpress-abilities-loader.php` registers this plugin's copy at plugin load, and the newest registered copy wins for the site. See `VENDORED.md`.

## Extending the Experiment

Third parties register gated abilities with the `wpai_gated_abilities` filter — no other code needs to change. Each entry must be a class-string that extends `Abstract_Gated_Ability`. The core-facing abilities are **not** in that filter's list anymore: they come from the package, and their selection policy is the `wpai_wordpress_abilities_args` filter below.

```php
add_filter(
	'wpai_gated_abilities',
	function ( array $classes ): array {
		// Add a custom gated ability.
		$classes[] = \My_Plugin\Abilities\My_Gated_Ability::class;

		return $classes;
	}
);
```

A gated ability wrapper implements `register()` (to register its underlying ability) and `requires_core_object_exposure()` (return `true` if the ability reads curated core objects — `show_in_abilities`-style flags — that must be marked first, on hosts that lack them).

### Selecting the package abilities

Two filters control the package registration:

- **`wpai_register_wordpress_abilities`** (bool, default `true`): pass `false` to skip the package registration entirely; the plugin's gated abilities still register.
- **`wpai_wordpress_abilities_args`** (array, default `array()`): passed straight to `Registrar::register_all()`. Its `filter` callable is the per-ability selection policy — return `false` to leave an ability out — and `public_only` restricts the set to abilities carrying `meta.public`. Core-provided abilities (WordPress 7.2+) can never be vetoed; the register-time stand-down already covers them.

```php
add_filter(
	'wpai_wordpress_abilities_args',
	function ( array $args ): array {
		// Keep every package ability except the media query, e.g. for a
		// managed site that already exposes media through another channel.
		$args['filter'] = static fn ( string $name ): bool => 'wp/media-query' !== $name;

		return $args;
	}
);
```

The package's own category declarations can be declined with the `wordpress_abilities_declare_categories` filter (package-side contract), leaving a consumer free to declare the categories itself during `wp_abilities_api_categories_init`.

**Boundary change (⚠️ PoC):** the `wpai_gated_abilities` filter keeps working for adding plugin-local gated abilities, but it can no longer remove or replace the core-facing package abilities — their classes no longer exist in the plugin. Removing or replacing package abilities goes through `wpai_wordpress_abilities_args` → the Registrar's `filter` callable. This is a PoC shape for the team to review (see the roadmap in [WordPress/ai #1289](https://github.com/WordPress/ai/issues/1289)).

## Permissions

- Each gated ability keeps its own `permission_callback`, which runs through `WP_Ability::execute()`.
- Package abilities set their own permission floors the same way (`execute_callback` level checks per row, documented per ability in the package's README).
- Where an ability's data is also exposed through a direct helper (rather than through `WP_Ability::execute()`), that helper does **not** run the ability's permission callback. Any caller using such a helper directly is responsible for its own capability checks before exposing the data.

## Testing

### Manual Testing

1. **Enable the experiment:**
   - Go to `Settings → AI`
   - Toggle **Custom Abilities** to enabled
2. **Verify registration:**
   - Open the Abilities Explorer (or query the REST `abilities` endpoints) and confirm the package's core-facing abilities and the plugin's utilities are listed, with the package's provenance meta on the core-facing set.
3. **Verify gating:**
   - Disable the experiment and confirm none of these abilities are registered.
4. **Verify extensibility:**
   - Add a class via the `wpai_gated_abilities` filter and confirm it registers when the experiment is enabled; narrow the package set via `wpai_wordpress_abilities_args` and confirm the change in the Explorer.

## Notes & Considerations

- **Single toggle, all-or-nothing:** enabling the experiment registers every gated ability together. Per-ability toggling is intentionally out of scope here; finer-grained control for the package set is the `wpai_wordpress_abilities_args` filter.
- **Default off:** as a new experiment it defaults to disabled, so these abilities are opt-in. Sites relying on them (e.g. via MCP/agents) must enable the experiment.
- **Polyfills are conditional:** the package's support polyfills (`show_in_abilities`, `setting_show_in_abilities`, `taxonomy_show_in_abilities`) only load on hosts that lack the flag and when a registering ability needs them, so they are not loaded needlessly. The plugin's former standalone `Show_In_Abilities` polyfill class is replaced by the package's polyfills.
