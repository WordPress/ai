# Vendored: html-to-md (WordPress HTML API → Markdown renderer)

This directory contains a **minimal, namespaced copy** of the WordPress HTML API-based
HTML-to-Markdown renderer from [dmsnell/html-to-md](https://github.com/dmsnell/html-to-md),
bundled so the Markdown Feeds experiment can convert post HTML to Markdown without requiring
users to install a separate plugin.

- **Upstream:** [dmsnell/html-to-md](https://github.com/dmsnell/html-to-md)
- **Vendored commit:** `d525102bbc52039f35f7f425f4ffa18740b2225b`
- **License:** GPL-2.0. Original copyright © Dennis Snell / WordPress Core Team.

## What was copied

Only the runtime renderer library (`lib/`) is vendored:

| Vendored file | Upstream source |
| --- | --- |
| `WP_Experimental_HTML_Renderer.php` | `lib/class-wp-experimental-html-renderer.php` |
| `WP_Experimental_HTML_Renderer_Options.php` | `lib/class-wp-experimental-html-renderer-options.php` |
| `WP_Experimental_HTML_Renderer_Block.php` | `lib/class-wp-experimental-html-renderer-block.php` |
| `WP_Experimental_HTML_Renderer_Block_ATX.php` | `lib/class-wp-experimental-html-renderer-block-atx.php` |
| `WP_Experimental_HTML_Renderer_Block_Blockquote.php` | `lib/class-wp-experimental-html-renderer-block-blockquote.php` |
| `WP_Experimental_HTML_Renderer_Block_Code.php` | `lib/class-wp-experimental-html-renderer-block-code.php` |
| `WP_Experimental_HTML_Renderer_Block_List.php` | `lib/class-wp-experimental-html-renderer-block-list.php` |
| `WP_Experimental_HTML_Renderer_Block_Paragraph.php` | `lib/class-wp-experimental-html-renderer-block-paragraph.php` |
| `WP_Experimental_HTML_Renderer_Format.php` | `lib/class-wp-experimental-html-renderer-format.php` |
| `WP_Experimental_HTML_Renderer_Format_Generic.php` | `lib/class-wp-experimental-html-renderer-format-generic.php` |
| `WP_Experimental_HTML_Renderer_Format_Image.php` | `lib/class-wp-experimental-html-renderer-format-image.php` |
| `WP_Experimental_HTML_Renderer_Format_Link.php` | `lib/class-wp-experimental-html-renderer-format-link.php` |
| `WP_Experimental_HTML_Renderer_Line_Buffer.php` | `lib/class-wp-experimental-html-renderer-line-buffer.php` |
| `WP_Experimental_HTML_Renderer_Line_Wrapper.php` | `lib/class-wp-experimental-html-renderer-line-wrapper.php` |

## What was intentionally NOT copied

- `deps/` — standalone polyfills for the WordPress HTML API. WordPress 7.0+ ships the HTML API
  natively, so these are unnecessary.
- The `html-to-md.php` plugin bootstrap and the global `wp_html_to_markdown()` helper function
  (`wp-html-to-markdown.php`) — these carry a redeclaration-collision risk if a site also installs
  the upstream plugin. We instantiate `WP_Experimental_HTML_Renderer` directly instead.
- The loader file (`wp-experimental-html-renderer-loader.php`) — superseded by the plugin's PSR-4
  autoloader.
- `tests/` and `prior-art/` — not needed at runtime.

## Modifications applied

Kept byte-for-byte identical to upstream **except**:

1. **Namespace** changed from `WordPress\Experiments\HtmlToMarkdown` to
   `WordPress\AI\Vendor\Html_To_Markdown` in all 14 files (so the classes are isolated and resolved
   by the plugin's PSR-4 autoloader).
2. **`ABSPATH` guard** inserted immediately after the namespace declaration in each file (matches the
   Secrets vendor precedent):

   ```php
   if ( ! defined( 'ABSPATH' ) ) {
   	exit;
   }
   ```
3. **Files renamed** from upstream's `class-wp-experimental-html-renderer-*.php` kebab-case names to
   PSR-4 class-name files (e.g. `class-wp-experimental-html-renderer-options.php` →
   `WP_Experimental_HTML_Renderer_Options.php`).
4. **PHP 7.4 constructor patch** in `WP_Experimental_HTML_Renderer.php` only. Upstream uses a PHP 8.1
   `new`-in-default-parameter, which is a fatal syntax error on PHP 7.4. Rewritten to a null-coalescing
   default:

   ```diff
   -	public function __construct( string $html, ?WP_Experimental_HTML_Renderer_Options $options = new WP_Experimental_HTML_Renderer_Options() ) {
   +	public function __construct( string $html, ?WP_Experimental_HTML_Renderer_Options $options = null ) {
   		$this->html        = $html;
   -		$this->options     = $options;
   +		$this->options     = $options ?? new WP_Experimental_HTML_Renderer_Options();
   		$this->line_buffer = new WP_Experimental_HTML_Renderer_Line_Buffer();
   	}
   ```

No other changes were made. The `@since {WP_VERSION}` placeholders, the `\str_starts_with()` /
`\str_ends_with()` calls (WordPress core polyfills these globally on PHP 7.4), coding style, and
everything else are left byte-identical to upstream.

> **Note:** `WP_Experimental_HTML_Renderer_Line_Wrapper.php` defines a namespaced `line_wrap()`
> *function* rather than a class, so it is not resolvable by the class autoloader and must be
> `require`d explicitly by consumers that need it. This mirrors upstream.

## Updating

To pull a newer upstream version:

1. Re-copy the 14 `lib/` files above at the newer commit.
2. Re-apply the modifications listed above (namespace, `ABSPATH` guard, file renames, and the PHP 7.4
   constructor patch — plus re-check for any new PHP 8+ syntax via `php -l` under PHP 7.4).
3. Bump the **Vendored commit** hash recorded at the top of this file.

These files are excluded from the project's PHPCS rules and are only scanned by PHPStan for symbol
resolution (see `phpcs.xml.dist` / `phpstan.neon.dist`), so they do not need to be reformatted to
match the plugin's coding standards.

## Known upstream limitations

- **No GFM table output** — HTML tables degrade to flowed text rather than Markdown table syntax.
- **Incomplete Markdown character escaping** — certain characters that are significant in Markdown
  may not be escaped in the output.
- **Occasional alt-text / link-title duplication** — an upstream-acknowledged quirk where image alt
  text or link titles can be repeated.
