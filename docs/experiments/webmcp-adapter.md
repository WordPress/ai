# WebMCP Adapter

## Summary

The WebMCP Adapter experiment exposes WordPress abilities to browser agents through `navigator.modelContext` using a layered tool surface:

- `wp-discover-abilities`
- `wp-get-ability-info`
- `wp-execute-ability`

It runs in wp-admin and executes abilities through the Abilities client API (`@wordpress/abilities` or `wp.abilities`), so server-side permission callbacks and REST auth still remain authoritative.

## Exposure Model

An ability is exposed to agents only when:

1. `meta.mcp.public === true`
2. Optional `meta.mcp.context` rules match the current WordPress page context.

Supported context rules:

- `screens`: matches `pagenow` (for example `post.php`, `site-editor.php`)
- `adminPages`: matches `adminpage`
- `postTypes`: matches `typenow` (or `post_type` query var fallback)
- `query`: exact query-var matching

## Confirmation Policy

Execution confirmation uses ability annotations:

- `meta.annotations.readonly === true` -> no extra confirmation
- `meta.annotations.destructive === true` -> double confirmation
- otherwise -> standard confirmation prompt

Prompts are requested through `agent.requestUserInteraction()` when available.

## Debug Panel

The experiment includes an optional in-page debug panel to verify WebMCP registration and run quick tool calls.

Enablement options:

- **Experiment setting:** `Enable WebMCP debug panel` in `Settings -> AI Experiments`
- **Runtime JS flag:** `window.aiWebMCPDebug = true` (or `false` to disable)

Advanced runtime config:

```js
window.aiWebMCPDebug = {
	enabled: true,
	open: true,
	shimModelContext: true, // Optional: auto-install local modelContext shim for browser testing
};
```

The adapter also exposes a small runtime API:

```js
window.aiWebMCPAdapterDebug.enable();
window.aiWebMCPAdapterDebug.disable();
window.aiWebMCPAdapterDebug.refresh();
window.aiWebMCPAdapterDebug.getState();
window.aiWebMCPAdapterDebug.register( { forceReloadAbilities: true } );
window.aiWebMCPAdapterDebug.installModelContextShim();
window.aiWebMCPAdapterDebug.callTool( 'discover' );
```

## Hooks

### `ai_webmcp_adapter_allowed_hooks`

Filters admin hooks where the adapter is enqueued.

Default hooks:

- `post.php`
- `post-new.php`
- `site-editor.php`
- `appearance_page_gutenberg-edit-site`
- `admin_page_gutenberg-edit-site`
- `appearance_page_site-editor-v2`

### `ai_webmcp_adapter_tool_names`

Filters layered tool names.

Default:

- `discover`: `wp-discover-abilities`
- `info`: `wp-get-ability-info`
- `execute`: `wp-execute-ability`

### `ai.webmcp.isAbilityExposed` (JS filter)

Client-side filter (via `@wordpress/hooks`) to override default exposure decisions.

Arguments:

1. `isExposed` (`boolean`)
2. `ability` (`Ability`)
3. `wpContext` (`{ screen, adminPage, postType, query }`)

## Requirements

- Browser must support `navigator.modelContext` to register tools.
- WordPress abilities API must be available, either via:
  - `window.wp.abilities` globals, or
  - script modules (`@wordpress/core-abilities` + `@wordpress/abilities`).

For browsers without native WebMCP support, you can enable the local debug shim with `window.aiWebMCPDebug = { enabled: true, shimModelContext: true }` or `window.aiWebMCPAdapterDebug.installModelContextShim()`.
