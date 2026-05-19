# Experiment Framework

## Summary

The Experiment Framework is the plugin's opt-in architecture for shipping AI functionality as isolated, toggleable units. Experiments are implemented as Feature classes, registered through a shared loader/registry system, and initialized only when global AI functionality and experiment-specific settings are enabled.

## Overview

### For End Users

The framework powers the Settings > AI experience where users can:

- Globally enable or disable AI functionality.
- Turn individual experiments on or off independently.
- Combine only the capabilities needed for a site.

This makes experimentation safer by reducing unintended runtime behavior and allowing incremental adoption.

### For Developers

The framework standardizes experiment development by requiring:

- A unique feature ID (`get_id()`).
- Metadata (`label`, `description`, optional `category`, `stability`, `image`).
- A `register()` method that wires hooks only when enabled.

It also supports extension points for third-party plugins to register custom features/experiments and override defaults.

## Architecture & Lifecycle

### Core Components

- **`Abstract_Feature`** (`includes/Abstracts/Abstract_Feature.php`)
  - Base implementation for metadata, enablement checks, and shared settings conventions.
- **`Registry`** (`includes/Features/Registry.php`)
  - Stores feature instances and exposes feature lookups.
- **`Loader`** (`includes/Features/Loader.php`)
  - Instantiates default features, runs registration hooks, and initializes enabled items.
- **`Experiments` bootstrap** (`includes/Experiments/Experiments.php`)
  - Adds built-in experiment classes to `wpai_default_feature_classes`.

### Registration Flow

1. `Experiments::init()` hooks `wpai_default_feature_classes`.
2. `Loader` resolves default features and applies `wpai_default_feature_classes`.
3. Feature classes are validated and instantiated.
4. Instances are stored in `Registry`.
5. `wpai_register_features` fires, allowing third-party registration.

### Initialization Flow

1. `Loader::initialize_features()` checks global filter `wpai_features_enabled`.
2. Each registered feature checks `is_enabled()`:
   - Global option (`wpai_features_enabled`).
   - Feature option (`wpai_feature_{id}_enabled`).
   - Feature-specific enablement filter.
3. Enabled features run `register()` and wire runtime hooks.
4. `wpai_features_initialized` fires after initialization.

## Stability Model

The framework supports explicit stability levels:

- `experimental`
- `stable`
- `deprecated`

Stability influences how functionality is surfaced in admin UI and grouped in status views. Experiments normally default to `experimental` unless explicitly set otherwise in metadata.

## Extensibility

### Registering a Custom Experiment

You can register your own class through `wpai_register_features`:

```php
add_action( 'wpai_register_features', function( $registry ) {
    $registry->register_feature( new My_Custom_Experiment() );
} );
```

### Adding/Replacing Default Feature Classes

You can alter default class registration:

```php
add_filter( 'wpai_default_feature_classes', function( $classes ) {
    $classes[ My_Custom_Experiment::get_id() ] = My_Custom_Experiment::class;
    return $classes;
} );
```

### Overriding Enablement

Disable one experiment:

```php
add_filter( 'wpai_feature_title-generation_enabled', '__return_false' );
```

Disable all features/experiments:

```php
add_filter( 'wpai_features_enabled', '__return_false' );
```

## When to Use This Framework

Use this framework for any plugin capability that should:

- Be independently toggleable.
- Register its hooks lazily only when needed.
- Follow shared metadata/settings conventions.
- Stay isolated for experimentation and potential future promotion.

For broader policy and graduation criteria, see `docs/FEATURE_EXPERIMENT_LIFECYCLE.md`.

## Related Files

- `includes/Abstracts/Abstract_Feature.php`
- `includes/Features/Registry.php`
- `includes/Features/Loader.php`
- `includes/Experiments/Experiments.php`
- `docs/FEATURE_EXPERIMENT_LIFECYCLE.md`
