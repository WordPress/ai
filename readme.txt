=== AI ===
Contributors:      wordpressdotorg, dkotter, jeffpaul
Tags:              ai, artificial intelligence, experiments, abilities, mcp
Tested up to:      7.1
Stable tag:        1.5.0
License:           GPL-2.0-or-later
License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html

Opt-in AI for WordPress: one stable feature plus experiments that may change or be removed. Needs an AI provider plugin and your own API key.

== Description ==

The AI plugin adds opt-in AI tools to the WordPress admin and Block Editor. It's built by the WordPress [Core AI team](https://make.wordpress.org/ai/) for site owners, content creators, and developers who want to try AI in WordPress early and help shape what comes next.

**Features and Experiments**

Nothing is turned on when you activate the plugin. You choose what to enable in `Settings -> AI`, and each item there is one of two kinds:

* **Feature** – Stable and supported. Changes avoid breaking existing behavior. Image Generation and Editing is currently the only Feature.
* **Experiment** – Early-stage and actively being developed. Experiments may change significantly or be removed in any release, so we recommend trying them on a staging site before using them on a live site.

Once enabled, most Experiments only send a request to your AI provider when you click a button, but some run on their own. Comment Moderation, for example, checks every new comment, including comments from site visitors. In `Settings -> AI`, Experiments that run on their own are marked "Runs automatically", and the info icon next to each item's name shows when it runs, what it sends to your AI provider, and how many requests it makes.

Read the [Feature and Experiment Lifecycle](https://github.com/WordPress/ai/blob/trunk/docs/FEATURE_EXPERIMENT_LIFECYCLE.md) to learn how Experiments are evaluated and how they can become Features.

**Before You Start**

* **This plugin is not part of WordPress core.** It does nothing until you install and activate it, and each Feature or Experiment stays off until you enable it.
* **You need an AI Connector plugin and an account with an AI provider.** The AI plugin doesn't include provider credentials. Install a connector plugin such as [Anthropic](https://wordpress.org/plugins/ai-provider-for-anthropic), [Google](https://wordpress.org/plugins/ai-provider-for-google), or [OpenAI](https://wordpress.org/plugins/ai-provider-for-openai) ([more connectors](https://wordpress.org/plugins/tags/connector/)), then add your API key in `Settings -> Connectors`.
* **Your AI provider bills you for usage.** The plugin is free; each AI request is billed by your provider under your account.
* **The Block Editor is required.** The Classic Editor and other editing experiences are not supported.

New to WordPress AI? Take the [AI-Powered WordPress course](https://learn.wordpress.org/course/ai-powered-wordpress/) to learn how to configure the plugin, use its features, and understand the building blocks behind them. The [Getting Started guide](https://github.com/WordPress/ai/blob/trunk/docs/GETTING_STARTED.md) covers setup and troubleshooting.

**Feature**

* **Image Generation and Editing** – Create and edit images from post content in the editor, and from the Media Library.

**Experiments**

Experiments marked "runs automatically" do their work without you clicking anything once they're enabled. Most of them send requests to your AI provider when they run.

* **Abilities Explorer** – Browse and test registered AI abilities from a dedicated admin screen.
* **AI Request Logging** – Logs AI requests, including short previews of prompts and responses, for observability and debugging.
* **Alt Text Generation** – Generate descriptive alt text for images to improve accessibility.
* **Block Container Label Generation** – Generates short List View labels for Group, Columns, and other container blocks.
* **Comment Moderation** (runs automatically) – Checks each new comment for toxicity and sentiment, gives it a value score, and can hold comments for moderation. Includes comments from site visitors by default.
* **Connector Approval** – Require administrator approval before other plugins or themes can use the AI connectors configured on this site.
* **Content Classification** – Suggests relevant tags and categories to organize content.
* **Content Resizing** – Shorten, expand, or rephrase selected block content.
* **Content Summarization** – Summarizes long-form content into digestible overviews.
* **Content Translation** – Translates paragraph and heading blocks, and optionally the post title, into a selected language from the post editor.
* **Content Verification** (runs automatically) – Detects [C2PA Content Credentials](https://c2pa.org/) in uploaded images and stores them alongside the attachment.
* **Custom Abilities** – Makes the plugin's general-purpose WordPress Abilities available through the Abilities API and MCP.
* **Editorial Notes** – Reviews post content block-by-block and adds Notes with suggestions for Accessibility, Readability, Grammar, and SEO.
* **Editorial Updates** – Applies pending editorial Notes to your content and saves the post, without a preview.
* **Excerpt Generation** – Generates excerpt suggestions for your posts.
* **Key Encryption** – Encrypts AI provider API keys stored in your database. Disabling the experiment or deactivating the plugin restores plaintext keys.
* **Markdown Feeds** – Publishes a Markdown feed and Markdown versions of your public posts and pages for AI agents and other machine readers.
* **MCP Access** – Controls which abilities AI agents can use over the Model Context Protocol (MCP). Installs and activates the MCP Adapter plugin when enabled.
* **Meta Description Generation** – Generates meta description suggestions and integrates them with several SEO plugins.
* **Revision Change Summary** – Summarizes what changed between two revisions of a post in plain language, on the visual and classic revisions screens.
* **Slug Generation** – Suggests SEO-friendly permalink slugs from the permalink popover or the pre-publish panel.
* **Suggest Reply** – Adds a "Suggest Reply" action to the Comments screen and Activity widget to draft replies to comments.
* **Text to Speech** – Generates an audio version of a post that visitors can listen to instead of reading.
* **Title Generation** – Generates title suggestions for your posts.
* **Type-ahead Text** (runs automatically) – Suggests text as you type in the Block Editor.
* **WebMCP** – Lets an agent browser work in the Block Editor through WebMCP, with every change visible on the page as it happens.

**Coming Soon**

We're actively developing new features to enhance your WordPress workflow:

* **AI Playground** – Experiment with different AI models and providers.
* **Content Assistant** – AI-powered writing and editing in Gutenberg.
* **Site Agent** – Natural language WordPress administration.
* **Workflow Automation** – AI-driven task automation.

**Roadmap**

You can view the active plugin roadmap in a filtered view in the WordPress AI [GitHub Project Board](https://github.com/orgs/WordPress/projects/240/views/1).

== Installation ==

1. Install the plugin through the `Plugins -> Add Plugin` screen, or upload the plugin files to the `/wp-content/plugins/ai` directory. We recommend trying the plugin on a staging site first.
2. Activate the plugin through the `Plugins` screen in WordPress.
3. Install and activate at least one AI Connector plugin, then go to `Settings -> Connectors` and add your API key.
4. Go to `Settings -> AI` and enable the Features or Experiments you want to use. Select the info icon next to each Experiment's name to see when it runs and what it sends to your AI provider.
5. Try it out. For Title Generation, edit a post and click into the title field. Click the `Generate` button above the field, then choose a suggestion and click `Select` to insert it.

== For Developers ==

The AI plugin is designed to be studied, extended, and built upon. Whether you're a plugin developer, agency, or hosting provider, here's what you can do:

**Under the Hood:**

These building blocks power the Features and Experiments above. They aren't settings you turn on or off.

* **AI Building Blocks** – Combines the [PHP AI Client and Abilities API](https://make.wordpress.org/ai/2025/07/17/ai-building-blocks) into one plugin, as a reference implementation for developers.
* **Dashboard Widgets** – AI Status and AI Capabilities widgets, plus a framework for registering new ones.
* **Experiment Framework** – The opt-in system that registers each Feature and Experiment and its toggle.
* **Guidelines** – Lets abilities respect site-wide editorial standards.
* **Multi-Provider Support** – Works with AI Connector plugins for providers such as OpenAI, Google, and Anthropic.

**Extend the Plugin:**

* **Build Custom Experiments** - Use the `Abstract_Feature` base class to create your own AI-powered features.
* **Pre-configure Providers** - Hosts and agencies can set up AI Connector plugins so users don't need their own API keys.
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

This plugin brings opt-in AI tools for writing, editing, media, and site administration into WordPress. It's where the WordPress Core AI team builds and tests AI features with the community, and it's a reference implementation for developers who want to build their own AI features.

= Is this plugin part of WordPress core? =

No. The AI plugin is a separate plugin that you choose to install and activate. WordPress core includes the underlying AI Client and the `Settings -> Connectors` screen, but it doesn't send anything to an AI provider on its own. AI Connector plugins (for Anthropic, Google, OpenAI, and others) are also separate plugins, and you can deactivate and delete them at any time.

= What's the difference between a Feature and an Experiment? =

Features are stable and supported, and changes to them avoid breaking existing behavior. Experiments are early-stage: they may change significantly or be removed in any release. Both are off by default. See the [Feature and Experiment Lifecycle](https://github.com/WordPress/ai/blob/trunk/docs/FEATURE_EXPERIMENT_LIFECYCLE.md) for details.

= Is this safe to use on a production site? =

Image Generation and Editing is a stable Feature that only runs when you ask for it in the editor or Media Library.

Experiments may change or be removed in any release, so we recommend trying them on a staging site before enabling them on a live site. Once enabled, most Experiments only run when you click a button. A few run automatically:

* **Comment Moderation** sends every new comment, including comments from site visitors, to your AI provider and can hold comments for moderation.
* **Type-ahead Text** requests suggestions while you type in the Block Editor.
* **Content Verification** checks each new image upload for Content Credentials. It doesn't send anything to your AI provider.

Some Experiments change your content without a preview after you click their button. For example, Editorial Updates rewrites blocks and saves the post, and Content Translation replaces block text in the editor. You can review or undo saved changes with revisions.

In `Settings -> AI`, the info icon next to each Experiment's name shows when it runs and what it sends to your AI provider.

= Which AI providers are supported? =

The plugin can work with provider connector plugins from [Anthropic](https://wordpress.org/plugins/ai-provider-for-anthropic) (Claude), [Google](https://wordpress.org/plugins/ai-provider-for-google) (Gemini), [OpenAI](https://wordpress.org/plugins/ai-provider-for-openai), and [others](https://wordpress.org/plugins/tags/connector/). Install and activate the relevant connector plugin, then configure it in `Settings -> Connectors`. The AI plugin also lists a few reviewed connector plugins there, such as [Mistral](https://wordpress.org/plugins/ai-provider-for-mistral/) and [Vercel AI Gateway](https://wordpress.org/plugins/vercel-ai-gateway-provider/), so they can be installed from that screen.

= Do I need an API key to use the features? =

Yes, currently you need to provide your own API key for the configured AI Connector plugin, such as OpenAI, Google AI, or Anthropic.

= How much does it cost? =

The plugin itself is free, but you'll need to pay for API usage from your chosen AI provider. Costs vary by provider and usage. Most providers offer free trial credits to get started. There are some local, open source, and free providers (like [Ollama](https://wordpress.org/plugins/ai-provider-for-ollama/)) that can be used as well.

Experiments that run automatically can use requests without anyone clicking a button. Comment Moderation, for example, makes one request for each new comment, including comments from site visitors and spam that your spam filter hasn't already caught. Check your provider's usage dashboard after enabling an Experiment, and turn on AI Request Logging to see each request the plugin makes.

= Can I use this without coding knowledge? =

Yes. The plugin is designed for content creators and site administrators. Once your AI Connectors are configured, you can enable Features and Experiments in `Settings -> AI` and use them directly from the post editor. As with any Experiment, we recommend trying them on a staging site first.

= Does this plugin support the Classic Editor? =

No.

The AI plugin currently supports only the Block Editor (aka Gutenberg).  The plugin is designed around modern editor APIs, block-based content workflows, and the evolving editing capabilities being developed within WordPress core (including Gutenberg).

The Block Editor has been the default WordPress editing experience since WordPress 5.0 in 2018 and remains the primary focus of active editor development.  Concentrating development efforts on the Block Editor enables the project to ship new features, experiments, and integrations more quickly while avoiding the complexity of maintaining parallel implementations across multiple editing experiences.

Although the Classic Editor plugin continues to have a large installed base, the Core AI team has chosen to prioritize innovation and experimentation within the Block Editor ecosystem.  At this time there are no plans to add official Classic Editor support.

= Something went wrong. How do I turn the plugin off? =

Go to `Plugins` and deactivate AI. If you can't reach the admin, deactivate it with WP-CLI (`wp plugin deactivate ai`) or rename the `wp-content/plugins/ai` folder using FTP or your host's file manager. To turn off a single Experiment instead, disable it in `Settings -> AI`. The [Getting Started guide](https://github.com/WordPress/ai/blob/trunk/docs/GETTING_STARTED.md) has more troubleshooting steps.

= Where can I get help or report issues? =

* **Questions and help:** Post in the [plugin's support forum](https://wordpress.org/support/plugin/ai/), or ask in the [#core-ai channel on WordPress Slack](https://wordpress.slack.com/archives/C08TJ8BPULS).
* **Bugs and feature requests:** Open an issue in the [AI plugin's GitHub repository](https://github.com/WordPress/ai/issues).
* **Problems connecting to a provider or with an API key:** Contact the support forum or GitHub repository for that AI Connector plugin, such as [Anthropic](https://github.com/WordPress/ai-provider-for-anthropic), [Google](https://github.com/WordPress/ai-provider-for-google), or [OpenAI](https://github.com/WordPress/ai-provider-for-openai).

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
13. Editor Experiment: Editorial Updates. Applies pending Editorial Notes to your content and saves the post.
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

= 1.5.0 - 2026-10-29 =

**Added**

- New Experiment: Content Verification, that adds read-only detection of C2PA Content Credentials in uploaded JPEG/PNG/WebP images ([#459](https://github.com/WordPress/ai/pull/459)).
- New Experiment: Text to Speech, that allows you to generate audio for a specific post and then render audio controls on the frontend ([#888](https://github.com/WordPress/ai/pull/888)).
- New Experiment: MCP Access, that installs and activates the MCP Adapter plugin from WordPress.org when enabled, and adds a `Tools → MCP Access` screen to control which abilities are exposed to AI agents over MCP ([#992](https://github.com/WordPress/ai/pull/992)).
- New Experiment: WebMCP, that lets an agent browser work in the block editor through `document.modelContext`, with tools that set the title, insert and edit blocks, save and publish, with every change visible on the page as it happens ([#1081](https://github.com/WordPress/ai/pull/1081)).
- New Experiment: Revision Change Summary, that summarizes what changed between two revisions of a post on the visual and classic revisions screens ([#1159](https://github.com/WordPress/ai/pull/1159)).
- New Experiment: Block Container Label Generation, that generates concise block container labels in List View and the block Rename modal ([#1175](https://github.com/WordPress/ai/pull/1175), ([#1218](https://github.com/WordPress/ai/pull/1218)).
- A post's Markdown document (`?output_format=markdown`) has approved comments appended ([#1121](https://github.com/WordPress/ai/pull/1121)).
- New background sync processing to handle generating embeddings for posts and terms ([#1135](https://github.com/WordPress/ai/pull/1135)).
- "Generate title" button in the post Rename modal ([#1140](https://github.com/WordPress/ai/pull/1140)).
- Learn WordPress AI course links to the plugin readmes and the AI settings header for easier access to course resources ([#1154](https://github.com/WordPress/ai/pull/1154)).
- A neutral badge icon to thumbnails of images with C2PA data detected in the Media Library grid view ([#1164](https://github.com/WordPress/ai/pull/1164)).
- An `ai.abilitiesExplorer.fields` JavaScript filter for adding read-only table columns to the Abilities Explorer page ([#1181](https://github.com/WordPress/ai/pull/1181)).
- Clarified which parts of the plugin are stable Features vs Experiments, flag Experiments that run automatically in `Settings > AI`, and show when each runs, what it sends to your AI provider, and how many requests it makes from an info icon, the readme, and docs ([#1190](https://github.com/WordPress/ai/pull/1190)).
- Featured Connector plugins (Mistral and Vercel AI Gateway) on `Settings > Connectors`, sorted by name, plus a `wpai_featured_connectors` filter to change the list ([#1212](https://github.com/WordPress/ai/pull/1212)).

**Changed**

- Update the wording in our capability check notice and change that from an error to a warning ([#935](https://github.com/WordPress/ai/pull/935)).
- Content Translation now translates any block that has rich text instead of just paragraphs and headings ([#1009](https://github.com/WordPress/ai/pull/1009)).
- Move the Key Encryption experiment onto the new Secrets API feature plugin with backward-compatible fallback and migration ([#1107](https://github.com/WordPress/ai/pull/1107)).
- The `/<post>/feed/markdown/` no longer works and serves a 404 ([#1121](https://github.com/WordPress/ai/pull/1121)).
- The `core/content-query`, `core/settings-get`, and `core/users-query` abilities now set the `public` meta flag added in WordPress 7.1 ([#1122](https://github.com/WordPress/ai/pull/1122)).
- Summarize enabled and disabled experiments in the AI Status dashboard widget to keep it compact as more experiments are added ([#1155](https://github.com/WordPress/ai/pull/1155)).
- Rebuilt the Abilities Explorer on DataViews and added three `ai/v1` REST routes to retrieve and run abilities ([#1181](https://github.com/WordPress/ai/pull/1181)).
- Replace the "Purge All Logs" button in the AI Request Logs screen with a control to delete logs older than 30 days, 90 days or 1 year, or all logs, and add a `before_days` argument to `DELETE /ai/v1/logs` ([#1182](https://github.com/WordPress/ai/pull/1182)).
- The `core/settings-get` ability now matches WordPress core. Settings use their REST API names (for example, `title` instead of `blogname`), and a value that does not match its schema is left out. **Breaking changes**: Some schema fields have changed so ensure you are using the correct field names in your ability calls; the `core/read-settings` alias was also removed ([#1206](https://github.com/WordPress/ai/pull/1206)).
- The `core/users-query` ability now matches WordPress core. The input is now `where`, `fields`, and `pagination`, and the output is always `items` and `pagination`. Users are looked up through the `ids`, `slugs`, or `usernames` lists in `where`, and lookup by email is no longer supported. Results are ordered by username, a filter that cannot be used is rejected, and a page after the last one returns an error. **Breaking changes**: Some schema fields have changed so ensure you are using the correct field names in your ability calls; the `core/read-users` alias was also removed ([#1207](https://github.com/WordPress/ai/pull/1207)).
- The `core/content-query` ability now matches WordPress core. The input is now `where`, `fields`, and `pagination`, and the output is always `items` and `pagination`. Posts are looked up through the `ids` or `slugs` lists in `where`, which also requires the post type in `type`. Posts are filtered by author through `where.author`, with the author `id` or `slug`. Fields are nested, such as `title.rendered`. A filter that cannot be used is rejected, and a page after the last one returns an error. The author name and slug are only returned to users who may see the author. Only a `show_in_abilities` value of `true` exposes a post type. **Breaking changes**: Some schema fields have changed so ensure you are using the correct field names in your ability calls; the `core/read-content` alias was also removed ([#1208](https://github.com/WordPress/ai/pull/1208)).
- The `date` and `modified` fields of `core/content-query` return `site` and `gmt` dates instead of `local` and `gmt`. **Breaking change**: Ensure you are using the correct field names in your ability calls ([#1235](https://github.com/WordPress/ai/pull/1235)).
- The `registered_date` field of `core/users-query` is replaced by a `registered` object with `site` and `gmt` dates. Note that all 3 core abilities have now identical shape like these shipping in WordPress 7.2. **Breaking change**: Ensure you are using the correct field names in your ability calls ([#1237](https://github.com/WordPress/ai/pull/1237)).

**Removed**

- The deprecated `core/read-settings` ability alias. Use `core/settings-get` with REST API field names (for example, `title` instead of `blogname`). The alias could not keep the old field names or response shape after the sync with WordPress core ([#1206](https://github.com/WordPress/ai/pull/1206)).
- The deprecated `core/read-users` ability alias. Use `core/users-query` with the new `where`, `fields`, and `pagination` input. The alias could not keep the old input and response shape after the sync with WordPress core ([#1207](https://github.com/WordPress/ai/pull/1207)).
- The deprecated `core/read-content` ability alias. Use `core/content-query` with the new `where`, `fields`, and `pagination` input. The alias could not keep the old input and response shape after the sync with WordPress core ([#1208](https://github.com/WordPress/ai/pull/1208)).

**Fixed**

- AI Request Logging now captures successful generations from providers that use a custom transport, not only those routed through the SDK HTTP transporter ([#757](https://github.com/WordPress/ai/pull/757)).
- Display a clear, context-aware error notice during Ability execution when the configured AI connector is pending approval ([#759](https://github.com/WordPress/ai/pull/759)).
- Remove the text generation capability check from the alt text generation WP-CLI command ([#935](https://github.com/WordPress/ai/pull/935)).
- Feature toggles are loaded with one database query instead of one per feature on sites without a persistent object cache ([#1113](https://github.com/WordPress/ai/pull/1113)).
- Content Translation: Translate the blocks inside Post Content when "Show template" is enabled ([#1116](https://github.com/WordPress/ai/pull/1116)).
- On multisite, network deactivation restores API keys on every site, and uninstall no longer deletes keys that were still encrypted ([#1118](https://github.com/WordPress/ai/pull/1118)).
- The Markdown Feeds and Key Encryption flags are loaded together with the feature toggles instead of with one query each on every request ([#1119](https://github.com/WordPress/ai/pull/1119)).
- The site-wide comment markdown feed lists comments instead of the post list ([#1121](https://github.com/WordPress/ai/pull/1121)).
- Feed requests are no longer served the post's Markdown by Accept negotiation or `?output_format=markdown` ([#1121](https://github.com/WordPress/ai/pull/1121)).
- Accept-header negotiation now follows the header's quality values and order, so a request that prefers HTML gets HTML and one that prefers Markdown gets Markdown ([#1125](https://github.com/WordPress/ai/pull/1125)).
- Ensure canceling meta description generation stops the loading state. Note the actual request won't be cancelled but the result from that will be discarded ([#1128](https://github.com/WordPress/ai/pull/1128)).
- Show an error when a Note fails to save, in place of a "suggestions added" message, and keep reviewing the other blocks when one fails ([#1131](https://github.com/WordPress/ai/pull/1131)).
- Skip blocks that are removed, replaced or edited mid-translation ([#1137](https://github.com/WordPress/ai/pull/1137)).
- Ensure all strings can be translated in the Abilities Explorer pages ([#1146](https://github.com/WordPress/ai/pull/1146)).
- Ensure all strings can be translated in the Content Classification experiment ([#1147](https://github.com/WordPress/ai/pull/1147)).
- A comment reply suggestion is no longer inserted into the reply form of a different comment ([#1158](https://github.com/WordPress/ai/pull/1158)).
- Match existing terms whose names contain "&" or non-ASCII letters, and add them from suggestions ([#1168](https://github.com/WordPress/ai/pull/1168)).
- A non-string term or parent in the AI response no longer fails the whole request when running content classification ([#1171](https://github.com/WordPress/ai/pull/1171)).
- Add missing title attribute to singular alternate Markdown discovery link ([#1177](https://github.com/WordPress/ai/pull/1177)).
- Show “Regenerate Reply” after a reply suggestion has been generated ([#1179](https://github.com/WordPress/ai/pull/1179)).
- Checkbox icon color on the AI settings page ([#1183](https://github.com/WordPress/ai/pull/1183)).
- Ensure success and error notices show on the AI Request Logs screen ([#1184](https://github.com/WordPress/ai/pull/1184)).
- Key Encryption no longer causes a fatal error when stored API keys can't be decrypted ([#1186](https://github.com/WordPress/ai/pull/1186)).
- Suggest Reply no longer sends the title or excerpt of private or password-protected posts to the AI provider ([#1192](https://github.com/WordPress/ai/pull/1192)).
- Comment Moderation no longer automatically analyzes pingbacks, trackbacks or block editor notes ([#1192](https://github.com/WordPress/ai/pull/1192)).
- Uninstalling the plugin now removes Comment Moderation analysis data ([#1192](https://github.com/WordPress/ai/pull/1192)).
- Omit Author meta line in Markdown feeds when a post has no assigned author ([#1210](https://github.com/WordPress/ai/pull/1210)).
- Preserve line-level diff granularity for multi-line blocks like tables and quotes in Revision Change Summary ([#1216](https://github.com/WordPress/ai/pull/1216)).
- Markdown Feeds now apply the core `the_content_feed` and `the_excerpt_rss` filters, matching RSS and Atom feed output ([#1224](https://github.com/WordPress/ai/pull/1224)).
- Ensure the "Serve Markdown via the Accept header" setting can now be saved ([#1229](https://github.com/WordPress/ai/pull/1229)).
- The Guidelines prompt no longer contains the HTML entity `&#39;` ([#1230](https://github.com/WordPress/ai/pull/1230)).
- The permission error for a post now mentions image prompts instead of excerpts when running image prompt generation ([#1234](https://github.com/WordPress/ai/pull/1234)).

**Security**

- Resolved GHSA-7mwf-4ppp-cxmp ([GHSA-7mwf-4ppp-cxmp](https://github.com/WordPress/ai/security/advisories/GHSA-7mwf-4ppp-cxmp)).
- Resolved GHSA-g94j-h78j-rr2j ([GHSA-g94j-h78j-rr2j](https://github.com/WordPress/ai/security/advisories/GHSA-g94j-h78j-rr2j)).

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

Older changelog entries can be found in the [CHANGELOG.md](https://github.com/WordPress/ai/blob/trunk/CHANGELOG.md) file.

== Upgrade Notice ==

= 0.6.0 =
This version includes Breaking Changes.

= 0.5.0 =
This version bumps the WordPress minimum supported version from 6.9 to 7.0.
