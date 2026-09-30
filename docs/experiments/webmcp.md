# WebMCP

## Summary

The WebMCP experiment lets an agent browser (ChatGPT's in-app browser, Chrome builds with WebMCP behind a flag) work in the block editor. On the post editor screens it registers a small set of tools on `document.modelContext`, and every tool acts on the page the person is looking at, through the editor's own data stores: the title changes, the block appears, the post saves, in front of them, and they can stop at any point.

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
| `editor-insert-block` | A new block appears, selected. Defaults to a paragraph; takes a block name, attributes, and an optional `afterClientId` to place it after a specific block. |
| `editor-update-block-text` | The text of a paragraph, heading, list item, quote or similar block is replaced; the block is selected. |
| `editor-update-block-attributes` | Any attributes of a block change; the block is selected. |
| `editor-remove-block` | The block disappears. |
| `editor-select-block` | The block is highlighted, for the agent to point at something before asking. |
| `editor-save` | The post saves (draft stays draft). |
| `editor-publish` | The post's status changes to published and it saves. Annotated as not read-only so an agent asks first. |

Tool descriptions are written for the model, in English, and are not translated.

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

## Evals

`src/experiments/webmcp/evals.json` holds prompts with the tool an agent is expected to pick. `node tools/webmcp-evals.mjs` runs them against any OpenAI-compatible chat endpoint (`WEBMCP_EVAL_ENDPOINT`, `WEBMCP_EVAL_API_KEY`, `WEBMCP_EVAL_MODEL`) and reports which prompts chose the wrong tool. It does not run in CI; it exists so a change to a tool description is judged by whether a model still picks the right tool.

## Testing

- `npm run test:php -- --filter WebMCP` covers the PHP side.
- `tests/e2e/specs/experiments/webmcp.spec.js` installs a `document.modelContext` shim before the editor loads, calls the tools the way a browser would, and asserts that the title and the canvas change.
- In an agent browser, enable the experiment, open a post, and ask the agent to give the post a title and add a paragraph. Both should appear in the editor as it works.

## Prior art

This follows the direction set in [#448](https://github.com/WordPress/ai/issues/448), where the maintainers pointed out that WebMCP is for driving the UI on the current page rather than for exposing server-side abilities a second time. [#224](https://github.com/WordPress/ai/pull/224) remains as prior art.
