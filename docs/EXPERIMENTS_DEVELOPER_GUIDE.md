# Experiments Developer Guide

Welcome to the WordPress AI Experiments plugin development guide. This document provides everything you need to know to contribute to the plugin or create your own AI-powered experiments.

## Table of Contents

- [Architecture Overview](ARCHITECTURE_OVERVIEW.md)
- [Creating a New Experiment](#creating-a-new-experiment)
- [Plugin API](#plugin-api)
- [Development Workflow](#development-workflow)
- [Additional Resources](#additional-resources)

---

## Creating a New Experiment

Experiments are the core building blocks of the AI plugin. Each experiment represents a distinct AI capability.

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

Create your experiment class by extending `Abstract_Experiment`:

```php
<?php
/**
 * My Experiment implementation.
 *
 * @package WordPress\AI\Experiments
 */

namespace WordPress\AI\Experiments\My_Experiment;

use WordPress\AI\Abstracts\Abstract_Experiment;
use WordPress\AI\Asset_Loader;

/**
 * My Experiment class.
 *
 * @since 0.1.0
 */
class My_Experiment extends Abstract_Experiment {
	/**
	 * Loads experiment metadata.
	 *
	 * @since 0.1.0
	 *
	 * @return array{id: string, label: string, description: string} Experiment metadata.
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'my-experiment',
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

Add your experiment class name to the default experiments list in `Experiment_Loader::get_default_experiments()`:

```php
private function get_default_experiments(): array {
	$experiment_classes = array(
		'WordPress\AI\Experiments\Example_Experiment\Example_Experiment',
		'WordPress\AI\Experiments\My_Experiment\My_Experiment', // Add your experiment
	);

	// ... rest of the method
}
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
use WordPress\AI\Exception\Invalid_Experiment_Metadata_Exception;

class My_Experiment extends Abstract_Experiment {
	public function __construct() {
		if ( ! extension_loaded( 'gd' ) ) {
			throw new Invalid_Experiment_Metadata_Exception(
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

Developers can register their own experiments using the `ai_experiments_register_experiments` action. This is the primary way to add new functionality to the plugin.

```php
add_action( 'ai_experiments_register_experiments', function( $registry ) {
	$registry->register_experiment( new My_Custom_Experiment() );
} );
```

### Filtering Default Experiments

Modify the list of default experiment classes before they are instantiated:

```php
add_filter( 'ai_experiments_default_experiment_classes', function( $experiment_classes ) {
	// Add a custom experiment
	$experiment_classes[] = 'My_Namespace\My_Custom_Experiment';

	// Remove a default experiment
	$key = array_search( 'WordPress\AI\Experiments\Example_Experiment\Example_Experiment', $experiment_classes );
	if ( false !== $key ) {
		unset( $experiment_classes[ $key ] );
	}

	return $experiment_classes;
} );
```

### Disabling an Experiment

Experiments can be disabled using the `ai_experiment_{$experiment_id}_enabled` filter:

```php
add_filter( 'ai_experiments_experiment_example-experiment_enabled', '__return_false' );
```

### Disabling All Experiments

Disable all experiments at once:

```php
add_filter( 'ai_experiments_enabled', '__return_false' );
```

### Other Hooks

The plugin also includes the following action hooks:

- `ai_experiments_register_experiments`: Fires after default experiments are registered, receives `$registry` parameter
- `ai_experiments_initialized`: Fires after all registered experiments have been initialized

### Asset Loading

The plugin provides a utility class for loading assets. This uses `wp-scripts` to build assets which are expected to live within the `src/` directory. They will then be built into the `build/` directory, where the asset loader will look for the files, pulling in the proper dependencies and versioning.

```php
use WordPress\AI\Asset_Loader;

/**
 * Enqueue a script.
 *
 * First argument is the script handle.
 * The second argument is the script file name.
 * This script file name should be in the build/ directory.
 * The source script files should be in the src/ directory. If needed,
 * you can add the entry point to the webpack.config.js file.
 */
Asset_Loader::enqueue_script( 'my-experiment', 'experiments/my-experiment' );

/**
 * Enqueue a style.
 *
 * First argument is the style handle.
 * The second argument is the style file name.
 * This style file name should be in the build/ directory.
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

Create unit tests in `tests/Unit/` for your experiment:

```php
<?php
namespace WordPress\AI\Tests\Unit\Experiments\My_Experiment;

use WordPress\AI\Experiments\My_Experiment\My_Experiment;
use PHPUnit\Framework\TestCase;

class My_Experiment_Test extends TestCase {
	public function test_experiment_metadata() {
		$experiment = new My_Experiment();
		$this->assertEquals( 'my-experiment', $experiment->get_id() );
		$this->assertNotEmpty( $experiment->get_label() );
	}
}
```

### 4. Quality Checks & Testing

Before submitting, ensure all quality checks pass. See [CONTRIBUTING.md](../CONTRIBUTING.md) for the complete list of required checks including:
- Coding standards validation
- Static analysis
- Unit tests

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
- [Example Experiment](../includes/Experiments/Example_Experiment/README.md) - Reference implementation
- [WordPress Plugin Handbook](https://developer.wordpress.org/plugins/)
- [WordPress AI Team](https://make.wordpress.org/ai/)

### Getting Help

- **GitHub Issues**: Report bugs or request features
- **WordPress Slack**: Join the `#core-ai` channel in Slack, see the [WordPress Slack page](https://make.wordpress.org/chat/) for signup information; it is free to join.
- **Make WordPress AI**: https://make.wordpress.org/ai/

---

## License

GPL-2.0-or-later
