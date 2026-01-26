=== AI Experiments ===
Contributors:      wordpressorg
Tags:              ai, artificial intelligence, experiments, abilities, mcp
Tested up to:      6.9
Stable tag:        0.2.1
License:           GPL-2.0-or-later
License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html

AI experiments and capabilities for WordPress.

== Description ==

The WordPress AI Experiments plugin brings experimental AI-powered features directly into your WordPress admin and editing experience.

**What's Inside:**

This plugin is built on the [AI Building Blocks for WordPress](https://make.wordpress.org/ai/2025/07/17/ai-building-blocks) initiative, combining the WP AI Client SDK, Abilities API, and MCP Adapter into a unified experience. It serves as both a practical tool for content creators and a reference implementation for developers.

**Current Features:**

* **Title Generation** - Generate title suggestions for your posts with a single click. Perfect for brainstorming headlines or finding the right tone for your content.
* **Excerpt Generation** - Automatically create concise summaries for your posts.
* **Experiment Framework** - Opt-in system that lets you enable only the AI features you want to use.
* **Multi-Provider Support** - Works with popular AI providers like OpenAI, Google, and Anthropic.
* **Abilities Explorer** – Browse and interact with registered AI abilities from a dedicated admin screen.

**Coming Soon:**

We're actively developing new features to enhance your WordPress workflow:

* **Image Generation** - Create images from text prompts directly in the block editor.
* **Alt Text Generation** - Generate descriptive alt text for images to improve accessibility.
* **Content Summarization** - Quickly summarize long-form content.
* **Contextual Tagging** - AI-suggested tags and categories to organize your content.
* **Comment Moderation** – AI-assisted moderation tools to help classify or manage user comments.
* **AI Playground** - Experiment with different AI models and prompts.
* **Extended Providers** – Support for experimenting with additional or alternate AI providers.
* **MCP (Model Context Protocol)** – Integrate and test Model Context Protocol capabilities in WordPress workflows.
* **AI Request Logging & Observability Dashboard** – Track AI requests and visualize performance and cost metrics.
* **Type Ahead** – Contextual type-ahead assistance for suggestions while typing.
* **Date Calculation Ability** – Natural-language date interpretation for AI workflows like “every 3rd Tuesday.”

This is an experimental plugin; functionality may change as we gather feedback from the community.

**Roadmap:**

You can view the active plugin roadmap in a filtered view in the WordPress AI [GitHub Project Board](https://github.com/orgs/WordPress/projects/240/views/7).

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/ai` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to `Settings -> AI Credentials` and add at least one valid AI credential.
4. Go to `Settings -> AI Experiments` and globally enable experiments and then enable the individual experiments you want to test.
5. Start experimenting with AI features! For the Title Generation experiment, edit a post and click into the title field. You should see a `Generate/Re-generate` button above the field. Click that button and after the request is complete, title suggestions will be displayed in a modal. Choose the title you like and click the `Select` button to insert it into the title field.

== For Developers ==

The AI Experiments plugin is designed to be studied, extended, and built upon. Whether you're a plugin developer, agency, or hosting provider, here's what you can do:

**Extend the Plugin:**

* **Build Custom Experiments** - Use the `Abstract_Experiment` base class to create your own AI-powered features
* **Register Custom Abilities** - Hook into the Abilities API to add new AI capabilities
* **Override Default Behavior** - Use filters to customize prompts, responses, and UI elements
* **Pre-configure Providers** - Hosts and agencies can set up AI providers so users don't need their own API keys

**Developer Tools Coming Soon:**

* **Abilities Explorer** - Test and explore registered AI abilities (available when experiments are enabled)
* **MCP Demo** - See how Model Context Protocol integration works with WordPress
* **Comprehensive Hooks** - Filters and actions throughout the codebase for customization

**Get Started:**

1. Read the [Contributing Guide](https://github.com/WordPress/ai/blob/trunk/CONTRIBUTING.md) for development setup
2. Join the conversation in [#core-ai on WordPress Slack](https://wordpress.slack.com/archives/C08TJ8BPULS)
3. Browse the [GitHub repository](https://github.com/WordPress/ai) to see how experiments are built
4. Participate in [discussions](https://github.com/WordPress/ai/discussions) on how best the plugin should iterate.

We welcome contributions! Whether you want to build new experiments, improve existing features, or help with documentation, check out our [GitHub repository](https://github.com/WordPress/ai) to get involved.

== Frequently Asked Questions ==

= What is this plugin for? =

This plugin brings AI-powered writing and editing tools directly into WordPress. It's also a reference implementation for developers who want to build their own AI features.

= Is this safe to use on a production site? =

This is an experimental plugin, so we recommend testing in a staging environment first. Features may change as we gather community feedback. All AI features are opt-in and require manual triggering - nothing happens automatically without your approval.

= Which AI providers are supported? =

The plugin supports OpenAI, Google AI (Gemini), and Anthropic (Claude). You can configure one or multiple providers in Settings -> AI Credentials.

= Do I need an API key to use the experiments? =

Yes, currently you need to provide your own API key from a supported AI provider (OpenAI, Google AI, or Anthropic).

= How much does it cost? =

The plugin itself is free, but you'll need to pay for API usage from your chosen AI provider. Costs vary by provider and usage. Most providers offer free trial credits to get started.

= Can I use this without coding knowledge? =

Absolutely! The plugin is designed for content creators and site administrators. Once your API credentials are configured, you can use AI experiments directly from the post editor.

= Where can I get help or report issues? =

You can ask questions in the [#core-ai channel on WordPress Slack](https://wordpress.slack.com/archives/C08TJ8BPULS) or report issues on the [GitHub repository](https://github.com/WordPress/ai/issues).

== Screenshots ==

1. Post editor showing (Re-)Generate button above the post title field and title recommendations in a modal.
2. AI Experiments settings screen showing toggles to enable specific experiments.
3. AI Credentials settings screen showing API key fields for available AI service providers.

== Changelog ==

= 0.2.0 – 2026-01-20 =

* **Added:** Core excerpt generation support for AI-powered summaries, including a new Excerpt Generation Experiment with editor UI ([#96](https://github.com/WordPress/ai/pull/96), [#143](https://github.com/WordPress/ai/pull/143)).
* **Added:** Abilities Explorer — a new admin screen to view and interact with registered AI abilities in the plugin ([#63](https://github.com/WordPress/ai/pull/63)).
* **Added:** Introduce foundational backend support for Content Summarization and Image Generation experiments (API-only; no UI yet) ([#134](https://github.com/WordPress/ai/pull/134), [#136](https://github.com/WordPress/ai/pull/136)).
* **Added:** Improve plugin documentation and onboarding with expanded WP.org readme content ([#135](https://github.com/WordPress/ai/pull/135)).
* **Added:** Add Playground preview support to build and PR workflows using the official WordPress action ([#144](https://github.com/WordPress/ai/pull/144)).
* **Changed:** Rely on the Abilities API bundled with WordPress 6.9 and remove the previously bundled dependency (minimum WP version updated) ([#107](https://github.com/WordPress/ai/pull/107)).
* **Changed:** Reorganize Playground blueprints and update demo paths to align with WordPress.org conventions ([#137](https://github.com/WordPress/ai/pull/137)).
* **Changed:** Improve and clarify plugin documentation, descriptions, screenshots, and in-context messaging ([#69](https://github.com/WordPress/ai/pull/69), [#158](https://github.com/WordPress/ai/pull/158), [#161](https://github.com/WordPress/ai/pull/161), [#162](https://github.com/WordPress/ai/pull/162), [#164](https://github.com/WordPress/ai/pull/164)).
* **Changed:** Update and align runtime and development dependencies, including `preact`, `qs`, `express`, and React overrides ([#165](https://github.com/WordPress/ai/pull/165), [#166](https://github.com/WordPress/ai/pull/166), [#171](https://github.com/WordPress/ai/pull/171)).
* **Changed:** Replace custom Plugin Check setup with the official GitHub workflow for more reliable enforcement ([#139](https://github.com/WordPress/ai/pull/139)).
* **Fixed:** Resolve UI and messaging issues on the AI Experiments settings screen ([#130](https://github.com/WordPress/ai/pull/130), [#132](https://github.com/WordPress/ai/pull/132)).
* **Fixed:** Ensure AI Experiments are visible even when no credentials are configured ([#173](https://github.com/WordPress/ai/pull/173)).
* **Fixed:** Fix Plugin Check, linting, and CI failures introduced by updated tooling and workflows ([#150](https://github.com/WordPress/ai/pull/150), [#163](https://github.com/WordPress/ai/pull/163), [#167](https://github.com/WordPress/ai/pull/167), [#176](https://github.com/WordPress/ai/pull/176)).
* **Developer:** Cleanup and standardize scaffold, linting, TypeScript, and CI configuration to better align with WordPress Coding Standards ([#172](https://github.com/WordPress/ai/pull/172)).

= 0.1.1 - 2025-12-01 =

* **Added:** Link to the plugin settings screen from the plugin list table ([#98](https://github.com/WordPress/ai/pull/98)).
* **Added:** WordPress Playground live preview integration ([#85](https://github.com/WordPress/ai/pull/85)).
* **Added:** RTL language support and inlining for performance ([#113](https://github.com/WordPress/ai/pull/113)).
* **Changed:** Updated namespace to `ai_experiments` ([#111](https://github.com/WordPress/ai/pull/111)).
* **Changed:** Bumped WP AI Client from `dev-trunk` to 0.2.0 ([#118](https://github.com/WordPress/ai/pull/118), [#122](https://github.com/WordPress/ai/pull/122), [#125](https://github.com/WordPress/ai/pull/125)).
* **Removed:** Valid AI credentials check from the Experiment `is_enabled` check ([#120](https://github.com/WordPress/ai/pull/120)).
* **Removed:** Example Experiment registration ([#121](https://github.com/WordPress/ai/pull/121)).
* **Fixed:** Bug in asset loader causing missing dependencies ([#113](https://github.com/WordPress/ai/pull/113)).
* **Security:** Bumped `js-yaml` from 3.14.1 to 3.14.2 ([#105](https://github.com/WordPress/ai/pull/105)).

= 0.1.0 - 2025-11-26 =

First public release of the AI Experiments plugin, introducing a framework for exploring experimental AI-powered features in WordPress. 🎉

* **Added:** Experiment registry and loader system for managing AI features
* **Added:** Abstract experiment base class for consistent feature development
* **Added:** Experiment: Title Generation
* **Added:** Basic admin settings screen with toggle support
* **Added:** Initial integration with WP AI Client SDK and Abilities API
* **Added:** Utilities Ability for common AI tasks and testing
