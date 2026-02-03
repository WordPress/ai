
## Architecture Overview

The plugin follows a modular, experiment-based architecture:

```
ai/
├── ai.php                            # Plugin bootstrap
├── build/                            # Built assets
├── docs/                             # Documentation
│   ├── experiments/                  # Experiment specific documentation
│   ├── ARCHITECTURE_OVERVIEW.md      # Architecture Overview
│   ├── DEVELOPER_GUIDE.md            # Developer Guide
│   ├── RELEASE_INSTRUCTIONS.md       # Release Instructions
│   ├── TESTING.md                    # Testing strategy
│   └── TESTING_REST_API.md           # Testing API strategy
├── includes/                         # Core plugin code
│   ├── Abilities/                    # AI Ability implementations (Excerpt, Image, etc.)
│   ├── Abstracts/                    # Base implementations (Abstract_Ability, Abstract_Experiment)
│   ├── Contracts/                    # Interfaces (Experiment contract)
│   ├── Exception/                    # Custom exceptions
│   ├── Experiments/                  # Experiment implementations (Abilities_Explorer, etc.)
│   ├── Services/                     # External services (AI_Service)
│   ├── Settings/                     # Plugin settings and admin pages
│   ├── Asset_Loader.php              # Asset loader utility class
│   ├── bootstrap.php                 # Plugin initialization
│   ├── Experiment_Loader.php         # Experiment loading logic
│   ├── Experiment_Registry.php       # Experiment registration system
│   └── helpers.php                   # Helper functions
├── src/                              # Source asset files (JS/SCSS)
│   ├── admin/                        # Admin-specific assets
│   ├── experiments/                  # Experiment-specific assets
│   └── index.js                      # Main entry point
└── tests/                            # Tests
    ├── Integration/                  # Integration tests for WordPress + Plugin
    ├── e2e/                          # Playwright end-to-end tests
    ├── e2e-request-mocking/          # Mock API calls for e2e tests
    └── bootstrap.php                 # PHPUnit bootstrap
```
