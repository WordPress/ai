# Comment Moderation

## Summary

The Comment Moderation experiment adds AI-powered sentiment and toxicity analysis to the classic Comments admin screen (`edit-comments.php`). It also performs automatic moderation when new comments are created: comments with **high toxicity** and **negative sentiment** are moved to moderation. The experiment exposes one WordPress Ability (`ai/comment-analysis`) that can be used from the UI or via REST API.

## Overview

### For End Users

When enabled, the Comments list table gets two additional columns: **Sentiment** and **Toxicity**. Each comment can show a badge with the current analysis state or result.

**Key Features:**

- Adds sentiment and toxicity badges to the Comments admin list table
- Adds a bulk action (**Analyze with AI**) to queue selected comments for analysis
- Processes queued comments in the browser, sequentially, to avoid server overload
- Automatically moderates newly created comments when analysis indicates high risk
- Uses one shared ability (`ai/comment-analysis`) for both automated and manual workflows

**Automatic moderation rule (default):**

- If `toxicity_score >= 0.7` **and** `sentiment === 'negative'`, the comment is set to pending moderation (`comment_approved = '0'`).

### For Developers

The experiment consists of three main parts:

1. **Experiment Class** (`WordPress\AI\Experiments\Comment_Moderation\Comment_Moderation`): Registers hooks, list-table columns, bulk action handling, automatic moderation on insert, and asset loading
2. **Ability Class** (`WordPress\AI\Abilities\Comment_Moderation\Comment_Analysis`): Runs AI analysis, validates output, and stores result metadata on comments
3. **Frontend Controller** (`src/experiments/comment-moderation/components/LazyAnalysisController.tsx`): Detects pending/failed badges in the DOM, runs analysis sequentially, updates badges in place, and strips queue query args from the URL after processing

## Architecture & Implementation

### Key Hooks & Entry Points

- `WordPress\AI\Experiments\Comment_Moderation\Comment_Moderation::register()` wires everything when the experiment is enabled:
  - `wp_abilities_api_init` -> registers `ai/comment-analysis` (`includes/Abilities/Comment_Moderation/Comment_Analysis.php`)
  - `wp_insert_comment` -> `moderate_comment()` analyzes newly inserted comments and may set them to moderation
  - `manage_edit-comments_columns`, `manage_comments_custom_column` -> adds/renders `Sentiment` and `Toxicity` columns
  - `bulk_actions-edit-comments`, `handle_bulk_actions-edit-comments`, `admin_notices` -> adds and handles **Analyze with AI** with queued-count notice
  - `admin_enqueue_scripts` -> enqueues `experiments/comment-moderation` on `edit-comments.php`
  - `admin_head-edit-comments.php` -> prints badge styles inline

### Assets & Data Flow

1. **PHP side:**
   - `enqueue_assets()` loads `experiments/comment-moderation` (`src/experiments/comment-moderation/index.tsx`)
   - Localizes `window.aiCommentModerationData` with:
     - `enabled`: whether the experiment is enabled

2. **React side:**
   - `index.tsx` mounts `LazyAnalysisController` after `domReady`
   - `LazyAnalysisController` scans for badges marked `data-ai-status="pending"` or `data-ai-status="failed"`
   - For each comment, it calls `runAbility( 'ai/comment-analysis', { comment_id } )` and updates sentiment/toxicity badges from the response
   - Processing is sequential (one comment at a time)
   - After processing (or if nothing is pending), it removes `wpai_analysis_queued` from the URL via `history.replaceState()` so refresh does not re-show the queue notice

3. **Ability execution:**
   - Validates `comment_id` and permission (`moderate_comments`)
   - Prevents concurrent runs for the same comment if status is already `processing`
   - Builds prompt from comment author + content and requests strict JSON output (`toxicity_score`, `sentiment`)
   - Stores analysis metadata on the comment:
     - `_wpai_toxicity_score`
     - `_wpai_sentiment`
     - `_wpai_analysis_status`
     - `_wpai_analyzed_at`

4. **Automatic moderation:**
   - `moderate_comment()` executes the ability on new comments
   - Applies default moderation threshold logic (`>= 0.7` + `negative`)
   - Uses a filter so integrators can override the moderation decision

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
    ),
)
```

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
  "sentiment": "negative"
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

## Testing

### Manual Testing

1. **Enable the experiment:**
   - Go to `Settings -> AI`
   - Enable global AI features and toggle **Comment Moderation**
   - Ensure valid AI connector credentials are configured

2. **List table badges and bulk analysis:**
   - Go to `Comments -> All Comments`
   - Select multiple comments and run **Analyze with AI**
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
- Runs in wp-admin on the classic comments list screen (`edit-comments.php`)

### Performance & Behavior

- Frontend processing is sequential by design to avoid sending many concurrent requests
- Ability-level status locking prevents duplicate simultaneous analysis on the same comment
- Failed comments are marked `failed` and are retried on subsequent scans because failed badges remain queryable

### Limitations

- Works only on the classic comments list table (no block-based comments UI integration here)
- Analysis is not batched server-side; one ability invocation per comment
- Current retry behavior for failed badges is best-effort and may repeat on reload until resolved
