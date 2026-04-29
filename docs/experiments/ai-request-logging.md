# AI Request Logging

## Summary
Provides an opt-in observability surface that records every AI request (provider, model, duration, token counts, status, and request source) and exposes them through a React-powered dashboard under `Tools → AI Request Logs`. When enabled, the SDK's HTTP transporter is wrapped with a logging decorator using the public `setHttpTransporter()` API.

## Key Hooks & Entry Points
- `WordPress\AI\Experiments\AI_Request_Logging\AI_Request_Logging::register()`:
  - Calls `AI_Request_Log_Manager::init()` to ensure the log table schema is synchronized and the cleanup cron exists.
  - Calls `Logging_Integration::init()` so the SDK transporter is wrapped only while the feature is enabled.
  - `rest_api_init` → registers `WordPress\AI\Logging\REST\AI_Request_Log_Controller`, which exposes `/ai/v1/logs`, `/summary`, `/filters`, and per-log endpoints.
  - `admin_menu` → registers `WordPress\AI\Logging\AI_Request_Log_Page`, which adds the `Tools` submenu and enqueues assets.
- Database + cleanup are handled inside `AI_Request_Log_Manager::init()` (schema-version-gated table sync, cron scheduling, option storage).

## Architecture
The logging system uses the decorator pattern to wrap the SDK's HTTP transporter:

1. `Logging_Integration::init()` is called from the feature registration flow when the experiment is enabled.
2. On `wp_loaded` or `admin_init`, `Logging_Integration::wrap_transporter()`:
   - Gets the current transporter from `AiClient::defaultRegistry()`
   - Creates a `Logging_Http_Transporter` decorator around it
   - Uses `registry->setHttpTransporter()` to swap in the logging version
3. All subsequent AI requests go through the logging transporter, which records metrics before delegating to the underlying transporter.
4. `Logging_Http_Transporter` also inspects the PHP call stack to record whether the request was initiated by a plugin, mu-plugin, theme, or WordPress core file when that can be determined.
5. Data extraction is handled by `Log_Data_Extractor`, which parses request/response payloads and extracts provider, model, tokens, and previews.

This approach uses the SDK's public API rather than reflection or internal hacks, making it resilient to SDK updates.

## Filter Hooks
The logging system exposes several filter hooks for extensibility:

### `wpai_request_log_providers`
Filters the provider detection patterns. The default map is derived from the connectors registry — every `ai_provider` connector contributes a host-substring pattern equal to its slug (with `google` matching `googleapis` since its API runs on a different domain). This filter is the extension point for non-connector providers or for connectors whose slug doesn't appear in their API host (for example, a connector whose API is served from an unrelated domain).

```php
add_filter( 'wpai_request_log_providers', function( $patterns ) {
    $patterns['my_provider'] = array( 'my-api.com', 'api.myprovider.io' );
    return $patterns;
} );
```

### `wpai_request_log_context`
Filters the log context data before it's stored. Allows adding custom context or removing sensitive data.

```php
add_filter( 'wpai_request_log_context', function( $context, $decoded, $log_data ) {
    $context['custom_field'] = 'custom_value';
    unset( $context['sensitive_field'] );
    return $context;
}, 10, 3 );
```

### `wpai_request_log_tokens`
Filters the extracted token usage. Allows custom providers to supply their own token extraction logic.

```php
add_filter( 'wpai_request_log_tokens', function( $tokens, $response ) {
    if ( isset( $response['my_token_field'] ) ) {
        $tokens['input'] = $response['my_token_field']['in'];
        $tokens['output'] = $response['my_token_field']['out'];
    }
    return $tokens;
}, 10, 2 );
```

### `wpai_request_log_kind`
Filters the detected request kind (text, image, embeddings, audio, metadata).

```php
add_filter( 'wpai_request_log_kind', function( $kind, $provider, $path, $payload ) {
    if ( false !== strpos( $path, '/my-custom-endpoint' ) ) {
        return 'custom';
    }
    return $kind;
}, 10, 4 );
```

## Assets & Data Flow
1. When `AI Request Logs` is visited, `Asset_Loader` enqueues `admin/ai-request-logs` (`src/admin/ai-request-logs/index.tsx`) plus its stylesheet. The localized payload (`window.AiRequestLogsSettings`) includes REST routes, a nonce, and initial state (enabled flag, retention days, summary, filters).
2. The React app:
   - Configures `@wordpress/api-fetch` with the nonce/root.
   - Fetches logs (`GET /ai/v1/logs` with search/filter params) and displays them in a table with pagination.
   - Uses a persisted operations multi-select that excludes `*:models` discovery calls by default so capability lookups do not drown out actual request traffic unless the user opts in.
   - Fetches summaries (`GET /ai/v1/logs/summary`) for the KPI cards, including `minute`, `hour`, `day`, `week`, `month`, and `all` periods, and filter metadata (`GET /ai/v1/logs/filters`).
   - Posts to `/ai/v1/logs` to toggle logging and retention, and sends `DELETE /ai/v1/logs` to purge the table.
3. On the backend, every AI HTTP request flows through `Logging_Http_Transporter`, which records metrics via `AI_Request_Log_Manager::log()` before returning the response to callers. Logs are stored in the `wp_ai_request_logs` table alongside JSON-encoded context for later inspection.

## Testing
1. Enable Experiments globally, toggle **AI Request Logging**, and ensure valid AI credentials exist (the experiment won't enable otherwise).
2. Trigger an AI-powered feature (e.g., Type Ahead or Title Generation) so the system issues at least one completion request.
3. Navigate to `Tools → AI Request Logs`. Confirm the chart and table populate, that the "Logging enabled" toggle reflects the current setting, and that `*:models` discovery calls only appear after you explicitly include them in the operations filter.
4. Change the retention days value, save, and verify the option persists (reload the page or inspect `wpai_feature_ai-request-logging_field_retention_days`).
5. Click "Purge logs", confirm the success notice, and check the table empties.
6. Disable the experiment and reload a front-end AI feature; no new rows should appear, and the logging integration should remain inactive.

## Notes
- The HTTP logging layer only boots when both the global experiment switch and the `ai-request-logging` toggle are on, preventing unnecessary DB tables or cron events on installs that don't need observability.
- REST endpoints require `manage_options`.
