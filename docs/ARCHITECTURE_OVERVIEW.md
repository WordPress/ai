
## Architecture Overview

The plugin follows a modular, experiment-based architecture:

```
ai/
├── ai.php                            # Plugin bootstrap
├── build/                            # Built assets
├── includes/                         # Core plugin code
│   ├── Asset_Loader.php              # Asset loader utility class
│   ├── bootstrap.php                 # Plugin initialization
│   ├── Experiment_Registry.php      # Experiment registration system
│   ├── Experiment_Loader.php         # Experiment loading and initialization
│   ├── Abstracts/                    # Base implementations
│   │   └── Abstract_Experiment.php   # Base experiment class
│   ├── Contracts/                    # Experiment interfaces
│   │   └── Experiment.php            # Experiment contract
│   ├── Exception/                    # Custom exceptions
│   │   ├── Invalid_Experiment_Exception.php
│   │   └── Invalid_Experiment_Metadata_Exception.php
│   └── Experiments/                  # Experiment implementations
│       └── Example_Experiment/       # Each experiment in own directory
│           ├── Example_Experiment.php
│           └── README.md
├── admin/                            # Admin interface (planned)
├── assets/                           # CSS, JS, images
├── docs/                             # Documentation
│   ├── ARCHITECTURE_OVERVIEW.md      # Architecture Overview
│   ├── DEVELOPER_GUIDE.md            # This guide
│   ├── RELEASE_INSTRUCTIONS.md       # Release Instructions
│   ├── TESTING.md                    # Testing strategy
│   └── TESTING_REST_API.md           # Testing API strategy
├── languages/                        # Translation files
├── src/                              # Source asset files that will be built
└── tests/                            # Tests
    ├── Intergation/                  # Unit tests for WordPress + Plugin Interactions
    ├── e2e/                          # Playright tests
    ├── e2e-requst-mocking/           # Mock API calls for playright tests
    └── Unit/                         # Unit tests for Logic Layer
```
