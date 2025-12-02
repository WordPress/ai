# Changelog

All notable changes to this project will be documented in this file, per [the Keep a Changelog standard](http://keepachangelog.com/), and will adhere to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased] - TBD

## [0.2.0] - YYYY-MM-DD

## [0.1.1] - 2025-12-01
### Added
- Action link to the plugin list table (#98).
- WordPress Playground live preview integration (#85).

### Changed
- Updated namespace to `ai_experiments` (#111).
- Bumped WP AI Client from dev-trunk to 0.1.1 (#118, #122).
- Updated format script to only format JS to avoid random JSON file changes (#114).

### Removed
- Credentials check from `is_enabled` check (#120).
- Example Experiment registration (#121).

### Fixed
- Bug in asset loader causing missing dependencies, lack of RTL support, lack of inlining for performance (#113).

### Security
- Bumped `js-yaml` from 3.14.1 to 3.14.2 (#105).

### Developer
- Updated documentation (#108, #112).

## [0.1.0] - 2025-11-26
First public release of the AI Experiments plugin, introducing a framework for exploring experimental AI-powered features in WordPress. 🎉

### Added
- Experiment registry and loader system for managing AI features
- Abstract experiment base class for consistent feature development
- Experiment: Title Generation
- Basic admin settings screen with toggle support
- Initial integration with WP AI Client SDK and Abilities API
- Utilities Ability for common AI tasks and testing

[Unreleased]: https://github.com/wordpress/ai/compare/trunk...develop
[0.2.0]: https://github.com/wordpress/ai/compare/0.1.1...0.2.0
[0.1.1]: https://github.com/wordpress/ai/compare/0.1.0...0.1.1
[0.1.0]: https://github.com/wordpress/ai/tree/0.1.0
