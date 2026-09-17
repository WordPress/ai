# Comment Moderation

## Summary

The Comment Moderation experiment adds sentiment, toxicity, and value analysis to the classic Comments admin screen (`edit-comments.php`). It also performs automatic moderation when new comments are created: comments with **high toxicity** and **negative sentiment** are moved to moderation. The experiment exposes one WordPress Ability (`ai/comment-analysis`) that can be used from the UI or via REST API.

## Overview

When enabled, the Comments list table gets three additional columns: **Sentiment**, **Toxicity**, and **Value**. Each comment can show a badge with the current analysis state or result.

The **Value** score rates how relevant and substantive a comment is to the post it was left on, so moderators can separate genuine contributions from spam, engagement bait, and generic filler. Scoring it needs the post for context, which the ability reads from the post excerpt, then the AI-generated summary (`wpai_generated_summary`), then the post content, in that order. Context is only drawn from publicly readable posts — password-protected posts and non-public statuses (drafts, private posts) are skipped so gated editorial content is never sent to the provider.

**Key Features:**

- Adds sentiment, toxicity, and value badges to the Comments admin list table
- Adds a bulk action (**Analyze Sentiment, Toxicity, and Value**) to queue selected comments for analysis
- Processes queued comments in the browser, sequentially, to avoid server overload
- Automatically moderates newly created comments when analysis indicates high risk
- Uses one shared ability (`ai/comment-analysis`) for both automated and manual workflows

**Automatic moderation rule (default):**

- If `toxicity_score >= 0.7` **and** `sentiment === 'negative'`, the comment is set to pending moderation (`comment_approved = '0'`).

### Input Schema

The `ai/comment-analysis` ability accepts:

```php
array(
    'type'       => 'object',
    'properties' => array(
        'comment_id' => array(
            'type'        => 'integer',
            'description' => 'The ID of the comment to analyze.',
            'required'    => true,
        ),
    ),
    'required'   => array( 'comment_id' ),
)
```

### Output Schema

The ability returns:

```php
array(
    'type'       => 'object',
    'properties' => array(
        'comment_id'     => array( 'type' => 'integer' ),
        'toxicity_score' => array(
            'type'    => 'number',
            'minimum' => 0,
            'maximum' => 1,
        ),
        'sentiment'      => array(
            'type' => 'string',
            'enum' => array( 'positive', 'negative', 'neutral' ),
        ),
        'value_score'    => array(
            'type'    => 'number',
            'minimum' => 0,
            'maximum' => 1,
        ),
    ),
)
```

`value_score` runs from 0 (spam, engagement bait, or completely off-topic) to 1 (on-topic and substantive).

### Permissions

- `ai/comment-analysis` requires `current_user_can( 'moderate_comments' )`
- List table UI and bulk action are intended for comment moderators

## Using the Ability via REST API

### Endpoint

```text
POST /wp-json/wp-abilities/v1/abilities/ai/comment-analysis/run
```

### Authentication

You can authenticate using either:

1. **Application Password** (Recommended)
2. **Cookie Authentication with Nonce**

See [TESTING_REST_API.md](../TESTING_REST_API.md) for detailed authentication instructions.

### Request Example

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/comment-analysis/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "comment_id": 123
    }
  }'
```

**Response:**

```json
{
  "comment_id": 123,
  "toxicity_score": 0.81,
  "sentiment": "negative",
  "value_score": 0.24
}
```

### Error Responses

The ability may return:

- `missing_comment_id`: `comment_id` was not provided
- `comment_not_found`: no comment exists for the given ID
- `already_processing`: comment is currently being analyzed
- `parse_error`: AI output could not be parsed as expected JSON
- `insufficient_capabilities`: current user lacks moderation permissions

## Extending the Experiment

### Customize moderation behavior

Use `wpai_comment_moderation_should_moderate` to override the default threshold-based moderation decision:

```php
add_filter( 'wpai_comment_moderation_should_moderate', function( $should_moderate, $analysis, $comment_id ) {
    if ( $analysis['toxicity_score'] >= 0.6 ) {
        return true;
    }
    return $should_moderate;
}, 10, 3 );
```

### Controlling which posts supply value-score context

By default a post only supplies context when it is publicly readable and not password-protected, so gated editorial content is never sent to the provider. Use `wpai_comment_analysis_post_context_shareable` to widen or narrow that:

```php
add_filter( 'wpai_comment_analysis_post_context_shareable', function ( bool $shareable, WP_Post $post ): bool {
    // Never send context from a specific post type.
    return 'internal_memo' === $post->post_type ? false : $shareable;
}, 10, 2 );
```

When no context is available the comment is still analyzed — the model scores it on its own merits.

### Adjusting the Bulk Batch Cap

Each comment queued by the **Analyze Sentiment, Toxicity, and Value** bulk action becomes one billed model call once it is analyzed, so `handle_bulk_action()` caps how many comments a single request will queue. The default is 100; comments beyond the cap are not queued, and the surplus is reported to the user in an admin notice.

```php
add_filter( 'wpai_bulk_action_max_items', function ( int $max_items, string $feature_id ): int {
    return 'comment-moderation' === $feature_id ? 25 : $max_items;
}, 10, 2 );
```

Values below 1 are clamped to 1. The same filter governs the alt text and summarization bulk caps, so check `$feature_id` when you only mean to change one.

## Testing

### Manual Testing

1. **Enable the experiment:**
   - Go to `Settings -> AI`
   - Enable global AI features and toggle **Comment Moderation**
   - Ensure valid AI connector credentials are configured

2. **List table badges and bulk analysis:**
   - Go to `Comments -> All Comments`
   - Select multiple comments and run **Analyze Sentiment, Toxicity, and Value**
   - Confirm a success notice reports queued count
   - Verify pending badges transition to analyzed badges (or failed state) as processing runs
   - Confirm `wpai_analysis_queued` is removed from the URL after processing, and refresh does not re-show the queue notice

3. **Automatic moderation on insert:**
   - Submit new comments with varied tone/content
   - Confirm high-toxicity negative comments are moved to moderation (`comment_approved = 0`)
   - Confirm lower-risk or non-negative comments are not auto-moderated by default

4. **REST API:**
   - Call `POST /wp-json/wp-abilities/v1/abilities/ai/comment-analysis/run` with a valid `comment_id`
   - Verify response shape and error handling for invalid IDs or insufficient permissions

## Notes & Considerations

### Requirements

- Requires valid AI credentials and text-generation-capable models
- Requires users with comment moderation capabilities for ability access

### Performance & Behavior

- Frontend processing is sequential by design to avoid sending many concurrent requests
- Ability-level status locking prevents duplicate simultaneous analysis on the same comment
- Failed comments are marked `failed` and are retried on subsequent scans because failed badges remain queryable
- Bulk analysis is capped at 100 comments per request by default; see [Adjusting the Bulk Batch Cap](#adjusting-the-bulk-batch-cap)

### Limitations

- Works only on the classic comments list table (no block-based comments UI integration here)
- Analysis is not batched server-side; one ability invocation per comment
- Current retry behavior for failed badges is best-effort and may repeat on reload until resolved
- Comments analyzed before the **Value** column existed have no value score stored. They show a `—` in that column rather than a fabricated score, and are excluded from the value score filters until they are re-analyzed.
