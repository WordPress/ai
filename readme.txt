=== AI ===
Contributors:      wordpressdotorg
Tags:              ai, artificial intelligence, experiments, abilities, mcp
Tested up to:      7.0
Stable tag:        0.8.0
License:           GPL-2.0-or-later
License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html

AI features, experiments and capabilities for WordPress.

== Description ==

The AI plugin brings AI-powered features directly into your WordPress admin and editing experience.

**What's Inside:**

This plugin is built on the [AI Building Blocks for WordPress](https://make.wordpress.org/ai/2025/07/17/ai-building-blocks) initiative, combining the AI Client library and Abilities API into a unified experience. It serves as both a practical tool for content creators and a reference implementation for developers.

**Current Features:**

* **Abilities Explorer** – Browse and interact with registered AI abilities from a dedicated admin screen.
* **Alt Text Generation** - Generate descriptive alt text for images to improve accessibility.
* **Content Classification** – Suggests relevant tags and categories to organize content.
* **Content Summarization** - Summarizes long-form content into digestible overviews.
* **Dashboard Widgets** - AI Status and AI Capabilities widgets, plus framework for registering new ones.
* **Excerpt Generation** - Automatically create concise summaries for your posts.
* **Experiment Framework** - Opt-in system that lets you enable only the AI features you want to use.
* **Guidelines** - Allows abilities to respect site-wide editorial standards.
* **Image Generation and Editing** - Create and edit images from post content in the editor, also via the Media Library.
* **Meta Description Generation** - Generates meta description suggestions and integrates those with various SEO plugins.
* **Multi-Provider Support** - Works with popular AI providers like OpenAI, Google, and Anthropic.
* **Refine Notes** - Automatically apply editorial notes to content.
* **Review Notes** - Reviews post content block-by-block and adds Notes with suggestions for Accessibility, Readability, Grammar, and SEO.
* **Title Generation** - Generate title suggestions for your posts with a single click. Perfect for brainstorming headlines or finding the right tone for your content.

**Coming Soon:**

We're actively developing new features to enhance your WordPress workflow:

* **Comment Moderation** – AI-assisted moderation tools to help classify or manage user comments.
* **Type Ahead** – Contextual type-ahead assistance for suggestions while typing.
* **AI Request Logging & Observability Dashboard** – Track AI requests and visualize performance and cost metrics.
* **AI Playground** – Experiment with different AI models and providers.
* **Content Assistant** – AI-powered writing and editing in Gutenberg.
* **Site Agent** – Natural language WordPress administration.
* **Workflow Automation** – AI-driven task automation.

This is an experimental plugin; functionality may change as we gather feedback from the community.

**Roadmap:**

You can view the active plugin roadmap in a filtered view in the WordPress AI [GitHub Project Board](https://github.com/orgs/WordPress/projects/240/views/7).

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/ai` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to `Settings -> Connectors` and setup at least one AI connector.
4. Go to `Settings -> AI` and globally enable functionality and then enable the individual features or experiments you want to test.
5. Start experimenting with AI features! For the Title Generation experiment, edit a post and click into the title field. You should see a `Generate/Regenerate` button above the field. Click that button and after the request is complete, title suggestions will be displayed in a modal. Choose the title you like and click the `Select` button to insert it into the title field.

== For Developers ==

The AI plugin is designed to be studied, extended, and built upon. Whether you're a plugin developer, agency, or hosting provider, here's what you can do:

**Extend the Plugin:**

* **Build Custom Experiments** - Use the `Abstract_Feature` base class to create your own AI-powered features.
* **Pre-configure Providers** - Hosts and agencies can set up AI providers so users don't need their own API keys.
* **Abilities Explorer** - Test and explore registered AI abilities (available when experiments are enabled).
* **Register Custom Abilities** - Hook into the Abilities API to add new AI capabilities.
* **Override Default Behavior** - Use filters to customize prompts, responses, and UI elements.
* **Comprehensive Hooks** - Filters and actions throughout the codebase for customization.

**Developer Tools Coming Soon:**

* **AI Playground** - Experiment with different AI models and prompts.
* **MCP (Model Context Protocol)** – Integrate and test Model Context Protocol capabilities in WordPress workflows.
* **Extended Providers** – Support for experimenting with additional or alternate AI providers.

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

The plugin supports OpenAI, Google AI (Gemini), and Anthropic (Claude). You can configure one or multiple providers in Settings -> Connectors.

= Do I need an API key to use the features? =

Yes, currently you need to provide your own API key from a supported AI provider (OpenAI, Google AI, or Anthropic).

= How much does it cost? =

The plugin itself is free, but you'll need to pay for API usage from your chosen AI provider. Costs vary by provider and usage. Most providers offer free trial credits to get started.

= Can I use this without coding knowledge? =

Absolutely! The plugin is designed for content creators and site administrators. Once your AI Connectors are configured, you can use the AI functionality directly from the post editor.

= Where can I get help or report issues? =

You can ask questions in the [#core-ai channel on WordPress Slack](https://wordpress.slack.com/archives/C08TJ8BPULS) or report issues on the [GitHub repository](https://github.com/WordPress/ai/issues).

== Screenshots ==

1. Post editor showing Generate button above the post title field and title recommendations in a modal.
2. Post editor sidebar showing Generate Excerpt button and generated excerpt.
3. Post editor sidebar showing Generate AI Summary button and the generated content summary within an AI Summary block.
4. Post editor sidebar showing Generate featured image button and the generated featured image preview with Alt Text, Title, and Description.
5. Post editor showing Generate Image flows.
6. Media Library showing Generate Image flows.
7. Image block settings showing Generate Alt Text button and the generated alt text.
8. Post editor sidebar showing Generate Review Notes flows.
9. Abilities Explorer admin screen listing available AI abilities with filters, providers, and test actions.
10. Abilities Explorer's view details screen showing an AI ability’s description, provider, input schema, output schema, and raw data.
11. Abilities Explorer's test ability screen showing JSON input data, validation, and input schema reference for an AI ability.
12. AI settings screen showing toggles to enable specific experiments.

== Changelog ==

= 0.8.0 - 2026-04-23 =

**Added**

* New Experiment: Refine from Notes, automatically apply editorial notes to content ([#289](https://github.com/WordPress/ai/pull/289)).
* AI Status and AI Capabilities dashboard widgets, plus framework for registering new dashboard widgets ([#311](https://github.com/WordPress/ai/pull/311)).
* Integrates Gutenberg's Guidelines allowing abilities to respect site-wide editorial standards ([#359](https://github.com/WordPress/ai/pull/359)).
* Check `wp_supports_ai()` before initializing experiments ([#268](https://github.com/WordPress/ai/pull/268)).
* Admin redirect from the old `ai` page to the new `ai-wp-admin` page ([#424](https://github.com/WordPress/ai/pull/424)).
* Set the new `gpt-image-2` model for our preferred model list ([#456](https://github.com/WordPress/ai/pull/456)).

**Changed**

* Promote Image Generation from an Experiment to a Feature ([#418](https://github.com/WordPress/ai/pull/418)).
* Title Generation now utilizes a modal for editing and regeneration before applying changes to the Post Title ([#290](https://github.com/WordPress/ai/pull/290)).
* Update feature descriptions to include AI provider model supports ([#377](https://github.com/WordPress/ai/pull/377)).
* Update button loading states to match the standard loading pattern ([#382](https://github.com/WordPress/ai/pull/382), [#389](https://github.com/WordPress/ai/pull/389), [#396](https://github.com/WordPress/ai/pull/396),[#433](https://github.com/WordPress/ai/pull/433), [#449](https://github.com/WordPress/ai/pull/449)).
* Refactor `Main` bootstrap class ([#404](https://github.com/WordPress/ai/pull/404)).
* Allow bulk enabling/disabling Experiments in groups ([#422](https://github.com/WordPress/ai/pull/422)).
* Improve visual hierarchy on the AI settings page so card titles are more prominent than the toggle labels ([#431](https://github.com/WordPress/ai/pull/431)).
* Reduce the context we send when running Review Notes to decrease the amount of tokens used ([#434](https://github.com/WordPress/ai/pull/434)).
* Refactor `strpos` to `str_starts_with` and `str_contains` ([#438](https://github.com/WordPress/ai/pull/438)).
* Render Review Notes only on post types that support `editor.notes` ([#444](https://github.com/WordPress/ai/pull/444)).
* Improve accessibility of the Meta Description modal: inline "Copied!" confirmation on the copy button and accessibleWhenDisabled on disabled controls ([#445](https://github.com/WordPress/ai/pull/445)).
* Refactor `Asset_Loader` class and add error checking when dependencies are missing ([#458](https://github.com/WordPress/ai/pull/458)).

**Removed**

* Remove references to DALL·E image models ([#414](https://github.com/WordPress/ai/pull/414)).

**Fixed**

* Excerpt and Title generation no longer include conversational preambles, wrapper quotes, markdown, or meta-commentary when using smaller language models ([#440](https://github.com/WordPress/ai/pull/440)).
* Defer failed `Requirements` messages until translation functions are available ([#453](https://github.com/WordPress/ai/pull/453)).

= 0.7.0 - 2026-04-09 =

* **Added:** New Experiment: Content Classification to generate taxonomy terms based on post content ([#313](https://github.com/WordPress/ai/pull/313)).
* **Added:** New Experiment: SEO Descriptions that provides AI-generated meta description support ([#318](https://github.com/WordPress/ai/pull/318)).
* **Added:** Added a bulk "Generate Alt Text" action to Media Library to generate alt text for multiple images at once ([#330](https://github.com/WordPress/ai/pull/330)).
* **Added:** Added Category filtering to the Abilities table to improve organization and discoverability ([#355](https://github.com/WordPress/ai/pull/355)).
* **Added:** Added extensibility hooks for customizing system instructions, and post context during AI operations ([#304](https://github.com/WordPress/ai/pull/304)).
* **Added:** Added a new `wpai_has_ai_credentials` filter to allow 3rd parties to modify the credential detection logic, for instance to support non-API-key connectors to report their configured status ([#337](https://github.com/WordPress/ai/pull/337)).
* **Changed:** Adjust Alt Text Generation to better align with the W3C Alt Text decision tree guidance ([#374](https://github.com/WordPress/ai/pull/374)).
* **Changed:** Updated AI settings page leveraging modern `wp-build` DataForm route ([#340](https://github.com/WordPress/ai/pull/340), [#376](https://github.com/WordPress/ai/pull/376)).
* **Changed:** Revised Feature and Experiment Lifecycle and other documentation updates ([#326](https://github.com/WordPress/ai/pull/326), [#329](https://github.com/WordPress/ai/pull/329)).
* **Changed:** Update some of our system instructions to prompt the LLM to return content in the same language as the original content they were given ([#357](https://github.com/WordPress/ai/pull/357)).
* **Changed:** Updated end-to-end tests to resolve flaky failures and account for markup changes in the Connectors screen ([#360](https://github.com/WordPress/ai/pull/360)).
* **Changed:** Updated preferred models to more recent ones for the three default providers ([#361](https://github.com/WordPress/ai/pull/361)).
* **Changed:** Updated provider compatibility checks to use the AI Client's built-in `is_supported_*` methods for improved validation and error reporting ([#362](https://github.com/WordPress/ai/pull/362)).
* **Changed:** Updated the PR preview workflow to use a preferred WordPress version for improved consistency during testing ([#366](https://github.com/WordPress/ai/pull/366)).
* **Changed:** Switch to using a `Button` component instead of a `ToolbarButton` component within the Title Generation Experiment when in normal editing mode (non-template mode) ([#375](https://github.com/WordPress/ai/pull/375)).
* **Removed:** Unneeded `function_exists` checks ([#378](https://github.com/WordPress/ai/pull/378)).
* **Fixed:** Improved error messages when Image Generation or Editing fails due to incompatible providers ([#332](https://github.com/WordPress/ai/pull/332)).
* **Fixed:** Fixed an issue where Title Generation could fail when using the Anthropic provider ([#341](https://github.com/WordPress/ai/pull/341)).
* **Fixed:** Invalid schema type in the summarization ability that prevented proper execution in some environments ([#347](https://github.com/WordPress/ai/pull/347)).
* **Fixed:** Fixed an issue where the Generate Alt Text button could appear when an Image block was not selected, particularly when working with Patterns ([#356](https://github.com/WordPress/ai/pull/356)).
* **Fixed:** Fixed an issue where repeated calls to load system instructions could return empty content ([#358](https://github.com/WordPress/ai/pull/358)).
* **Fixed:** Fixed an issue where retrieving post content did not always return the most recently edited version ([#367](https://github.com/WordPress/ai/pull/367)).

= 0.6.0 - 2026-03-20 =

**There are Breaking Changes in this release.**

* **Breaking Changes:** Refactor `Experiments` to be a type of `Feature`, improving how functionality is organized and surfaced ([#316](https://github.com/WordPress/ai/pull/316)).

The following classes have been removed. Anyone that was directly using these will need to make updates to utilize the correct replacements: `Abstract_Experiment`, `Invalid_Experiment_Metadata_Exception`, `Invalid_Experiment_Exception`, `Experiment_Loader`, `Experiment_Registry`.

* **Breaking Changes:** Standardize the Title Generation Ability to align with other registered Abilities ([#227](https://github.com/WordPress/ai/pull/227)).

The `ai/title-generation` Ability now uses a `context` argument instead of a `post_id` argument in the `input_schema`. Anyone directly using this Ability will need to make updates to account for that.

* **Added:** New Experiment: Image Editing via prompt-based image refining in the Post Editor and Media Library ([#292](https://github.com/WordPress/ai/pull/292)).
* **Added:** New Experiment: Image Editing via expanding or removing background and removing or replacing items in the Media Libary ([#305](https://github.com/WordPress/ai/pull/305), [#312](https://github.com/WordPress/ai/pull/312)).
* **Changed:** Rename the plugin from "AI Experiments" to "AI" ([#287](https://github.com/WordPress/ai/pull/287)).
* **Changed:** Replace `Invalid_Experiment_Exception` with `_doing_it_wrong()` ([#303](https://github.com/WordPress/ai/pull/303)).
* **Changed:** Rename hook prefixes in `helpers.php` ([#315](https://github.com/WordPress/ai/pull/315)).
* **Changed:** Rename plugin constants to `WPAI_*` ([#317](https://github.com/WordPress/ai/pull/317)).
* **Changed:** Refactor the upgrade routine and add v0.6.0 migrations ([#321](https://github.com/WordPress/ai/pull/321)).
* **Changed:** Move the Generate Alt Text button to the new Content tab for improved discoverability ([#306](https://github.com/WordPress/ai/pull/306)).
* **Changed:** Remove stray "AI" references from UI for improved consistency ([#320](https://github.com/WordPress/ai/pull/320)).
* **Changed:** Update documentation ([#314](https://github.com/WordPress/ai/pull/314)).
* **Fixed:** Remove duplicate error display in the Generate Alt Text flow ([#255](https://github.com/WordPress/ai/pull/255)).

= 0.5.0 - 2026-03-12 =

* **Added:** Switch to using AI Client bundled in WordPress 7.0 ([#275](https://github.com/WordPress/ai/pull/275), [#301](https://github.com/WordPress/ai/pull/301)).
* **Changed:** Bump WordPress minimum supported version from 6.9 to 7.0 ([#272](https://github.com/WordPress/ai/pull/272)).
* **Changed:** Bump WordPress tested-up-to version 7.0 ([#272](https://github.com/WordPress/ai/pull/272)).
* **Changed:** Migrate credentials from the AI Credentials to the new Connectors screen ([#286](https://github.com/WordPress/ai/pull/286)).
* **Changed:** Improve documentation and plugin assets ([#280](https://github.com/WordPress/ai/pull/280), [#281](https://github.com/WordPress/ai/pull/281), [#291](https://github.com/WordPress/ai/pull/291), [#293](https://github.com/WordPress/ai/pull/293), [#296](https://github.com/WordPress/ai/pull/296)).
* **Removed:** No longer using AI Client via Composer package ([#271](https://github.com/WordPress/ai/pull/271)).

= 0.4.1 - 2026-03-06 =

* **Fixed:** Issues with 0.4.0 release merge and deploy ([#266](https://github.com/WordPress/ai/pull/266)).

= 0.4.0 - 2026-03-05 =

* **Added:** Inline Image Generation directly in the post editor, enabling users to generate images without leaving authoring/editing flows ([#235](https://github.com/WordPress/ai/pull/235)).
* **Added:** Generate Image within the Media Library with prompt-based image generation workflows ([#258](https://github.com/WordPress/ai/pull/258)).
* **Added:** Generate Review Notes experiment to analyze post content or individual blocks and suggest refinements via Notes comments in the editor ([#260](https://github.com/WordPress/ai/pull/260), [#267](https://github.com/WordPress/ai/pull/267)).
* **Added:** Split editor and admin experiments within the settings page ([#232](https://github.com/WordPress/ai/pull/232)).
* **Added:** Contextual help text to the Abilities Explorer screen to assist users in understanding what Abilities are and how to use them ([#243](https://github.com/WordPress/ai/pull/243)).
* **Changed:** Update “Generate Summary” button style to use consistent UI with other buttons in the ediot ([#253](https://github.com/WordPress/ai/pull/253)).
* **Changed:** Standardize Abilities invocation using the `runAbility` helper to improve consistency across API calls ([#228](https://github.com/WordPress/ai/pull/228)).
* **Changed:** Make provider labels in the Abilities Explorer translatable and adjust badge styling for clarity ([#247](https://github.com/WordPress/ai/pull/247)).
* **Changed:** Improve Abilities Explorer table layout by aligning spacing and styles with WordPress admin table conventions ([#248](https://github.com/WordPress/ai/pull/248)).
* **Changed:** Improve the Ability test page with better internationalization and add copy-to-clipboard functionality ([#256](https://github.com/WordPress/ai/pull/256)).
* **Removed:** Remove unused checkbox column from the Abilities Explorer table, as it was not tied to any bulk actions ([#246](https://github.com/WordPress/ai/pull/246)).
* **Fixed:** Fix the position and behavior of the “Copy” button in code blocks within the Abilities Explorer ([#245](https://github.com/WordPress/ai/pull/245)).

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

= 0.2.1 - 2026-01-26 =

* **Added:** Introduced a shared `AI_Service` layer to standardize provider access across experiments ([#101](https://github.com/WordPress/ai/pull/101)).
* **Changed:** Documentation updates ([#195](https://github.com/WordPress/ai/pull/195)).
* **Fixed:** Guarded against `preg_replace()` returning `null` to prevent content corruption in `normalize_content()` ([#177](https://github.com/WordPress/ai/pull/177)).
* **Security:** Change our user permission checks to use `edit_post` instead of `read_post` ([GHSA-mxf5-gp98-93wv](https://github.com/WordPress/ai/security/advisories/GHSA-mxf5-gp98-93wv)).

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

= 0.1.1 - 2025-12-01 =

* **Added:** Link to the plugin settings screen from the plugin list table ([#98](https://github.com/WordPress/ai/pull/98)).
* **Added:** WordPress Playground live preview integration ([#85](https://github.com/WordPress/ai/pull/85)).
* **Added:** RTL language support and inlining for performance ([#113](https://github.com/WordPress/ai/pull/113)).
* **Changed:** Updated namespace to `ai_experiments` ([#111](https://github.com/WordPress/ai/pull/111)).
* **Changed:** Bumped WP AI Client from `dev-trunk` to 0.2.0 ([#118](https://github.com/WordPress/ai/pull/118), [#122](https://github.com/WordPress/ai/pull/122), [#125](https://github.com/WordPress/ai/pull/125)).
* **Removed:** Valid AI credentials check from the Experiment `is_enabled` check ([#120](https://github.com/WordPress/ai/pull/120)).
* **Removed:** Example Experiment registration ([#121](https://github.com/WordPress/ai/pull/121)).
* **Fixed:** Bug in asset loader causing missing dependencies ([#113](https://github.com/WordPress/ai/pull/113)).

= 0.1.0 - 2025-11-26 =

First public release of the AI Experiments plugin, introducing a framework for exploring experimental AI-powered features in WordPress. 🎉

* **Added:** Experiment registry and loader system for managing AI features
* **Added:** Abstract experiment base class for consistent feature development
* **Added:** Experiment: Title Generation
* **Added:** Basic admin settings screen with toggle support
* **Added:** Initial integration with WP AI Client SDK and Abilities API
* **Added:** Utilities Ability for common AI tasks and testing

== Upgrade Notice ==

= 0.6.0 =
This version includes Breaking Changes.

= 0.5.0 =
This version bumps the WordPress minimum supported version from 6.9 to 7.0.
