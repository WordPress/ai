# WordPress AI Experiments

Experimental AI features for WordPress. Modular framework for testing AI capabilities.

## Description

The WordPress AI Experiments plugin provides a set of opt-in, experimental AI features for authors, editors, and admins directly inside WordPress. It serves as a reference implementation for developers, agencies, and hosts looking to build or extend AI-powered workflows using the building blocks from the WordPress AI team (as [*part of the **AI Building Blocks for WordPress** initiative*](https://make.wordpress.org/ai/2025/07/17/ai-building-blocks)).

## Overview

* **Purpose:** Demonstrate and deliver AI features by combining all AI Building Blocks ([PHP AI Client SDK](https://github.com/WordPress/php-ai-client), [Abilities API](https://github.com/WordPress/abilities-api), and [MCP Adapter](https://github.com/WordPress/mcp-adapter)) into a unified WordPress experience.
* **Scope:** Reference implementations, user-facing AI features, and experimental capabilities for testing and feedback.
* **Audience:** WordPress users, content creators, site administrators, and developers learning the AI APIs.

This [Canonical Plugin](https://make.wordpress.org/core/2022/09/11/canonical-plugins-revisited/) is built following the [Features as Plugins model](https://make.wordpress.org/core/handbook/about/release-cycle/features-as-plugins/). The community will help evaluate which features could evolve toward inclusion in WordPress core based on testing, feedback, and adoption.

*Note: This plugin is experimental.  Features may change, move, or break.  Use on Production sites at your own risk.  It is recommended to test in a non-Production environment and follow the plugin’s development closely if adopting early.*

## Design Goals

1. **Showcase integration** – Demonstrate how all Building Blocks work together (e.g., connects to providers via PHP AI Client integration).
2. **User-focused** – Deliver practical AI features users can use today, integrated seamlessly into Gutenberg (block & site editors) and WordPress admin flows. Minimal setup required prioritizes user control, with manual review defaults before automation.
3. **Experimentation lab** – Test new AI capabilities and gather feedback.
4. **Path to core** – Explore which features should become part of WordPress.

## Roadmap

You can view the active plugin roadmap in [`ROADMAP.md`](./ROADMAP.md).  We are currently working toward a stable, user-friendly release aligned with [WordPress 6.9](https://make.wordpress.org/core/6-9/) (targeted for 2 December 2025).

Overview of planned features:

* **AI Playground** – Experiment with different AI models and providers
* **Content Assistant** – AI-powered writing and editing in Gutenberg
* **Site Agent** – Natural language WordPress administration
* **Workflow Automation** – AI-driven task automation
  * Title Generation / Rewriting – Suggests alternative post titles for better clarity, tone, or engagement.
  * Excerpt Generation – Creates concise summaries for post excerpts.
  * Content Summarization – Summarizes long-form content into digestible overviews.
  * Contextual Tagging – Suggests relevant tags and categories to organize content.
* **Media Enhancement** – Auto-captioning and intelligent organization
  * Alt Text Generation – Auto-generates descriptive alt text for images.
  * Image Generation – Produces inline or featured images from text prompts.

## Developer Experience

The AI Experiments plugin is meant to be studied, forked, and extended.  If you’re a host or agency, you can configure AI providers on behalf of your users so they don’t need to bring their own API keys.

If you’re a plugin developer, you’ll be able to:

*   Read the [Contributing Guide](docs/CONTRIBUTING.md) for detailed development information.
*   Register new AI abilities
*   Override default behavior with custom filters
*   Reuse the same building blocks in your own plugins

## Current Status

| Milestone | State |
|-----------|-------|
| Placeholder repository | **created** |
| Feature roadmap | **created** |
| Initial prototype | planned |
| Community testing | planned |

## How to Get Involved

We want your input especially if you’re an author, editor, educator, accessibility expert, or just someone with strong feelings about AI.

* **Discuss:** [`#core-ai` channel](https://wordpress.slack.com/archives/C08TJ8BPULS) on WordPress Slack.
* **Ideate:** Propose and comment on [GitHub discussions](https://github.com/WordPress/ai/discussions).
* **Design:** [Share feedback](https://github.com/WordPress/ai/issues) on UX flows and accessibility.
* **Test:** Try features as they're [released](https://github.com/WordPress/ai/releases) and [report feedback](https://github.com/WordPress/ai/issues).

## License

This plugin is released under the [GNU General Public License v2 or later](https://spdx.org/licenses/GPL-2.0-or-later.html)
