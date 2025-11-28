# MCP Server Admin Page

## Purpose

The MCP Server admin page gives site owners a dedicated control surface for exposing WordPress abilities to Model Context Protocol (MCP) clients. It surfaces runtime status, ready-to-paste client configuration, copy buttons for both the HTTP and STDIO transports, an allow-list for abilities, and an inline connection tester.

## Prerequisites

- WordPress 6.8+
- PHP 7.4+
- The `wordpress/mcp-adapter`, `wordpress/abilities-api`, and `wordpress/wp-ai-client` Composer dependencies installed (`composer install`)
- Built admin assets (`npm run build` or `npm run start` during development)
- An Application Password for the administrator who will authenticate remote clients (`Users → Profile → Application Passwords`)

## UI Overview

1. **Status & Transports** – Shows whether the default MCP server is running, the REST endpoint (`/wp-json/mcp/mcp-adapter-default-server`), and the `wp mcp-adapter serve --server=mcp-adapter-default-server` STDIO command. Copy buttons keep the values in sync with whatever URL WordPress is currently using.
2. **Client Configuration Generator** – Provides JSON templates for Claude Desktop, Cursor, and a generic MCP client. The templates embed the site’s REST endpoint and highlight the `MCP_HEADERS` variable that should contain a Base64-encoded Application Password credential.
3. **Exposed Abilities Table** – Lists every registered ability that includes `meta.mcp` metadata and lets administrators toggle whether it remains publicly accessible through the MCP adapter. The selection is stored in `ai_mcp_enabled_tools` so it survives deployments.
4. **Connection Test** – Issues a lightweight HTTP request against the endpoint and reports the HTTP status code so admins can confirm routing/authentication before wiring an external client.

## Implementation Notes

- `WordPress\AI\MCP\MCP_Server_Manager` bootstraps the adapter on every request, filters the default server config, and lowers `meta.mcp.public` for abilities removed from the UI allow-list via the `wp_register_ability_args` filter.
- REST endpoints live under `ai/v1/mcp-server` and are registered by `WordPress\AI\MCP\REST\Mcp_Server_Controller`. All routes require `manage_options`.
- The React application is built from `src/admin/mcp-server` and enqueued from `MCP_Server_Page` (Settings → MCP Server). Assets compile to `build/admin/mcp-server.js` and `build/admin/style-mcp-server.css`.
- Front-end data is hydrated via `aiMcpServerSettings`, which provides REST routes, a nonce, the Application Password helper URL, and the server’s initial status.

## Manual Testing Checklist

1. Run `composer install` and `npm install` if dependencies are missing.
2. Build the assets with `npm run build` (or `npm run start` while iterating).
3. Navigate to **Settings → MCP Server** as an administrator. Ensure the status card shows “Running” once `wp-json/mcp/mcp-adapter-default-server` is reachable.
4. Toggle an ability off and on, then confirm the `ai_mcp_enabled_tools` option reflects the change and that the REST response updates immediately.
5. Use the **Copy URL** and **Copy Command** buttons and verify clipboard contents.
6. Use the **Client configuration** selector to copy the Claude Desktop template, then spot-check that it includes the site’s REST URL.
7. Click **Test connection**. When authenticated, the notice should report the HTTP status code (401 is acceptable if Application Password headers weren’t supplied).
8. Create an Application Password for your admin user, launch `wp mcp-adapter serve --server=mcp-adapter-default-server`, and paste one of the generated templates into Claude Desktop or Cursor to confirm MCP clients can connect end-to-end.
