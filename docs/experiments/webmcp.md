# WebMCP

## Summary

The WebMCP experiment lets an agent browser (ChatGPT's in-app browser, Chrome builds with WebMCP behind a flag) work in the block editor. On the post editor screens it registers fourteen tools on `document.modelContext`, and every tool acts on the page the person is looking at, through the editor's own data stores: the title changes, the block appears, the post saves, in front of them, and they can stop at any point.

This is deliberately not a second door to the Abilities API. A site that wants server-side abilities in an agent connects it over MCP. WebMCP is for the page.

## Overview

When enabled, on `post.php` and `post-new.php` the experiment enqueues a bridge script. The bridge:

1. collects tools from its registry (the built-in editor tools, plus anything a plugin adds),
2. runs them through the `wpai.webmcp.tools` filter,
3. registers each one with `document.modelContext.registerTool()`, one call per tool, up to the per-page cap.

Each tool's `execute` runs in the page and dispatches into `core/editor` and `core/block-editor`, the same stores the editor's own UI uses, so the result is visible immediately and lands in the post's undo history.

## Editor tools

| Tool | What the person sees |
| --- | --- |
| `editor-get-document` | Nothing changes. Returns the post ID, type, status, title, and an outline of the blocks with their `clientId`, block name and a short text preview, so the agent can refer to a block precisely. |
| `editor-set-title` | The title field updates. |
| `editor-insert-block` | A new block appears, selected. Defaults to a paragraph; takes a block name, attributes, and either `afterClientId` (place it after a block) or `parentClientId` (place it inside a container). Refuses a block the editor does not allow there. |
| `editor-update-block-text` | The text of a paragraph, heading, list item, verse, preformatted or code block is replaced; the block is selected. For a quote, edit its inner paragraphs. For a pullquote, set its `value` through `editor-update-block-attributes`. |
| `editor-update-block-attributes` | Any attributes of a block change; the block is selected. |
| `editor-remove-block` | The block disappears. |
| `editor-move-block` | The block moves: after another block, into a container, or to the top of its parent. |
| `editor-duplicate-block` | A copy appears directly after the original. |
| `editor-transform-block` | The block becomes another type, through the same transforms the editor's own menu offers. |
| `editor-select-block` | The block is highlighted, for the agent to point at something before asking. |
| `editor-get-block-types` | Nothing changes. Lists what the editor allows at a position, or returns one block type's attributes, so the agent sets attributes that exist. |
| `editor-undo` | The last change is undone, exactly like the editor's Undo button. Each tool call that changed something is one undo step. |
| `editor-save` | The post saves (draft stays draft). |
| `editor-publish` | The post's status changes to published and it saves. Annotated as not read-only so an agent asks first. |

Tool descriptions are written for the model, in English, and are not translated.

Editor tools register only on an initialized block editor page, not in the classic editor. Updates, moves and removals respect the editor's lock selectors. Moving a block after itself leaves it unchanged; moving it into itself or a descendant is refused. Save and publish check the editor's save failure state and report an error when saving fails.

## Adding tools from a plugin or another screen

The bridge exposes a registry on `window.wpai.webmcp`:

```js
wpai.webmcp.registerTool( {
	name: 'woo-add-to-cart',
	description: 'Adds the product on the current page to the cart. The cart count updates on the page.',
	inputSchema: { type: 'object', properties: { quantity: { type: 'integer' } } },
	annotations: { readOnlyHint: false },
	execute: async ( { quantity = 1 } ) => {
		// act on the page, then return text content
		return { content: [ { type: 'text', text: `Added ${ quantity }.` } ] };
	},
} );
```

Register before `DOMContentLoaded` finishes, or call `wpai.webmcp.refresh()` afterwards. The `wpai.webmcp.tools` filter (`@wordpress/hooks`) receives the full list and the screen name and can remove or reorder tools.

To load the bridge on another admin screen, add its hook suffix through the PHP filter:

```php
add_filter( 'wpai_webmcp_screens', fn( array $screens ) => array_merge( $screens, array( 'edit.php' ) ) );
```

The bridge only ships editor tools; a screen added this way needs its own.

## The per-page cap

Agent browsers cap the tools a page may register. Registering a few hundred disabled WebMCP for the document with no error in testing, while about thirty worked. The bridge registers at most 30 tools, filterable through `wpai_webmcp_max_tools`, and logs the ones it dropped to the console.

## Testing

- `npm run test:php -- --filter WebMCP` covers the PHP side.
- `tests/e2e/specs/experiments/webmcp.spec.js` installs a `document.modelContext` shim before the editor loads, calls the tools the way a browser would, and asserts that the title and the canvas change.
- In an agent browser, enable the experiment, open a post, and ask the agent to give the post a title and add a paragraph. Both should appear in the editor as it works.

## Prior art

This follows the direction set in [#448](https://github.com/WordPress/ai/issues/448), where the maintainers pointed out that WebMCP is for driving the UI on the current page rather than for exposing server-side abilities a second time. [#224](https://github.com/WordPress/ai/pull/224) remains as prior art.

The structural tools (insert into a container, move, duplicate, transform, discovery of block types, undo) follow what [Block MCP](https://github.com/GravityKit/block-mcp) established for atomic block editing outside the browser: an agent needs stable references, structural operations and a way to discover what a position allows. Inside the editor those come from the editor's own stores: `clientId` is the stable reference for the session, `canInsertBlockType` enforces the site's and the template's rules, attribute changes re-render through the block's own save function, and every tool call is one undo step.
