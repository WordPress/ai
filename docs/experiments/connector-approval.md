# Connector Approval

## Summary

The Connector Approval experiment adds an administrator-controlled permission layer for AI connector usage. When enabled, outbound HTTP requests that include a configured AI connector credential are attributed to the calling plugin or theme. If that caller has not been approved for the connector, the request is blocked and a pending approval request is recorded for review.

## Overview

### For End Users

When enabled, a new admin page appears under `Tools > Connector Approvals`. The page provides:

- A **Pending requests** table showing plugins/themes that attempted to use a connector without approval
- An **Approval matrix** showing caller-by-connector approvals for active plugins and the active theme
- One-click **Approve** and **Dismiss** actions for pending requests
- Toggle controls to grant or revoke connector access per caller

Pending requests are also surfaced through an admin notice so administrators can quickly review them.

### For Developers

The experiment consists of:

1. **Experiment class** (`WordPress\AI\Experiments\Connector_Approval\Connector_Approval`): Boots the enforcement, admin UI, and REST endpoints
2. **HTTP guard** (`WordPress\AI\Connector_Approval\Http_Guard`): Hooks `pre_http_request` and blocks unapproved connector usage
3. **Caller identification** (`WordPress\AI\Connector_Approval\Caller_Identifier`): Walks the call stack to identify the originating plugin, mu-plugin, or theme
4. **Approvals store** (`WordPress\AI\Connector_Approval\Approvals_Store`): Persists approvals and pending requests in WordPress options
5. **REST controller** (`WordPress\AI\Connector_Approval\REST_Controller`): Powers the admin page data and approval actions
6. **React admin app** (`src/experiments/connector-approval/`): Renders pending requests and approval matrix UI

## Architecture & Implementation

### Key Hooks & Entry Points

`WordPress\AI\Experiments\Connector_Approval\Connector_Approval::register()` wires:

- `pre_http_request` (via `Http_Guard`) to enforce connector approvals
- `rest_api_init` to register `ai/v1/connector-approvals` endpoints
- `admin_menu` and `admin_enqueue_scripts` for the admin page
- `admin_notices` and `admin_init` for pending-request notices and dismissal handling

### Enforcement Flow

1. A plugin/theme initiates an outbound HTTP request.
2. `Http_Guard::maybe_block_request()` checks whether the request carries a known connector credential by scanning the request URL and headers through `Connector_Key_Index`.
3. If no connector credential matches, the request is ignored by this experiment.
4. If a connector matches, `Caller_Identifier` resolves the originating caller from the stack trace.
5. `Approvals_Store::is_approved()` checks whether that caller is approved for the connector.
6. If approved, request proceeds; if not approved:
   - a pending request is recorded (or attempt count incremented), and
   - a `WP_Error( 'wpai_connector_not_approved', ... )` with status `403` is returned.

### Data Storage

The experiment uses two options:

- `wpai_connector_approvals`: approval matrix (`caller_basename -> connector_id -> bool`)
- `wpai_connector_approval_pending`: pending request map with caller metadata, connector ID, attempts, first/last seen timestamps

Pending entries are keyed as `caller_basename::connector_id` and capped at 50 entries.

### Admin UI Data Flow

1. Admin page localizes `window.aiConnectorApproval` with a REST nonce.
2. React app calls:
   - `GET /ai/v1/connector-approvals` for initial state
   - `POST /ai/v1/connector-approvals` to set/revoke approval
   - `DELETE /ai/v1/connector-approvals/pending/<key>` to dismiss pending entries
3. REST responses always return the latest full state (connectors, approvals, pending, plugins, themes).

## REST API

### Endpoints

```text
GET    /wp-json/ai/v1/connector-approvals
POST   /wp-json/ai/v1/connector-approvals
DELETE /wp-json/ai/v1/connector-approvals/pending/<key>
```

### Permissions

All endpoints require `current_user_can( 'manage_options' )`.

## Testing

### Manual Testing

1. **Enable the experiment:**
   - Go to `Settings > AI`
   - Enable global experiments and **Connector Approval**
   - Ensure at least one AI connector is configured

2. **Generate a pending request:**
   - From a plugin/theme that uses AI connectors, trigger an AI request without pre-approving it
   - Verify the request fails with a `403` and code `wpai_connector_not_approved`
   - Verify an admin notice appears with a link to review requests

3. **Approve access:**
   - Open `Tools > Connector Approvals`
   - In **Pending requests**, click **Approve**
   - Re-run the same plugin action and verify the request now succeeds

4. **Revoke access:**
   - In **Approval matrix**, toggle the plugin/connector pair off
   - Re-run the plugin action and verify requests are blocked again and pending is tracked

5. **Dismiss request without approving:**
   - In **Pending requests**, click **Dismiss**
   - Verify the row disappears but the plugin is still not approved in the matrix

## Notes & Considerations

### Scope

- Enforcement happens at WordPress HTTP request level, not only in AI Client prompt APIs
- This catches direct connector-key usage in plugin HTTP calls as long as the raw key appears in URL or headers

### Matching Behavior

- Requests are matched by searching for configured connector credentials in outbound request URL/header values
- Very short credential strings are ignored to reduce false positives

### Pending Queue

- Pending requests are deduplicated by `caller_basename + connector_id`
- Repeat denied requests increment `attempts` and update `last_seen`
- Queue length is capped to avoid unbounded growth

### Limitations

- If a caller transforms or encrypts credentials before transport, key-matching cannot identify the connector
- Caller identification depends on stack-frame visibility and may return `null` in edge contexts; those requests are allowed through
