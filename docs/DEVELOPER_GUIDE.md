# Developer Guide

Welcome to the AI plugin development guide. This document provides everything you need to know to contribute to the plugin or create your own AI-powered experiments.

## Table of Contents

- [Architecture Overview](ARCHITECTURE_OVERVIEW.md)
- [Creating a New Experiment](#creating-a-new-experiment)
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

---

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

### Real-World Usage: Abilities API + MCP Adapter

The following examples show how this plugin uses the Abilities API in production code, and how the same abilities are exposed for MCP consumers through ability metadata.

#### 1) Define an ability with schema, execution, permissions, and MCP metadata

In `includes/Abilities/Image/Alt_Text_Generation.php`, the `Alt_Text_Generation` class extends `Abstract_Ability` and defines:

- Input and output schema (`input_schema()`, `output_schema()`)
- Runtime behavior (`execute_callback()`)
- Access control (`permission_callback()`)
- Ability metadata (`meta()`), including MCP metadata

```php
protected function meta(): array {
	return array(
		'show_in_rest' => true,
		'mcp'          => array(
			'public'   => true,
			'type'     => 'tool',
			'category' => 'media',
		),
	);
}
```

This is the key bridge for MCP Adapter compatibility: abilities are declared once, then discoverable/exposable with MCP-specific metadata.

#### 2) Register utility abilities with MCP metadata

In `includes/Abilities/Utilities/Posts.php`, the plugin registers utility abilities such as:

- `ai/get-post-details`
- `ai/get-post-terms`

Both are registered with `wp_register_ability()` and include MCP metadata under `meta.mcp`, for example:

```php
'meta' => array(
	'show_in_rest' => true,
	'mcp'          => array(
		'public' => true,
		'type'   => 'tool',
	),
),
```

This enables the same capability to serve WordPress-native callers and MCP-capable tooling.

#### 3) Compose abilities to build higher-level context

In `includes/helpers.php`, `get_post_context()` resolves registered abilities with `wp_get_ability()` and executes them:

```php
$details_ability = wp_get_ability( 'ai/get-post-details' );
$terms_ability   = wp_get_ability( 'ai/get-post-terms' );
```

The results are normalized and merged into richer context for downstream AI tasks. This demonstrates real orchestration of multiple abilities, not just isolated ability execution.

#### 4) Invoke abilities from editor JavaScript with safe fallback

In `src/utils/run-ability.ts`, the plugin first attempts the client API:

```ts
window.wp?.abilities?.executeAbility?.( ability, input )
```

If unavailable, it falls back to REST:

```ts
/wp-abilities/v1/abilities/${ability}/run
```

This pattern keeps experiments resilient across admin/editor contexts while still using the same ability contract.

#### 5) Use the invocation helper in real features

In `src/utils/generate-alt-text.ts`, the plugin invokes:

```ts
runAbility( 'ai/alt-text-generation', params )
```

This is a concrete production usage path: block/editor context is prepared, ability input is built, and the ability response is mapped to UI behavior.

#### 6) Inspect and test abilities in wp-admin

The Abilities Explorer experiment (`includes/Experiments/Abilities_Explorer/Ability_Handler.php`) uses:

- `wp_get_abilities()` to list capabilities
- `wp_get_ability()` to inspect specific ability definitions
- `execute()` to invoke abilities against user-supplied input

It acts as a practical verification interface for both schema and runtime behavior.

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

---

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

---

## Additional Resources

### Documentation

- [Contributing Guidelines](../CONTRIBUTING.md) - Code standards and contribution process
- [Testing Strategy](TESTING.md) – Testing philosophy and guidelines
- [Testing REST API Strategy](TESTING_REST_API.md) – Guidelines specific to testing REST API integrations
- [Experiment Framework](experiments/experiment-framework.md) - How experiments are registered, toggled, and initialized
- [Multi-Provider Support](experiments/multi-provider-support.md) - Provider detection, model preference, and fallback behavior
- [Title Generation](experiments/title-generation.md) - Deep dive into the title generation experiment and ability
- [Example Experiment](../includes/Experiments/Example_Experiment/README.md) - Reference implementation
- [WordPress Plugin Handbook](https://developer.wordpress.org/plugins/)
- [Experiment Lifecycle](EXPERIMENT_LIFECYCLE.md) - Defines how new Experiments land in the plugin and how they could graduate towards WordPress core
- [WordPress AI Team](https://make.wordpress.org/ai/)

### Getting Help

- **GitHub Issues**: Report bugs or request features
- **WordPress Slack**: Join the `#core-ai` channel in Slack, see the [WordPress Slack page](https://make.wordpress.org/chat/) for signup information; it is free to join.
- **Make WordPress AI**: https://make.wordpress.org/ai/

---

## License

GPL-2.0-or-later

---

<br/><br/><p align="center"><img src="https://s.w.org/style/images/codeispoetry.png?1" alt="Code is Poetry." /></p>
