# Changelog

All notable changes to this project will be documented in this file, per [the Keep a Changelog standard](http://keepachangelog.com/), and will adhere to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased] - TBD

## [0.6.0] - 2026-03-20
**There are Breaking Changes in this release.**

### Breaking Changes
- Refactor `Experiments` to be a type of `Feature`, improving how functionality is organized and surfaced ([#316](https://github.com/WordPress/ai/pull/316)).

The following classes have been removed. Anyone that was directly using these will need to make updates to utilize the correct replacements: `Abstract_Experiment`, `Invalid_Experiment_Metadata_Exception`, `Invalid_Experiment_Exception`, `Experiment_Loader`, `Experiment_Registry`.

- Standardize the Title Generation Ability to align with other registered Abilities ([#227](https://github.com/WordPress/ai/pull/227)).

The `ai/title-generation` Ability now uses a `context` argument instead of a `post_id` argument in the `input_schema`. Anyone directly using this Ability will need to make updates to account for that.

### Added
- New Experiment: Image Editing via prompt-based image refining in the Post Editor and Media Library ([#292](https://github.com/WordPress/ai/pull/292)).
- New Experiment: Image Editing via expanding or removing background and removing or replacing items in the Media Libary ([#305](https://github.com/WordPress/ai/pull/305), [#312](https://github.com/WordPress/ai/pull/312)).

### Changed
- Rename the plugin from "AI Experiments" to "AI" ([#287](https://github.com/WordPress/ai/pull/287)).
- Replace `Invalid_Experiment_Exception` with `_doing_it_wrong()` ([#303](https://github.com/WordPress/ai/pull/303)).
- Rename hook prefixes in `helpers.php` ([#315](https://github.com/WordPress/ai/pull/315)).
- Rename plugin constants to `WPAI_*` ([#317](https://github.com/WordPress/ai/pull/317)).
- Refactor the upgrade routine and add v0.6.0 migrations ([#321](https://github.com/WordPress/ai/pull/321)).
- Move the Generate Alt Text button to the new Content tab for improved discoverability ([#306](https://github.com/WordPress/ai/pull/306)).
- Remove stray "AI" references from UI for improved consistency ([#320](https://github.com/WordPress/ai/pull/320)).
- Update documentation ([#314](https://github.com/WordPress/ai/pull/314)).

### Fixed
- Remove duplicate error display in the Generate Alt Text flow ([#255](https://github.com/WordPress/ai/pull/255)).

## [0.5.0] - 2026-03-12
**Note this version bumps the WordPress minimum supported version from 6.9 to 7.0.**

### Added
- Switch to using AI Client bundled in WordPress 7.0 ([#275](https://github.com/WordPress/ai/pull/275), [#301](https://github.com/WordPress/ai/pull/301)).

### Changed
- Bump WordPress minimum supported version from 6.9 to 7.0 ([#272](https://github.com/WordPress/ai/pull/272)).
- Bump WordPress tested-up-to version 7.0 ([#272](https://github.com/WordPress/ai/pull/272)).
- Migrate credentials from the AI Credentials to the new Connectors screen ([#286](https://github.com/WordPress/ai/pull/286)).
- Improve documentation and plugin assets ([#280](https://github.com/WordPress/ai/pull/280), [#281](https://github.com/WordPress/ai/pull/281), [#291](https://github.com/WordPress/ai/pull/291), [#293](https://github.com/WordPress/ai/pull/293), [#296](https://github.com/WordPress/ai/pull/296)).

### Removed
- No longer using AI Client via Composer package ([#271](https://github.com/WordPress/ai/pull/271)).

### Developer
- Bump `simple-git` from 3.30.0 to 3.33.0 ([#295](https://github.com/WordPress/ai/pull/295)).

## [0.4.1] - 2026-03-06
### Fixed
- Issues with 0.4.0 release merge and deploy ([#266](https://github.com/WordPress/ai/pull/266)).

### Developer
- Bump `immutable` from 5.1.4 to 5.1.5 ([#273](https://github.com/WordPress/ai/pull/273)).
- Bump `svgo` from 3.3.2 to 3.3.3 ([#274](https://github.com/WordPress/ai/pull/274)).
- Updated Release Instructions documentation ([#277](https://github.com/WordPress/ai/pull/277)).

## [0.4.0] - 2026-03-05
### Added
- Inline Image Generation directly in the post editor, enabling users to generate images without leaving authoring/editing flows ([#235](https://github.com/WordPress/ai/pull/235)).
- Generate Image within the Media Library with prompt-based image generation workflows ([#258](https://github.com/WordPress/ai/pull/258)).
- Generate Review Notes experiment to analyze post content or individual blocks and suggest refinements via Notes comments in the editor ([#260](https://github.com/WordPress/ai/pull/260), [#267](https://github.com/WordPress/ai/pull/267)).
- Split editor and admin experiments within the settings page ([#232](https://github.com/WordPress/ai/pull/232)).
- Contextual help text to the Abilities Explorer screen to assist users in understanding what Abilities are and how to use them ([#243](https://github.com/WordPress/ai/pull/243)).

### Changed
- Update “Generate Summary” button style to use consistent UI with other buttons in the ediot ([#253](https://github.com/WordPress/ai/pull/253)).
- Standardize Abilities invocation using the `runAbility` helper to improve consistency across API calls ([#228](https://github.com/WordPress/ai/pull/228)).
- Make provider labels in the Abilities Explorer translatable and adjust badge styling for clarity ([#247](https://github.com/WordPress/ai/pull/247)).
- Improve Abilities Explorer table layout by aligning spacing and styles with WordPress admin table conventions ([#248](https://github.com/WordPress/ai/pull/248)).
- Improve the Ability test page with better internationalization and add copy-to-clipboard functionality ([#256](https://github.com/WordPress/ai/pull/256)).

### Removed
- Remove unused checkbox column from the Abilities Explorer table, as it was not tied to any bulk actions ([#246](https://github.com/WordPress/ai/pull/246)).

### Fixed
- Fix the position and behavior of the “Copy” button in code blocks within the Abilities Explorer ([#245](https://github.com/WordPress/ai/pull/245)).

### Developer
- Bump `basic-ftp` from 5.1.0 to 5.2.0 ([#259](https://github.com/WordPress/ai/pull/259)).

## [0.3.1] - 2026-02-18
### Fixed
- Increased image generation request timeout from 30s to 90s to reduce failed generations on slower providers/models ([#226](https://github.com/WordPress/ai/pull/226)).

### Developer
- Added Experiment lifecycle and contribution criteria documentation, plus general doc tidy-ups to better explain how experiments land in (and could eventually graduate from) the plugin ([#219](https://github.com/WordPress/ai/pull/219)).
- Updated the Pull Request template to include an “AI tools usage” disclosure section, aligned with the [equivalent change in core](https://github.com/WordPress/wordpress-develop/pull/10850) ([#217](https://github.com/WordPress/ai/pull/217)).
- Bump `qs` from 6.14.1 to 6.14.2 ([#229](https://github.com/WordPress/ai/pull/229)).

## [0.3.0] - 2026-02-09
### Added
- Content Summarization Experiment, allowing authors to generate and store AI-powered summaries directly in the post editor ([#147](https://github.com/WordPress/ai/pull/147)).
- Featured Image Generation Experiment, enabling AI-generated featured images from the editor sidebar with optional alt text and AI attribution metadata ([#146](https://github.com/WordPress/ai/pull/146)).
- Alt Text Generation Experiment, supporting images within Image blocks and Media Library workflows ([#156](https://github.com/WordPress/ai/pull/156)).
- “Experiments” and “Credentials” quick action links to the Installed Plugins screen for faster configuration ([#206](https://github.com/WordPress/ai/pull/206)).

### Changed
- Replace the global “Enable Experiments” checkbox with an auto-submitting enable/disable button to reduce friction when toggling experiments ([#168](https://github.com/WordPress/ai/pull/168)).

### Fixed
- Improve robustness of asset loading to handle missing or invalid built files and prevent admin and editor warnings ([#175](https://github.com/WordPress/ai/pull/175)).
- Add missing strict typing declarations in the Abilities Explorer to ensure consistency and correctness ([#208](https://github.com/WordPress/ai/pull/208)).

### Developer
- Streamline and clarify Contributor and Developer documentation to improve onboarding and reduce duplication ([#169](https://github.com/WordPress/ai/pull/169)).
- Fix inline documentation issues, including missing `@global` tags, non-standard hook tags, and incomplete `@return` descriptions ([#207](https://github.com/WordPress/ai/pull/207), [#210](https://github.com/WordPress/ai/pull/210)).
- Bump `phpunit/phpunit` from 9.6.31 to 9.6.33 as part of ongoing test and tooling maintenance ([#209](https://github.com/WordPress/ai/pull/209)).
- Expand and align allowed open source licenses in dependency configuration to better match Gutenberg and ecosystem tooling ([#212](https://github.com/WordPress/ai/pull/212), [#213](https://github.com/WordPress/ai/pull/213), [#214](https://github.com/WordPress/ai/pull/214)).

## [0.2.1] - 2026-01-26
### Added
- Introduced a shared `AI_Service` layer to standardize provider access across experiments ([#101](https://github.com/WordPress/ai/pull/101)).

### Changed
- Documentation updates ([#195](https://github.com/WordPress/ai/pull/195)).

### Fixed
- Guarded against `preg_replace()` returning `null` to prevent content corruption in `normalize_content()` ([#177](https://github.com/WordPress/ai/pull/177)).

### Security
- Change our user permission checks to use `edit_post` instead of `read_post` ([GHSA-mxf5-gp98-93wv](https://github.com/WordPress/ai/security/advisories/GHSA-mxf5-gp98-93wv)).

### Developer
- Bumped `diff` from 4.0.2 to 4.0.4 ([#196](https://github.com/WordPress/ai/pull/196)).
- Bumped `lodash-es` from 4.17.22 to 4.17.23 ([#198](https://github.com/WordPress/ai/pull/198)).
- Bumped `lodash` from 4.17.21 to 4.17.23 ([#199](https://github.com/WordPress/ai/pull/199)).

## [0.2.0] – 2026-01-20
### Added
- Core excerpt generation support for AI-powered summaries, including a new Excerpt Generation Experiment with editor UI ([#96](https://github.com/WordPress/ai/pull/96), [#143](https://github.com/WordPress/ai/pull/143)).
- Abilities Explorer - a new admin screen to view and interact with registered AI abilities in the plugin ([#63](https://github.com/WordPress/ai/pull/63)).
- Introduce foundational backend support for Content Summarization and Image Generation experiments (API-only; no UI yet) ([#134](https://github.com/WordPress/ai/pull/134), [#136](https://github.com/WordPress/ai/pull/136)).
- Improve plugin documentation and onboarding with expanded WP.org readme content ([#135](https://github.com/WordPress/ai/pull/135)).
- Add Playground preview support to build and PR workflows using the official WordPress action ([#144](https://github.com/WordPress/ai/pull/144)).

### Changed
- Rely on the Abilities API bundled with WordPress 6.9 and remove the previously bundled dependency (minimum WP version updated) ([#107](https://github.com/WordPress/ai/pull/107)).
- Reorganize Playground blueprints and update demo paths to align with WordPress.org conventions ([#137](https://github.com/WordPress/ai/pull/137)).
- Improve and clarify plugin documentation, descriptions, screenshots, and in-context messaging ([#69](https://github.com/WordPress/ai/pull/69), [#158](https://github.com/WordPress/ai/pull/158), [#161](https://github.com/WordPress/ai/pull/161), [#162](https://github.com/WordPress/ai/pull/162), [#164](https://github.com/WordPress/ai/pull/164)).
- Update and align runtime and development dependencies, including `preact`, `qs`, `express`, and React overrides ([#165](https://github.com/WordPress/ai/pull/165), [#166](https://github.com/WordPress/ai/pull/166), [#171](https://github.com/WordPress/ai/pull/171)).
- Replace custom Plugin Check setup with the official GitHub workflow for more reliable enforcement ([#139](https://github.com/WordPress/ai/pull/139)).

### Fixed
- Resolve UI and messaging issues on the AI Experiments settings screen ([#130](https://github.com/WordPress/ai/pull/130), [#132](https://github.com/WordPress/ai/pull/132)).
- Ensure AI Experiments are visible even when no credentials are configured ([#173](https://github.com/WordPress/ai/pull/173)).
- Fix Plugin Check, linting, and CI failures introduced by updated tooling and workflows ([#150](https://github.com/WordPress/ai/pull/150), [#163](https://github.com/WordPress/ai/pull/163), [#167](https://github.com/WordPress/ai/pull/167), [#176](https://github.com/WordPress/ai/pull/176)).

### Developer
- Cleanup and standardize scaffold, linting, TypeScript, and CI configuration to better align with WordPress Coding Standards ([#172](https://github.com/WordPress/ai/pull/172)).

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

### Developer
- Bumped `js-yaml` from 3.14.1 to 3.14.2 ([#105](https://github.com/WordPress/ai/pull/105)).
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

[Unreleased]: https://github.com/WordPress/ai/compare/trunk...develop
[0.5.0]: https://github.com/WordPress/ai/compare/0.4.1...0.5.0
[0.4.1]: https://github.com/WordPress/ai/compare/0.4.0...0.4.1
[0.4.0]: https://github.com/WordPress/ai/compare/0.3.1...0.4.0
[0.3.1]: https://github.com/WordPress/ai/compare/0.3.0...0.3.1
[0.3.0]: https://github.com/WordPress/ai/compare/0.2.1...0.3.0
[0.2.1]: https://github.com/WordPress/ai/compare/0.2.0...0.2.1
[0.2.0]: https://github.com/WordPress/ai/compare/0.1.1...0.2.0
[0.1.1]: https://github.com/WordPress/ai/compare/0.1.0...0.1.1
[0.1.0]: https://github.com/WordPress/ai/tree/0.1.0
