# Embedding Sync

## Summary
Keeps stored post embeddings up to date in the background as content changes. When a synced post type is saved, it is queued rather than embedded immediately; a recurring cron event drains the queue in bounded batches, so re-indexing a large site never happens inside a single page-load request. A post whose content hash already matches what's stored is skipped without a provider request. See [Storing Embeddings](embeddings.md) for the underlying storage layer this experiment writes to.

## Requirements
This experiment needs a provider and model to be selected in its **Developer Options** on the Settings screen before it does anything. Embedding vectors are only comparable to other vectors from the same model, so background sync never chooses one automatically — the same rule `wp ai embeddings generate` and `WordPress\AI\generate_embeddings()` follow. Until a provider and model are configured, saved posts are still queued, but the cron run that would process them returns immediately.

## Key Hooks & Entry Points
- `WordPress\AI\Experiments\Embedding_Sync\Embedding_Sync::register()` calls `Embedding_Sync_Manager::init()`.
- `Embedding_Sync_Manager::init()`:
  - Registers the `wpai_embedding_sync` cron schedule via the `cron_schedules` filter.
  - Hooks `save_post` → `maybe_queue_post()`.
  - Hooks `before_delete_post` → `handle_post_deleted()`.
  - Schedules `wpai_embedding_sync_process_queue` if it isn't already scheduled.

## Architecture
1. `maybe_queue_post()` runs on every `save_post`. It skips autosaves and revisions, then checks the post's type and status against the synced sets (defaults: `post`/`page`, `publish`). A match is recorded in the `wpai_embedding_sync_queue` option — a set of post IDs, not a copy of their content — so saving a post never waits on a provider request.
2. On its cron schedule, `process_queue()`:
   - Resolves the provider and model from this experiment's Developer Options (`WordPress\AI\get_feature_developer_model_config()`). Returns immediately if either is empty.
   - Takes up to `wpai_embedding_sync_batch_size` post IDs off the front of the queue and removes them from it.
   - For each, normalizes the post content (`WordPress\AI\normalize_content()`) and hashes it, comparing against `Embedding_Repository::get_content_hash()`. A match means the post is already current and is dropped with no provider request.
   - The remaining ("dirty") posts are embedded in a single batched call to `WordPress\AI\generate_embeddings()`, then stored with `Embedding_Repository::save_many()`.
   - If the provider call fails, the whole batch is put back on the queue for the next run and `wpai_embedding_sync_batch_failed` fires; nothing partial is stored.
3. `handle_post_deleted()` runs on `before_delete_post`: it drops the post from the queue and deletes any stored embeddings for it across every provider and model (`Embedding_Repository::delete_for_object()`).

Unpublishing a previously-synced post does not delete or refresh its stored embedding — the experiment only reacts to saves and deletions of posts currently in the synced status set.

## Filter Hooks

### `wpai_embedding_sync_post_types`
Filters which post types are queued for sync. Default `[ 'post', 'page' ]`.

```php
add_filter( 'wpai_embedding_sync_post_types', function( $post_types ) {
    $post_types[] = 'product';
    return $post_types;
} );
```

### `wpai_embedding_sync_post_statuses`
Filters which post statuses are queued for sync. Default `[ 'publish' ]`.

```php
add_filter( 'wpai_embedding_sync_post_statuses', function( $statuses ) {
    $statuses[] = 'private';
    return $statuses;
} );
```

### `wpai_embedding_sync_batch_size`
Filters how many queued posts are embedded per cron run. Default `20`, minimum `1`.

```php
add_filter( 'wpai_embedding_sync_batch_size', function() {
    return 50;
} );
```

### `wpai_embedding_sync_interval`
Filters the interval, in seconds, between cron runs. Default `900` (fifteen minutes) and never allowed to go lower, matching the platform guidance against sub-fifteen-minute cron schedules.

```php
add_filter( 'wpai_embedding_sync_interval', function() {
    return 30 * MINUTE_IN_SECONDS;
} );
```

## Action Hooks

### `wpai_embedding_sync_batch_processed`
Fires after a batch of posts is embedded and stored, with the list of stored `Embedding_Record` instances.

### `wpai_embedding_sync_batch_failed`
Fires when `generate_embeddings()` returns a `WP_Error` for a batch, with the error and the post IDs that were being processed (and have been re-queued).

```php
add_action( 'wpai_embedding_sync_batch_failed', function( $error, $post_ids ) {
    error_log( sprintf( 'Embedding sync failed for %s: %s', implode( ', ', $post_ids ), $error->get_error_message() ) );
}, 10, 2 );
```

## Testing
1. Enable Experiments globally, toggle **Embedding Sync**, and set a provider and model in its Developer Options.
2. Publish or edit a synced post type (a post or page, by default).
3. Trigger `wpai_embedding_sync_process_queue` (via WP-Cron, or `wp cron event run wpai_embedding_sync_process_queue` on WP-CLI) and confirm the post now has a stored embedding: `wp ai embeddings compare <id> <id>` against itself should report a cosine similarity of `1`.
4. Edit the same post without changing its content, run the cron event again, and confirm no new provider request was made (see the `wpai_embedding_sync_batch_processed`/`wpai_embedding_sync_batch_failed` hooks, or provider-side request logs, to verify).
5. Delete the post and confirm its embedding is gone via `wp ai embeddings compare` reporting no stored vectors.

## Notes
- This experiment only maintains embeddings for individual posts as a whole; it does not chunk content the way `wp ai embeddings generate --chunk` does. Use the CLI command directly for chunked, ad hoc embedding.
- The queue is a single `wpai_embedding_sync_queue` option capped at 5,000 pending post IDs. On a site that edits synced content faster than the configured provider can keep up, the oldest queued entries are dropped; they are queued again the next time they are saved.
