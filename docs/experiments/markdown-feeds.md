# Markdown Feeds

## Summary

The Markdown Feeds experiment serves your site's content as `text/markdown` for AI agents and other machine readers. It adds a Markdown feed at `/feed/markdown/` (available in every feed context), serves Markdown versions of individual posts and pages via `?format=md`, emits autodiscovery link tags, and can optionally negotiate Markdown via the `Accept` request header. Post HTML is converted to Markdown with a vendored copy of the WordPress HTML API-based [dmsnell/html-to-md](https://github.com/dmsnell/html-to-md) renderer, so no separate plugin is required.

## Overview

When enabled, the experiment exposes three request surfaces plus discovery link tags.

### Feed

A `markdown` feed format is registered with WordPress and is reachable at:

- `/feed/markdown/` (pretty permalinks)
- `?feed=markdown` (plain permalinks)

The feed is available in every feed context WordPress supports — main, category, tag, and author — because it hooks into the standard feed machinery. It respects the site's `posts_per_rss` setting (the "Syndication feeds show the most recent" value under **Settings → Reading**) and the **Settings → Reading** feed content option: when "For each post in a feed, include Full text / Excerpt" is set to **Excerpt** (the `rss_use_excerpt` option), each item renders the excerpt as plain text; otherwise the full post content is converted to Markdown.

The feed opens with the site name (as an H1), the site description, and the site URL, followed by one block per post. Each item block contains the post title (H2), a metadata list (link, published date, author), and the content.

### Singular

Appending `?format=md` to any singular URL (a post, page, or other singular view) returns that item as a `text/markdown` document. The singular document contains the title (H1), a metadata list (link, published date, author), and the converted post content.

- The response is served with `Content-Type: text/markdown` and an `X-Robots-Tag: noindex` header.
- Markdown is only served for posts that are publicly viewable and not password-protected.
- `?format=md` is ignored on non-singular views (archives, home, search, etc.); those requests fall through to the normal template.

### Accept-header negotiation

On singular URLs the experiment can also respond to a request that sends `Accept: text/markdown` (or `text/x-markdown`), returning the same Markdown document without needing the `?format=md` query argument. This is **off by default** and is controlled by the "Serve Markdown when a request prefers it via the Accept header" setting.

When negotiation is enabled, singular responses append a `Vary: Accept` header (appended, not replacing any existing `Vary` header) so that caches can distinguish Markdown from HTML responses. The default is off because some page caches ignore the `Vary` header and could serve a cached Markdown response to a browser (or vice versa) — the setting label calls out this caveat.

### Discovery

On every front-end page the experiment prints an autodiscovery link tag for the Markdown feed in `wp_head`:

```html
<link rel="alternate" type="text/markdown" title="Your Site Markdown Feed" href="https://example.com/feed/markdown/" />
```

On singular views it additionally prints a link tag pointing at the `?format=md` variant of the current permalink:

```html
<link rel="alternate" type="text/markdown" href="https://example.com/sample-post/?format=md" />
```

## Settings

Enable the experiment under **Settings → AI** (global AI features must also be enabled). The experiment adds one sub-toggle:

- **Serve Markdown when a request prefers it via the Accept header** — enables Accept-header negotiation on singular URLs (see above). Default: **off**.
  - Option name: `wpai_feature_markdown-feeds_field_accept_header` (a boolean option).

Toggling the experiment on or off schedules a one-time rewrite-rules flush on the next request so the `/feed/markdown/` permalink is registered or removed.

## Extending the Experiment

Both the singular document and each feed item are assembled from an ordered, named array of Markdown sections (`title`, `meta`, `content`). Blocks are joined with blank lines in array order, so you can add, remove, or reorder entries. Two filters expose these arrays.

### `wpai_markdown_singular_sections`

Filters the sections for a singular Markdown document.

```php
/**
 * @param array<string, string> $sections Named Markdown sections.
 * @param WP_Post                $post     Post being rendered.
 * @return array<string, string>
 */
apply_filters( 'wpai_markdown_singular_sections', array $sections, WP_Post $post );
```

### `wpai_markdown_feed_item_sections`

Filters the sections for a single Markdown feed item.

```php
/**
 * @param array<string, string> $sections Named Markdown sections.
 * @param WP_Post                $post     Post being rendered.
 * @return array<string, string>
 */
apply_filters( 'wpai_markdown_feed_item_sections', array $sections, WP_Post $post );
```

### Example: inject a custom field into feed items

```php
add_filter(
	'wpai_markdown_feed_item_sections',
	function ( array $sections, WP_Post $post ): array {
		$subtitle = get_post_meta( $post->ID, 'subtitle', true );

		if ( '' !== $subtitle ) {
			$sections['subtitle'] = '_' . $subtitle . '_';
		}

		return $sections;
	},
	10,
	2
);
```

The same pattern works for `wpai_markdown_singular_sections` to customize the single-post document.

## HTML to Markdown conversion

Post HTML is converted to Markdown by a vendored, namespaced copy of the WordPress HTML API-based renderer from [dmsnell/html-to-md](https://github.com/dmsnell/html-to-md). Only the runtime renderer library is bundled; the upstream plugin bootstrap and global helper function are deliberately omitted to avoid redeclaration collisions if a site also installs the upstream plugin. See `includes/Vendor/Html_To_Markdown/README.md` for the exact vendored commit, the list of copied files, and the modifications applied (namespace change, `ABSPATH` guards, PSR-4 file renames, and a PHP 7.4 constructor patch).

If conversion produces empty output or throws, the converter falls back to a stripped-tags plain-text rendering of the HTML. Note that the vendored renderer uses PHP's `intl` extension (via `IntlBreakIterator`) to wrap paragraphs, so if `intl` is not installed every conversion trips this fallback and returns plain text with no Markdown structure.

## Known limitations

Conversion inherits the upstream renderer's acknowledged limitations:

- **No GFM table output** — HTML tables degrade to flowed text rather than Markdown table syntax.
- **Incomplete Markdown character escaping** — certain characters that are significant in Markdown may not be escaped in the output.
- **Occasional alt-text / link-title duplication** — an upstream-acknowledged quirk where image alt text or link titles can be repeated.

The conversion also depends on a PHP extension:

- **Requires the PHP `intl` extension.** The vendored renderer calls `IntlBreakIterator` to wrap each paragraph. `intl` is recommended by WordPress but not guaranteed to be present on every host; without it, output silently falls back to plain text (`wp_strip_all_tags()`) with no Markdown structure.

The experiment also has intentional scope boundaries:

- **`?format=md` is only supported on singular views.** Archives and the home/blog page do not have a Markdown variant; use the `/feed/markdown/` feed (which is available in archive contexts) for list-style Markdown output.
- **No `.md` permalink suffix.** Markdown is served via the `?format=md` query argument and the `/feed/markdown/` feed route rather than by appending `.md` to permalinks. (This URL-structure decision follows the discussion on the predecessor PR #194.)
