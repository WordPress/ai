# Custom Abilities

## Summary

The Custom Abilities experiment gates the plugin's general-purpose WordPress Abilities behind a single toggle. These are standalone abilities that are not tied to a user-facing editor feature. Because they expose site data through the Abilities API and MCP — and the set is expected to grow — they are registered only when a site owner explicitly enables this experiment, rather than always being on.

## Overview

### For End Users

When enabled (Settings → AI → **Custom Abilities**), the plugin registers its gated abilities so they become available to the WordPress Abilities API, the REST `abilities` endpoints, the Abilities Explorer, and MCP clients. When disabled, none of these abilities are registered and they are absent everywhere.

The exact set of gated abilities can change over time and can be extended by other plugins (see [Extending the Experiment](#extending-the-experiment)).

### For Developers

The experiment is intentionally thin and delegates to a small registry so new abilities can be gated without touching the experiment class:

1. **Custom_Abilities** (`includes/Experiments/Custom_Abilities/Custom_Abilities.php`): the experiment class. On `register()` it pulls every gated ability from the registry and registers them together.
2. **Gated_Abilities** (`includes/Abilities/Gated/Gated_Abilities.php`): the registry of gated ability classes, filterable via `wpai_gated_abilities`.
3. **Abstract_Gated_Ability** (`includes/Abstracts/Abstract_Gated_Ability.php`): the base class each gated ability wrapper extends. It exposes `register()` and `requires_core_object_exposure()`.
4. **Gated ability wrappers** (`includes/Abilities/Gated/`): one wrapper per gated ability. New wrappers are added here (and to the registry) as more abilities are placed behind the gate.

## Architecture & Implementation

### Key Hooks & Entry Points

- `WordPress\AI\Experiments\Custom_Abilities\Custom_Abilities::register()` runs once the experiment is enabled (via the feature Loader). It:
  - Calls `Gated_Abilities::get_all()` to resolve the gated ability instances.
  - Registers `Show_In_Abilities` once **if** any gated ability reports `requires_core_object_exposure()` — i.e. it reads curated core objects that must be marked before the ability registers.
  - Calls `register()` on each gated ability, which hooks `wp_abilities_api_init` and calls `wp_register_ability()`.

Because registration flows through the standard experiment enable gate, the abilities inherit the usual behavior: when the experiment is off, `register()` is never called, the `wp_abilities_api_init` listeners are never attached, and the abilities never enter the registry (no `wp_unregister_ability()` needed).

### The gated abilities registry

`Gated_Abilities::get_all()` resolves a list of class names into instances, validating each one:

- Non-string entries are skipped with a `_doing_it_wrong()` notice.
- Entries that are not subclasses of `Abstract_Gated_Ability` are skipped with a notice.
- Instantiation failures are caught and reported.

Duplicate class names are de-duplicated. String entries are collected before de-duplication so a malformed filter value (e.g. a nested array) cannot raise an "Array to string conversion" warning.

## Extending the Experiment

Third parties can add or remove gated abilities with the `wpai_gated_abilities` filter — no other code needs to change. Each entry must be a class-string that extends `Abstract_Gated_Ability`.

```php
add_filter(
	'wpai_gated_abilities',
	function ( array $classes ): array {
		// Add a custom gated ability.
		$classes[] = \My_Plugin\Abilities\My_Gated_Ability::class;

		// Remove a gated ability by its class-string.
		return array_values(
			array_filter(
				$classes,
				static fn ( $class ) => \Some\Class\To_Remove::class !== $class
			)
		);
	}
);
```

A gated ability wrapper implements `register()` (to register its underlying ability) and `requires_core_object_exposure()` (return `true` if the ability reads curated core objects that `Show_In_Abilities` must mark first).

## Permissions

- Each gated ability keeps its own `permission_callback`, which runs through `WP_Ability::execute()`.
- Where an ability's data is also exposed through a direct helper (rather than through `WP_Ability::execute()`), that helper does **not** run the ability's permission callback. Any caller using such a helper directly is responsible for its own capability checks before exposing the data.

## Testing

### Manual Testing

1. **Enable the experiment:**
   - Go to `Settings → AI`
   - Toggle **Custom Abilities** to enabled
2. **Verify registration:**
   - Open the Abilities Explorer (or query the REST `abilities` endpoint) and confirm the plugin's gated abilities are listed.
3. **Verify gating:**
   - Disable the experiment and confirm those abilities are no longer registered.
4. **Verify extensibility:**
   - Add a class via the `wpai_gated_abilities` filter and confirm it registers when the experiment is enabled.

### Automated Testing

- `tests/Integration/Includes/Experiments/Custom_Abilities/Custom_AbilitiesTest.php`

## Notes & Considerations

- **Single toggle, all-or-nothing:** enabling the experiment registers every gated ability together. Per-ability toggling is intentionally out of scope here; finer-grained control can layer on later.
- **Default off:** as a new experiment it defaults to disabled, so these abilities are opt-in. Sites relying on them (e.g. via MCP/agents) must enable the experiment.
- **`Show_In_Abilities` is conditional:** it only runs when a gated ability needs curated core objects exposed, so it is not loaded needlessly.
