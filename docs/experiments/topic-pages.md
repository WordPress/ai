# Topic Pages

Topic Pages creates a summary, narrative overview, and coverage timeline from an existing category or tag's public articles. It is a manual proof of concept. It uses the existing archive URL and never calls a provider during public rendering.

## Editorial workflow

1. Enable **Topic Pages** in Settings → AI and connect a text-generation provider.
2. Edit a category or tag and select **Open Topic Guide Editor**.
3. Generate the unlocked sections together, or use a section's actions menu to generate just that section.
4. Edit the content with WordPress's block editor. Keep source links to public articles belonging to this topic. Paragraph, heading, list, and quote blocks are supported.
5. Choose **Save draft** to keep work for later. The existing published version stays visible while its replacement is a draft. **Compare with published version** shows the current public content as readable text.
6. Choose **Publish changes** to save and publish the current edits together. Section-level publishing is also available. If any selected edit or source is invalid, nothing in that publication is saved or published; the local edits remain available to correct.
7. Add **Topic Summary**, **Topic Overview**, and **Topic Timeline** blocks to the archive template. Leave their source topic set to **Current archive topic**. Keep the template's existing Query Loop for latest/all articles. An explicit source topic lets a block appear on another page or template.

Topic content is displayed through the blocks only. The experiment does not insert content into descriptions or loops automatically. Public blocks use the theme's typography and colors, with a quote-style summary, rounded source links, and a vertical timeline. Their stylesheet is scoped to the Topic blocks and loaded through WordPress's block-style API. Normal block controls still adjust typography, spacing, and colors.

## Drafts, locks, and conflicts

A generated draft is saved on the term but stays private until an editor publishes it. Unsaved typing remains in the current browser page; leaving it prompts before discarding those edits. A successful action in one section preserves unrelated local edits.

Editing a section excludes it from bulk regeneration. Its actions menu can include it again or regenerate it explicitly. This preference protects manual work during a bulk request. There is no background refresh in this iteration.

Regenerating a section with a saved draft or unsaved edits requires confirmation. Cancelling preserves that draft. Provider failures retain the previous draft and published content.

Revision checks reject stale browser requests. Owner-token database leases serialize writes and generation; an expired generation request cannot save over its replacement. A failed publication never approves an older draft as a fallback.

## Sources and freshness

Only published, publicly readable, password-free posts/pages with direct membership in the topic are eligible. Pages count only where the existing taxonomy setup associates them with the term; the experiment does not add taxonomy support to post types.

The minimum is five public sources by default. A topic below the minimum, or a section whose source context is withdrawn, becomes hidden without deleting its saved content. The editor explains the hidden state. Content changes mark source snapshots as needing review; generation records the input fingerprint and time. Earlier saved versions without snapshots also need review.

Generated claims link to validated post IDs. WordPress constructs the links and milestone publication dates. Edited citations are checked again before saving/publishing, and current permalinks are resolved from stable source identities. These checks cannot prove a claim is factually correct; editors must review the prose and citations.

This proof of concept accepts up to 20 source articles and 60 KB of source text. Oversized topics show an explanation before generation. It does not silently choose a subset of a large topic. Background refresh, large-topic processing, related topics, dedicated structured data, and Markdown integration remain outside this iteration.

## Integration

The implementation follows the experiment/ability/prompt/UI split used by Suggest Reply:

- `Sources` selects public topic content.
- `Topic_Overview` produces structured, validated sections without saving them.
- `Store` owns reviewed/draft versions and atomic editorial transitions.
- `Controller` exposes authenticated REST actions.
- `Topic_Pages` connects the editor and three public blocks to WordPress.

`ai/topic-overview` accepts a term ID and a list of requested sections. It uses the plugin's existing provider/model preferences, editorial guidelines, and prompt filters.

`GET /ai/v1/topic-pages/<id>` returns editor state. `POST` accepts an action (`generate`, `save`, `publish`, `approve`, or `lock`), section (`summary`, `overview`, `timeline`, or `all`), and revision. Save/publish can carry one `edits` object mapping section names to block markup. `approve` remains available for integrations publishing already-saved drafts. Requests require the taxonomy's management capability and permission to edit the term; browser requests use WordPress REST nonces.

Storage uses `_wpai_topic_page` term metadata. Earlier nested editorial records and the previous flat saved-section format are normalized on read. Reads do not rewrite storage. Plugin deactivation preserves the data; the existing uninstall data-removal policy governs removal of this owned metadata.

The existing filters remain available: `wpai_topic_pages_taxonomies`, `wpai_topic_pages_post_types`, and `wpai_topic_pages_minimum_posts`.
