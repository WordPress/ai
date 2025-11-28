# AI Request Logging

## Summary
Provides an opt-in observability surface that records every AI request (provider, model, duration, token counts, status, cost estimate) and exposes them through a React-powered dashboard under `Settings → AI Request Logs`. When enabled, outbound HTTP calls made via the WP AI Client are wrapped with a logging HTTP client.

## Key Hooks & Entry Points
- `WordPress\AI\Experiments\AI_Request_Logging\AI_Request_Logging::register()`:
  - `rest_api_init` → registers `WordPress\AI\Logging\REST\AI_Request_Log_Controller`, which exposes `/ai/v1/logs`, `/summary`, `/filters`, and per-log endpoints.
  - `is_admin()` guard → instantiates `WordPress\AI\Logging\AI_Request_Log_Page` to add the Settings submenu and enqueue assets.
- Plugin bootstrap now only initializes `WordPress\AI\Logging\Logging_Discovery_Strategy` (and `AI_Request_Log_Manager::init()`) when the experiment toggle is enabled, ensuring the HTTP wrapper and daily cleanup job stay disabled otherwise.
- Database + cleanup are handled inside `AI_Request_Log_Manager::init()` (table creation, cron scheduling, option storage).

## Assets & Data Flow
1. When `AI Request Logs` is visited, `Asset_Loader` enqueues `admin/ai-request-logs` (`src/admin/ai-request-logs/index.tsx`) plus its stylesheet. The localized payload (`window.AiRequestLogsSettings`) includes REST routes, a nonce, and initial state (enabled flag, retention days, summary, filters).
2. The React app:
   - Configures `@wordpress/api-fetch` with the nonce/root.
   - Fetches logs (`GET /ai/v1/logs` with search/filter params) and displays them in a table with pagination.
   - Fetches summaries (`GET /ai/v1/logs/summary`) for the KPI cards and filter metadata (`GET /ai/v1/logs/filters`).
   - Posts to `/ai/v1/logs` to toggle logging and retention, and sends `DELETE /ai/v1/logs` to purge the table.
3. On the backend, every AI HTTP request flows through `Logging_HTTP_Client`, which records metrics via `AI_Request_Log_Manager::log()` before returning the response to callers. Logs are stored in the `wp_ai_request_logs` table alongside JSON context for later inspection.

## Testing
1. Enable Experiments globally, toggle **AI Request Logging**, and ensure valid AI credentials exist (the experiment won’t enable otherwise).
2. Trigger an AI-powered feature (e.g., Type Ahead or Title Generation) so the system issues at least one completion request.
3. Navigate to `Settings → AI Request Logs`. Confirm the chart and table populate and that the “Logging enabled” toggle reflects the settings page switch.
4. Change the retention days value, save, and verify the option persists (reload the page or inspect `ai_request_logs_retention_days`).
5. Click “Purge logs”, confirm the success notice, and check the table empties.
6. Disable the experiment and reload a front-end AI feature; no new rows should appear, and the `Logging_Discovery_Strategy` hooks should remain inactive.

## Notes
- The HTTP logging layer only boots when both the global experiment switch and the `ai-request-logging` toggle are on, preventing unnecessary DB tables or cron events on installs that don’t need observability.
- REST endpoints require `manage_options`.
- Model cost estimates rely on the static pricing table inside `AI_Request_Log_Manager`; update it as provider pricing evolves.
