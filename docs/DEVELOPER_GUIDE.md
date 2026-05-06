# Developer Guide

Welcome to the AI plugin development guide. This document provides everything you need to know to contribute to the plugin or create your own AI-powered experiments.

## Table of Contents

- [Architecture Overview](ARCHITECTURE_OVERVIEW.md)
- [Creating a New Experiment](#creating-a-new-experiment)
- [Custom Experiment Reference](experiments/custom-experiment-reference.md)
- [Plugin API](#plugin-api)
- [Development Workflow](#development-workflow)
- [Additional Resources](#additional-resources)

---

## Creating a New Experiment

Experiments are the core building blocks of the AI plugin. Each experiment represents a distinct piece of functionality that may utilize AI capabilities.

### Key Design Principles

1. **Encapsulation**: Each experiment is self-contained and can be reviewed independently
2. **Modularity**: Experiments can be added/removed without affecting core functionality
3. **Extensibility**: Third-party developers can register custom experiments via hooks
4. **Standards Compliance**: All code follows WordPress coding standards

### Step 1: Create Experiment Directory

Create a new directory in `includes/Experiments/` for your experiment:

```bash
mkdir -p includes/Experiments/My_Experiment
```

### Step 2: Create Experiment Class

Create your experiment class by extending `Abstract_Feature`:

```php
<?php
/**
 * My Experiment implementation.
 *
 * @package WordPress\AI\Experiments
 */

namespace WordPress\AI\Experiments\My_Experiment;

use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;

/**
 * My Experiment class.
 *
 * @since 0.1.0
 */
class My_Experiment extends Abstract_Feature {
  /**
   * {@inheritDoc}
   */
  public static function get_id(): string {
    return 'my-experiment';
  }

  /**
   * {@inheritDoc}
   */
  protected function load_metadata(): array {
    return array(
    'label'       => __( 'My Experiment', 'ai' ),
    'description' => __( 'Description of what my experiment does.', 'ai' ),
    );
  }

  /**
   * Registers the experiment's hooks and functionality.
   *
   * @since 0.1.0
   */
  public function register(): void {
    // Register your hooks here
    add_action( 'init', array( $this, 'initialize' ) );
    add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    add_filter( 'the_content', array( $this, 'filter_content' ) );
  }

  /**
   * Initializes the experiment.
   *
   * @since 0.1.0
   */
  public function initialize(): void {
    // Experiment initialization logic
  }

  /**
   * Enqueues and localizes the admin script.
   *
   * @since 0.1.0
   *
   * @param string $hook_suffix The current admin page hook suffix.
   */
  public function enqueue_assets( string $hook_suffix ): void {
    Asset_Loader::enqueue_script( 'my-experiment', 'experiments/my-experiment' );
    Asset_Loader::localize_script(
    'my-experiment',
    'MyExperimentData',
    array(
      'enabled' => $this->is_enabled(),
    )
    );
  }

  /**
   * Filters content.
   *
   * @since 0.1.0
   *
   * @param string $content Post content.
   * @return string Modified content.
   */
  public function filter_content( string $content ): string {
    // Experiment logic here
    return $content;
  }
}
```

If you want a complete end-to-end reference instead of a starter snippet, see the [Custom Experiment Reference](experiments/custom-experiment-reference.md). It points to the in-repo `Example_Experiment` implementation and shows a minimal third-party plugin example using the same extension points.

### Step 3: Register the Experiment

Register your experiment class via the `wpai_default_feature_classes` filter. The built-in experiments are registered through the `Experiments` class, but third-party experiments can be added the same way:

```php
add_filter( 'wpai_default_feature_classes', function( $classes ) {
  $classes[ My_Experiment::get_id() ] = My_Experiment::class;
  return $classes;
} );
```

### Step 4: Add Experiment Documentation

Create a `README.md` in your experiment directory:

```markdown
# My Experiment

Brief description of the experiment.

## Functionality

- What the experiment does
- How it works
- Any requirements

## Usage

Examples of how to use the experiment.

## Configuration

Any settings or filters available.
```

### Conditional Experiments

If your experiment has requirements (PHP extensions, other plugins, etc.), implement validation in your constructor:

```php
class My_Experiment extends Abstract_Experiment {
	public function __construct() {
		if ( ! extension_loaded( 'gd' ) ) {
			throw new \RuntimeException(
				__( 'This experiment requires the GD extension.', 'ai' )
			);
		}

    parent::__construct();
  }
}
```

## Plugin API

The plugin provides a set of hooks and filters to allow third-party developers to extend its functionality.

### Registering a Custom Experiment

Developers can register their own experiments using the `wpai_register_features` action. This is the primary way to add new functionality to the plugin.

```php
add_action( 'wpai_register_features', function( $registry ) {
	$registry->register_feature( new My_Custom_Experiment() );
} );
```

### Filtering Default Experiments

Modify the list of default experiment classes before they are instantiated:

```php
add_filter( 'wpai_default_feature_classes', function( $feature_classes ) {
  // Add a custom experiment
  $feature_classes[ My_Custom_Experiment::get_id() ] = My_Custom_Experiment::class;

  // Remove a default experiment
  unset( $feature_classes['example-experiment'] );

  return $feature_classes;
} );
```

### Disabling an Experiment

Experiments can be disabled using the `wpai_feature_{$feature_id}_enabled` filter:

```php
// Disable a specific experiment by its ID
add_filter( 'wpai_feature_example-experiment_enabled', '__return_false' );

// Or with a custom callback
add_filter( 'wpai_feature_example-experiment_enabled', function( $enabled ) {
  // Your custom logic here
  return false;
} );
```

### Disabling All Experiments

Disable all experiments at once:

```php
add_filter( 'wpai_features_enabled', '__return_false' );
```

### Other Hooks

The plugin also includes the following action hooks:

- `wpai_register_features`: Fires after default features are registered, receives `$registry` parameter
- `wpai_features_initialized`: Fires after all registered features have been initialized

### Asset Loading

The plugin provides a utility class for loading assets. This uses `wp-scripts` to build assets which are expected to live within the `src/` directory. They will then be built into the `build-scripts/` directory, where the asset loader will look for the files, pulling in the proper dependencies and versioning.

```php
use WordPress\AI\Asset_Loader;

/**
 * Enqueue a script.
 *
 * First argument is the script handle.
 * The second argument is the script file name.
 * This script file name should be in the build-scripts/ directory.
 * The source script files should be in the src/ directory. If needed,
 * you can add the entry point to the webpack.config.js file.
 */
Asset_Loader::enqueue_script( 'my-experiment', 'experiments/my-experiment' );

/**
 * Enqueue a style.
 *
 * First argument is the style handle.
 * The second argument is the style file name.
 * This style file name should be in the build-scripts/ directory.
 * The source style files should be in the src/ directory. If needed,
 * you can add the entry point to the webpack.config.js file.
 */
Asset_Loader::enqueue_style( 'my-experiment', 'experiments/my-experiment' );

/**
 * Localize a script.
 *
 * First argument is the script handle.
 * The second argument is the data object name.
 * The third argument is the data to localize.
 * In this example, the data will be available in the script as `aiMyExperimentData`.
 */
Asset_Loader::localize_script(
  'my-experiment',
  'MyExperimentData',
  array(
    'my_data' => 'my data',
  )
);
```

## Development Workflow

### 1. Create a Feature Branch

```bash
git checkout -b feature/my-feature-name
```

### 2. Implement Your Experiment

Follow the steps in [Creating a New Experiment](#creating-a-new-experiment) above to build your experiment.

### 3. Write Tests

Add or update tests for your code in the existing test suite under `tests/Integration/`:

```php
<?php
namespace WordPress\AI\Tests\Integration\Experiments\My_Experiment;

use WordPress\AI\Experiments\My_Experiment\My_Experiment;
use WP_UnitTestCase;

class My_Experiment_Test extends WP_UnitTestCase {
  public function test_experiment_metadata() {
    $this->assertEquals( 'my-experiment', My_Experiment::get_id() );

    $experiment = new My_Experiment();
    $this->assertNotEmpty( $experiment->get_label() );
  }
}
```

### 4. Quality Checks & Testing

Before submitting, ensure all quality checks pass. See [CONTRIBUTING.md](../CONTRIBUTING.md) for the complete list of required checks including:
- Coding standards validation
- Static analysis
- Unit tests
- E2E tests

### 5. Submit Pull Request

Push your branch and create a pull request. Follow the contribution guidelines in [CONTRIBUTING.md](../CONTRIBUTING.md) for:
- Branch naming conventions
- Commit message format
- Pull request requirements
- Code review process

## Merge Strategy

This project makes use of squash merges from PR branches to the `develop` branch and as such we've disabled the "Allow merge commits" and "Allow rebase merging" in the repo so that anyone merging will be forced into the "Allow squash merging" approach.

Note that not every commit message should be kept in the resulting squash merge commit message, feel free to strip out unhelpful commit messages to keep the resulting squash merge commit message as concise as possible (e.g. get ride of those "lets try this again" commit messages).

An example of a squash merge from #359 can be seen in 4c9699f, while an example of the prior approach of a merge commit from #311 can be seen in e63d8c0.

---

## Additional Resources

For more detailed information on plugin architecture, creating experiments, and development workflows, see:

- [Contributing Guidelines](../CONTRIBUTING.md) - Code standards and contribution process
- [Architecture Overview](docs/ARCHITECTURE_OVERVIEW.md) - Comprehensive guide to plugin architecture
- [Experiment Lifecycle](EXPERIMENT_LIFECYCLE.md) - Defines how new Experiments land in the plugin and how they could graduate towards WordPress core
- [Testing Strategy](TESTING.md) – Testing philosophy and guidelines
- [Testing REST API Strategy](TESTING_REST_API.md) – Guidelines specific to testing REST API integrations
- [Example Experiment](../includes/Experiments/Example_Experiment/README.md) - Reference implementation
- [Custom Experiment Reference](experiments/custom-experiment-reference.md) - Documented example for extending the plugin
- [Release Instructions](docs/RELEASE_INSTRUCTIONS.md) - Checklist steps for releasing versions of the plugin
