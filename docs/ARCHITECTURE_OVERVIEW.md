
## Architecture Overview

The plugin follows a modular, experiment-based architecture:

```
ai/
├── ai.php                            # Plugin bootstrap
├── build/                            # wp-build route assets
├── build-scripts/                    # wp-scripts built assets
├── docs/                             # Documentation
│   ├── experiments/                  # Experiment specific documentation
│   ├── ARCHITECTURE_OVERVIEW.md      # Architecture Overview
│   ├── DEVELOPER_GUIDE.md            # Developer Guide
│   ├── RELEASE_INSTRUCTIONS.md       # Release Instructions
│   ├── TESTING.md                    # Testing strategy
│   └── TESTING_REST_API.md           # Testing API strategy
├── includes/                         # Core plugin code
│   ├── Abilities/                    # AI Ability implementations (Excerpt, Image, etc.)
│   ├── Abstracts/                    # Base implementations (Abstract_Ability, Abstract_Feature)
│   ├── Contracts/                    # Interfaces (Feature contract)
│   ├── Experiments/                  # Experiment implementations (Abilities_Explorer, etc.)
│   ├── Features/                     # Feature registration and loading (Loader.php, Registry.php, Feature_Category.php)
│   ├── Services/                     # External services (AI_Service)
│   ├── Settings/                     # Plugin settings and admin pages
│   ├── Asset_Loader.php              # Asset loader utility class
│   ├── Deprecated.php                # Backward-compatibility layer for deprecated hooks/filters
│   ├── Main.php                      # Main plugin initialization.
│   ├── Requirements.php              # Plugin requirements checks.
│   └── helpers.php                   # Helper functions
├── src/                              # Source asset files (JS/SCSS)
│   ├── admin/                        # Admin-specific assets
│   ├── experiments/                  # Experiment-specific assets
│   └── index.js                      # Main entry point
└── tests/                            # Tests
    ├── Integration/                  # Integration tests for WordPress + Plugin
    ├── e2e/                          # Playwright end-to-end tests
    ├── e2e-testing/                  # Support plugin for e2e tests (API mocking, fixtures)
    └── bootstrap.php                 # PHPUnit bootstrap
```
