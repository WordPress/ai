# MCP Experiment

## Purpose

The MCP experiment surfaces a control panel where site owners can provision one or more Model Context Protocol (MCP) servers, expose hand-picked abilities, generate ready-to-use client configuration, and validate connectivity without leaving wp-admin.

## Prerequisites

- WordPress 6.8+
- PHP 7.4+
- The `wordpress/mcp-adapter`, `wordpress/abilities-api`, and `wordpress/wp-ai-client` Composer dependencies installed (`composer install`)
- Built admin assets (`npm run build` or `npm run start` during development)
- An Application Password for the administrator who will authenticate remote clients (`Users → Profile → Application Passwords`)

## UI Overview

1. **Status & Transports** – Shows whether MCP is globally enabled, then for the selected server displays its REST endpoint (`/wp-json/{namespace}/{route}`) plus the `wp mcp-adapter serve --server=<id>` STDIO command. Copy buttons keep the values aligned with the current site URL.
2. **Client Configuration Generator** – Provides JSON templates for Claude Desktop, Cursor, and a generic MCP client. The templates embed the site’s REST endpoint and highlight the `MCP_HEADERS` variable that should contain a Base64-encoded Application Password credential.
3. **Servers Toolbar** – Administrators can switch between existing servers or create new ones. Each server tracks its own route namespace/slug, transport list, and ability allow-list.
4. **Exposed Abilities Table** – Lists every registered ability (with category + provider badges) and lets administrators toggle whether it’s available for the selected server. When a server’s allow-list is empty it automatically falls back to “all MCP-public abilities”.
4. **Connection Test** – Issues a lightweight HTTP request against the endpoint and reports the HTTP status code so admins can confirm routing/authentication before wiring an external client.

## Implementation Notes

- `WordPress\AI\Experiments\MCP\Manager` owns all configuration: it stores server definitions in `ai_mcp_servers`, migrates the legacy `ai_mcp_enabled_tools` option, initializes the adapter, and registers each server (optionally passing custom allow-lists) during the `mcp_adapter_init` hook.
- REST routes live under `ai/v1/mcp` via `WordPress\AI\Experiments\MCP\REST\MCP_Controller`. Endpoints include overview (`GET /ai/v1/mcp?server_id=...`), global enable toggle, server CRUD, per-server tool updates, and connection tests.
- The React application is built from `src/admin/mcp-server` and enqueued from `WordPress\AI\Experiments\MCP\Admin_Page` (top-level **MCP** menu). Assets compile to `build/admin/mcp-server.js` and `build/admin/style-mcp-server.css`.
- Client-side data hydrates via `window.aiMcpServerSettings`, which now only contains REST routing + nonce metadata; the UI fetches its state from the overview endpoint on load and whenever the selected server changes.

## Manual Testing Checklist

1. Run `composer install` and `npm install` if dependencies are missing.
2. Build the assets with `npm run build` (or `npm run start` while iterating).
3. Navigate to the top-level **MCP** menu as an administrator. Confirm the status card shows “Running” once `/wp-json/{namespace}/{route}` is reachable.
4. Use the server selector to add an additional server. Verify a new REST route slug is generated and that it appears in the status card + CLI copy helper.
5. Toggle abilities for each server and ensure the REST response updates immediately (the allow-list lives inside `ai_mcp_servers`).
6. Use the **Copy URL** and **Copy Command** buttons and verify clipboard contents.
7. Use the **Client configuration** selector to copy the Claude Desktop template, then spot-check that it includes the site’s REST URL.
8. Click **Test connection** for each server. When authenticated, the notice should report the HTTP status code (401 is acceptable if Application Password headers weren’t supplied). If HTTPS fails locally, the fallback should retry with HTTP.
9. Create an Application Password for your admin user, launch `wp mcp-adapter serve --server=<server-id>`, and paste one of the generated templates into Claude Desktop or Cursor to confirm MCP clients can connect end-to-end.
