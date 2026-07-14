# Content Classification

## Summary

The Content Classification experiment adds AI-powered tag and category suggestions to the WordPress post editor. It analyzes post content and suggests relevant taxonomy terms directly within the Tags and Categories sidebar panels. The experiment registers a WordPress Ability (`ai/content-classification`) that can be used both through the admin UI and directly via REST API requests.

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

### Key Hooks & Entry Points

- `WordPress\AI\Experiments\Content_Classification\Content_Classification::register()` wires everything once the experiment is enabled:
  - `wp_abilities_api_init` → registers the `ai/content-classification` ability (`includes/Abilities/Content_Classification/Content_Classification.php`)
  - `admin_enqueue_scripts` → enqueues the React bundle and stylesheet on `post.php` and `post-new.php` screens for post types that support the editor

### Assets & Data Flow

1. **PHP Side:**
   - `enqueue_assets()` loads `experiments/content-classification` (`src/experiments/content-classification/index.tsx`) and localizes `window.aiContentClassificationData` with:
     - `enabled`: Whether the experiment is enabled
     - `strategy`: The configured taxonomy strategy (`existing_only` or `allow_new`)
     - `maxSuggestions`: The configured maximum number of suggestions

2. **React Side:**
   - The React entry point (`index.tsx`) uses the `editor.PostTaxonomyType` filter via `addFilter` to wrap the native taxonomy selector components
   - `SuggestionPanel` component renders a generate button and suggestion pills for each supported taxonomy
   - `useContentClassification` hook:
     - Gets current post ID and content from the editor store
     - Checks character count using `@wordpress/wordcount` (minimum 250 characters)
     - Calls the ability via `runAbility()` when the button is clicked
     - Manages suggestion state (accept, dismiss, regenerate)
     - Adds accepted terms to the post via `editPost()` and REST API

3. **Ability Execution:**
   - Accepts `content`, `post_id`, `taxonomy`, `strategy`, and `max_suggestions` as input
   - If `post_id` is provided, fetches post context using `get_post_context()`
   - Normalizes content using `normalize_content()` helper
   - Fetches all existing terms for the taxonomy to encourage consistency
   - Builds a dynamic system instruction with strategy rules, existing terms, and JSON output format
   - Sends content to AI client and parses the structured JSON response
   - Returns an array of suggestions with term name, confidence score, new/existing flag, and optional parent

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

### Filtering Preferred Models

You can filter which AI models are used for suggestion generation:

```php
add_filter( 'wpai_experiments_preferred_models_for_text_generation', function( $models ) {
    return array(
        array( 'openai', 'gpt-4' ),
        array( 'anthropic', 'claude-haiku-4-5' ),
    );
} );
```

### Customizing Content Normalization

The `normalize_content()` helper function processes content before sending it to the AI:

```php
add_filter( 'wpai_experiments_pre_normalize_content', function( $content ) {
    // Custom preprocessing.
    return $content;
} );

add_filter( 'wpai_experiments_normalize_content', function( $content ) {
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

### Automated Testing

Tests are located in:

- `tests/Integration/Includes/Abilities/Content_ClassificationTest.php`
- `tests/Integration/Includes/Experiments/Content_Classification/Content_ClassificationTest.php`

Run tests with:

```bash
npm run test:php
```

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

- Suggestions are generated in real-time and not cached
- The ability processes one taxonomy per request
- Generated suggestions should be reviewed before publishing
- The experiment requires JavaScript to be enabled in the admin
- The `is_new` flag is determined server-side by comparing against existing terms, not from the AI response

## Related Files

- **Experiment:** `includes/Experiments/Content_Classification/Content_Classification.php`
- **Ability:** `includes/Abilities/Content_Classification/Content_Classification.php`
- **React Entry:** `src/experiments/content-classification/index.tsx`
- **React Components:** `src/experiments/content-classification/components/`
- **Styles:** `src/experiments/content-classification/index.scss`
- **Types:** `src/experiments/content-classification/types.ts`
- **Tests:** `tests/Integration/Includes/Abilities/Content_ClassificationTest.php`
- **Tests:** `tests/Integration/Includes/Experiments/Content_Classification/Content_ClassificationTest.php`
