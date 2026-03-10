# WordPress AI Experiments

![AI Experiments](https://github.com/WordPress/ai/blob/trunk/.wordpress-org/banner-1544x500.png)

![Required PHP Version](https://img.shields.io/wordpress/plugin/required-php/ai?label=Requires%20PHP) ![Required WordPress Version](https://img.shields.io/wordpress/plugin/wp-version/ai?label=Requires%20WordPress) ![WordPress Tested Up To](https://img.shields.io/wordpress/plugin/tested/ai?label=WordPress) [![GPL-2.0-or-later License](https://img.shields.io/github/license/WordPress/ai.svg)](https://github.com/WordPress/ai/blob/trunk/LICENSE.md?label=License)

![WordPress.org Rating](https://img.shields.io/wordpress/plugin/rating/ai?label=WP.org%20Rating) ![WordPress Plugin Downloads](https://img.shields.io/wordpress/plugin/dt/ai?label=WP.org%20Downloads) ![WordPress Plugin Active Installs](https://img.shields.io/wordpress/plugin/installs/ai?label=WP.org%20Active%20Installs) [![WordPress Playground Demo](https://img.shields.io/wordpress/plugin/v/ai?logo=wordpress&logoColor=FFFFFF&label=Live%20Demo&labelColor=3858E9&color=3858E9)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/WordPress/ai/trunk/.wordpress-org/blueprints/blueprint.json)

[![Test](https://github.com/WordPress/ai/actions/workflows/test.yml/badge.svg)](https://github.com/WordPress/ai/actions/workflows/test.yml) [![Dependency Review](https://github.com/WordPress/ai/actions/workflows/dependency-review.yml/badge.svg)](https://github.com/WordPress/ai/actions/workflows/dependency-review.yml)

> AI experiments for WordPress. Modular framework for testing AI capabilities.

## Description

The WordPress AI Experiments plugin provides a set of opt-in, experimental AI features for authors, editors, and admins directly within WordPress. It serves as a reference implementation for developers, agencies, and hosts looking to build or extend AI-powered workflows using building blocks from the WordPress AI team (as [*part of the **AI Building Blocks for WordPress** initiative*](https://make.wordpress.org/ai/2025/07/17/ai-building-blocks)).

## Overview

* **Purpose:** Demonstrate and deliver AI features by combining the AI Building Blocks ([PHP AI Client SDK](https://github.com/WordPress/php-ai-client), [Abilities API](https://github.com/WordPress/abilities-api)) into a unified WordPress experience.
* **Scope:** Reference implementations, user-facing AI features, and experimental capabilities for testing and feedback.
* **Audience:** WordPress users, content creators, site administrators, and developers learning the AI APIs.

This [Canonical Plugin](https://make.wordpress.org/core/2022/09/11/canonical-plugins-revisited/) is built following the [Features as Plugins model](https://make.wordpress.org/core/handbook/about/release-cycle/features-as-plugins/). The community will help evaluate which features could evolve toward inclusion in WordPress core based on testing, feedback, and adoption.

*Note: This plugin is experimental.  Features may change, move, or break.  Use on Production sites at your own risk.  It is recommended to test in a non-Production environment and follow the plugin’s development closely if adopting early.*

## Design Goals

1. **Showcase integration** – Demonstrate how all Building Blocks work together (e.g., connects to providers via PHP AI Client integration).
2. **User-focused** – Deliver practical AI features users can use today, integrated seamlessly into Gutenberg (block & site editors) and WordPress admin flows. Minimal setup required prioritizes user control, with manual review defaults before automation.
3. **Experimentation lab** – Test new AI capabilities and gather feedback.
4. **Path to core** – Explore which features should become part of WordPress.

## Current Features

* **[Abilities Explorer](docs/experiments/abilities-explorer.md)** – Discover, inspect, test, and document all abilities registered via the WordPress Abilities API.
* * **[Alt Text Generation](docs/experiments/alt-text-generation.md)** - Generates descriptive alt text for images using AI vision models.
* **[Content Summarization](docs/experiments/summarization.md)** - Summarizes long-form content into digestible overviews.
* **[Excerpt Generation](docs/experiments/excerpt-generation.md)** - Generates excerpt suggestions from content.
* **Experiment Framework** - Opt-in system that lets you enable only the AI features you want to use.
* **[Image Generation](docs/experiments/image-generation.md)** - Create images from post content in the editor, also via the Media Library.
* **Multi-Provider Support** - Works with popular AI providers like OpenAI, Google, and Anthropic.
* **Review Notes** - Reviews post content block-by-block and adds Notes with suggestions for Accessibility, Readability, Grammar, and SEO.
* **Title Generation** -  Generates title suggestions from content.

## Roadmap

You can view the active plugin roadmap in a filtered view in the WordPress AI [GitHub Project Board](https://github.com/orgs/WordPress/projects/240/views/7).

Overview of planned features:

* **AI Playground** – Experiment with different AI models and providers
* **Content Assistant** – AI-powered writing and editing in Gutenberg
* **Site Agent** – Natural language WordPress administration
* **Workflow Automation** – AI-driven task automation
  * Contextual Tagging – Suggests relevant tags and categories to organize content.

## Developer Experience

The AI Experiments plugin is meant to be studied, forked, and extended.  If you’re a host or agency, you can configure AI providers on behalf of your users so they don’t need to bring their own API keys.

If you’re a plugin developer, you’ll be able to:

* Read the [Contributing Guide](CONTRIBUTING.md) for detailed development information.
* Register new AI abilities
* Override default behavior with custom filters
* Reuse the same building blocks in your own plugins

## How to Get Involved

We want everyone's input! Whether you're an author, editor, educator, researcher, accessibility expert, user, or someone with strong feelings about AI, all are welcome.

Anyone contributing to the AI Experiments plugin is expected to conduct themselves in accordance with the WordPress project's [Code of Conduct](https://github.com/WordPress/.github/blob/trunk/CODE_OF_CONDUCT.md).

* **Discuss:** [`#core-ai` channel](https://wordpress.slack.com/archives/C08TJ8BPULS) on WordPress Slack.
* **Ideate:** Propose and comment on [GitHub discussions](https://github.com/WordPress/ai/discussions).
* **Design:** [Share feedback](https://github.com/WordPress/ai/issues) on UX flows and accessibility.
* **Test:** Try features as they're [released](https://github.com/WordPress/ai/releases) and [report feedback](https://github.com/WordPress/ai/issues).

View the [Credits](CREDITS.md) file for maintainers, contributors, and libraries for the AI Experiments plugin.
