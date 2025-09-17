# AI Experiments Plugin Roadmap

This roadmap outlines the planned development of the AI Experiments plugin from its early prototypes through the [WordPress 6.9](https://make.wordpress.org/core/6-9/) release and into 2026.  Each milestone includes goals, feature targets, and integration points with key components across the WordPress AI ecosystem.

## Milestone 0.1.0 - Plugin Foundations and First Feature Integration

**Target:** Early October 2025

### Goals

- [ ] Scaffold the initial plugin structure in the `WordPress/ai` GitHub repository
- [ ] Create a basic admin settings screen with a toggle for enabling experimental features
- [ ] Integrate the PHP AI Client SDK (or alternatively WP AI Client SDK) with a simple service provider setup
- [ ] Add developer support for pre-configured AI providers so hosts, agencies, and developers can offer out-of-the-box providers to power plugin features
- [ ] Plugin submission to WordPress.org and public availability via WPORG SVN repo

### Features

- [ ] Implement Title Rewriting using Abilities API, MCP Adapter, and the PHP AI Client (#10)
- [ ] Add UI controls in the post editor for rewriting titles
- [ ] Ensure graceful fallback behavior when no provider is configured

## Milestone 0.2.0 - Core Feature Expansion

**Target:** Mid–October 2025

### Goals

- [ ] Deliver a set of widely expected, foundational AI features
- [ ] Validate integration patterns across multiple AI Abilities
- [ ] Expand usage of MCP and the PHP/WP AI Client
- [ ] Refine consistency in UI elements, loading states, and language across features

### Features

- [ ] Add Excerpt Generation with inline controls in the editor (#11)
- [ ] Add Alt Text Generation for newly uploaded media in the Media Library
- [ ] Add Image Generation with prompt entry in the Block Editor sidebar (#13)

## Milestone 0.3.0 - Experimental Tools and Developer Options

**Target:** 21 October 2025 (Aligned with WordPress 6.9 Beta 1)

### Goals

- [ ] Finalize all core UX flows and editorial experiences for future v1 launch alongside WordPress 6.9
- [ ] Document how developers can hook into or customize the plugin
- [ ] Add tools and configuration options for more advanced users

### Features

- [ ] Add Content Summarization, either inline or through a sidebar panel (#12)
- [ ] Add Contextual Tagging (also known as Content Classification)
- [ ] Introduce an optional "AI Playground" section (enabled via settings) with:
  - [ ] Prompt testing interface
  - [ ] Model configuration settings like temperature and max tokens
  - [ ] Response inspection and debugging tools

### Developer Experience

- [ ] Add filters and documentation for customizing provider setup
- [ ] Documentation on how to override default behaviors with custom handlers

## Milestone 1.0.0 - WordPress 6.9 Stable Release Candidate

**Target:** 2 December 2025 (Aligned with WordPress 6.9 Release)

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
