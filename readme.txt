=== AI Experiments ===
Contributors:      wordpressorg
Tags:              ai, artificial intelligence, experiments, abilities, mcp
Tested up to:      6.9
Stable tag:        0.3.1
License:           GPL-2.0-or-later
License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html

AI experiments and capabilities for WordPress.

== Description ==

The WordPress AI Experiments plugin brings experimental AI-powered features directly into your WordPress admin and editing experience.

**What's Inside:**

This plugin is built on the [AI Building Blocks for WordPress](https://make.wordpress.org/ai/2025/07/17/ai-building-blocks) initiative, combining the WP AI Client SDK, Abilities API, and MCP Adapter into a unified experience. It serves as both a practical tool for content creators and a reference implementation for developers.

**Current Features:**

* **Abilities Explorer** – Browse and interact with registered AI abilities from a dedicated admin screen.
* **Alt Text Generation** - Generate descriptive alt text for images to improve accessibility.
* **Content Summarization** - Summarizes long-form content into digestible overviews.
* **Excerpt Generation** - Automatically create concise summaries for your posts.
* **Experiment Framework** - Opt-in system that lets you enable only the AI features you want to use.
* **Image Generation** - Create feature image from post content directly in the block editor.
* **Multi-Provider Support** - Works with popular AI providers like OpenAI, Google, and Anthropic.
* **Title Generation** - Generate title suggestions for your posts with a single click. Perfect for brainstorming headlines or finding the right tone for your content.

**Coming Soon:**

We're actively developing new features to enhance your WordPress workflow:

* **Contextual Tagging** - AI-suggested tags and categories to organize your content.
* **Comment Moderation** – AI-assisted moderation tools to help classify or manage user comments.
* **Type Ahead** – Contextual type-ahead assistance for suggestions while typing.
* **AI Request Logging & Observability Dashboard** – Track AI requests and visualize performance and cost metrics.

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

* **Build Custom Experiments** - Use the `Abstract_Experiment` base class to create your own AI-powered features.
* **Pre-configure Providers** - Hosts and agencies can set up AI providers so users don't need their own API keys.
* **Abilities Explorer** - Test and explore registered AI abilities (available when experiments are enabled).
* **Register Custom Abilities** - Hook into the Abilities API to add new AI capabilities.
* **Override Default Behavior** - Use filters to customize prompts, responses, and UI elements.
* **Comprehensive Hooks** - Filters and actions throughout the codebase for customization.

**Developer Tools Coming Soon:**

* **AI Playground** - Experiment with different AI models and prompts.
* **MCP (Model Context Protocol)** – Integrate and test Model Context Protocol capabilities in WordPress workflows.
* **Extended Providers** – Support for experimenting with additional or alternate AI providers.
* **Date Calculation Ability** – Natural-language date interpretation for AI workflows like “every 3rd Tuesday.”

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

1. Post editor showing Generate button above the post title field and title recommendations in a modal.
2. Post editor sidebar showing Generate Excerpt button and generated excerpt.
3. Post editor sidebar showing Generate AI Summary button and the generated content summary within an AI Summary block.
4. Post editor sidebar showing Generate featured image button and the generated featured image preview with Alt Text, Title, and Description.
5. Image block settings showing Generate Alt Text button and the generated alt text.
6. Abilities Explorer admin screen listing available AI abilities with filters, providers, and test actions.
7. Abilities Explorer's view details screen showing an AI ability’s description, provider, input schema, output schema, and raw data.
8. Abilities Explorer's test ability screen showing JSON input data, validation, and input schema reference for an AI ability.
9. AI Experiments settings screen showing toggles to enable specific experiments.
10. AI Credentials settings screen showing API key fields for available AI service providers.

== Changelog ==

= 0.3.1 - 2026-02-18 =

* **Fixed:** Increased image generation request timeout from 30s to 90s to reduce failed generations on slower providers/models ([#226](https://github.com/WordPress/ai/pull/226)).

= 0.3.0 - 2026-02-09 =

* **Added:** Content Summarization Experiment, allowing authors to generate and store AI-powered summaries directly in the post editor ([#147](https://github.com/WordPress/ai/pull/147)).
* **Added:** Featured Image Generation Experiment, enabling AI-generated featured images from the editor sidebar with optional alt text and AI attribution metadata ([#146](https://github.com/WordPress/ai/pull/146)).
* **Added:** Alt Text Generation Experiment, supporting images within Image blocks and Media Library workflows ([#156](https://github.com/WordPress/ai/pull/156)).
* **Added:** “Experiments” and “Credentials” quick action links to the Installed Plugins screen for faster configuration ([#206](https://github.com/WordPress/ai/pull/206)).
* **Changed:** Replace the global “Enable Experiments” checkbox with an auto-submitting enable/disable button to reduce friction when toggling experiments ([#168](https://github.com/WordPress/ai/pull/168)).
* **Fixed:** Improve robustness of asset loading to handle missing or invalid built files and prevent admin and editor warnings ([#175](https://github.com/WordPress/ai/pull/175)).
* **Fixed:** Add missing strict typing declarations in the Abilities Explorer to ensure consistency and correctness ([#208](https://github.com/WordPress/ai/pull/208)).
* **Developer:** Streamline and clarify Contributor and Developer documentation to improve onboarding and reduce duplication ([#169](https://github.com/WordPress/ai/pull/169)).
* **Developer:** Fix inline documentation issues, including missing `@global` tags, non-standard hook tags, and incomplete `@return` descriptions ([#207](https://github.com/WordPress/ai/pull/207), [#210](https://github.com/WordPress/ai/pull/210)).
* **Developer:** Bump `phpunit/phpunit` from 9.6.31 to 9.6.33 as part of ongoing test and tooling maintenance ([#209](https://github.com/WordPress/ai/pull/209)).
* **Developer:** Expand and align allowed open source licenses in dependency configuration to better match Gutenberg and ecosystem tooling ([#212](https://github.com/WordPress/ai/pull/212), [#213](https://github.com/WordPress/ai/pull/213), [#214](https://github.com/WordPress/ai/pull/214)).

= 0.2.1 - 2026-01-26 =

* **Added:** Introduced a shared `AI_Service` layer to standardize provider access across experiments ([#101](https://github.com/WordPress/ai/pull/101)).
* **Changed:** Documentation updates ([#195](https://github.com/WordPress/ai/pull/195)).
* **Fixed:** Guarded against `preg_replace()` returning `null` to prevent content corruption in `normalize_content()` ([#177](https://github.com/WordPress/ai/pull/177)).
* **Security:** Change our user permission checks to use `edit_post` instead of `read_post` ([GHSA-mxf5-gp98-93wv](https://github.com/WordPress/ai/security/advisories/GHSA-mxf5-gp98-93wv)).
* **Security:** Bumped `diff` from 4.0.2 to 4.0.4 ([#196](https://github.com/WordPress/ai/pull/196)).
* **Security:** Bumped `lodash-es` from 4.17.22 to 4.17.23 ([#198](https://github.com/WordPress/ai/pull/198)).
* **Security:** Bumped `lodash` from 4.17.21 to 4.17.23 ([#199](https://github.com/WordPress/ai/pull/199)).

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
