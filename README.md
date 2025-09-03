# AI Experiments Plugin

[*Part of the **AI Building Blocks for WordPress** initiative*](https://make.wordpress.org/ai/2025/07/17/ai-building-blocks)

## Overview

* **Purpose:** Demonstrate and deliver AI features by combining all AI Building Blocks ([PHP AI Client SDK](https://github.com/WordPress/php-ai-client), [Abilities API](https://github.com/WordPress/abilities-api), and [MCP Adapter](https://github.com/WordPress/mcp-adapter)) into a unified WordPress experience.
* **Scope:** Reference implementations, user-facing AI features, and experimental capabilities for testing and feedback.
* **Audience:** WordPress users, content creators, site administrators, and developers learning the AI APIs.

This [Canonical Plugin](https://make.wordpress.org/core/2022/09/11/canonical-plugins-revisited/) is built following the [Features as Plugins model](https://make.wordpress.org/core/handbook/about/release-cycle/features-as-plugins/). The community will help evaluate which features could evolve toward inclusion in WordPress core based on testing, feedback, and adoption.

## Design Goals

1. **Showcase integration** – Demonstrate how all Building Blocks work together (e.g., connects to providers via PHP AI Client integration).
2. **User-focused** – Deliver practical AI features users can use today, integrated seamlessly into Gutenberg (block & site editors) and WordPress admin flows. Minimal setup required prioritizes user control, with manual review defaults before automation.
3. **Experimentation lab** – Test new AI capabilities and gather feedback.
4. **Path to core** – Explore which features should become part of WordPress.

## Planned Features

* **AI Playground** – Experiment with different AI models and providers
* **Content Assistant** – AI-powered writing and editing in Gutenberg
* **Site Agent** – Natural language WordPress administration
* **Workflow Automation** – AI-driven task automation
  * [Title Generation / Rewriting](https://github.com/WordPress/ai/issues/10) – Suggests alternative post titles for better clarity, tone, or engagement.
  * [Excerpt Generation](https://github.com/WordPress/ai/issues/11) – Creates concise summaries for post excerpts.
  * [Content Summarization](https://github.com/WordPress/ai/issues/12) – Summarizes long-form content into digestible overviews.
  * Contextual Tagging – Suggests relevant tags and categories to organize content.
* **Media Enhancement** – Auto-captioning and intelligent organization
  * Alt Text Generation – Auto-generates descriptive alt text for images.
  * [Image Generation](https://github.com/WordPress/ai/issues/13) – Produces inline or featured images from text prompts.

## Current Status

| Milestone | State |
|-----------|-------|
| Placeholder repository | **created** |
| Feature roadmap | in progress |
| Initial prototype | planned |
| Community testing | planned |

## How to Get Involved

* **Discuss:** [`#core-ai` channel](https://wordpress.slack.com/archives/C08TJ8BPULS) on WordPress Slack.
* **Design:** [Share feedback](https://github.com/WordPress/ai/issues) on UX flows and accessibility.
* **Test:** Try features as they're [released](https://github.com/WordPress/ai/releases) and [report feedback](https://github.com/WordPress/ai/issues).

## License

[GPL-2.0-or-later](https://spdx.org/licenses/GPL-2.0-or-later.html)
