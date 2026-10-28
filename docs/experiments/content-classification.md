# Content Classification

## Summary

The Content Classification experiment adds AI-powered tag and category suggestions to the WordPress post editor. It analyzes post content and suggests relevant taxonomy terms directly within the Tags and Categories sidebar panels. The experiment registers a WordPress Ability (`ai/content-classification`) that can be used both through the admin UI and directly via REST API requests.

## At a Glance

- **Type:** Experiment (may change or be removed in any release; try it on a staging site first)
- **Runs without a user action:** No, only when you ask
- **When it runs:** When you click Suggest Tags or Suggest Categories
- **Sent to your AI provider:** Post content, title, slug, author name, excerpt, content type, the terms already assigned, and up to 100 existing terms to choose from. In [embedding mode](#embedding-mode), only the post title and content go to your embedding provider, plus a one-time background embedding of your categories and tags.
- **Requests and data:** One request per click (in embedding mode, only when the post has changed since the last click)
- **Good to know:** Accepting a suggested new term creates that term on your site right away.

## Overview

### For End Users

When enabled, the Content Classification experiment adds "Suggest Tags" and "Suggest Categories" buttons to their respective panels in the post editor sidebar. Users can click these buttons to generate a list of AI-suggested terms based on the current post content. Suggestions appear as clickable pills that can be accepted (adding the term to the post) or dismissed.

**Key Features:**

- One-click tag and category suggestions from post content
- Suggestions shown as clickable pills within the existing taxonomy panels
- New terms are visually distinguished with a "new" badge
- Support for parent/child category relationships
- Configurable strategy: suggest only existing terms or allow new ones
- Configurable maximum number of suggestions (1-10, default 5)
- Regenerate suggestions for different results
- Requires approximately 250 characters of content before enabling

### For Developers

The experiment consists of two main components:

1. **Experiment Class** (`WordPress\AI\Experiments\Content_Classification\Content_Classification`): Handles registration, asset enqueuing, settings, and UI integration
2. **Ability Class** (`WordPress\AI\Abilities\Content_Classification\Content_Classification`): Implements the core suggestion logic via the WordPress Abilities API

The ability can be called directly via REST API, making it useful for automation, bulk processing, or custom integrations.

## Architecture & Implementation

### Input Schema

The ability accepts the following input parameters:

```php
array(
    'content' => array(
        'type'        => 'string',
        'description' => 'Content to generate taxonomy suggestions for.',
    ),
    'post_id' => array(
        'type'        => 'integer',
        'description' => 'Post ID to generate suggestions for. Overrides content if both provided.',
    ),
    'taxonomy' => array(
        'type'    => 'string',
        'default' => 'post_tag',
        'description' => 'The taxonomy to generate suggestions for (e.g., post_tag, category).',
    ),
    'strategy' => array(
        'type'    => 'string',
        'default' => 'existing_only',
        'description' => 'The suggestion strategy: existing_only or allow_new.',
    ),
    'max_suggestions' => array(
        'type'    => 'integer',
        'default' => 5,
        'minimum' => 1,
        'maximum' => 10,
        'description' => 'Maximum number of suggestions to generate.',
    ),
)
```

### Output Schema

The ability returns a structured object:

```php
array(
    'suggestions' => array(
        array(
            'term'       => 'machine learning',  // string - the suggested term name
            'confidence' => 0.95,                 // float  - relevance score (0-1)
            'is_new'     => true,                 // bool   - whether term exists on site
            'parent'     => 'technology',         // string - optional parent for categories
        ),
        // ... more suggestions
    ),
)
```

### Permissions

The ability checks permissions based on the input:

- **If `post_id` is provided:**
  - Verifies the post exists
  - Checks `current_user_can( 'edit_post', $post_id )`
  - Ensures the post type has `show_in_rest` enabled

- **If `post_id` is not provided:**
  - Checks `current_user_can( 'edit_posts' )`

## Settings

The experiment registers two settings on the AI Experiments settings page:

- **Taxonomy strategy** (`wpai_experiment_content-classification_field_strategy`):
  - `existing_only` (default) — Only suggest terms that already exist on the site
  - `allow_new` — Allow suggestions for new terms based on content

- **Maximum suggestions** (`wpai_experiment_content-classification_field_max_suggestions`):
  - Integer between 1 and 10, default 5

The suggestion method and embedding model settings are described under [Embedding mode](#embedding-mode).

## Embedding mode

By default, suggestions come from a language model that reads the post and picks terms. Embedding mode is an alternative that suggests existing terms by meaning instead. It turns each category and tag into an embedding (a vector of numbers that captures what the text is about), does the same for the post when you click Suggest, and suggests the terms whose vectors are closest to the post's. It never invents new terms, and it doesn't send post content to a language model.

It needs a connected AI provider with an embedding model, such as OpenAI's `text-embedding-3-small`.

### Turning it on

1. Go to `Settings > AI`, open **Developer Tools**, and turn on **Advanced settings**.
2. Under Content Classification, set **Suggestion method** to **Similarity matching (embeddings)**.
3. Choose a provider and model under **Embedding model**. Only models that support embeddings are listed.
4. Save, then click **Generate embeddings** in the **Embeddings** panel and confirm. See [Generating term embeddings](#generating-term-embeddings).

While embedding mode is on:

- **Taxonomy strategy** is disabled with the note "Similarity matching can only suggest existing terms." The saved strategy is kept and applies again when you switch back to AI model.
- With **Model selection** on in Developer Tools, the feature's Provider and Model dropdowns are disabled with a note. That selection is for the language model, so it's kept and applies again when you switch back. Both locks follow the unsaved method, so they change as soon as you pick a method.
- **Maximum suggestions** applies to both methods.

| Setting | Option | Values |
|---|---|---|
| Suggestion method | `wpai_feature_content-classification_field_method` | `llm` (default) or `embeddings` |
| Embedding model | `wpai_feature_content-classification_field_embedding_model` | `{ "provider": "openai", "model": "text-embedding-3-small" }`, empty by default |

Embedding mode is only active once the method is `embeddings` and an embedding model is saved. Until then, the experiment registers no embedding consumer and does no background work.

### What is sent, and when

- **When you click Suggest Tags or Suggest Categories:** the post title and content, as plain text, go to your embedding provider. Long posts are split into chunks, and all chunks go in one request. The post's vectors are saved and reused while its text is unchanged, so clicking again on an unchanged post makes no request. Password-protected posts are embedded on every click and never saved.
- **In the background:** the name and description of every category and tag go to your embedding provider once, when you generate term embeddings. After that, terms you add or edit while embedding mode is on are embedded automatically, and vectors for deleted terms are removed.

Nothing else about the post (slug, author, excerpt, assigned terms) is sent.

### How suggestions are ranked

The post's chunk vectors are averaged into one vector and compared with every stored term vector in the requested taxonomy by cosine similarity. Terms already on the post are skipped, terms below the minimum score are dropped, and the closest are returned up to the maximum suggestions. Each suggestion's `confidence` is its cosine similarity clamped to `0`–`1`, and `is_new` is always `false`. Child categories include their `parent`. The result still passes through the `wpai_content_classification_suggestions` filter.

The search runs in two passes so it never loads every vector into memory. It first compares compact one-bit-per-dimension codes to shortlist the closest candidates, then reranks only that shortlist by exact cosine similarity.

### Generating term embeddings

The **Embeddings** panel under the Content Classification settings shows how many terms are embedded and starts the backfill (the one-time pass over existing terms):

- Before a model is chosen and saved, **Generate embeddings** is disabled and the panel says what to do first.
- Once saved, it shows how many terms are embedded. **Generate embeddings** asks you to confirm, restating the number of terms and that requests to your provider may cost money.
- While it runs, the panel shows progress and a **Cancel** button and refreshes every 10 seconds. The work runs in the background through WP-Cron, about 20 seconds at a time. If nothing has run for 5 minutes, or `DISABLE_WP_CRON` is set, the panel warns you and suggests running `wp ai embeddings sync run` from WP-CLI instead.
- If the provider returns an error, the panel shows it and when the next retry is due.
- When it finishes, the panel shows "All N terms are embedded." **Re-run** resumes the backfill to pick up anything missed.

The panel uses these REST endpoints, which require `manage_options`:

```text
GET    /wp-json/ai/v1/embeddings/consumers/content-classification            Sync status and totals
POST   /wp-json/ai/v1/embeddings/consumers/content-classification/backfill   Start or resume the backfill
DELETE /wp-json/ai/v1/embeddings/consumers/content-classification/backfill   Cancel the backfill
```

Each returns the current status. An unknown consumer, including Content Classification while embedding mode is off, returns a 404 with the code `ai_embeddings_unknown_consumer`. For the shared sync engine and its WP-CLI commands, see [Embeddings](embeddings.md#keeping-embeddings-in-sync).

### Readiness

Suggestions can only run once the terms have vectors. The rule is: no backfill is running, and every suggested taxonomy (categories and tags) that has terms has at least one of them embedded.

Until then, the Suggest Tags and Suggest Categories buttons are disabled, with the notice "Suggestions will be available once term embeddings finish generating." Users who can manage options also get a link to the settings screen. Readiness is checked when the editor loads, so reload the editor after the backfill finishes.

The ability enforces the same rule for REST callers. It returns `ai_embeddings_not_ready` while terms aren't embedded, and `ai_embeddings_failed` when the provider request fails.

### Changing the embedding model

Vectors from different models can't be compared. When you pick a different model, a warning appears under the picker. Saving deletes the vectors made with the old model, along with its backfill state, so the panel shows 0 terms embedded and you generate them again with the new model. The old vectors are kept only if another registered embedding consumer still uses that model.

Switching **Suggestion method** back to **AI model** keeps the stored vectors, so switching back to embedding mode doesn't cost anything again. Terms changed while embedding mode was off aren't embedded until you click **Re-run**. To delete vectors for a model you no longer use, see [Switching models](embeddings.md#switching-models).

### Tuning

Use `wpai_content_classification_embedding_min_score` to change the minimum cosine similarity a term needs to be suggested (default `0.3`, `Embedding_Suggester::DEFAULT_MIN_SCORE`). Scores vary by model, so a threshold that suits one model may be too strict or too loose for another:

```php
add_filter( 'wpai_content_classification_embedding_min_score', function( $min_score, $provider, $model, $taxonomy ) {
    // Be stricter for categories.
    return 'category' === $taxonomy ? 0.4 : $min_score;
}, 10, 4 );
```

Embedding mode only suggests terms already used on at least 2 published posts (default `2`, `Embedding_Suggester::DEFAULT_MIN_TERM_COUNT`). The count is the term's `term_taxonomy.count`, so brand-new and single-use terms aren't suggested, and neither are parent categories that are only used through their children. Use `wpai_content_classification_embedding_min_term_count` to change the minimum; return `1` to consider every term:

```php
add_filter( 'wpai_content_classification_embedding_min_term_count', function( $min_count, $taxonomy ) {
    return 1;
}, 10, 2 );
```

Use `wpai_embedding_search_shortlist_size` to change how many candidates the first search pass keeps for the exact rerank (default `200`). A larger shortlist can find more good matches on sites with many thousands of terms, at the cost of speed. It is never allowed below the number of results requested:

```php
add_filter( 'wpai_embedding_search_shortlist_size', function( $shortlist, $object_type, $subtypes ) {
    return 'term' === $object_type ? 500 : $shortlist;
}, 10, 3 );
```

The text embedded for each term and post can be changed with the `wpai_embedding_sync_term_text` and `wpai_embedding_sync_post_text` filters. See [Embeddings](embeddings.md#what-text-is-embedded).

## Using the Ability via REST API

### Endpoint

```text
POST /wp-json/wp-abilities/v1/abilities/ai/content-classification/run
```

### Authentication

You can authenticate using either:

1. **Application Password** (Recommended)
2. **Cookie Authentication with Nonce**

See [TESTING_REST_API.md](../TESTING_REST_API.md) for detailed authentication instructions.

### Request Examples

#### Example 1: Suggest Tags from Post ID

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/content-classification/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "post_id": 123,
      "taxonomy": "post_tag",
      "strategy": "allow_new",
      "max_suggestions": 5
    }
  }'
```

**Response:**

```json
{
  "suggestions": [
    {"term": "artificial intelligence", "confidence": 0.95, "is_new": true},
    {"term": "machine learning", "confidence": 0.9, "is_new": true},
    {"term": "technology", "confidence": 0.85, "is_new": false}
  ]
}
```

#### Example 2: Suggest Categories from Content String

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/content-classification/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "content": "This article discusses the latest advances in renewable energy technology, including solar panel efficiency improvements and wind turbine innovations.",
      "taxonomy": "category",
      "strategy": "existing_only"
    }
  }'
```

#### Example 3: Using WordPress API Fetch (in Gutenberg/Admin)

```javascript
import apiFetch from '@wordpress/api-fetch';

async function suggestTags( postId, taxonomy = 'post_tag' ) {
  try {
    const result = await apiFetch( {
      path: '/wp-abilities/v1/abilities/ai/content-classification/run',
      method: 'POST',
      data: {
        input: {
          post_id: postId,
          taxonomy,
          strategy: 'allow_new',
          max_suggestions: 5,
        },
      },
    } );
    return result.suggestions;
  } catch ( error ) {
    console.error( 'Error generating suggestions:', error );
    throw error;
  }
}
```

### Error Responses

The ability may return the following error codes:

- `invalid_taxonomy`: The specified taxonomy does not exist
- `post_not_found`: The provided post ID does not exist
- `content_not_provided`: No content was provided and no valid post ID was found
- `no_results`: The AI client did not return any suggestions
- `invalid_response`: The AI response could not be parsed as valid JSON
- `insufficient_capabilities`: The current user does not have permission

## Extending the Experiment

### Filtering Content Before AI Processing

Use the `wpai_content_classification_content` filter to modify the content string before it is sent to the AI model:

```php
add_filter( 'wpai_content_classification_content', function( $content, $taxonomy, $strategy ) {
    // Add custom context to improve suggestions.
    if ( 'category' === $taxonomy ) {
        $content .= "\n\nSite focus: technology and science news.";
    }
    return $content;
}, 10, 3 );
```

### Filtering Suggestions After AI Processing

Use the `wpai_content_classification_suggestions` filter to modify the parsed suggestions before they are returned:

```php
add_filter( 'wpai_content_classification_suggestions', function( $suggestions, $taxonomy, $strategy ) {
    // Remove suggestions with low confidence.
    return array_filter( $suggestions, function( $s ) {
        return $s['confidence'] >= 0.7;
    } );
}, 10, 3 );
```

### Filtering Strategy and Max Suggestions

```php
// Override the strategy programmatically.
add_filter( 'wpai_content_classification_strategy', function( $strategy ) {
    return 'allow_new';
} );

// Override the max suggestions count.
add_filter( 'wpai_content_classification_max_suggestions', function( $max ) {
    return 7;
} );
```

### Setting the Confidence Floor

Suggestions are dropped when their confidence falls below a floor (default `0.6`, the value of `Content_Classification::MIN_CONFIDENCE`) before they are sorted and limited to the max count. This removes low-relevance, popularity-driven noise without the model having to be perfect. Use the `wpai_content_classification_min_confidence` filter to raise or lower the floor:

```php
add_filter( 'wpai_content_classification_min_confidence', function( $min_confidence, $taxonomy, $strategy ) {
    // Be stricter for categories, more permissive for tags.
    return 'category' === $taxonomy ? 0.75 : 0.5;
}, 10, 3 );
```

The return value is clamped to the `0.0`–`1.0` range, so a stray value cannot disable the floor entirely or drop every suggestion. Return `0.0` to disable the floor.

### Supplying a Candidate Pool of Existing Terms

Use the `wpai_content_classification_available_terms` filter to replace the pool of existing terms surfaced to the model as `<available-terms>`. By default this is the top terms ordered by usage count when the `existing_only` strategy is in use, and an empty array otherwise. The system instruction treats the pool as candidates ("use only when they genuinely fit; relevance outweighs popularity") rather than required choices, so a site can return a relevance-ranked pool — for example from an embedding-based retrieval step — without touching prompt code:

```php
add_filter( 'wpai_content_classification_available_terms', function( $available_terms, $taxonomy, $strategy ) {
    // Replace the popularity-ordered default with a relevance-ranked pool.
    return my_embedding_search( get_the_ID(), $taxonomy );
}, 10, 3 );
```

The filter also fires for the `allow_new` strategy (with an empty default pool), so candidates can be injected without forcing `existing_only` behavior. Returning an empty array suppresses the `<available-terms>` block entirely, letting the model rely purely on the content and any assigned terms.

### Tuning the Candidate Pool Size

Use the `wpai_content_classification_candidate_pool_size` filter to change how many existing terms are fetched for the default `existing_only` candidate pool (default `100`). Larger taxonomies may benefit from a higher cap; smaller sites can lower it to reduce prompt token count:

```php
add_filter( 'wpai_content_classification_candidate_pool_size', function( $limit, $taxonomy ) {
    return 'post_tag' === $taxonomy ? 250 : $limit;
}, 10, 2 );
```

A non-positive return falls back to the default so the pool always stays bounded.

### Filtering Preferred Models

You can filter which AI models are used for suggestion generation:

```php
add_filter( 'wpai_preferred_text_models', function( $models ) {
    return array(
        array( 'openai', 'gpt-4' ),
        array( 'anthropic', 'claude-haiku-4-5' ),
    );
} );
```

### Customizing Content Normalization

The `normalize_content()` helper function processes content before sending it to the AI:

```php
add_filter( 'wpai_pre_normalize_content', function( $content ) {
    // Custom preprocessing.
    return $content;
} );

add_filter( 'wpai_normalize_content', function( $content ) {
    // Custom post-processing.
    return $content;
} );
```

## Testing

### Manual Testing

1. **Enable the experiment:**
   - Go to `Settings > AI Experiments`
   - Toggle **Content Classification** to enabled
   - Configure the taxonomy strategy and max suggestions
   - Ensure you have valid AI credentials configured

2. **Test in the editor:**
   - Create or edit a post with at least 250 characters of content
   - Scroll to the Tags or Categories panel in the sidebar
   - Click the "Suggest Tags" or "Suggest Categories" button
   - Verify suggestions appear as clickable pills
   - Click a suggestion to add it to the post
   - Click the X on a suggestion to dismiss it
   - Click "Regenerate" for new suggestions
   - Click "Dismiss all" to clear all suggestions

3. **Test panel toggle behavior:**
   - Close and reopen the Tags/Categories panel
   - Verify the button appears correctly after reopening

4. **Test with different strategies:**
   - Set strategy to "Only suggest existing terms" and verify only existing terms are suggested
   - Set strategy to "Suggest new terms" and verify new terms appear with "new" badges

5. **Test REST API:**
   - Use curl or Postman to test the REST endpoint
   - Verify authentication works
   - Test with different input combinations
   - Verify error handling for invalid inputs

6. **Test embedding mode:**
   - Connect a provider with an embedding model, then follow [Turning it on](#turning-it-on)
   - Before generating embeddings, verify the Suggest buttons in the editor are disabled with a notice
   - Generate embeddings and wait for "All N terms are embedded." (or run `wp ai embeddings sync run`)
   - Reload the editor and verify suggestions are existing terms related to the post
   - Change the embedding model, save, and verify the panel shows 0 terms embedded

## Notes & Considerations

### Requirements

- The experiment requires valid AI credentials to be configured
- The experiment only works for post types that support the editor (`post_type_supports( $post_type, 'editor' )`)
- The experiment does not load for attachment post types
- Users must have `edit_posts` capability (or `edit_post` for specific posts when using post ID)
- Post content must contain approximately 250 characters before suggestions can be generated

### AI Model Selection

- The ability uses `get_preferred_models_for_text_generation()` to determine which AI models to use
- Models are tried in order until one succeeds
- Temperature is set to 0.5 for consistent, relevant results

### Content Processing

- Content is normalized before being sent to the AI (HTML stripped, shortcodes removed, etc.)
- The `normalize_content()` function handles this processing
- Additional context from post metadata (title, categories, tags) is included when using post ID
- All existing terms for the taxonomy are included in the prompt to encourage consistency

### Limitations

- Suggestions are generated in real time and not cached (embedding mode reuses a post's stored vectors while its text is unchanged, but still ranks terms on every click)
- The ability processes one taxonomy per request
- Generated suggestions should be reviewed before publishing
- The experiment requires JavaScript to be enabled in the admin
- The `is_new` flag is determined server-side by comparing against existing terms, not from the AI response
