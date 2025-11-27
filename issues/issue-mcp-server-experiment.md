# MCP Server Experiment

## What problem does this address?

The MCP Adapter is already a dependency of the AI Experiments plugin, but there's no user-facing interface to:
- See that WordPress is exposing an MCP server
- Get connection configuration for external AI tools
- Understand which abilities are exposed as MCP tools
- Test the MCP connection

Users who want to connect Claude Desktop, Cursor, or other MCP-compatible AI tools to their WordPress site have no guidance or tooling within the admin.

## What is your proposed solution?

Extend the Abilities Explorer (PR #63, Issue #62) to include an "MCP Server" tab that exposes the MCP adapter configuration and provides copy-paste setup for external AI clients.

### Core Features

**Server Status & Configuration**
- MCP server status indicator (running/stopped)
- HTTP endpoint URL display
- STDIO command for WP-CLI usage
- Authentication method configuration

**Client Configuration Generator**
Generate ready-to-use config snippets for:
- Claude Desktop (`claude_desktop_config.json`)
- Cursor (`.cursor/mcp.json`)
- Other MCP-compatible clients

**Exposed Tools List**
- Show all abilities exposed via MCP
- Filter by category
- Link to ability details/testing in Explorer tab

**Connection Testing**
- Test button to verify MCP endpoint is accessible
- Validate authentication is working
- Show sample tool call and response

## UI Mockup

```
┌─────────────────────────────────────────────────────────────┐
│  AI Experiments → Abilities                                 │
├─────────────────────────────────────────────────────────────┤
│  [Explorer] [MCP Server] [Test]                             │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  MCP Server                                                 │
│  ──────────────────────────────────────────────────────     │
│                                                             │
│  Status: ● Running                                          │
│                                                             │
│  HTTP Endpoint:                                             │
│  ┌────────────────────────────────────────────────────┐     │
│  │ https://yoursite.com/wp-json/mcp/default-server    │     │
│  └────────────────────────────────────────────────────┘     │
│  [Copy URL]                                                 │
│                                                             │
│  WP-CLI (STDIO):                                            │
│  ┌────────────────────────────────────────────────────┐     │
│  │ wp mcp-adapter serve --server=default-server       │     │
│  └────────────────────────────────────────────────────┘     │
│  [Copy Command]                                             │
│                                                             │
│  ──────────────────────────────────────────────────────     │
│                                                             │
│  Client Configuration                                       │
│  ──────────────────────────────────────────────────────     │
│                                                             │
│  [Claude Desktop ▼]                                         │
│                                                             │
│  ┌────────────────────────────────────────────────────┐     │
│  │ {                                                  │     │
│  │   "mcpServers": {                                  │     │
│  │     "wordpress": {                                 │     │
│  │       "command": "npx",                            │     │
│  │       "args": [                                    │     │
│  │         "mcp-remote",                              │     │
│  │         "https://yoursite.com/wp-json/mcp/..."     │     │
│  │       ],                                           │     │
│  │       "env": {                                     │     │
│  │         "MCP_HEADERS": "Authorization: Basic ..."  │     │
│  │       }                                            │     │
│  │     }                                              │     │
│  │   }                                                │     │
│  │ }                                                  │     │
│  └────────────────────────────────────────────────────┘     │
│  [Copy Config] [Download File]                              │
│                                                             │
│  ⚠️  You'll need an Application Password for authentication │
│  [Generate Application Password]                            │
│                                                             │
│  ──────────────────────────────────────────────────────     │
│                                                             │
│  Exposed Tools (12)                                         │
│  ──────────────────────────────────────────────────────     │
│                                                             │
│  ┌──────────────────────────┬─────────────────────────┐     │
│  │ Tool                     │ Category                │     │
│  ├──────────────────────────┼─────────────────────────┤     │
│  │ ai/title-generation      │ Content                 │     │
│  │ ai/alt-text-generation   │ Media                   │     │
│  │ ai/get-post-details      │ Utilities               │     │
│  │ ai/get-post-terms        │ Utilities               │     │
│  │ ...                      │ ...                     │     │
│  └──────────────────────────┴─────────────────────────┘     │
│                                                             │
│  [Test Connection]                                          │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

## User Flow

### First-Time Setup
1. User navigates to AI Experiments → Abilities → MCP Server tab
2. Sees MCP server is running with endpoint URL
3. Selects their AI client (Claude Desktop, Cursor, etc.)
4. Copies generated configuration
5. If needed, generates Application Password for auth
6. Pastes config into their AI client
7. Tests connection from within WordPress

### Ongoing Usage
1. User adds new abilities via plugins
2. Returns to MCP Server tab to see updated tool list
3. AI clients automatically have access to new abilities

## Technical Considerations

### Authentication Options
- **Application Passwords** (recommended) - built into WordPress
- **API Keys** - custom implementation
- **OAuth** - for more complex setups

### Transport Methods
- **HTTP** - for remote access (requires auth)
- **STDIO** - for local WP-CLI usage (no auth needed)

### Client Config Templates
Need templates for:
- Claude Desktop (macOS/Windows paths differ)
- Cursor
- Generic MCP client
- Custom/manual setup

### Security
- Warn users about exposing abilities publicly
- Recommend strong authentication
- Consider IP whitelisting option
- Rate limiting on MCP endpoint

## Integration with Abilities Explorer

This should be a tab within the Abilities Explorer interface, not a separate page:
- **Explorer Tab**: Browse and search abilities
- **MCP Server Tab**: Configure external access
- **Test Tab**: Interactive ability testing

The DataViews approach discussed in #62 should apply here too for the tools list.

## Dependencies

- PR #63 (Abilities Explorer) - should merge first or develop in parallel
- `wordpress/mcp-adapter` - already a dependency
- `wordpress/abilities-api` - already a dependency

## Related Issues

- #62 - Add feature to show what abilities are on site
- #37 - MCP usage across features and request routing
- #21 - How to best support hundreds or thousands of abilities
- #40 - WordPress Core Abilities

## Open Questions

1. Should MCP server be enabled by default or opt-in?
2. How to handle authentication for users unfamiliar with Application Passwords?
3. Should we support multiple MCP server configurations?
4. How to communicate which abilities are "safe" for external access?

## Labels

`enhancement`, `mcp`, `admin`, `abilities`
