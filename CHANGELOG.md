# Changelog

All notable changes to this project will be documented in this file, per [the Keep a Changelog standard](http://keepachangelog.com/), and will adhere to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased] - TBD

## [0.1.1] - 2025-12-01
### Added
- Link to the plugin settings screen from the plugin list table ([#98](https://github.com/WordPress/ai/pull/98)).
- WordPress Playground live preview integration ([#85](https://github.com/WordPress/ai/pull/85)).
- RTL language support and inlining for performance ([#113](https://github.com/WordPress/ai/pull/113)).

### Changed
- Updated namespace to `ai_experiments` ([#111](https://github.com/WordPress/ai/pull/111)).
- Bumped WP AI Client from `dev-trunk` to 0.2.0 ([#118](https://github.com/WordPress/ai/pull/118), [#122](https://github.com/WordPress/ai/pull/122), [#125](https://github.com/WordPress/ai/pull/125)).

### Removed
- Valid AI credentials check from the Experiment `is_enabled` check ([#120](https://github.com/WordPress/ai/pull/120)).
- Example Experiment registration ([#121](https://github.com/WordPress/ai/pull/121)).

### Fixed
- Bug in asset loader causing missing dependencies ([#113](https://github.com/WordPress/ai/pull/113)).

### Security
- Bumped `js-yaml` from 3.14.1 to 3.14.2 ([#105](https://github.com/WordPress/ai/pull/105)).

### Developer
- Updated format script to only format JS to avoid random JSON file changes ([#114](https://github.com/WordPress/ai/pull/114)).
- Updated documentation ([#108](https://github.com/WordPress/ai/pull/108), [#112](https://github.com/WordPress/ai/pull/112)).

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
[0.1.1]: https://github.com/wordpress/ai/compare/0.1.0...0.1.1
[0.1.0]: https://github.com/wordpress/ai/tree/0.1.0
