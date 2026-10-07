# MCP Access

## Summary

The MCP Access experiment wires a site up to the [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) companion plugin and gives site owners control over which abilities are exposed to AI agents over the Model Context Protocol. Enabling the experiment installs and activates the MCP Adapter plugin from WordPress.org if it is not already active.

## Overview

### For End Users

When enabled, a new admin page appears under `Tools > MCP Access`. The page provides:

- The MCP server **endpoint URL**, read from the adapter's registered servers
- The adapter's **install state**, with a manual install/activate button when it is missing or inactive
- A table of registered abilities with per-ability **expose/hide** controls; a **Status** column shows which abilities are overridden, and toggling a checkbox back restores the developer-declared default

Abilities keep their developer-declared visibility unless the site owner explicitly changes them. The adapter's own default-server abilities (`mcp-adapter/*`) are not listed: they are the server's machinery and are active whenever the server runs.

While the experiment is enabled, the MCP Adapter plugin is installed and activated automatically on the first admin page load by a user who can install plugins. The attempt runs once per experiment activation: deactivating the adapter afterwards is respected, and toggling the experiment off and on runs the install again. Exposure overrides are only enforced while the experiment is enabled.

### For Developers

The experiment consists of:

1. **Experiment class** (`WordPress\AI\Experiments\MCP_Adapter\MCP_Adapter`): Boots the exposure filter, REST route, admin page, and installer
2. **Auto-installer** (`WordPress\AI\Experiments\MCP_Adapter\Plugin_Installer`): Installs and activates the adapter from WordPress.org once per experiment enable, via `plugins_api()` and `Plugin_Upgrader`
3. **Exposure overrides** (`WordPress\AI\Experiments\MCP_Adapter\Exposure_Overrides`): Injects saved overrides into ability registration meta so the adapter's default server honors them
4. **REST controller** (`WordPress\AI\Experiments\MCP_Adapter\Settings_Controller`): Powers the admin page over `ai/v1/mcp/settings`
5. **React admin app** (`src/experiments/mcp-adapter/`): Renders the install state and the ability exposure table

## Architecture & Implementation

### Key Hooks & Entry Points

`WordPress\AI\Experiments\MCP_Adapter\MCP_Adapter::register()` wires:

- `wp_register_ability_args` (via `Exposure_Overrides`) to apply saved exposure overrides as `meta.mcp.public`
- `rest_api_init` to register the `ai/v1/mcp/settings` route
- `admin_menu` and `admin_enqueue_scripts` for the Tools page
- `admin_init` (via `Plugin_Installer`) for the once-per-enable install attempt

### Exposure Flow

1. The MCP Adapter resolves an ability's exposure from `meta.mcp.public`, falling back to `meta.public`.
2. Overrides saved on the MCP Access screen are stored as an ability-name map and injected into that meta key at registration time, so the adapter picks them up with no hard dependency between the plugins.
3. The registration-time default is captured before injection, so the screen can always report and restore it.
4. Overrides for the reserved `mcp-adapter/*` namespace are never listed, accepted, or applied.

### Install Flow

1. On `admin_init`, if the current enable cycle has not been handled and the visitor can activate plugins, the installer resolves the adapter's install state.
2. An active copy (including one installed under a non-standard directory, detected via the adapter's classes) marks the cycle handled with no action.
3. Otherwise the installer claims the cycle atomically, downloads the plugin from WordPress.org if missing, and activates it. Success or failure is recorded; a failure is surfaced on the MCP Access screen with the manual button as the retry path.
4. Disabling the experiment clears the marker, so re-enabling runs a fresh attempt.

### Data Storage

The experiment uses two options:

- `wpai_mcp_exposed_abilities`: exposure override map (`ability_name -> bool`); abilities not present keep their developer default
- `wpai_mcp_adapter_autoinstall_handled`: once-per-enable marker (`'1'` on success, the error message on failure)

### Filters

- `wpai_mcp_adapter_plugin_slug`: Filters the WordPress.org slug of the companion plugin (useful for testing the install flow against a stand-in plugin)
- `wpai_pre_mcp_adapter_autoinstall`: Short-circuits the automatic install; return `true` to report success or a `WP_Error` to report failure without touching the filesystem
