# Topic Pages

Topic Pages adds a guide to existing category archives in block themes. Enable the experiment in **Settings → AI** after connecting a text-generation provider. Generation runs only when an editor clicks Regenerate; provider charges may apply.

Categories qualify with **at least five public, password-free posts**, including posts in child categories. Enabling the experiment makes no AI requests.

| Section | Sources | Display |
| --- | --- | --- |
| Summary | Latest five posts | Two or three sentences about the current situation |
| Narrative overview | Up to 20 posts sampled across publication dates | Two to four connected paragraphs, without internal headings |
| Timeline | Up to ten real posts sampled across publication dates; no AI | Publication date and linked title, oldest first |
| Latest posts | Theme's existing Query Loop | Normal archive list and pagination |

Each AI request receives at most 60 KB of post text. Sampling includes the first and latest posts; it shows coverage over time, not an AI ranking of importance. The overview and timeline disclose that they use selected posts.

## Review replacements

Open **Posts → Categories → Edit → Topic guide**. Use WordPress’s existing Edit and Edit Category links to reach this screen.

- **Regenerate** creates a private replacement for one section.
- **Save** publishes that preview immediately. The Category form's Update button is separate.
- **Discard** removes the preview and keeps the published text.

Previews survive a page reload. Provider failures leave saved content unchanged. If another editor changes the guide, load the latest version before continuing.

The preset archive template keeps the theme's header, footer, and post list. Category-specific templates take priority. Blocks can also be placed manually. Classic themes require a separate block placement; the preset applies only to block themes.

## Developer notes

`Sources` selects bounded public excerpts. `Topic_Overview` makes and validates provider requests. `Store` keeps approved text and private previews in `_wpai_topic_page` term metadata. `Controller` provides authenticated REST actions. `Template` registers the preset through WordPress's template API.

`GET /ai/v1/topic-pages/<id>` returns review state. `POST` accepts `action` (`generate`, `save`, `discard`), `section` (`summary`, `overview`, `all`), and `revision`. Writes require category-management permission; browser requests use REST nonces. Old stored formats remain readable. Regenerate and Save to apply the new paragraph format to earlier text. Retired AI timeline text stays in storage.

Every generation creates a private preview, including the first one. Save is always required to publish AI text. Public rendering makes no provider requests. The timeline appears immediately for eligible categories and needs no AI generation. Cached samples contain only post IDs and are invalidated when sources change. Withdrawn sources hide affected text without deleting it.

Embeddings, editable AI prose, and automatic generation are outside this first version.

See the [short testing guide](topic-pages-testing.md).
