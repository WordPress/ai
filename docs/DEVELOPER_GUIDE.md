# Developer Guide

Welcome to the WordPress AI Experiments plugin development guide. This document provides everything you need to know to contribute to the plugin or create your own AI-powered features.

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
├── admin/                            # Admin settings services, controllers, assets
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
	 * Registers hooks that should always run.
	 *
	 * @since 0.1.0
	 */
	protected function register_shared_hooks(): void {
		// Admin settings or dependency checks go here.
	}

	/**
	 * Registers hooks that only run when the feature is enabled.
	 *
	 * @since 0.1.0
	 */
	protected function register_enabled_hooks(): void {
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

Add your feature to the default list returned by `Feature_Loader::get_default_features()`:

```php
private function get_default_features(): array {
	$feature_toggles_provider = apply_filters( 'ai_feature_toggles_service', null );
	$feature_toggles          = $feature_toggles_provider instanceof Feature_Toggles ? $feature_toggles_provider : null;
	$feature_toggles_factory  = $this->resolve_feature_toggles_factory( $feature_toggles_provider );

	$features = array(
		new \WordPress\AI\Features\Example_Feature\Example_Feature( $feature_toggles, $feature_toggles_factory ),
		new \WordPress\AI\Features\My_Feature\My_Feature( $feature_toggles, $feature_toggles_factory ),
	);

	return apply_filters( 'ai_default_features', $features, $feature_toggles, $feature_toggles_factory );
}
```

Third-party developers should prefer the `ai_register_features` or `ai_default_features` hooks described later in this guide so core files don’t need to be modified.

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

## Admin Settings Architecture

The admin settings screen allows site administrators to manage AI Experiments globally and per feature. The PHP services live under `includes/Admin/` and the React application under `src/`.

```
includes/
├── Admin/
│   ├── Admin_Settings_Page.php          # Registers the options page and fallback markup
│   ├── Settings_Page_Assets.php         # Enqueues the React bundle when viewing the page
│   ├── Settings_Payload_Builder.php     # Builds the data passed to the React app
│   └── Settings/
│       ├── Feature_Toggles.php          # Persists per-feature enable/disable state
│       ├── Settings_Renderer.php        # Renders the fallback UI for the toggle section
│       ├── Settings_Registry.php        # Registry of settings sections registered by features
│       ├── Settings_Section.php         # Immutable value object describing a section
│       ├── Settings_Service.php         # Coordinates registration of toggles, sections, and page
│       └── Settings_Toggle.php          # Manages the global experiments option
└── Features/
    └── Traits/
        └── Provides_Settings_Section.php # Helper trait for feature-owned sections

src/
├── admin/
│   └── settings/
│       ├── app.tsx                      # Top-level settings UI
│       └── components/
│           ├── feature-section.tsx      # Card UI for per-feature toggles
│           └── toggle-section.tsx       # Card UI for the global toggle
├── global.d.ts                          # Ambient declaration for the payload on window
├── index.tsx                            # React entry point mounted on the admin page
├── style.scss                           # Styles for the settings screen
└── types.ts                             # Shared payload types
```

`includes/bootstrap.php` wires the settings services on the `init` hook via `initialize_admin_settings()`. That function:

1. Instantiates the toggle, registry, renderer, payload builder, page assets handler, and admin page controller.
2. Registers the shared `Feature_Toggles` service on the `ai_feature_toggles_service` filter. The filter may return an instantiated service, a callable factory, or a `class-string<Feature_Toggles>`, allowing features to resolve the dependency only when they actually need it.
3. Calls `Settings_Service::register()` to hook the global toggle option, expose REST fields, register the admin menu, and trigger section registration with `ai_register_settings_sections`.

Feature settings panels should be registered inside the `ai_register_settings_sections` hook. The `Provides_Settings_Section` trait streamlines the process:

```php
class Example_Feature extends Abstract_Feature {
	use Provides_Settings_Section;

	protected function register_shared_hooks(): void {
		add_action( 'ai_register_settings_sections', array( $this, 'register_settings_sections' ) );
	}

	protected function register_enabled_hooks(): void {
		// Register functional hooks only when enabled.
	}

	public function register_settings_sections( Settings_Registry $registry ): void {
		$this->register_feature_settings_section(
			$registry,
			'example-feature',
			__( 'Example Feature', 'ai' ),
			array( $this, 'render_settings_section' ),
			array(
				'description' => __( 'Demonstration controls for the example feature.', 'ai' ),
				'priority'    => 20,
			)
		);
	}

	public function render_settings_section( Settings_Toggle $toggle, Settings_Section $section ): void {
		// Output fallback markup when JavaScript is unavailable.
	}
}
```

`Settings_Payload_Builder` serializes the registry into a payload consumed by the React app. Each section’s `enabled` state reflects persisted data from `Feature_Toggles`, ensuring the UI mirrors stored values immediately.

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

Modify the list of default feature instances before they are registered:

```php
add_filter( 'ai_default_features', function( $features, $feature_toggles, $feature_toggles_factory ) {
	// Add a custom feature
	$features[] = new My_Namespace\My_Custom_Feature(
		$feature_toggles,
		$feature_toggles_factory
	);

	// Remove the bundled Example Feature
	return array_filter(
		$features,
		static function ( $feature ) {
			return ! $feature instanceof WordPress\AI\Features\Example_Feature\Example_Feature;
		}
	);
}, 10, 3 );
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

### 2. Implement Your Feature

Follow the steps in [Creating a New Feature](#creating-a-new-feature) above to build your feature.

### 3. Write Tests

Create unit tests in `tests/Unit/` for your feature:

```php
<?php
namespace WordPress\AI\Tests\Unit\Features\My_Feature;

use WordPress\AI\Features\My_Feature\My_Feature;
use PHPUnit\Framework\TestCase;

class My_Feature_Test extends TestCase {
	public function test_feature_metadata() {
		$feature = new My_Feature();
		$this->assertEquals( 'my-feature', $feature->get_id() );
		$this->assertNotEmpty( $feature->get_label() );
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
- [Testing Strategy](TESTING.md) - Testing philosophy and guidelines
- [Example Feature](../includes/Features/Example_Feature/README.md) - Reference implementation
- [WordPress Plugin Handbook](https://developer.wordpress.org/plugins/)
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
