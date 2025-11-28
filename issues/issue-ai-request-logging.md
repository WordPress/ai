# AI Request Logging & Observability

## What problem does this address?

Currently there's no visibility into AI operations happening within WordPress. When the WP AI Client makes requests, MCP server tools are invoked, or abilities execute, administrators and developers have no way to:

- Track request/response timing and latency
- Monitor token usage and estimate costs
- Debug failed or slow AI operations
- Understand usage patterns across the site
- Audit AI activity for compliance or billing

As the AI Experiments plugin matures and abilities multiply, observability becomes critical for:
- **Cost management**: AI API calls cost money; users need to understand their spend
- **Performance optimization**: Identifying slow operations or bottlenecks
- **Debugging**: Tracing issues when AI features misbehave
- **Usage analytics**: Understanding which abilities are actually being used

## What is your proposed solution?

Implement a centralized logging system that captures metadata for all AI-related operations:

### Three Log Sources

1. **WP AI Client Requests** - Direct API calls to AI providers (OpenAI, Anthropic, etc.)
2. **MCP Server Tool Invocations** - External AI tools calling WordPress abilities
3. **Ability Executions** - Internal ability executions (may overlap with #1 and #2)

### Core Metadata to Capture

| Field | Type | Description |
|-------|------|-------------|
| `id` | string | Unique log entry identifier |
| `timestamp` | datetime | When the operation started |
| `type` | enum | `ai_client`, `mcp_tool`, `ability` |
| `operation` | string | Specific operation (e.g., `generate_text`, `ai/title-generation`) |
| `provider` | string | AI provider used (e.g., `openai`, `anthropic`) |
| `model` | string | Model identifier (e.g., `gpt-4`, `claude-3-sonnet`) |
| `duration_ms` | int | Total request/response time in milliseconds |
| `tokens_input` | int | Input/prompt tokens used |
| `tokens_output` | int | Output/completion tokens generated |
| `tokens_total` | int | Total tokens (input + output) |
| `cost_estimate` | float | Estimated cost in USD based on model pricing |
| `status` | enum | `success`, `error`, `timeout` |
| `error_message` | string | Error details if status != success |
| `user_id` | int | WordPress user who initiated the operation |
| `context` | json | Additional context (post_id, ability_args, etc.) |

### Model Pricing Registry

Maintain a registry of model costs for accurate cost estimation:

```php
$model_costs = [
    'openai' => [
        'gpt-4' => ['input' => 0.03, 'output' => 0.06],      // per 1K tokens
        'gpt-4-turbo' => ['input' => 0.01, 'output' => 0.03],
        'gpt-3.5-turbo' => ['input' => 0.0005, 'output' => 0.0015],
    ],
    'anthropic' => [
        'claude-3-opus' => ['input' => 0.015, 'output' => 0.075],
        'claude-3-sonnet' => ['input' => 0.003, 'output' => 0.015],
        'claude-3-haiku' => ['input' => 0.00025, 'output' => 0.00125],
    ],
];
```

This registry should be:
- Filterable so users can update pricing
- Periodically updateable (pricing changes)
- Fallback to estimates when model unknown

## UI Mockup

### Logs Dashboard Tab (within Abilities Explorer)

```
┌─────────────────────────────────────────────────────────────────┐
│  AI Experiments → Abilities                                     │
├─────────────────────────────────────────────────────────────────┤
│  [Explorer] [MCP Server] [Logs] [Settings]                      │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  AI Request Logs                                                │
│  ───────────────────────────────────────────────────────────    │
│                                                                 │
│  Summary (Last 24 Hours)                                        │
│  ┌────────────┬────────────┬────────────┬────────────┐          │
│  │ Requests   │ Tokens     │ Avg Time   │ Est. Cost  │          │
│  │ 247        │ 1.2M       │ 1.8s       │ $4.52      │          │
│  └────────────┴────────────┴────────────┴────────────┘          │
│                                                                 │
│  [Date Range ▼] [Type ▼] [Status ▼] [Provider ▼] [Search...]    │
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │ Time        │ Operation          │ Model      │ Tokens │    │
│  │             │                    │            │ Time   │    │
│  ├─────────────┼────────────────────┼────────────┼────────┤    │
│  │ 2:34 PM     │ ai/title-gen       │ gpt-4      │ 1,240  │    │
│  │ ● success   │ Post #4521         │            │ 2.1s   │    │
│  ├─────────────┼────────────────────┼────────────┼────────┤    │
│  │ 2:32 PM     │ ai/alt-text        │ claude-3   │ 856    │    │
│  │ ● success   │ Image #892         │ sonnet     │ 1.4s   │    │
│  ├─────────────┼────────────────────┼────────────┼────────┤    │
│  │ 2:30 PM     │ mcp/execute        │ gpt-4      │ 2,103  │    │
│  │ ● error     │ External client    │            │ 5.2s   │    │
│  │             │ "Rate limit..."    │            │        │    │
│  └─────────────┴────────────────────┴────────────┴────────┘    │
│                                                                 │
│  ◀ 1 2 3 ... 12 ▶                         Items per page: [25] │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### Log Detail Modal

```
┌─────────────────────────────────────────────────────────────┐
│  Request Details                                        [✕]  │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  ai/title-generation                           ● Success    │
│  ────────────────────────────────────────────────────────   │
│                                                             │
│  Timestamp:     Nov 27, 2025 2:34:21 PM                     │
│  Duration:      2,134 ms                                    │
│  User:          admin (ID: 1)                               │
│                                                             │
│  Provider & Model                                           │
│  ────────────────────────────────────────────────────────   │
│  Provider:      OpenAI                                      │
│  Model:         gpt-4-turbo                                 │
│                                                             │
│  Token Usage                                                │
│  ────────────────────────────────────────────────────────   │
│  Input:         892 tokens                                  │
│  Output:        348 tokens                                  │
│  Total:         1,240 tokens                                │
│  Est. Cost:     $0.019                                      │
│                                                             │
│  Context                                                    │
│  ────────────────────────────────────────────────────────   │
│  ┌─────────────────────────────────────────────────────┐    │
│  │ {                                                   │    │
│  │   "post_id": 4521,                                  │    │
│  │   "post_type": "post",                              │    │
│  │   "candidates": 3                                   │    │
│  │ }                                                   │    │
│  └─────────────────────────────────────────────────────┘    │
│                                                             │
│  [View Full Request] [View Full Response] [Copy Log ID]     │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

## Technical Implementation

### 1. Log Storage

```php
// Custom table for log storage
global $wpdb;
$table = $wpdb->prefix . 'ai_request_logs';

CREATE TABLE $table (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    log_id VARCHAR(36) NOT NULL,
    timestamp DATETIME NOT NULL,
    type ENUM('ai_client', 'mcp_tool', 'ability') NOT NULL,
    operation VARCHAR(255) NOT NULL,
    provider VARCHAR(64),
    model VARCHAR(128),
    duration_ms INT UNSIGNED,
    tokens_input INT UNSIGNED,
    tokens_output INT UNSIGNED,
    tokens_total INT UNSIGNED,
    cost_estimate DECIMAL(10, 6),
    status ENUM('success', 'error', 'timeout') NOT NULL,
    error_message TEXT,
    user_id BIGINT UNSIGNED,
    context JSON,
    INDEX idx_timestamp (timestamp),
    INDEX idx_type (type),
    INDEX idx_status (status),
    INDEX idx_user_id (user_id)
);
```

### 2. Logger Class

```php
namespace WordPress\AI\Logging;

class AI_Request_Logger {

    public function log( array $data ): string {
        $log_id = wp_generate_uuid4();

        // Calculate cost estimate
        $data['cost_estimate'] = $this->estimate_cost(
            $data['provider'] ?? '',
            $data['model'] ?? '',
            $data['tokens_input'] ?? 0,
            $data['tokens_output'] ?? 0
        );

        // Insert into database
        $this->insert_log( $log_id, $data );

        // Fire action for external integrations
        do_action( 'ai_request_logged', $log_id, $data );

        return $log_id;
    }

    public function start_timer(): array {
        return [
            'start' => hrtime( true ),
            'memory_start' => memory_get_usage(),
        ];
    }

    public function end_timer( array $timer ): int {
        $end = hrtime( true );
        return (int) ( ( $end - $timer['start'] ) / 1e6 ); // Convert to ms
    }
}
```

### 3. Integration Points

**WP AI Client Integration:**
```php
// In WordPress_HTTP_Client or a decorator
public function sendRequest( RequestInterface $request ): ResponseInterface {
    $timer = $this->logger->start_timer();

    try {
        $response = parent::sendRequest( $request );

        $this->logger->log([
            'type' => 'ai_client',
            'operation' => $this->extract_operation( $request ),
            'duration_ms' => $this->logger->end_timer( $timer ),
            'status' => 'success',
            // ... extract tokens from response headers/body
        ]);

        return $response;
    } catch ( \Exception $e ) {
        $this->logger->log([
            'type' => 'ai_client',
            'duration_ms' => $this->logger->end_timer( $timer ),
            'status' => 'error',
            'error_message' => $e->getMessage(),
        ]);

        throw $e;
    }
}
```

**MCP Server Integration:**
```php
// Hook into MCP adapter tool execution
add_action( 'mcp_adapter_before_tool_execution', function( $tool_name, $args ) {
    // Start timing
});

add_action( 'mcp_adapter_after_tool_execution', function( $tool_name, $result, $timer ) {
    $logger->log([
        'type' => 'mcp_tool',
        'operation' => $tool_name,
        // ...
    ]);
});
```

**Ability Execution Integration:**
```php
// Hook into ability execution
add_filter( 'wp_execute_ability_result', function( $result, $ability_name, $args ) {
    // Log ability execution
}, 10, 3 );
```

### 4. REST API Endpoints

```
GET  /wp-json/ai/v1/logs              # List logs with filtering
GET  /wp-json/ai/v1/logs/{id}         # Get single log entry
GET  /wp-json/ai/v1/logs/summary      # Aggregate stats
DELETE /wp-json/ai/v1/logs            # Purge old logs
```

### 5. Settings

- **Log retention**: Days to keep logs (default: 30)
- **Log level**: What to capture (all, errors only, none)
- **Detailed logging**: Store full request/response bodies (privacy/storage considerations)
- **Cost alerts**: Notify when daily/monthly spend exceeds threshold

## Privacy & Security Considerations

1. **PII in prompts**: Option to redact/hash sensitive content before logging
2. **Log access**: Capability-gated (`manage_options` or custom capability)
3. **Data retention**: Automatic purge of old logs, configurable retention period
4. **Export/Delete**: GDPR compliance - ability to export/delete logs for a user

## Performance Considerations

1. **Async logging**: Queue logs for background processing on high-traffic sites
2. **Sampling**: Option to log only a percentage of requests
3. **Index optimization**: Proper indexes for common query patterns
4. **Aggregation**: Pre-compute hourly/daily summaries for dashboard

## Dependencies

- PR #63 (Abilities Explorer) - UI integration
- MCP Server implementation - For MCP tool logging
- WP AI Client - For AI request logging

## Related Issues

- #62 - Add feature to show what abilities are on site
- #63 - Adds Abilities Explorer (PR)
- Issue: MCP Server Experiment - MCP integration

## Open Questions

1. Should we log full request/response bodies or just metadata?
2. What's the right default retention period?
3. Should logging be opt-in or opt-out?
4. How to handle logging for streaming responses?
5. Should we integrate with external APM tools (Datadog, New Relic, etc.)?

## Labels

`enhancement`, `observability`, `logging`, `admin`, `abilities`
