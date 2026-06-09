=== AI ===
Contributors:      wordpressdotorg
Tags:              ai, artificial intelligence, experiments, abilities, mcp
Tested up to:      7.0
Stable tag:        1.0.1
License:           GPL-2.0-or-later
License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html

AI features, experiments and capabilities for WordPress.

== Description ==

The AI plugin brings AI-powered features directly into your WordPress admin and editing experience.

**What's Inside:**

This plugin is built on the [AI Building Blocks for WordPress](https://make.wordpress.org/ai/2025/07/17/ai-building-blocks) initiative, combining the AI Client library and Abilities API into a unified experience. It serves as both a practical tool for content creators and a reference implementation for developers.

**Current Features:**

* **Abilities Explorer** – Browse and interact with registered AI abilities from a dedicated admin screen.
* **AI Request Logging** – Logs AI requests for observability and debugging.
* **Alt Text Generation** - Generate descriptive alt text for images to improve accessibility.
* **Comment Moderation** - Automatically moderate comments based on toxicity detection and sentiment analysis.
* **Connector Approvals** - Require explicit administrator approval before plugins or themes can use AI connectors configured on this site.
* **Content Classification** – Suggests relevant tags and categories to organize content.
* **Content Resizing** - Shorten, expand, or rephrase selected block content.
* **Content Summarization** - Summarizes long-form content into digestible overviews.
* **Dashboard Widgets** - AI Status and AI Capabilities widgets, plus framework for registering new ones.
* **Editorial Notes** - Reviews post content block-by-block and adds Notes with suggestions for Accessibility, Readability, Grammar, and SEO.
* **Editorial Updates** - Automatically apply editorial notes to content.
* **Excerpt Generation** - Automatically create concise summaries for your posts.
* **Experiment Framework** - Opt-in system that lets you enable only the AI features you want to use.
* **Guidelines** - Allows abilities to respect site-wide editorial standards.
* **Image Generation and Editing** - Create and edit images from post content in the editor, also via the Media Library.
* **Meta Description Generation** - Generates meta description suggestions and integrates those with various SEO plugins.
* **Multi-Provider Support** - Works with popular AI providers like OpenAI, Google, and Anthropic.
* **Title Generation** - Generate title suggestions for your posts with a single click. Perfect for brainstorming headlines or finding the right tone for your content.

**Coming Soon:**

We're actively developing new features to enhance your WordPress workflow:

* **Type Ahead** – Contextual type-ahead assistance for suggestions while typing.
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
3. Go to `Settings -> Connectors` and set up at least one AI connector.
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
3. Post editor sidebar showing Generate AI Summary button and the generated content summary within a Content Summary block.
4. Post editor sidebar showing Generate featured image button and the generated featured image preview with Alt Text, Title, and Description.
5. Post editor showing Generate Image flows.
6. Media Library showing Generate Image flows.
7. Image block settings showing Generate Alt Text button and the generated alt text.
8. Post editor sidebar showing Generate Editorial Notes flows.
9. Abilities Explorer admin screen listing available AI abilities with filters, providers, and test actions.
10. Abilities Explorer's view details screen showing an AI ability’s description, provider, input schema, output schema, and raw data.
11. Abilities Explorer's test ability screen showing JSON input data, validation, and input schema reference for an AI ability.
12. AI settings screen showing toggles to enable specific experiments.
#. Comments admin screen showing AI-powered comment moderation features, including color-coded badges for toxicity scoring and comment sentiment.

== Changelog ==

= 1.0.1 - 2026-05-27 =

**Added**

- New helper functions that are used to determine if we have valid AI Connector credentials ([#603](https://github.com/WordPress/ai/pull/603)).
- New helper methods, `is_globally_enabled` and `is_individually_enabled` to help tell if a feature is enabled individually or if features are globally enabled ([#604](https://github.com/WordPress/ai/pull/604)).

**Changed**

- Removed the description from the Abilities listing within the Abilities Explorer ([#592](https://github.com/WordPress/ai/pull/592)).
- Filter Guideline queries by the guideline type content ([#593](https://github.com/WordPress/ai/pull/593)).
- Use the new `has_connector_authentication` instead of `is_connector_configured` to avoid unnecessary API requests ([#603](https://github.com/WordPress/ai/pull/603)).

**Removed**

- Deprecated `__nextHasNoMarginBottom` prop ([#609](https://github.com/WordPress/ai/pull/609)).

**Fixed**

- Utilize a new `is_connector_configured` function to properly determine if a connector is configured, whether via an API key, constant or ENV var ([#537](https://github.com/WordPress/ai/pull/537)).
- "Generate Editorial Note" button appearing in the block settings menu during post revisions ([#591](https://github.com/WordPress/ai/pull/591)).
- If the Connector Approvals experiment is turned on, ensure we don't over-aggressively block functionality in the AI plugin that isn't actually making requests, like Request Logging ([#595](https://github.com/WordPress/ai/pull/595)).
- Better matching of the originating code when the Connector Approvals experiment is on ([#595](https://github.com/WordPress/ai/pull/595)).
- Focus loss issues when interacting with Purge actions in the Request Logs experiments page ([#599](https://github.com/WordPress/ai/pull/599)).
- Disable the "Purge All" button when no logs are available to purge ([#599](https://github.com/WordPress/ai/pull/599)).
- AI Status feature checklist properly shows if an individual feature is enabled even if globally features are disabled ([#604](https://github.com/WordPress/ai/pull/604)).
- Ensure focus isn't lost when buttons enter disabled state during Alt Text Generation, Content Classification, Content Summarization, Excerpt Generation, Featured Image Generation, and Title Generation ([#608](https://github.com/WordPress/ai/pull/608), [#611](https://github.com/WordPress/ai/pull/611)).
- Settings page strings, which are enqueued as script modules, are now localized at runtime ([#613](https://github.com/WordPress/ai/pull/613)).
- Connector Approvals "Dismiss" button failing for pending requests whose key contains a slash ([#615](https://github.com/WordPress/ai/pull/615)).
- Hide empty provider capabilities section in the dashboard widget ([#616](https://github.com/WordPress/ai/pull/616)).
- Playground and test configs now target the latest WordPress release instead of the beta release ([#626](https://github.com/WordPress/ai/pull/626)).
- Connector Approvals notice no longer overlaps the page header on the AI Request Logs screen ([#628](https://github.com/WordPress/ai/pull/628)).

= 1.0.0 - 2026-05-19 =

**Added**

- New Experiment: Request Logging that provides observability for all AI operations ([#437](https://github.com/WordPress/ai/pull/437)).
- New Experiment: Connector Approvals that allows administrators the ability to determine which plugins can access which AI connectors ([#467](https://github.com/WordPress/ai/pull/467)).
- Integrate Alt Text generation into the experimental media editor ([#446](https://github.com/WordPress/ai/pull/446)).
- Sorting and filtering in Comments screen by Toxicity and/or Sentiment ([#518](https://github.com/WordPress/ai/pull/518)).
- Toxicity and Sentiment labelling in admin dashboard for comments ([#518](https://github.com/WordPress/ai/pull/518)).

**Changed**

- Disable the Summarization button until content reaches a certain length ([#492](https://github.com/WordPress/ai/pull/492)).
- Refined image generation loading state ([#512](https://github.com/WordPress/ai/pull/512)).
- Featured image button now hides when image is already set ([#512](https://github.com/WordPress/ai/pull/512)).
- When no AI provider is configured and a feature is triggered, show actionable guidance directing users to configure an AI Connector ([#523](https://github.com/WordPress/ai/pull/523)).
- Update Meta Description loading state and remove duplicate heading in modal ([#527](https://github.com/WordPress/ai/pull/527)).
- Rename "Review Notes" experiment to "Editorial Notes" and "Refine from Notes" experiment to "Editorial Updates" ([#528](https://github.com/WordPress/ai/pull/528)).
- Keep comments without moderation metadata visible when sorting by Comment Moderation columns ([#538](https://github.com/WordPress/ai/pull/538)).
- Updated plugin banner and icons ([#546](https://github.com/WordPress/ai/pull/546)).
- Show a notice when a user has chosen a provider that no longer exists ([#552](https://github.com/WordPress/ai/pull/552)).
- When no provider is configured, show an error notice instead of an admin notice for alt text generation ([#561](https://github.com/WordPress/ai/pull/561)).
- Standardize error message text ([#562](https://github.com/WordPress/ai/pull/562)).
- Abilities Explorer page heading ([#585](https://github.com/WordPress/ai/pull/585)).

**Fixed**

- Ensure we properly use the new client-side Abilities API ([#482](https://github.com/WordPress/ai/pull/482)).
- Keep keyboard focus on the Provider select when resetting per-feature developer settings to default ([#532](https://github.com/WordPress/ai/pull/532)).
- Deduplicate provider API requests on the settings page when developer mode is toggled on ([#542](https://github.com/WordPress/ai/pull/542)).
- Update the Playground Preview workflow to use `pluginData` instead of `pluginZipFile` ([#548](https://github.com/WordPress/ai/pull/548)).
- Empty space shown for Model field when saved provider no longer exists in developer settings ([#552](https://github.com/WordPress/ai/pull/552)).
- Prevent analyzing newly inserted comments when no provider is configured ([#554](https://github.com/WordPress/ai/pull/554)).
- Ensure the meta description modal doesn't open if no provider is configured ([#558](https://github.com/WordPress/ai/pull/558)).
- False error for alt text generation on decorative images in media library ([#559](https://github.com/WordPress/ai/pull/559)).
- Show a failed badge when comment analysis fails ([#568](https://github.com/WordPress/ai/pull/568)).
- Correct RTL rendering of directional icons, runtime-set styles, and inline styles in the admin UI ([#573](https://github.com/WordPress/ai/pull/573)).
- Add notice to standalone image generation when there is no provider connected ([#575](https://github.com/WordPress/ai/pull/575)).
- Ensure we show a more specific error message when no valid AI connector is in place and we try to generate a featured image ([#576](https://github.com/WordPress/ai/pull/576)).
- Improve keyboard focus visibility for suggested term actions in content classification ([#580](https://github.com/WordPress/ai/pull/580)).
- User-facing text in several experiments is now fully translatable, and JS-side translations are loaded at runtime ([#582](https://github.com/WordPress/ai/pull/582)).
- Make title generation and content classification UI react to current editor state ([#584](https://github.com/WordPress/ai/pull/584)).
- Ensure global AI enabled options are migrated properly ([#586](https://github.com/WordPress/ai/pull/586)).

= 0.9.0 - 2026-05-07 =

**Added**

* New Experiment: Comment Moderation to automatically moderate comments based on toxicity detection and sentiment analysis ([#155](https://github.com/WordPress/ai/pull/155), [#516](https://github.com/WordPress/ai/pull/516)).
* New Experiment: Content Resizing to shorten, expand, or rephrase selected block content ([#331](https://github.com/WordPress/ai/pull/331)).
* Developer Mode settings page toggle to set the desired provider and model per feature ([#486](https://github.com/WordPress/ai/pull/486)).
* WP-CLI command, `wp ai alt-text generate`, for bulk alt text generation ([#436](https://github.com/WordPress/ai/pull/436)).
* Basic styles for the Content Summary block ([#510](https://github.com/WordPress/ai/pull/510)).

**Changed**

* Compress the AI settings page by moving the global AI toggle into the header with an infotip ([#455](https://github.com/WordPress/ai/pull/455)).
* Update AI settings page to use `@wordpress/ui` components and related UI adjustments ([#472](https://github.com/WordPress/ai/pull/472), [#488](https://github.com/WordPress/ai/pull/488), [#490](https://github.com/WordPress/ai/pull/490), [#491](https://github.com/WordPress/ai/pull/491), [#505](https://github.com/WordPress/ai/pull/505), [#519](https://github.com/WordPress/ai/pull/519)).
* AI-generated images are now saved with descriptive, slugified filenames derived from the post title or prompt instead of `ai-generated-image-<timestamp>` ([#471](https://github.com/WordPress/ai/pull/471)).
* For image generation, set guidelines as part of the prompt instead of system instructions ([#497](https://github.com/WordPress/ai/pull/497)).
* Update the Content Summary experiment to render the summary in a Group variation block instead of a Paragraph variation block ([#510](https://github.com/WordPress/ai/pull/510)).

**Fixed**

* Standards compliance switch from the custom `$builder->is_text_generation_supported()` method with the abstract `ensure_text_generation_supported()` method ([#465](https://github.com/WordPress/ai/pull/465)).
* Ability schema JSON viewer now stays LTR under RTL admin languages ([#485](https://github.com/WordPress/ai/pull/485)).
* Ensure the Generate Image button doesn't render in contexts that aren't valid ([#489](https://github.com/WordPress/ai/pull/489)).
* Localize several user-facing fallback error strings in image-generation and summarization flows ([#500](https://github.com/WordPress/ai/pull/500)).

**Security**

* Bump `serialize-javascript` from 6.0.2 to 7.0.5 ([#503](https://github.com/WordPress/ai/pull/503)).
* Bump `postcss` from 8.5.10 to 8.5.14 ([#503](https://github.com/WordPress/ai/pull/503)).
* Bump `minimatch` from 3.0.8 to 3.1.4 ([#503](https://github.com/WordPress/ai/pull/503)).

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
* Update button loading states to match the standard loading pattern ([#382](https://github.com/WordPress/ai/pull/382), [#389](https://github.com/WordPress/ai/pull/389), [#396](https://github.com/WordPress/ai/pull/396), [#433](https://github.com/WordPress/ai/pull/433), [#449](https://github.com/WordPress/ai/pull/449)).
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

Older changelog entries can be found in the [CHANGELOG.md](https://github.com/WordPress/ai/blob/trunk/CHANGELOG.md) file.

== Upgrade Notice ==

= 0.6.0 =
This version includes Breaking Changes.

= 0.5.0 =
This version bumps the WordPress minimum supported version from 6.9 to 7.0.
