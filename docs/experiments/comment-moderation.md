# Comment Moderation

## Summary

The Comment Moderation experiment adds sentiment, toxicity, and value analysis to the classic Comments admin screen (`edit-comments.php`). It also performs automatic moderation when new comments are created: comments with **high toxicity** and **negative sentiment** are moved to moderation. The experiment exposes one WordPress Ability (`ai/comment-analysis`) that can be used from the UI or via REST API.

## At a Glance

- **Type:** Experiment (may change or be removed in any release; try it on a staging site first)
- **Runs without a user action:** Yes, once enabled
- **When it runs:** Automatically, on each new comment, including guest comments by default. You can also run it from the "Analyze Sentiment, Toxicity, and Value" row action or bulk action in Comments. If draft checks are enabled, it also runs while readers write or submit a comment.
- **Sent to your AI provider:** Comment text, author name, and a post excerpt, AI-generated summary, or trimmed post content for saved comments. Only comment text for drafts.
- **Requests and data:** One request per new comment, plus optional requests for drafts as readers revise them.
- **Good to know:** Comments already marked as spam or trash are skipped. Comments from logged-in users are always checked; turn off "Automatically moderate guest comments" to stop checking guest comments. A comment is held for moderation when it scores as toxic and negative.

## Overview

When enabled, the Comments list table gets three additional columns: **Sentiment**, **Toxicity**, and **Value**. Each comment can show a badge with the current analysis state or result.

The **Value** score rates how relevant and substantive a comment is to the post it was left on, so moderators can separate genuine contributions from spam, engagement bait, and generic filler. Scoring it needs the post for context, which the ability reads from the post excerpt, then the AI-generated summary (`wpai_generated_summary`), then the post content, in that order. Context is only drawn from publicly readable posts — password-protected posts, non-public statuses (drafts, private posts), and post types that are not publicly viewable are skipped so gated editorial content is never sent to the provider.

**Key Features:**

- Adds sentiment, toxicity, and value badges to the Comments admin list table
- Adds a bulk action (**Analyze Sentiment, Toxicity, and Value**) to queue selected comments for analysis
- Processes queued comments in the browser, sequentially, to avoid server overload
- Automatically moderates newly created comments when analysis indicates high risk
- Uses one shared ability (`ai/comment-analysis`) for both automated and manual workflows

### Check drafts on the comment form

In **Settings → AI**, turn on **Advanced settings**, then enable **Check comments before submission** under **Comment Moderation**. This setting is off by default. It applies to standard WordPress comment forms in classic and block themes. Guests are included when the site allows guest comments and **Automatically moderate guest comments** is enabled.

The form sends only the draft text to the configured AI provider after the reader pauses for 1.5 seconds or tries to submit text that hasn't been checked. Drafts shorter than 20 characters are checked on submit. The form reuses the result for unchanged text. When the sentiment is negative and the toxicity score reaches 0.7 (70%) by default, the form asks the reader to revise their comment. Editing lets them check the new text.

A spinner and checking message appear next to **Post Comment** while a request runs. Checks run one at a time. When a reader submits revised text during an older check, the current text gets its own four-second timeout after the older check finishes. This can take up to eight seconds in total.

Readers can still submit if the check fails, times out, or reaches the server's request limit. The draft threshold only applies to the browser form. WordPress still accepts submissions made without JavaScript and the automatic moderation rule below applies to eligible comments after they're saved.

### Draft request limits and logging

Draft checks allow up to 20 requests per minute per logged-in user or guest network address, with a site-wide limit of 90 per minute. These are fixed one-minute windows. Reaching either limit skips the draft check and leaves WordPress's existing moderation flow available.

For guests, the address comes from `REMOTE_ADDR` and is stored only as a keyed hash. Guests sharing a network or a reverse proxy, load balancer, or service such as Cloudflare may share the same limit. Forwarded address headers are not trusted automatically. If your site needs individual guest limits, configure your trusted proxy to restore the client address or use infrastructure-level rate limiting. These limits reduce provider traffic; they are not a complete abuse-prevention system.

Developers can change the limits and the draft toxicity threshold with filters:

```php
add_filter( 'wpai_comment_moderation_draft_visitor_limit', function () {
    return 40;
} );
add_filter( 'wpai_comment_moderation_draft_site_limit', function () {
    return 180;
} );
add_filter( 'wpai_comment_moderation_draft_toxicity_threshold', function () {
    return 0.8;
} );
```

Limit values are non-negative request counts; zero pauses draft checks. The toxicity threshold uses the 0–1 score range and affects only the browser form. It has no settings field and does not change the saved-comment moderation rule.

When AI Request Logging is enabled, draft prompt and response previews are omitted. Request metrics and error messages remain available for debugging. Provider error messages may themselves quote submitted content, so those logs should be treated as potentially sensitive.

### Automatic moderation rule

- If `toxicity_score >= 0.7` **and** `sentiment === 'negative'`, the comment is set to pending moderation (`comment_approved = '0'`).
- Only regular comments are analyzed automatically. Pingbacks, trackbacks, and block editor notes (including AI-authored Editorial Notes) are skipped.

### Input Schema

The `ai/comment-analysis` ability accepts either a saved `comment_id` or non-empty `comment_text`. When a positive `comment_id` is supplied, the ability analyzes that saved comment and ignores the raw-text fields.

For raw text, `author` and `post_id` are optional. Without a post ID, no post context is included. Raw-text analysis does not create a comment or save comment metadata.

```php
array(
    'type'       => 'object',
    'properties' => array(
        'comment_id'   => array(
            'type'        => 'integer',
            'description' => 'The ID of the comment to analyze.',
        ),
        'comment_text' => array(
            'type'        => 'string',
            'description' => 'The text of the comment to analyze.',
        ),
        'author'       => array(
            'type'        => 'string',
            'description' => 'The author of the comment.',
        ),
        'post_id'      => array(
            'type'        => 'integer',
            'description' => 'The ID of the post the comment was left on, if known.',
        ),
    ),
)
```

### Output Schema

The ability returns the same three scores for saved comments and raw text. `comment_id` is `null` when analyzing raw text.

```php
array(
    'type'       => 'object',
    'properties' => array(
        'comment_id'     => array( 'type' => array( 'integer', 'null' ) ),
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
- The list table bulk action and row action require `moderate_comments`, and additionally skip any comment the current user cannot `edit_comment`, matching how core's own comment bulk actions behave

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

To analyze text before it has been saved, use the same endpoint and authentication with this request body:

```json
{
  "input": {
    "comment_text": "This plugin did not work for me. Please improve the error messages."
  }
}
```

The response has the same score fields, with `"comment_id": null`. This ability still requires moderation permissions; the public comment form uses its separate, restricted draft-check endpoint.

### Error Responses

The ability may return:

- `missing_comment_id`: neither a positive `comment_id` nor non-empty `comment_text` was provided
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

### Choosing which comment types are moderated

Automatic moderation only runs on regular comments (type `comment`). Use `wpai_comment_moderation_comment_types` to add other types:

```php
add_filter( 'wpai_comment_moderation_comment_types', function ( array $comment_types ): array {
    // Also moderate a custom "review" comment type.
    $comment_types[] = 'review';
    return $comment_types;
} );
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
