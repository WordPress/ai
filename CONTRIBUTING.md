# Developer Guide

Welcome to the WordPress AI plugin development guide. This document provides everything you need to know to contribute to the plugin or create your own AI-powered features.

## Table of Contents

- [Getting Started](#getting-started)
- [Architecture Overview](#architecture-overview)
- [Creating a New Feature](#creating-a-new-feature)
- [Plugin API](#plugin-api)
- [Development Workflow](#development-workflow)
- [Testing](#testing)
- [Coding Standards](#coding-standards)
- [Contributing](#contributing)

---

## Getting Started

### Prerequisites

- PHP 7.4 or higher
- WordPress 6.7 or higher
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

3. **Link to WordPress:**

Symlink the plugin directory into your WordPress installation:

```bash
ln -s /path/to/ai /path/to/wordpress/wp-content/plugins/ai
```

4. **Activate the plugin:**

Through WordPress admin or via WP-CLI:

```bash
wp plugin activate ai
```

---

## Architecture Overview

The plugin follows a modular, feature-based architecture:

```
ai/
├── ai.php                      # Plugin bootstrap
├── includes/                   # Core plugin code
│   ├── Plugin.php              # Main plugin coordinator
│   ├── Feature_Registry.php   # Feature registration system
│   ├── Interfaces/             # Feature contracts
│   └── Abstracts/              # Base implementations
├── features/                   # Feature implementations
│   └── Example_Feature/        # Each feature in own directory
├── admin/                      # Admin interface (Issue #25)
├── assets/                     # CSS, JS, images
├── languages/                  # Translation files
└── tests/                      # PHPUnit tests
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

Create a new directory in `features/` for your feature:

```bash
mkdir -p features/my-feature
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
	 * Constructor.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		$this->id          = 'my-feature';
		$this->label       = __( 'My Feature', 'ai' );
		$this->description = __( 'Description of what my feature does.', 'ai' );
	}

	/**
	 * Registers the feature's hooks and functionality.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		// Register your hooks here
		$this->add_action( 'init', array( $this, 'initialize' ) );
		$this->add_filter( 'the_content', array( $this, 'filter_content' ) );
	}

	/**
	 * Initialize the feature.
	 *
	 * @since 0.1.0
	 */
	public function initialize(): void {
		// Feature initialization logic
	}

	/**
	 * Filter content.
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

Register your feature in `includes/Feature_Registry.php`:

```php
private function register_default_features(): void {
	// Register your feature
	if ( class_exists( 'WordPress\AI\Features\My_Feature\My_Feature' ) ) {
		$this->register_feature( new \WordPress\AI\Features\My_Feature\My_Feature() );
	}

	// Allow third-party registration
	do_action( 'ai_register_features', $this );
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

If your feature has requirements (PHP extensions, other plugins, etc.), implement the `Conditional_Feature` interface:

```php
use WordPress\AI\Interfaces\Conditional_Feature;

class My_Feature extends Abstract_Feature implements Conditional_Feature {
	public function meets_requirements(): bool {
		// Check if requirements are met
		return extension_loaded( 'gd' );
	}

	public function get_requirements_message(): string {
		return __( 'This feature requires the GD extension.', 'ai' );
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

### Disabling a Feature

Features can be disabled using the `ai_feature_enabled` filter. This is useful for site administrators who want to turn off specific features.

```php
add_filter( 'ai_feature_enabled', function( $enabled, $feature_id ) {
	if ( 'example-feature' === $feature_id ) {
		return false;
	}
	return $enabled;
}, 10, 2 );
```

### Other Hooks and Filters

The plugin also includes the following hooks:

- `ai_plugin_initialized`: Fires after the main plugin class has been initialized.
- `ai_features_initialized`: Fires after all registered features have been initialized.

---

## Development Workflow

### 1. Create a Feature Branch

```bash
git checkout -b feature/my-feature-name
```

### 2. Write Your Code

Follow WordPress coding standards and ensure proper documentation.

### 3. Write Tests

Create unit tests in `tests/phpunit/features/`:

```php
<?php
namespace WordPress\AI\Tests\Features;

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

Push your branch and create a pull request on GitHub.

---

## Testing

### Running Unit Tests

```bash
composer test
```

### Setting Up WordPress Test Library

The plugin uses the WordPress PHPUnit test library. Set it up:

```bash
# Install WordPress tests
bash bin/install-wp-tests.sh wordpress_test root password localhost latest

# Run tests
composer test
```

### Writing Tests

Tests should cover:
- Feature registration
- Hook registration
- Core functionality
- Edge cases and error handling

Example test structure:

```php
class My_Feature_Test extends WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		// Setup code
	}

	public function test_feature_initializes() {
		// Test code
		$this->assertTrue( true );
	}

	public function tearDown(): void {
		// Cleanup code
		parent::tearDown();
	}
}
```

---

## Coding Standards

### WordPress Coding Standards

All code must follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/).

**Check standards:**
```bash
composer lint
```

**Auto-fix issues:**
```bash
composer format
```

### PHP Version Compatibility

The plugin supports PHP 7.4+. Ensure your code is compatible:

```bash
composer lint  # Includes PHPCompatibility checks
```

### Documentation Standards

- All classes and methods must have PHPDoc blocks
- Use `@since` tags for versioning
- Document parameters and return types
- Add inline comments for complex logic

### Internationalization

All user-facing strings must be translatable:

```php
// Good
__( 'Hello World', 'ai' );
_e( 'Hello World', 'ai' );
esc_html__( 'Hello World', 'ai' );

// Bad
echo 'Hello World';
```

---

## Contributing

### Contribution Workflow

1. **Find or create an issue** on GitHub
2. **Fork the repository** and create a feature branch
3. **Write your code** following the standards above
4. **Write tests** for your changes
5. **Run linting and tests** to ensure quality
6. **Create a pull request** with a clear description
7. **Respond to review feedback**

### Pull Request Guidelines

**Title Format:**
```
Add: Brief description of feature
Fix: Brief description of bug fix
Update: Brief description of improvement
```

**Description Should Include:**
- What the change does
- Why the change is needed
- How to test the change
- Screenshots (if UI changes)
- Related issues

**Example:**
```markdown
## Description
Adds AI-powered alt text generation for images

## Why
Improves accessibility by automatically generating descriptive alt text

## Testing
1. Upload an image
2. Verify alt text is generated
3. Check quality of generated text

Closes #123
```

### Code Review Process

All contributions go through code review:
1. Automated checks (linting, tests)
2. Architecture review
3. Code quality review
4. Documentation review
5. Testing verification

---

## Additional Resources

### Documentation

- [Example Feature](features/Example_Feature/README.md) - Reference implementation
- [WordPress Plugin Handbook](https://developer.wordpress.org/plugins/)
- [WordPress AI Team](https://make.wordpress.org/ai/)

---

## Getting Help

- **GitHub Issues**: Report bugs or request features
- **WordPress Slack**: Join #core-ai channel
- **Make WordPress AI**: https://make.wordpress.org/ai/

---

## License

GPL-2.0-or-later

---

**Happy coding! 🚀**
