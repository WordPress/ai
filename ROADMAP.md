# AI Experiments Plugin Roadmap

This roadmap outlines the planned development of the AI Experiments plugin from its early prototypes through the [WordPress 6.9](https://make.wordpress.org/core/6-9/) release and into 2026.  Each milestone includes goals, feature targets, and integration points with key components across the WordPress AI ecosystem.

## Milestone 0.1.0 - Plugin Foundations and First Feature Integration

**Target:** Late November 2025 (Aligned with WordPress 6.9 Release)

### Goals

- [X] Scaffold the initial plugin structure in the `WordPress/ai` GitHub repository ([#24](https://github.com/WordPress/ai/issues/24))
- [X] Create a basic admin settings screen with a toggle for enabling experimental features ([#25](https://github.com/WordPress/ai/issues/25))
- [X] Integrate the WP AI Client SDK (and underlying PHP AI Client SDK) with a simple service provider setup ([#26](https://github.com/WordPress/ai/issues/26))
- [ ] Add developer support for pre-configured AI providers so hosts, agencies, and developers can offer out-of-the-box providers to power plugin features ([#27](https://github.com/WordPress/ai/issues/27))
- [X] Plugin submission to WordPress.org and public availability via WPORG SVN repo ([#28](https://github.com/WordPress/ai/issues/28))

### Features

- [X] Implement Title Rewriting using Abilities API, MCP Adapter, and the PHP AI Client ([#10](https://github.com/WordPress/ai/issues/10))
- [X] Add UI controls in the post editor for rewriting titles ([#29](https://github.com/WordPress/ai/issues/29))
- [X] Ensure graceful fallback behavior when no provider is configured ([#30](https://github.com/WordPress/ai/issues/30))

## Milestone 0.2.0 - Core Feature Expansion

**Target:** Mid–December 2025

### Goals

- [ ] Deliver a set of widely expected, foundational AI features ([#35](https://github.com/WordPress/ai/issues/35))
- [ ] Validate integration patterns across multiple AI Abilities ([#36](https://github.com/WordPress/ai/issues/36), [#40](https://github.com/WordPress/ai/issues/40))
- [ ] Expand usage of MCP and the PHP/WP AI Client ([#37](https://github.com/WordPress/ai/issues/37))
- [ ] Refine consistency in UI elements, loading states, and language across features ([#35](https://github.com/WordPress/ai/issues/35))

### Features

- [ ] Add Excerpt Generation with inline controls in the editor ([#11](https://github.com/WordPress/ai/issues/11))
- [ ] Add Alt Text Generation for newly uploaded media in the Media Library ([#44](https://github.com/WordPress/ai/issues/44))
- [ ] Add Image Generation with prompt entry in the Block Editor sidebar ([#13](https://github.com/WordPress/ai/issues/13))

## Milestone 0.3.0 - Experimental Tools and Developer Options

**Target:** Mid-January 2026

### Goals

- [ ] Finalize all core UX flows and editorial experiences for future v1 launch alongside WordPress 6.9 ([#31](https://github.com/WordPress/ai/issues/31))
- [ ] Document how developers can hook into or customize the plugin ([#34](https://github.com/WordPress/ai/issues/34))
- [ ] Add tools and configuration options for more advanced users ([#33](https://github.com/WordPress/ai/issues/33))

### Features

- [ ] Add Content Summarization, either inline or through a sidebar panel ([#12](https://github.com/WordPress/ai/issues/12))
- [ ] Add Contextual Tagging (also known as Content Classification) ([#45](https://github.com/WordPress/ai/issues/45))
- [ ] Introduce an optional "AI Playground" section (enabled via settings) with: ([#32](https://github.com/WordPress/ai/issues/32))
  - [ ] Prompt testing interface ([#32](https://github.com/WordPress/ai/issues/32))
  - [ ] Model configuration settings like temperature and max tokens ([#32](https://github.com/WordPress/ai/issues/32))
  - [ ] Response inspection and debugging tools ([#32](https://github.com/WordPress/ai/issues/32))

### Developer Experience

- [ ] Add filters and documentation for customizing provider setup ([#34](https://github.com/WordPress/ai/issues/34))
- [ ] Documentation on how to override default behaviors with custom handlers ([#34](https://github.com/WordPress/ai/issues/34))0.3

## Milestone 1.0.0 - Stable Release

**Target:** Mid-April 2026 (Aligned with WordPress 7.0 Release)

### Goals

- [ ] Polish UX across all features
- [ ] Complete accessibility and internationalization testing
- [ ] Establish the plugin as the go-to reference implementation for AI in WordPress leveraging the Abilities API, MCP Adapter, and PHP/WP AI Client

### Release Criteria

- [ ] Features must fail gracefully or guide users through simple provider configuration when no provider is active
- [ ] Plugin passes manual accessibility checks and automated scans
- [ ] Documentation must demonstrate how to extend or integrate plugin features as examples using Abilities API, MCP, and PHP/WP AI Client

## Looking Ahead - 2026 and Beyond

Future roadmap items will be discussed in GitHub Discussions and tracked with `future-idea` labels.

### Editorial Features

* Tone Adjustment (casual, formal, technical, etc.)
* Multilingual Rewriting and Translation
* Persona-Driven Content Generation

### Site Agent

Introduce a conversational agent interface (potentially via command palette or within the admin bar) that allows admins to perform tasks using natural language prompts.  Tasks might include:

* "Create a new post and schedule it for next Tuesday"
* "Install and activate a contact form plugin"
* "Update my site tagline to 'AI-Powered Publishing'"
* "Export all posts tagged with 'AI' to CSV"

This feature would rely heavily on the AI Client and MCP routing to translate intent into secure and verifiable WordPress actions.  It may be hidden behind a filter and should be built with security and permissions considerations from the start.

### Admin Features

* Analytics dashboard for AI usage
* Site-wide content insights powered by AI
* Import/export for settings and provider configurations

### Developer Features

* Ability to register custom AI Abilities via plugin filters
* Extension points for custom prompt templates
* Developer-only log panel for reviewing provider responses

### Contributions and Feedback

If you’d like to propose a new feature, contribute code, or help test, we welcome your participation. Please [open an issue](https://github.com/WordPress/ai/issues/new) or [discussion](https://github.com/WordPress/ai/discussions/new?category=ideas) on GitHub.
