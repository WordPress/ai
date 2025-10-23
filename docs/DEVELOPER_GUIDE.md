# Developer Guide

Welcome to the WordPress AI plugin development guide. This document provides everything you need to know to contribute to the plugin or create your own AI-powered features.

## Table of Contents

- [Getting Started](#getting-started)
- [Architecture Overview](#architecture-overview)
- [Creating a New Feature](#creating-a-new-feature)
- [Plugin API](#plugin-api)
- [Development Workflow](#development-workflow)
- [Additional Resources](#additional-resources)

---

## Getting Started

### Prerequisites

- PHP 7.4 or higher
- WordPress 6.8 or higher
- Composer
- Node.js and npm (for asset building)

### Local Development Setup

1. **Clone the repository:**

```bash
git clone https://github.com/WordPress/ai.git
cd ai
```

2. **Install dependencies:**

```bash
composer install
```

> **Note:** The `wordpress/wp-ai-client` package will be added to `composer.json` once it's officially released. For now, the plugin scaffolding is ready for integration.

3. **Activate the plugin:**

Through WordPress admin or via WP-CLI:

```bash
wp plugin activate ai
```

---

## Architecture Overview

The plugin follows a modular, feature-based architecture:

```
ai/
├── ai.php                            # Plugin bootstrap
├── includes/                         # Core plugin code
│   ├── bootstrap.php                 # Plugin initialization
│   ├── Feature_Registry.php         # Feature registration system
│   ├── Feature_Loader.php            # Feature loading and initialization
│   ├── Abstracts/                    # Base implementations
│   │   └── Abstract_Feature.php      # Base feature class
│   ├── Contracts/                    # Feature interfaces
│   │   └── Feature.php               # Feature contract
│   ├── Exception/                    # Custom exceptions
│   │   ├── Invalid_Feature_Exception.php
│   │   └── Invalid_Feature_Metadata_Exception.php
│   └── Features/                     # Feature implementations
│       └── Example_Feature/          # Each feature in own directory
│           ├── Example_Feature.php
│           └── README.md
├── admin/                            # Admin interface (planned)
├── assets/                           # CSS, JS, images
├── docs/                             # Documentation
│   ├── DEVELOPER_GUIDE.md            # This guide
│   └── TESTING.md                    # Testing strategy
├── languages/                        # Translation files
└── tests/                            # PHPUnit tests
    └── Unit/                         # Unit tests
```

### Key Design Principles

1. **Encapsulation**: Each feature is self-contained and can be reviewed independently
2. **Modularity**: Features can be added/removed without affecting core functionality
3. **Extensibility**: Third-party developers can register custom features via hooks
4. **Standards Compliance**: All code follows WordPress coding standards

---

## Creating a New Feature

Features are the core building blocks of the AI plugin. Each feature represents a distinct AI capability.

### Step 1: Create Feature Directory

Create a new directory in `includes/Features/` for your feature:

```bash
mkdir -p includes/Features/My_Feature
```

### Step 2: Create Feature Class

Create your feature class by extending `Abstract_Feature`:

```php
<?php
/**
 * My Feature implementation.
 *
 * @package WordPress\AI\Features
 */

namespace WordPress\AI\Features\My_Feature;

use WordPress\AI\Abstracts\Abstract_Feature;

/**
 * My Feature class.
 *
 * @since 0.1.0
 */
class My_Feature extends Abstract_Feature {
	/**
	 * Loads feature metadata.
	 *
	 * @since 0.1.0
	 *
	 * @return array{id: string, label: string, description: string} Feature metadata.
	 */
	protected function load_feature_metadata(): array {
		return array(
			'id'          => 'my-feature',
			'label'       => __( 'My Feature', 'ai' ),
			'description' => __( 'Description of what my feature does.', 'ai' ),
		);
	}

	/**
	 * Registers the feature's hooks and functionality.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		// Register your hooks here
		add_action( 'init', array( $this, 'initialize' ) );
		add_filter( 'the_content', array( $this, 'filter_content' ) );
	}

	/**
	 * Initializes the feature.
	 *
	 * @since 0.1.0
	 */
	public function initialize(): void {
		// Feature initialization logic
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
		// Feature logic here
		return $content;
	}
}
```

### Step 3: Register the Feature

Add your feature class name to the default features list in `Feature_Loader::get_default_features()`:

```php
private function get_default_features(): array {
	$feature_classes = array(
		'WordPress\AI\Features\Example_Feature\Example_Feature',
		'WordPress\AI\Features\My_Feature\My_Feature', // Add your feature
	);

	// ... rest of the method
}
```

### Step 4: Add Feature Documentation

Create a `README.md` in your feature directory:

```markdown
# My Feature

Brief description of the feature.

## Functionality

- What the feature does
- How it works
- Any requirements

## Usage

Examples of how to use the feature.

## Configuration

Any settings or filters available.
```

### Conditional Features

If your feature has requirements (PHP extensions, other plugins, etc.), implement validation in your constructor:

```php
use WordPress\AI\Exception\Invalid_Feature_Metadata_Exception;

class My_Feature extends Abstract_Feature {
	public function __construct() {
		if ( ! extension_loaded( 'gd' ) ) {
			throw new Invalid_Feature_Metadata_Exception(
				__( 'This feature requires the GD extension.', 'ai' )
			);
		}

		parent::__construct();
	}
}
```

---

## Plugin API

The plugin provides a set of hooks and filters to allow third-party developers to extend its functionality.

### Registering a Custom Feature

Developers can register their own features using the `ai_register_features` action. This is the primary way to add new functionality to the plugin.

```php
add_action( 'ai_register_features', function( $registry ) {
	$registry->register_feature( new My_Custom_Feature() );
} );
```

### Filtering Default Features

Modify the list of default feature classes before they are instantiated:

```php
add_filter( 'ai_default_feature_classes', function( $feature_classes ) {
	// Add a custom feature
	$feature_classes[] = 'My_Namespace\My_Custom_Feature';

	// Remove a default feature
	$key = array_search( 'WordPress\AI\Features\Example_Feature\Example_Feature', $feature_classes );
	if ( false !== $key ) {
		unset( $feature_classes[ $key ] );
	}

	return $feature_classes;
} );
```

### Disabling a Feature

Features can be disabled using the `ai_feature_enabled` filter:

```php
add_filter( 'ai_feature_enabled', function( $enabled, $feature_id ) {
	if ( 'example-feature' === $feature_id ) {
		return false;
	}
	return $enabled;
}, 10, 2 );
```

### Disabling All Features

Disable all features at once:

```php
add_filter( 'ai_features_enabled', '__return_false' );
```

### Other Hooks

The plugin also includes the following action hooks:

- `ai_register_features`: Fires after default features are registered, receives `$registry` parameter
- `ai_features_initialized`: Fires after all registered features have been initialized

---

## Development Workflow

### 1. Create a Feature Branch

```bash
git checkout -b feature/my-feature-name
```

### 2. Write Your Code

Follow WordPress coding standards and ensure proper documentation.

### 3. Write Tests

Create integration tests in `tests/Integration/Features/`:

```php
<?php
namespace WordPress\AI\Tests\Integration\Features\My_Feature;

use WordPress\AI\Features\My_Feature\My_Feature;
use WP_UnitTestCase;

class My_Feature_Test extends WP_UnitTestCase {
	public function test_feature_registration() {
		$feature = new My_Feature();
		$this->assertEquals( 'my-feature', $feature->get_id() );
	}
}
```

### 4. Run Tests and Linting

```bash
# Check coding standards
composer lint

# Run static analysis
composer stan

# Auto-fix coding standards
composer format

# Run tests
composer test
```

### 5. Commit Your Changes

```bash
git add .
git commit -m "Add My Feature for AI-powered content generation"
```

### 6. Create Pull Request

Push your branch and create a pull request on GitHub. See [CONTRIBUTING.md](../CONTRIBUTING.md) for detailed contribution guidelines.

---

## Additional Resources

### Documentation

- [Contributing Guidelines](../CONTRIBUTING.md) - Code standards and contribution process
- [Testing Strategy](TESTING.md) - Testing philosophy and guidelines
- [Example Feature](../includes/Features/Example_Feature/README.md) - Reference implementation
- [WordPress Plugin Handbook](https://developer.wordpress.org/plugins/)
- [WordPress AI Team](https://make.wordpress.org/ai/)

### Getting Help

- **GitHub Issues**: Report bugs or request features
- **WordPress Slack**: Join #core-ai channel
- **Make WordPress AI**: https://make.wordpress.org/ai/

---

## License

GPL-2.0-or-later

---

**Happy coding! 🚀**