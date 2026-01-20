# Changelog

All notable changes to this project will be documented in this file, per [the Keep a Changelog standard](http://keepachangelog.com/), and will adhere to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased] - TBD

## [0.2.0] – 2026-01-20

### Added
– Core excerpt generation support for AI-powered summaries, including a new Excerpt Generation Experiment with editor UI ([#96](https://github.com/WordPress/ai/pull/96), [#143](https://github.com/WordPress/ai/pull/143)).
- Abilities Explorer — a new admin screen to view and interact with registered AI abilities in the plugin ([#63](https://github.com/WordPress/ai/pull/63)).
– Introduce foundational backend support for Content Summarization and Image Generation experiments (API-only; no UI yet) ([#134](https://github.com/WordPress/ai/pull/134), [#136](https://github.com/WordPress/ai/pull/136)).
– Improve plugin documentation and onboarding with expanded WP.org readme content ([#135](https://github.com/WordPress/ai/pull/135)).
– Add Playground preview support to build and PR workflows using the official WordPress action ([#144](https://github.com/WordPress/ai/pull/144)).

### Changed
– Rely on the Abilities API bundled with WordPress 6.9 and remove the previously bundled dependency (minimum WP version updated) ([#107](https://github.com/WordPress/ai/pull/107)).
– Reorganize Playground blueprints and update demo paths to align with WordPress.org conventions ([#137](https://github.com/WordPress/ai/pull/137)).
– Improve and clarify plugin documentation, descriptions, screenshots, and in-context messaging ([#69](https://github.com/WordPress/ai/pull/69), [#158](https://github.com/WordPress/ai/pull/158), [#161](https://github.com/WordPress/ai/pull/161), [#162](https://github.com/WordPress/ai/pull/162), [#164](https://github.com/WordPress/ai/pull/164)).
– Update and align runtime and development dependencies, including `preact`, `qs`, `express`, and React overrides ([#165](https://github.com/WordPress/ai/pull/165), [#166](https://github.com/WordPress/ai/pull/166), [#171](https://github.com/WordPress/ai/pull/171)).
– Replace custom Plugin Check setup with the official GitHub workflow for more reliable enforcement ([#139](https://github.com/WordPress/ai/pull/139)).

### Fixed
– Resolve UI and messaging issues on the AI Experiments settings screen ([#130](https://github.com/WordPress/ai/pull/130), [#132](https://github.com/WordPress/ai/pull/132)).
– Ensure AI Experiments are visible even when no credentials are configured ([#173](https://github.com/WordPress/ai/pull/173)).
– Fix Plugin Check, linting, and CI failures introduced by updated tooling and workflows ([#150](https://github.com/WordPress/ai/pull/150), [#163](https://github.com/WordPress/ai/pull/163), [#167](https://github.com/WordPress/ai/pull/167), [#176](https://github.com/WordPress/ai/pull/176)).

### Developer
– Cleanup and standardize scaffold, linting, TypeScript, and CI configuration to better align with WordPress Coding Standards ([#172](https://github.com/WordPress/ai/pull/172)).

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
