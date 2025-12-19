# Markdown Feeds Experiment

## Summary

Adds Markdown representations of WordPress content:

- Feed: `/?feed=markdown` (and `/feed/markdown/` once rewrite rules are flushed).
- Singular: `https://example.com/my-post.md` (optional).
- Singular content negotiation: `Accept: text/markdown` (optional).

The output is intended to be a lightweight, text-first format that is easier for automated clients (including AI tooling) to ingest than full HTML.

## Key Hooks & Entry Points

- `init` -> registers a custom feed using `add_feed( 'markdown', ... )` (optional).
- `do_parse_request` -> strips a trailing `.md` from front-end requests so WordPress can resolve the underlying canonical URL (optional).
- `template_redirect` -> renders `text/markdown` for singular content when requested via `.md` or `Accept: text/markdown` (optional).
- Renderer -> converts rendered `the_content` HTML into Markdown using WordPress core’s HTML API (`WP_HTML_Processor`, with fallback to `WP_HTML_Tag_Processor`).
- Filters:
  - `ai_experiments_markdown_feed_html` -> adjust HTML before conversion.
  - `ai_experiments_markdown_feed_markdown` -> adjust Markdown after conversion.
  - `ai_experiments_markdown_singular_html` -> adjust HTML before conversion (singular).
  - `ai_experiments_markdown_singular_markdown` -> adjust Markdown after conversion (singular).

## Assets & Data Flow

- No JS/CSS assets.
- Uses the main feed query loop (`have_posts()` / `the_post()`) and outputs Markdown for each item (title, URL, publish date, content).

## Testing

1. Enable **AI Experiments** globally and enable **Markdown**.
2. Visit `/?feed=markdown` and confirm a `text/markdown` response containing one or more posts.
3. Visit a single post or page with `.md` appended (e.g. `/hello-world.md`) and confirm a `text/markdown` response.
4. Make a request to a post or page with `Accept: text/markdown` and confirm a `text/markdown` response.
5. If pretty permalinks are enabled, flush rewrite rules (e.g., visit Settings → Permalinks) and confirm `/feed/markdown/` works.
6. Verify common content renders reasonably (headings, paragraphs, lists, links, images, code blocks).

## Notes

- The HTML-to-Markdown conversion is intentionally conservative and based on WordPress core’s HTML API (`WP_HTML_Processor`) rather than a bundled third-party parser.
- This experiment currently targets singular post content (title + metadata + content). It does not attempt to convert full theme templates or archive views.
