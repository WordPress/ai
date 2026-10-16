# Privacy

This document describes what data the AI plugin sends to AI providers, what it stores on the site, and how it works with the WordPress privacy tools. It is a technical reference for site owners and developers, not legal advice.

All features and experiments are off by default. Nothing is sent to an AI provider until a feature or experiment that uses AI is enabled under Settings → AI. Requests go to the AI provider(s) configured under Settings → Connectors, through the WordPress AI Client.

## Suggested privacy policy text

The plugin adds a section named "AI" to Settings → Privacy → Policy Guide. It has one short section per data flow. Each section says which experiment it applies to, so site owners can copy only the parts that match their settings. Guidance for the site owner uses the `privacy-policy-tutorial` class, as core does, and is kept apart from the text meant for the policy.

The text is the same whichever experiments are enabled. WordPress marks the Policy Guide as changed whenever a plugin's suggested text changes, so making the text depend on experiment settings would raise a "policy changed" notice every time an experiment was toggled.

Site owners should also list the AI providers they use and link to each provider's privacy policy.

## What each experiment and feature sends

"On demand" means a user has to take an action, such as clicking a button, for the request to be sent. "Automatic" means the request is sent without a separate action once the experiment is enabled.

| Experiment / feature | What is sent | To whom | When |
| --- | --- | --- | --- |
| Comment Moderation | Comment author name, comment content and a short post excerpt (the excerpt, the generated summary or the first 650 characters, and only for public posts without a password). Email address, IP address, URL and user agent are not sent. | AI provider | Automatic, on `wp_insert_comment`. Includes guest comments by default (`moderate_guests` setting). Also on demand from the Comments screen. |
| Suggest Reply | Comment author name, comment content, post title and post excerpt. | AI provider | On demand (Comments screen action). |
| Alt Text Generation | The image file, base64-encoded, including any embedded metadata. In the editor, nearby post content and the caption. | AI provider | On demand (editor button or Media Library bulk action). Not on upload. |
| Image Generation (feature) | The text prompt. When generating a prompt from a post: post content, title and author name. When editing an image: the image. | AI provider | On demand. |
| Title Generation, Excerpt Generation, Meta Description, Slug Generation | Post content plus post context: title, slug, author display name, post type, excerpt and assigned terms. | AI provider | On demand. Meta Description also generates a suggestion when its panel is first opened and no description exists. |
| Summarization | Post content plus post context, as above. | AI provider | On demand (editor or Posts bulk action). |
| Content Classification | Post content, post context and a list of existing terms on the site. | AI provider | On demand. |
| Content Resizing | The selected block content. | AI provider | On demand. |
| Content Translation | Block content and, optionally, the post title. | AI provider | On demand. |
| Editorial Notes | Block content, nearby text and existing note texts. | AI provider | On demand. |
| Editorial Updates | Block content, pending note texts and nearby text. | AI provider | On demand (when applying notes). |
| Type-ahead | The current paragraph, the text before and after the cursor, and the neighboring blocks (each truncated to 5,000 characters). | AI provider | Automatic, while the user types, after a short pause (500 ms by default). |
| AI Request Logging | Nothing extra. Stores a record of every AI request (see below). | Stored on the site | Automatic, for every AI request while enabled. |
| Content Verification (C2PA Monitor) | Nothing. Parsing is local. | — | Automatic, on upload of JPEG, PNG and WebP images. Stores the raw Content Credentials manifest, which can name the image's creator. |
| Markdown Feeds | Nothing to AI providers. Serves public posts, author display names and approved comments' author names in Markdown. | Visitors and crawlers | When a feed or Markdown URL is requested. |
| Custom Abilities | Nothing sent by the plugin. Exposes read-only abilities (content, users, settings, post terms) to authenticated REST and MCP clients, subject to each ability's permission checks. | The calling client | On demand by an authenticated client. |
| Abilities Explorer | Nothing itself. Lets administrators run any registered ability. | — | On demand. |
| Connector Approvals, Key Encryption | Nothing. | — | — |

Several abilities also add the site's guidelines (the `wp_knowledge` guidelines content) to the prompt.

The embeddings CLI (`wp ai embeddings`) sends the content of the posts or text it is given to the embedding model named on the command line, and stores only the vectors and a content hash.

## AI request logs

When the AI Request Logging experiment is enabled, each AI request is stored in the `{prefix}wpai_request_logs` table. A row holds:

- the date, operation, provider, model, status, duration and token counts;
- any error message, and a JSON `context` with technical details of the request;
- `user_id`, the logged-in user who made the request (`0` when no user was logged in);
- `request_preview` and `response_preview`, up to 1,200 characters each of the prompt and the response.

Because prompts can contain comments, post content and author names, the previews can contain personal data about people other than the user who made the request.

Logs are kept indefinitely by default. To delete old entries automatically, return a number of days from the `wpai_request_log_retention_days` filter. Administrators can also delete logs older than 30 days, 90 days or 1 year, or all logs, from the Manage Logs panel in Tools → AI Request Logs. That is a one-off action and does not set a retention period. Uninstalling the plugin drops the table.

If the AI Request Logging experiment is disabled, the logs already recorded stay in the table. The Tools → AI Request Logs screen and the scheduled retention cleanup only run while the experiment is enabled, so those logs are not deleted until it is enabled again or the plugin is uninstalled. The exporter and eraser below keep working either way.

## Personal data export and erasure

The plugin registers an exporter and an eraser named "AI Request Logs" for Tools → Export Personal Data and Tools → Erase Personal Data.

- Both are registered whenever the log table exists, even if the logging experiment is now disabled, because rows recorded earlier may still be there.
- The email address in the request is matched to a user account, and rows are found by that account's `user_id`.
- The exporter returns 100 rows per page, oldest first. Each item has the date (UTC), operation, provider, model, status, token counts, error message (if any), and the request and response previews.
- The eraser deletes the matching rows in batches of up to 1,000 per page and clears the log caches. It reports `items_removed`, `items_retained`, `messages` and `done` as core expects.
- If a database query fails, the exporter or eraser returns a `WP_Error`, so core reports the request as failed instead of marking it done.
- An email address that matches no account returns no data, and nothing is removed.

### Rows that cannot be matched

Rows recorded with no logged-in user have `user_id` `0` and cannot be matched to an email address. The most common case is Comment Moderation checking a guest comment. These rows are not exported or erased by the privacy tools. They are removed when an administrator deletes them from Tools → AI Request Logs, or when they reach the retention period; both need the logging experiment to be enabled. A guest's name and comment text can still appear in the previews of rows like these, and in rows linked to a moderator who used Suggest Reply on that comment.

## Data stored with posts and comments

Some experiments store their results on the site. These are not covered by the request log exporter and eraser:

- Comment Moderation: comment meta `_wpai_toxicity_score`, `_wpai_sentiment`, `_wpai_value_score`, `_wpai_analysis_status` and `_wpai_analyzed_at`. When core erases a commenter's data, it anonymizes the comment rather than deleting it, so this meta stays with the anonymized comment.
- Summarization: post meta `wpai_generated_summary`.
- Meta Description: post meta `wpai_meta_description`, or the active SEO plugin's field.
- Image Generation: generated images in the Media Library, with the prompt in the description.
- Content Verification: post meta `_wpai_monitor_record` and `_wpai_c2pa_present`, and manifest files in `uploads/ai-c2pa/`.
