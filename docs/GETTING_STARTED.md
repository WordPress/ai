# Getting Started with the AI Plugin

This guide is for site owners and administrators setting up the AI plugin for the first time. If you want to build on the plugin, see the [Developer Guide](DEVELOPER_GUIDE.md) instead.

## What the Plugin Is

The AI plugin is where the WordPress Core AI team builds and tests AI tools for WordPress with the community. It is a separate plugin, not part of WordPress core, and it does nothing until you install it, activate it, and turn on the items you want.

Everything in `Settings -> AI` is one of two kinds:

- **Features** are stable and supported. Changes to them avoid breaking existing behavior. Image Generation and Editing is currently the only Feature.
- **Experiments** are early-stage and actively being developed. They may change significantly or be removed in any release.

Both are off by default. The [Feature and Experiment Lifecycle](FEATURE_EXPERIMENT_LIFECYCLE.md) explains how Experiments are evaluated and how they can become Features.

> [!IMPORTANT]
> Try Experiments on a staging site before you enable them on a live site, and re-check them after each plugin update.

## Before You Start

You need:

- WordPress with the Block Editor. The Classic Editor is not supported.
- An AI Connector plugin, such as [Anthropic](https://wordpress.org/plugins/ai-provider-for-anthropic), [Google](https://wordpress.org/plugins/ai-provider-for-google), or [OpenAI](https://wordpress.org/plugins/ai-provider-for-openai). [More connectors](https://wordpress.org/plugins/tags/connector/) are available, including local options such as [Ollama](https://wordpress.org/plugins/ai-provider-for-ollama/).
- An account and API key with that AI provider. Your provider bills you for each request.

## Setup

1. Install and activate the AI plugin.
2. Install and activate an AI Connector plugin.
3. Go to `Settings -> Connectors` and add your API key.
4. Go to `Settings -> AI`. If the page shows an error about AI Connectors, the key is missing or couldn't be verified; see [Troubleshooting](#troubleshooting).
5. Read the description of each Feature or Experiment and select the info icon next to its name for details, then turn on the ones you want.

## Choosing What to Enable

The info icon next to each item's name in `Settings -> AI`, and the "At a Glance" section of its doc in [`docs/experiments`](experiments/) or [`docs/features`](features/), tells you three things:

- **When it runs.** Most items only send a request to your AI provider when you click a button. A few run on their own once enabled.
- **What it sends.** The content that goes to your AI provider, such as post content, an image, or a comment.
- **How many requests it makes.** This drives your provider bill.

These Experiments run automatically once enabled:

| Experiment | What happens without a click |
| --- | --- |
| [Comment Moderation](experiments/comment-moderation.md) | Every new comment, including comments from site visitors and spam your spam filter hasn't caught, is sent to your AI provider. It can hold comments for moderation. |
| [Type-ahead Text](experiments/type-ahead.md) | Suggestions are requested each time you pause typing in a paragraph. |
| [Content Verification](experiments/c2pa-monitor.md) | Each new JPEG, PNG, or WebP upload is checked for Content Credentials. Nothing is sent to your AI provider. |

Some Experiments change content without a preview after you click their button. [Editorial Updates](experiments/editorial-updates.md) rewrites blocks and saves the post, [Content Translation](experiments/content-translation.md) replaces block text in the editor, and the bulk actions for [Alt Text Generation](experiments/alt-text-generation.md) and [Content Summarization](experiments/summarization.md) save their results directly. Use revisions to review or undo saved changes.

Some Experiments don't call your AI provider but still change how your site behaves. For example, [Markdown Feeds](experiments/markdown-feeds.md) publishes Markdown versions of your public content, and [AI Request Logging](experiments/ai-request-logging.md) stores previews of each AI prompt and response.

## Keeping an Eye on Usage

- Turn on [AI Request Logging](experiments/ai-request-logging.md) to see every AI request made on your site, with the operation, provider, model, and tokens used. Logs are kept until you delete them from `Tools -> AI Request Logs`.
- Check your AI provider's usage dashboard after enabling an Experiment, especially one that runs automatically.
- To stop all requests from one Experiment, turn it off in `Settings -> AI`.

## Troubleshooting

### Features don't appear in the editor

- Check that the item is turned on in `Settings -> AI`.
- Check that an AI Connector is set up in `Settings -> Connectors`. Some items also need a provider that supports a specific capability, such as image generation or vision. The item's description says which.
- Check that you're using the Block Editor.

### My API key is rejected

- Re-enter the key in `Settings -> Connectors` and check for extra spaces.
- Make sure your provider account has billing or credits set up.
- If the key works elsewhere but not on your site, ask in the support forum or GitHub repository for that connector plugin, for example [Anthropic](https://github.com/WordPress/ai-provider-for-anthropic), [Google](https://github.com/WordPress/ai-provider-for-google), or [OpenAI](https://github.com/WordPress/ai-provider-for-openai).

### Something broke and I need to turn the plugin off

- **From the admin:** Go to `Plugins` and deactivate AI. To turn off just one Experiment, disable it in `Settings -> AI`.
- **With WP-CLI:** Run `wp plugin deactivate ai`.
- **Without admin access:** Rename the `wp-content/plugins/ai` folder using FTP or your host's file manager.

`Tools -> Site Health -> Info -> AI Plugin` shows the plugin version, connector status, and which items are enabled. Include it when you ask for help.

## Getting Help

- **Questions and help:** The [plugin's support forum](https://wordpress.org/support/plugin/ai/) or the [#core-ai channel](https://wordpress.slack.com/archives/C08TJ8BPULS) on WordPress Slack.
- **Bugs and feature requests:** [GitHub issues](https://github.com/WordPress/ai/issues).
- **Connector or API key problems:** The support forum or GitHub repository for that AI Connector plugin.
