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

- `ai.php` bootstraps the plugin and defers to `includes/bootstrap.php`.
- `includes/` contains runtime PHP, with `Features/` housing feature classes and `Admin/` holding the settings page plus shared services such as `Feature_Toggles`.
- `src/` bundles the React settings UI; its compiled output lives under `build/`.
- `tests/` mirrors the PHP namespaces for integration coverage, while `docs/` captures the authoring notes you’re reading now.

### Key Design Principles

1. **Encapsulation**: Each feature is self-contained and can be reviewed independently
2. **Modularity**: Features can be added/removed without affecting core functionality
3. **Extensibility**: Third-party developers can register custom features via hooks
4. **Standards Compliance**: All code follows WordPress coding standards

---

## Creating a New Feature

- Scaffold a directory under `includes/Features/Your_Feature`.
- Extend `Abstract_Feature`, add metadata via `load_feature_metadata()`, and set up hooks inside `register_shared_hooks()` / `register_enabled_hooks()`.
- Call `register_feature_settings_section()` (from `Provides_Settings_Section`) if the feature needs admin controls.
- Register through `ai_default_features` or `ai_register_features` filters and include a short README near the class so reviewers know what it does.

## Admin Settings In Short

- `initialize_admin_settings()` instantiates the toggle, feature toggles, registry, and `Admin_Settings_Page`, then wires a handful of hooks (`ai_feature_toggles_service`, `ai_features_enabled`, `admin_init`, `rest_api_init`, `admin_menu`, and `ai_register_settings_sections`).
- Features expose settings panels inside the `ai_register_settings_sections` action. Most implementations should reuse the `Provides_Settings_Section` trait so they get consistent badges, assets metadata, and default-state handling.
- The settings page controller handles everything else: registering the submenu, hydrating the React app, rendering the fallback form, and enqueueing assets only on its screen.

Need more depth? The Example Feature README in `includes/Features/Example_Feature/README.md` covers conditional feature guards, hook usage (`ai_register_features`, `ai_default_features`, `ai_feature_enabled`, etc.), and REST helpers. Refer to it (or keep your own feature README) instead of duplicating long-form docs here.

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
