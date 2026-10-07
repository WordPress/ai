=== AI ===
Contributors:      wordpressdotorg, dkotter, jeffpaul
Tags:              ai, artificial intelligence, experiments, abilities, mcp
Tested up to:      7.1
Stable tag:        1.4.0
License:           GPL-2.0-or-later
License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html

AI features, experiments and capabilities for WordPress.

== Description ==

The AI plugin brings AI-powered features directly into your WordPress admin and editing experience.

Requires the WordPress Block Editor.  The Classic Editor plugin and other non-Block Editor editing experiences are not supported.

**What's Inside:**

This plugin is built on the [AI Building Blocks for WordPress](https://make.wordpress.org/ai/2025/07/17/ai-building-blocks) initiative, combining the AI Client library and Abilities API into a unified experience. It serves as both a practical tool for content creators and a reference implementation for developers.

**Current Features:**

* **Abilities Explorer** – Browse and interact with registered AI abilities from a dedicated admin screen.
* **AI Request Logging** – Logs AI requests for observability and debugging.
* **Alt Text Generation** - Generate descriptive alt text for images to improve accessibility.
* **Comment Moderation** - Automatically moderate comments based on toxicity detection and sentiment analysis, and give each comment a value score.
* **Connector Approvals** - Require explicit administrator approval before plugins or themes can use AI connectors configured on this site.
* **Content Classification** – Suggests relevant tags and categories to organize content.
* **Content Resizing** - Shorten, expand, or rephrase selected block content.
* **Content Summarization** - Summarizes long-form content into digestible overviews.
* **Content Translation** - Translates paragraph and heading blocks, and optionally the post title, into a selected language from the post editor.
* **Custom Abilities** - Gates the plugin's general-purpose WordPress Abilities behind a single opt-in toggle.
* **Dashboard Widgets** - AI Status and AI Capabilities widgets, plus framework for registering new ones.
* **Editorial Notes** - Reviews post content block-by-block and adds Notes with suggestions for Accessibility, Readability, Grammar, and SEO.
* **Editorial Updates** - Automatically apply editorial notes to content.
* **Excerpt Generation** - Automatically create concise summaries for your posts.
* **Experiment Framework** - Opt-in system that lets you enable only the AI features you want to use.
* **Guidelines** - Allows abilities to respect site-wide editorial standards.
* **Image Generation and Editing** - Create and edit images from post content in the editor, also via the Media Library.
* **Key Encryption** - Encrypts AI provider API keys at rest using bundled libsodium encryption. Keys are transparently decrypted on read and re-encrypted on write. Disabling the experiment or deactivating the plugin restores plaintext keys.
* **Meta Description Generation** - Generates meta description suggestions and integrates those with various SEO plugins.
* **Multi-Provider Support** - Works with AI Connector plugins for providers such as OpenAI, Google, and Anthropic.
* **Slug Generation** - Suggest SEO-friendly permalink slugs for your posts from the permalink popover or the pre-publish panel, then edit and apply the one you want.
* **Suggest Reply** - Adds a "Suggest Reply" action to the Comments screen and Activity widget, enabling moderators to quickly generate comment reply suggestions.
* **Title Generation** - Generate title suggestions for your posts with a single click. Perfect for brainstorming headlines or finding the right tone for your content.
* **Type Ahead** – Contextual type-ahead assistance for suggestions while typing.

**Provider Setup:**

The AI plugin does not include provider credentials or provider implementations by itself. To use AI-powered features, install and activate at least one AI Connector plugin, then configure its credentials in `Settings -> Connectors`. Features may appear unavailable until a connector is installed, authenticated, and capable of the required operation.

Provider connector plugins include [Anthropic](https://wordpress.org/plugins/ai-provider-for-anthropic), [Google](https://wordpress.org/plugins/ai-provider-for-google), [OpenAI](https://wordpress.org/plugins/ai-provider-for-openai), and [others](https://wordpress.org/plugins/tags/connector/).

**Coming Soon:**

We're actively developing new features to enhance your WordPress workflow:

* **AI Playground** – Experiment with different AI models and providers.
* **Content Assistant** – AI-powered writing and editing in Gutenberg.
* **Site Agent** – Natural language WordPress administration.
* **Workflow Automation** – AI-driven task automation.

This is an experimental plugin; functionality may change as we gather feedback from the community.

**Roadmap:**

You can view the active plugin roadmap in a filtered view in the WordPress AI [GitHub Project Board](https://github.com/orgs/WordPress/projects/240/views/1).

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/ai` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Install and activate at least one AI Connector plugin, then go to `Settings -> Connectors` and configure its credentials.
4. Go to `Settings -> AI` and enable the features or experiments you want to use.
5. Start experimenting with AI features! For the Title Generation experiment, edit a post and click into the title field. You should see a `Generate/Regenerate` button above the field. Click that button and after the request is complete, title suggestions will be displayed in a modal. Choose the title you like and click the `Select` button to insert it into the title field.

== For Developers ==

The AI plugin is designed to be studied, extended, and built upon. Whether you're a plugin developer, agency, or hosting provider, here's what you can do:

**Extend the Plugin:**

* **Build Custom Experiments** - Use the `Abstract_Feature` base class to create your own AI-powered features.
* **Pre-configure Providers** - Hosts and agencies can set up AI Connector plugins so users don't need their own API keys.
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

The plugin can work with provider connector plugins from [Anthropic](https://wordpress.org/plugins/ai-provider-for-anthropic) (Claude), [Google](https://wordpress.org/plugins/ai-provider-for-google) (Gemini), [OpenAI](https://wordpress.org/plugins/ai-provider-for-openai), and [others](https://wordpress.org/plugins/tags/connector/). Install and activate the relevant connector plugin, then configure it in `Settings -> Connectors`.

= Do I need an API key to use the features? =

Yes, currently you need to provide your own API key for the configured AI Connector plugin, such as OpenAI, Google AI, or Anthropic.

= How much does it cost? =

The plugin itself is free, but you'll need to pay for API usage from your chosen AI provider. Costs vary by provider and usage. Most providers offer free trial credits to get started. There are some local, open source, and free providers (like [Ollama](https://wordpress.org/plugins/ai-provider-for-ollama/)) that can be used as well.

= Can I use this without coding knowledge? =

Absolutely! The plugin is designed for content creators and site administrators. Once your AI Connectors are configured, you can use the AI functionality directly from the post editor.

= Does this plugin support the Classic Editor? =

No.

The AI plugin currently supports only the Block Editor (aka Gutenberg).  The plugin is designed around modern editor APIs, block-based content workflows, and the evolving editing capabilities being developed within WordPress core (including Gutenberg).

The Block Editor has been the default WordPress editing experience since WordPress 5.0 in 2018 and remains the primary focus of active editor development.  Concentrating development efforts on the Block Editor enables the project to ship new features, experiments, and integrations more quickly while avoiding the complexity of maintaining parallel implementations across multiple editing experiences.

Although the Classic Editor plugin continues to have a large installed base, the Core AI team has chosen to prioritize innovation and experimentation within the Block Editor ecosystem.  At this time there are no plans to add official Classic Editor support.

= Where can I get help or report issues? =

You can ask questions in the [#core-ai channel on WordPress Slack](https://wordpress.slack.com/archives/C08TJ8BPULS) or report issues on the [GitHub repository](https://github.com/WordPress/ai/issues).

== Screenshots ==

1. Feature: Image Generation and Editing. Post editor sidebar showing Generate featured image button and the generated featured image preview with Alt Text, Title, and Description.
2. Feature: Image Generation and Editing. Post editor showing Generate Image flows.
3. Feature: Image Generation and Editing. Media Library showing Generate Image flows.
4. Editor Experiment: Alt Text Generation. Image block settings showing Generate Alt Text button and the generated alt text.
5. Editor Experiment: Alt Text Generation. Bulk alt text generation from within the Media Library.
6. Editor Experiment: Comment Moderation. Comments admin screen showing AI-powered comment moderation features, including color-coded badges for toxicity scoring and comment sentiment.
7. Editor Experiment: Comment Moderation. Recent Comments section of the Activity widget showing Sentiment and Toxicity scores.
8. Editor Experiment: Content Classification. AI-powered suggestions for post tags and categories based on content analysis.
9. Editor Experiment: Content Resizing. Shorten, expand, or rephrase selected block content.
10. Editor Experiment: Content Summarization. Post editor sidebar showing Generate AI Summary button and the generated content summary within a Content Summary block.
11. Editor Experiment: Content Translation. Translates paragraph and heading blocks, and optionally the post title, into a selected language from the post editor.
12. Editor Experiment: Editorial Notes. Post editor sidebar showing Generate Editorial Notes flows.
13. Editor Experiment: Editorial Updates. Applies pending Editorial Notes to your content automatically.
14. Editor Experiment: Excerpt Generation. Post editor sidebar showing Generate Excerpt button and generated excerpt.
15. Editor Experiment: Meta Description Generation. Generates meta description suggestions and integrates those with various SEO plugins.
16. Slug Generation: Suggests SEO-friendly permalink slugs from post title or content, in the permalink popover and the pre-publish panel.
17. Editor Experiment: Title Generation. Post editor showing Generate button above the post title field and title recommendations in a modal.
18. Editor Experiment: Type-ahead Text. Ghost text suggestions while writing paragraphs in the block editor.
19. Dashboard Widgets. AI Capabilities widget showing Abilities Explorer summary and connected AI providers and model capabilities.
20. Dashboard Widgets. AI Status widget showing three step configuration process.
21. Dashboard Widgets. AI Status widget showing connected AI providers and enabled Features and Experiments.
22. Admin Experiment: Abilities Explorer. Abilities Explorer admin screen listing available AI abilities with filters, providers, and test actions.
23. Admin Experiment: Abilities Explorer. Abilities Explorer's view details screen showing an AI ability’s description, provider, input schema, output schema, and raw data.
24. Admin Experiment: Abilities Explorer. Abilities Explorer's test ability screen showing JSON input data, validation, and input schema reference for an AI ability.
25. Admin Experiment: AI Request Logging. Logs AI requests for observability and debugging. View detailed logs under Tools.
26. Admin Experiment: Connector Approvals. Require explicit administrator approval before plugins or themes can use AI connectors configured on this site.
27. Admin Experiment: Key Encryption. Encrypts AI provider API keys at rest using bundled libsodium encryption. Keys are transparently decrypted on read and re-encrypted on write. Disabling the experiment or deactivating the plugin restores plaintext keys.
28. Admin Experiment: Suggest Reply. Adds a "Suggest Reply" action to the Comments screen and Activity widget, enabling moderators to quickly generate comment reply suggestions.
29. AI Settings. AI settings screen showing toggles to enable specific experiments.
30. Developer Tool: Export and Import settings options.

== Changelog ==

= 1.4.0 - 2026-10-05 =

**Added**

- When comment moderation is run, calculate a value score, providing a relevance signal (0–1) for each comment based on how substantive and on-topic it is relative to the post it was left on. Show this score in a new column on the comment screen ([#681](https://github.com/WordPress/ai/pull/681)).
- New experiment, Markdown Feeds, that creates markdown feeds at `/feed/markdown/` (available in every feed context) and of individual posts and pages via `?output_format=markdown` ([#855](https://github.com/WordPress/ai/pull/855)).
- User-triggered retry for Content Translation if it fails ([#941](https://github.com/WordPress/ai/pull/941)).
- Limit comment moderation bulk analysis queue size ([#972](https://github.com/WordPress/ai/pull/972)).
- Storage and CRUD layer for embedding vectors (new `wpai_embeddings` table) that can be used to recording the provider and model alongside every vector ([#976](https://github.com/WordPress/ai/pull/976)).
- 9 new default target languages to the Content Translation experiment ([#986](https://github.com/WordPress/ai/pull/986)).
- Guideline categories support to the Content Translation Ability ([#987](https://github.com/WordPress/ai/pull/987)).
- New `Vector_Math` and `Vector_Ranker` classes, allowing users to run similarity and ranking queries against vectors ([#993](https://github.com/WordPress/ai/pull/993)).
- Loading indicator for type-ahead suggestion requests ([#1029](https://github.com/WordPress/ai/pull/1029)).
- Integrate Alt Text Generation with the Image block’s decorative setting ([#1031](https://github.com/WordPress/ai/pull/1031)).

**Changed**

- Bump minimum supported WordPress version to 7.0.3 ([#1001](https://github.com/WordPress/ai/pull/1011)).
- Display specific taxonomy labels in Content Classification notice error messages instead of generic terminology ([#916](https://github.com/WordPress/ai/pull/916)).
- Show a notice and disable title translation when the post title is too short ([#967](https://github.com/WordPress/ai/pull/967)).
- Update to the latest version of the embedding code from the
PHP AI Client ([#975](https://github.com/WordPress/ai/pull/975)).
- Our `generate_embeddings` helper function now requires a
specific embedding model. Pass `model` (a model ID or a `ModelInterface`
instance) and, for a model ID, the `provider` that offers it. This is a
breaking change for anyone that happened to start using this function ([#975](https://github.com/WordPress/ai/pull/975)).
- Update Settings vertical ellipsis icon to tool icon ([#1001](https://github.com/WordPress/ai/pull/1001)).
- The `core/read-content` and `core/read-users` abilities are renamed to `core/content-query` and `core/users-query`. The old names keep working as deprecated aliases for now ([#1002](https://github.com/WordPress/ai/pull/1002)).
- Use `gpt-image-2.5-flare` as our default OpenAI image generation model ([#1023](https://github.com/WordPress/ai/pull/1023)).
- The `core/read-settings` ability has been renamed to `core/settings-get`. The old name keeps working as a deprecated alias for now ([#1087](https://github.com/WordPress/ai/pull/1087)).

**Deprecated**

- The `ai/get-post-details` ability. Use the single-post mode of `core/content-query` instead ([#1002](https://github.com/WordPress/ai/pull/1002)).

**Removed**

- Unnecessary URL validation that is now handled by WordPress core ([#1011](https://github.com/WordPress/ai/pull/1011)).
- The `Enable AI` header toggle from the AI settings page ([#985](https://github.com/WordPress/ai/pull/985)).

**Fixed**

- Ensure focus moves to the Accept button when content generation completes in the Content Resizing modal ([#917](https://github.com/WordPress/ai/pull/917)).
- Increase the default request timeout from 5 seconds to 30 seconds when Core revalidates API keys. If this validation request times out, Core will delete the API key so this is an attempt to fix that ([#947](https://github.com/WordPress/ai/pull/947)).
- Cached the active SEO plugin detection - with a TTL and immediate invalidation on any plugin activation/deactivation - so meta description meta-key lookups no longer re-scan active plugins on every request ([#973](https://github.com/WordPress/ai/pull/973)).
- Improves the Content Translation loading animation to preserve existing text and background colors ([#977](https://github.com/WordPress/ai/pull/977)).
- Settings page failing to render on Gutenberg 23.9+ after `@wordpress/dataviews` was removed from the private-apis allowlist ([#989](https://github.com/WordPress/ai/pull/989)).
- Ensure our guidelines integration reads published guideline rows from the `wp_knowledge` post type instead of the removed `wp_guideline` so those work with the latest version of Gutenberg. We don't migrate data from the old post type and taxonomy to the new so anyone that had previously set guidelines in an older version of Gutenberg will need to re-add those ([#988](https://github.com/WordPress/ai/pull/988)).
- Import core's prefixed PSR `EventDispatcher` in the vendored `EmbeddingBuilder` ([#1005](https://github.com/WordPress/ai/pull/1005)).
- Reserve space for type-ahead text generation when caret is at the end ([#1008](https://github.com/WordPress/ai/pull/1008)).
- Updated the settings page container `min-height` so that it spans the full viewport height ([#1013](https://github.com/WordPress/ai/pull/1013)).
- Fix focus style on collapsible card by updating the `@wordpress/dataviews` package to latest version ([#1035](https://github.com/WordPress/ai/pull/1035)).
- AI Request Logs now records HTTP error responses (4xx/5xx) from providers as `error` with the status code and message, instead of `success`; Gemini text generation requests are no longer mislabeled as `Metadata` ([#1040](https://github.com/WordPress/ai/pull/1040)).
- Hide the "Suggest Reply" button when the inline comment form is in Quick Edit mode ([#1049](https://github.com/WordPress/ai/pull/1049)).
- Editorial Notes only reviewing template blocks when "Show template" mode is enabled in the block editor ([#1053](https://github.com/WordPress/ai/pull/1053)).
- Insert the generated summary into the post content when Show template is enabled ([#1055](https://github.com/WordPress/ai/pull/1055)).
- Aligned the focus styles of Content Classification suggestion pills with the core Button component ([#1060](https://github.com/WordPress/ai/pull/1060)).
- Connector Approvals no longer blocks core's connector key check by attributing it to the provider plugin or to Gutenberg ([#1070](https://github.com/WordPress/ai/pull/1070)).
- Abilities Explorer no longer crashes when an ability's input property lists several types ([#1073](https://github.com/WordPress/ai/pull/1073)).
- Preserve HTML entities and text between shortcodes when normalizing content, while still encoding entity-encoded tags ([#1077](https://github.com/WordPress/ai/pull/1077)).
- Prevent `options.php` validation error when saving general settings with abilities active ([#1080](https://github.com/WordPress/ai/pull/1080)).
- Ensure post titles, the site tagline and excerpts no longer contain HTML entities in the markdown feeds ([#1086](https://github.com/WordPress/ai/pull/1086)).
- Ensure markdown feeds don't 404 when the rewrite rules were rebuilt without the feed in place ([#1110](https://github.com/WordPress/ai/pull/1110)).

**Security**

- Ensure we check for the `moderate_comments` capability before we allow bulk comment moderation and then check for the `edit_comment` capability for each individual comment that is being moderated ([GHSA-pjw7-q94g-4q34](https://github.com/WordPress/ai/security/advisories/GHSA-pjw7-q94g-4q34)).
- Harden the Playground preview publishing workflow to derive pull request and commit metadata from the triggering workflow run ([#978](https://github.com/WordPress/ai/pull/978)).
- Harden JSON data parsing on admin screen ([#991](https://github.com/WordPress/ai/pull/991)).

= 1.3.0 - 2026-08-18 =

**Added**

- New Experiment: Content Translation; translates Paragraph and Heading blocks—and optionally the post title—into a selected language directly from the post editor ([#747](https://github.com/WordPress/ai/pull/747)).
- New Experiment: Slug Generation; suggest SEO-friendly permalinks that can be set as the slug ([#897](https://github.com/WordPress/ai/pull/897), [#932](https://github.com/WordPress/ai/pull/932)).
- New Experiment: Custom Abilities. Gates the plugin's custom WordPress Abilities (`ai/get-post-details`, `ai/get-post-terms`, `core/read-settings`, `core/read-users`, `core/read-content`) behind a single opt-in toggle, so enabling it exposes all of them at once via the Abilities API. Note for anyone that is using these Abilities, you'll need to enable this new experiment first for those to be available ([#881](https://github.com/WordPress/ai/pull/881)).
- New Developer Tool: Import/Export functionality for non-sensitive AI settings ([#734](https://github.com/WordPress/ai/pull/734)).
- Cleanup plugin data when the plugin is deleted ([#692](https://github.com/WordPress/ai/pull/692)).
- AI-specific Site Health integration and status tests ([#734](https://github.com/WordPress/ai/pull/734)).
- New filters, `wpai_content_classification_available_terms`, `wpai_content_classification_min_confidence` and `wpai_content_classification_candidate_pool_size`, to allow more control over Content Classification ([#633](https://github.com/WordPress/ai/pull/633)).
- Prompt template extension points, making it easy for others to filter prompts and prompt builders ([#770](https://github.com/WordPress/ai/pull/770)).
- Brought the embedding code over from the PHP AI Client and load that conditionally so those using the AI plugin can start to take advantage of embedding generation ([#892](https://github.com/WordPress/ai/pull/892), [#946](https://github.com/WordPress/ai/pull/946)).
- Public `WordPress\AI\log_ai_request()` API so MCP servers and ability consumers can record requests in the AI Request Log ([#914](https://github.com/WordPress/ai/pull/914)).

**Changed**

- Updated all meta keys to use the `wpai_` prefix instead of just `ai_`. Note this changes the prefix on the `ai_generated`, `ai_generated_summary` and `ai_note` meta so if you are directly using those, update to using the `wpai_` names ([#867](https://github.com/WordPress/ai/pull/867)).
- Updated preferred models to more recent ones for the three default providers ([#913](https://github.com/WordPress/ai/pull/913)).
- Bump WordPress tested-up-to version 7.1 ([#934](https://github.com/WordPress/ai/pull/934)).
- Improve the relevance of category and tag suggestions produced by the
Content Classification experiment ([#633](https://github.com/WordPress/ai/pull/633)).
- Editorial Updates now links to the visual revisions screen when reviewing refined content, falling back to the classic revisions screen when visual revisions are unavailable ([#861](https://github.com/WordPress/ai/pull/861)).
- Reordered setting experiments list; grouped linked experiments and sorted editor experiments alphabetically ([#871](https://github.com/WordPress/ai/pull/871)).
- Improved keyboard focus handling when generating, accepting, or dismissing classification suggestions ([#873](https://github.com/WordPress/ai/pull/873)).
- The Abilities Explorer provider filter dropdown now includes custom providers, and the overview statistics count abilities by origin so custom-provider abilities remain in their Core/Plugins/Theme bucket ([#884](https://github.com/WordPress/ai/pull/884)).
- Set focus to the generated title textarea when generating a title ([#901](https://github.com/WordPress/ai/pull/901)).
- The `core/read-users` ability now returns collections ordered by display name, A to Z ([#948](https://github.com/WordPress/ai/pull/948)).

**Deprecated**

- The `AI_Service` class and the `get_ai_service()` helper introduced in 0.2.1 will be removed in the next major release. Neither is used anywhere in the plugin; experiments and abilities call `wp_ai_client_prompt()` directly ([#905](https://github.com/WordPress/ai/pull/905)).
- Filter `wpai_meta_description_result_temperature` is no longer being used and will be removed in the next release ([#913](https://github.com/WordPress/ai/pull/913)).

**Removed**

- No longer set custom temperature values on any of our requests ([#913](https://github.com/WordPress/ai/pull/913)).

**Fixed**

- The AI Request Log REST endpoint now registers its `operation` filter parameter, so it appears in the REST schema and a non-string value returns a 400 instead of causing a fatal error ([#758](https://github.com/WordPress/ai/pull/758)).
- Inline reply textarea not receiving focus after generating a suggested reply ([#877](https://github.com/WordPress/ai/pull/877)).
- Meta Description suggestions applied on pages and custom post types were lost on save when Yoast SEO was active ([#886](https://github.com/WordPress/ai/pull/886)).
- Improved accessibility and keyboard usability for the request logs provider/model details ([#889](https://github.com/WordPress/ai/pull/889)).
- Improved keyboard and focus handling for the Suggest Reply tone dropdown ([#907](https://github.com/WordPress/ai/pull/907)).
- Synchronized generating state across the inline and modal excerpt generation buttons ([#908](https://github.com/WordPress/ai/pull/908)).
- Ensure caller detection in the encryption experiment properly matches the calling plugin, not the host plugin ([#909](https://github.com/WordPress/ai/pull/909)).
- Synchronized loading state between the sidebar and block toolbar regenerate summary buttons ([#912](https://github.com/WordPress/ai/pull/912)).
- Preserve inline HTML when resizing content ([#915](https://github.com/WordPress/ai/pull/915)).
- Bulk actions no longer re-run when sorting or paginating the list after the action completes ([#928](https://github.com/WordPress/ai/pull/928)).
- Apply editorial updates to blocks that store editable text in the `value` attribute ([#930](https://github.com/WordPress/ai/pull/930)).

**Security**

- Ensure any content we render from the LLM or content we send to the LLM is properly sanitized ([#950](https://github.com/WordPress/ai/pull/950)).
- Add proper nonce check prior to bulk alt text and summarization generation ([GHSA-hfp9-55vw-ccjc](https://github.com/WordPress/ai/security/advisories/GHSA-hfp9-55vw-ccjc)).
- When passing a custom image URL to the Alt Text Generation Ability, ensure that URL is public, that it points to an allowed image type and that the final URL we download matches the initial one we verify ([GHSA-v2wx-9j88-4rqq](https://github.com/WordPress/ai/security/advisories/GHSA-v2wx-9j88-4rqq)).

= 1.2.0 - 2026-07-14 =

**Added**

- New Experiment: Suggest Reply; gives comment moderators a quick way to generate a reply to a comment through the admin ([#724](https://github.com/WordPress/ai/pull/724)).
- New "Advanced settings" option in Developer Tools to show/hide additional configuration options for features and experiments ([#842](https://github.com/WordPress/ai/pull/842)).
- Bulk "Generate AI Summary" action to the posts and pages list table, enabling summary generation for multiple posts at once ([#650](https://github.com/WordPress/ai/pull/650)).
- New `core/read-content` Ability with secure single-post and query modes (including include and opt-in fields) to enable read-only content access ([#739](https://github.com/WordPress/ai/pull/739)).
- New `core/read-users` Ability that retrieves a single user by ID, email, login, or nicename, or a filtered and paginated users collection, with sensitive fields opt-in and permission-gated ([#774](https://github.com/WordPress/ai/pull/774)).
- An inline admin notice when Connector Approvals are enabled and no AI connectors are yet approved, prompting admins to approve the AI plugin for use ([#830](https://github.com/WordPress/ai/pull/830)).
- Introduce new `wp_ai_client_default_request_timeout` filter to make the default request timeout configurable. Use this for the image generation request timeout ([#862](https://github.com/WordPress/ai/pull/862)).

**Changed**

- Content Summary block detection now checks within nested blocks ([#810](https://github.com/WordPress/ai/pull/810)).
- Move all existing configuration options into a new "Advanced settings" section which is hidden by default ([#842](https://github.com/WordPress/ai/pull/842)).

**Fixed**

- Focus restoration after AI setting saves ([#812](https://github.com/WordPress/ai/pull/812)).
- Added descriptive alt text to AI Home feature card images for improved screen reader accessibility ([#819](https://github.com/WordPress/ai/pull/819)).
- Prevent Type-Ahead assets from loading on the front end ([#820](https://github.com/WordPress/ai/pull/820)).
- Dismissing a type-ahead suggestion with escape should not trigger a new suggestion request ([#840](https://github.com/WordPress/ai/pull/840)).
- Type-ahead ghost text placement and stale suggestions overlapping empty-block placeholders ([#847](https://github.com/WordPress/ai/pull/847)).
- `MutationObserver` crash when editor iframe body isn't ready when using Title Generation ([#849](https://github.com/WordPress/ai/pull/849)).
- Respect an explicit `show_in_abilities` value on curated settings, and leave the flag to WordPress core once core declares it ([#852](https://github.com/WordPress/ai/pull/852)).
- Register initial settings before `core/read-settings` snapshots them ([#856](https://github.com/WordPress/ai/pull/856)).

Older changelog entries can be found in the [CHANGELOG.md](https://github.com/WordPress/ai/blob/trunk/CHANGELOG.md) file.

== Upgrade Notice ==

= 0.6.0 =
This version includes Breaking Changes.

= 0.5.0 =
This version bumps the WordPress minimum supported version from 6.9 to 7.0.
