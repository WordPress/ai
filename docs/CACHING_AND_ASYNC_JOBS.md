# Caching and Asynchronous AI Jobs

Two shared capabilities keep slow provider calls out of the request that asked
for them:

- **Response cache** — successful, non-streaming text generation responses can be
  served from the object cache (or transients) instead of calling the provider again.
- **Async job queue** — work can be stored and scheduled through WP-Cron, with a
  REST status route and an `ai/enqueue-job` ability.

Both are part of the plugin core, independent of any experiment. Caching is off
by default; enqueuing a job is always opt-in per caller.

## Response cache

The cache sits inside the AI Client transport: it decorates
`HttpTransporterInterface`, so every provider benefits without changing any
feature code. Only raw, successful, non-streaming text generation responses are
cached. Requests for embeddings, images, speech, moderation, batches, files and
model listings are skipped, as are streaming requests and error responses.

The cache key is a SHA-256 hash of the HTTP method, URI, request body and plugin
version. It never includes credentials. The request body already carries the
model, prompt, temperature and system instruction, so identical requests hash
identically.

### Enabling it

Caching is off unless one of these is true:

```php
// Site-wide, for example from a small must-use plugin.
update_option( 'wpai_cache_enabled', true );
```

```php
add_filter( 'wpai_http_cache_enabled', '__return_true' );
```

### Tuning it

```php
// Skip the cache for one request, even when it is enabled site-wide.
add_filter(
	'wpai_http_cache_should_cache',
	static function ( bool $should_cache, $request ): bool {
		if ( false !== strpos( $request->getBody() ?? '', 'unstable prompt' ) ) {
			return false;
		}

		return $should_cache;
	},
	10,
	2
);

// Cache for a day instead of the default hour. Return 0 to skip caching.
add_filter( 'wpai_http_cache_ttl', static fn(): int => DAY_IN_SECONDS );

// Make every key unique to an environment.
add_filter( 'wpai_cache_key_salt', static fn(): string => 'staging' );
```

Observability hooks: `wpai_http_cache_hit`, `wpai_http_cache_miss` and
`wpai_http_cache_store`. Each receives the cache key and request; the store hook
also receives the TTL.

## Async jobs

`POST /wp-json/ai/v1/jobs` writes one row and schedules one WP-Cron event, then
answers `202 Accepted` immediately:

```json
{
	"job_id": "2f7c…",
	"type": "ai/ability",
	"status": "enqueued",
	"status_url": "https://example.com/wp-json/ai/v1/jobs/2f7c…"
}
```

Poll the status URL until `status` is `completed` or `failed`. A completed job
includes its `result`; a failed one includes `error.code` and `error.message`.
Finished jobs are pollable for one day by default, then removed by a daily cron
cleanup.

The same operation is available as the `ai/enqueue-job` ability, and through
`Jobs::instance()->get_queue()->enqueue()` in PHP.

### Built-in job types

`ai/ability` runs a registered ability in the background:

```json
{
	"type": "ai/ability",
	"args": {
		"ability": "ai/excerpt-generation",
		"input": { "content": "…", "context": "12" }
	}
}
```

`ai/alt-text` generates alt text for an image and saves it:

```json
{
	"type": "ai/alt-text",
	"args": { "attachment_id": 42, "context": "Product hero image" }
}
```

The job resolves the `ai/alt-text-generation` ability, re-checks its
permission callback, and writes the result to `_wp_attachment_image_alt`.
Decorative images are returned with `saved: false`, so hand-written alt text
is never erased by a decorative result. Return a different ability name from
`wpai_alt_text_job_ability` if a site supplies its own generator.

The Alt Text Generation experiment also registers `ai/enqueue-alt-text`, which
queues an `ai/alt-text` job and returns its `job_id` and `status_url`.

The ability's own permission callback runs when the job is enqueued **and** again
when it executes, with the enqueuing user restored as the current user. Only a
registered ability name can run; a queued payload never carries code. Return
`false` from `wpai_job_ability_allowed` to keep an ability synchronous.

### Registering a job type

```php
add_action(
	'wpai_register_job_types',
	static function ( \WordPress\AI\Jobs\Job_Registry $registry ): void {
		$registry->register( new My_Import_Job() );
	}
);
```

A job type implements `WordPress\AI\Jobs\Job_Type_Interface`:

```php
final class My_Import_Job implements \WordPress\AI\Jobs\Job_Type_Interface {
	public function get_id(): string {
		return 'my-plugin/import-post';
	}

	public function get_label(): string {
		return __( 'Import a post', 'my-plugin' );
	}

	public function get_input_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'post_id' ),
			'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
		);
	}

	public function can_enqueue( array $args, int $user_id ) {
		return user_can( $user_id, 'edit_post', (int) ( $args['post_id'] ?? 0 ) );
	}

	public function handle( array $args, \WordPress\AI\Jobs\Job $job ) {
		$result = do_expensive_import( (int) $args['post_id'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'imported' => true );
	}
}
```

Job types must be deterministic about results: a `WP_Error` fails the job
permanently. Exceptions are caught and stored as failures.

### Idempotency

Send `X-WP-AI-Idempotency-Key` (or `idempotency_key` in the body) to make
repeated requests return the same job ID:

```bash
curl -X POST https://example.com/wp-json/ai/v1/jobs \
	-H 'X-WP-AI-Idempotency-Key: import-123' \
	-H 'Content-Type: application/json' \
	-d '{"type":"ai/ability","args":{"ability":"ai/summarization","input":{"context":"12"}}}'
```

Keys are scoped to the enqueuing user and job type.

### Hooks

- `wpai_register_job_types` — register job types.
- `wpai_job_enqueued`, `wpai_job_completed`, `wpai_job_failed` — lifecycle.
- `wpai_job_retention` — seconds a finished job stays pollable.
- `wpai_job_ability_allowed` — allow or deny an ability as an `ai/ability` job.

### Operational notes

- The queue uses WP-Cron; it never ships Action Scheduler. On low-traffic sites,
  run a real cron job against `wp-cron.php`.
- Each run is guarded by the plugin's options-table lock, so two overlapping
  cron requests cannot run the same job.
- Permissions are re-checked at execution time; a job enqueued by a user whose
  role later changes fails rather than running with stale access.
- The jobs table is `{prefix}wpai_jobs`, created on activation and upgraded
  lazily. Uninstall drops it.

### WP-CLI

Queue alt text generation without blocking:

```bash
wp ai alt-text queue --ids=42,55
wp ai alt-text queue --limit=10
wp ai alt-text queue --ids=42 --force
```

Inspect or run queued jobs without waiting for WP-Cron:

```bash
wp ai jobs status <job-id>
wp ai jobs run <job-id>
wp ai jobs cleanup
```

`run` is also the deterministic way to exercise the queue in automated tests.
