# AI Admin Console (Abilities Explorer + MCP Server)

## Overview

Unify everything related to abilities inside a single React experience that both **surfaces abilities on the site** and **exposes them to external MCP clients**. The intention is to replace the current static Explorer list with a DataViews interface, add richer ability detail/test tooling, and place the MCP server configuration/testing workflow alongside it.

## What problems does this address?

1. **Poor discoverability of abilities** – The current Explorer UI is a basic list that does not match the modern WordPress admin patterns (no filtering, very little hierarchy, no bulk actions, no responsive layout).
2. **No first‑party MCP guidance** – Even though the MCP adapter ships with the plugin, administrators have no way to copy connection details, generate client configs, or verify that external agents can talk to their site.
3. **Fragmented workflow** – Ability discovery, testing, and MCP exposure all live in separate places. We want a single “AI Admin Console” that mirrors the [Site Editor → DataViews](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-dataviews/) experience.

## Proposed solution

| Pillar | Description |
|--------|-------------|
| **Explorer (DataViews)** | Replace the current list with `@wordpress/dataviews`, giving us consistent filtering, search, bulk actions, and multiple layouts (table, grid, list). |
| **Ability Insights** | Provide detail + test drawers/modals that show schema, metadata, activation state, and a quick “Test ability” action using the existing test harness. |
| **MCP Server Panel** | A dedicated tab inside the console that surfaces HTTP/STDIO endpoints, generates Claude Desktop / Cursor config snippets, lists exposed tools, and runs connectivity tests. |

```
┌───────────────────────────────────────────────┐
│ AI Experiments → Abilities                    │
├───────────────────────────────────────────────┤
│ [Explorer] [MCP Server] [Test]                │
│                                               │
│ Explorer (DataViews)                          │
│ ┌───────────────────────────────────────────┐ │
│ │ Search ▢  Filters ▾  View: Table/Grid/List│ │
│ └───────────────────────────────────────────┘ │
│ │ □ ai/title-generation  Content  AI Exp.  ⋮ │ │
│ │   Generates title suggestions…             │ │
│ │ □ ai/alt-text-generation  Media  AI Exp. ⋮ │ │
│ └───────────────────────────────────────────┘ │
│                                               │
└───────────────────────────────────────────────┘
```

### Explorer: DataViews structure

**Fields / columns**

| Field | Purpose |
|-------|---------|
| `name` | Machine ID (e.g., `ai/title-generation`). Shows as bold text with label + description stacked beneath. |
| `category` | Taxonomy filter (Content, Media, Utility…). |
| `source` | Core / Plugin / Theme origin, plus human name. |
| `status` | Badge that surfaces `isActive` state (Active, Inactive, Missing dependency). |
| `actions` | Row actions: View details, Test, Copy ID, Copy MCP Tool name. |

**Filters & controls**
- Category, Source, Status filters (multi-select where it makes sense).
- Global search across ID, label, description.
- Bulk actions: export selected ability schemas, copy IDs, toggle activation (future).
- View switcher: Table (default), Grid (cards), List (compact).

**Data source**

Use the existing `/wp-json/ai/v1/abilities` endpoint (or create a REST wrapper) that returns:

```ts
interface AbilityData {
  id: string;
  label: string;
  description: string;
  category: string;
  source: 'core' | 'plugin' | 'theme';
  sourceName: string;
  inputSchema: object;
  outputSchema: object;
  isActive: boolean;
}
```

### Ability detail & testing

Clicking a row opens a modal/drawer that includes:
- Metadata + description + status.
- Input/output schema rendered as code blocks.
- Context (category, source, dependencies).
- Primary CTAs: “Test this ability” (opens in Test tab) and “Copy ID”.

### MCP Server tab

Goal: zero-copy configuration for Claude Desktop, Cursor, and other MCP clients.

```
┌───────────────────────────────────────────────┐
│ MCP Server                                    │
├───────────────────────────────────────────────┤
│ Status: ● Running                             │
│ HTTP Endpoint: https://example.com/wp-json/...│
│ [Copy URL]                                    │
│ WP-CLI (STDIO): wp mcp-adapter serve --server │
│ [Copy Command]                                │
│                                               │
│ Client Config ▾  (Claude Desktop / Cursor …)  │
│ { "mcpServers": { "wordpress": { … } } }      │
│ [Copy config] [Download file]                 │
│                                               │
│ Exposed tools (DataView list w/ filters)      │
│ [Test Connection] [Generate App Password]     │
└───────────────────────────────────────────────┘
```

Key behaviors:
- Status indicator that reflects adapter health (running/stopped/error).
- Endpoint card exposes HTTP + STDIO commands. Include helper buttons to copy values.
- Authentication helper: explain Application Passwords, link to creation modal.
- Client configuration generator for Claude Desktop (`claude_desktop_config.json`), Cursor (`.cursor/mcp.json`), and a “manual/custom” option. Each snippet should inject endpoint + auth headers.
- Tool list reuses the DataViews component so filters stay familiar. Selecting a tool deep-links back to the Explorer detail/test surface.
- “Test connection” action performs a simple MCP ping/tool invocation and surfaces latency + auth errors inline.

### Testing tab

Reuse the existing “Test” view but upgrade its layout to feel like part of the console:
- Launches ability test modals with prefilled sample payloads.
- Shows recent test history, latency, token usage (if available).
- Future: allow posting sample MCP tool invocations here as well.

## Technical notes

- All three tabs live in the same React entry point registered from `includes/Abilities/Abilities_Admin_Page.php` (or similar). Build with `@wordpress/data`, `@wordpress/components`, and `@wordpress/dataviews`.
- REST endpoints required:
  - `/ai/v1/abilities` (list/detail) – already exists.
  - `/ai/v1/mcp/server` – exposes status, endpoints, auth requirements.
  - `/ai/v1/mcp/clients` – returns config templates, accepts POST to trigger “download”.
  - `/ai/v1/mcp/test` – performs connection test and returns result payload.
- Security considerations: warn about exposing abilities publicly, encourage locked-down auth, consider rate limiting and Application Password scopes.

## Dependencies

- Requires WordPress 6.5+ for DataViews + modern components.
- Abilities Explorer PR #63 for baseline data.
- `wordpress/mcp-adapter` and `wordpress/abilities-api` packages already bundled.

## Open questions

1. Should MCP server be opt-in or always running when the plugin is active?
2. Do we support multiple MCP server configurations (e.g., different namespaces)?
3. How should we persist client-specific settings (App Password aliasing, default client dropdown selection)?
4. Can we extend DataViews bulk actions to toggle ability activation, or should that remain elsewhere?

## Labels

`enhancement`, `admin`, `abilities`, `mcp`, `dataviews`, `ui`
