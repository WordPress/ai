# Excerpt Generation

## Summary

The Excerpt Generation experiment adds AI-powered excerpt generation to the WordPress post editor. It provides a "Generate excerpt" button in the excerpt panel that uses AI to create concise, engaging summaries of post content. The experiment registers a WordPress Ability (`ai/excerpt-generation`) that can be used both through the admin UI and directly via REST API requests.

## Overview

### For End Users

When enabled, the Excerpt Generation experiment adds a "Generate excerpt" button to the excerpt panel in the WordPress post editor. Users can click this button to automatically generate an excerpt suggestion based on the current post content. The generated excerpt is approximately 55 words, optimized for clarity, engagement, and SEO, and suitable for archive views, RSS feeds, and search results.

**Key Features:**

- One-click excerpt generation from post content
- Automatically populates the excerpt field in the editor
- Works with any post type that supports excerpts
- Generates excerpts optimized for SEO and readability
- Can regenerate excerpts if you want a different suggestion

### For Developers

The experiment consists of two main components:

1. **Experiment Class** (`WordPress\AI\Experiments\Excerpt_Generation\Excerpt_Generation`): Handles registration, asset enqueuing, and UI integration
2. **Ability Class** (`WordPress\AI\Abilities\Excerpt_Generation\Excerpt_Generation`): Implements the core excerpt generation logic via the WordPress Abilities API

The ability can be called directly via REST API, making it useful for automation, bulk processing, or custom integrations.

## Architecture & Implementation

### Key Hooks & Entry Points

- `WordPress\AI\Experiments\Excerpt_Generation\Excerpt_Generation::register()` wires everything once the experiment is enabled:
  - `wp_abilities_api_init` → registers the `ai/excerpt-generation` ability (`includes/Abilities/Excerpt_Generation/Excerpt_Generation.php`)
  - `admin_enqueue_scripts` → enqueues the React bundle on `post.php` and `post-new.php` screens for post types that support excerpts

### Assets & Data Flow

1. **PHP Side:**
   - `enqueue_assets()` loads `experiments/excerpt-generation` (`src/experiments/excerpt-generation/index.tsx`) and localizes `window.aiExcerptGenerationData` with:
     - `enabled`: Whether the experiment is enabled

2. **React Side:**
   - The React entry point (`index.tsx`) registers a WordPress plugin that hooks into the excerpt panel using `__experimentalPluginPostExcerpt`
   - `ExcerptGeneration` component renders a button that calls `useExcerptGeneration()` hook
   - `useExcerptGeneration` hook:
     - Gets current post ID and content from the editor store
     - Calls the ability via `apiFetch` when the button is clicked
     - Updates the editor store and DOM textarea with the generated excerpt
     - Handles loading states and error notifications

3. **Ability Execution:**
   - Accepts `content` (string) and `context` (string or post ID) as input
   - If `context` is numeric, treats it as a post ID and fetches post content using `get_post_context()`
   - Normalizes content using `normalize_content()` helper
   - Sends content to AI client with system instruction for excerpt generation
   - Returns a plain text excerpt (approximately 55 words)

### Input Schema

The ability accepts the following input parameters:

```php
array(
    'content' => array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'description'       => 'Content to generate an excerpt suggestion for.',
    ),
    'context' => array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'description'       => 'Additional context to use when generating an excerpt. Can be a string of additional context or a post ID (as string) that will be used to get context from that post. If no content is provided but a valid post ID is used, the content from that post will be used.',
    ),
)
```

### Output Schema

The ability returns a plain text string:

```php
array(
    'type'        => 'string',
    'description' => 'Generated excerpt.',
)
```

### Permissions

The ability checks permissions based on the input:

- **If `context` is a post ID:**
  - Verifies the post exists
  - Checks `current_user_can( 'edit_post', $post_id )`
  - Ensures the post type has `show_in_rest` enabled

- **If `context` is not a post ID:**
  - Checks `current_user_can( 'edit_posts' )`

## Using the Ability via REST API

The excerpt generation ability can be called directly via REST API, making it useful for automation, bulk processing, or custom integrations.

### Endpoint

```text
POST /wp-json/wp-abilities/v1/abilities/ai/excerpt-generation/run
```

### Authentication

You can authenticate using either:

1. **Application Password** (Recommended)
2. **Cookie Authentication with Nonce**

See [TESTING_REST_API.md](../TESTING_REST_API.md) for detailed authentication instructions.

### Request Examples

#### Example 1: Generate Excerpt from Content String

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/excerpt-generation/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "content": "This is a comprehensive article about artificial intelligence and machine learning. AI has revolutionized many industries including healthcare, finance, and transportation. Machine learning algorithms can now process vast amounts of data to identify patterns and make predictions that were previously impossible. The future of AI looks promising with advances in natural language processing, computer vision, and autonomous systems."
    }
  }'
```

**Response:**

```json
"This article explores how artificial intelligence and machine learning have transformed industries like healthcare, finance, and transportation. These technologies enable algorithms to analyze massive datasets, uncovering patterns and generating predictions that were once beyond human capability."
```

#### Example 2: Generate Excerpt from Post ID

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/excerpt-generation/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "context": "123"
    }
  }'
```

This will automatically fetch the content from post ID 123 and generate an excerpt.

#### Example 3: Generate Excerpt with Additional Context

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/excerpt-generation/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "content": "This article discusses the benefits of renewable energy.",
      "context": "Focus on environmental impact and cost savings"
    }
  }'
```

#### Example 4: Using JavaScript (Fetch API)

```javascript
async function generateExcerpt(content, postId = null) {
  const input = { content };
  if (postId) {
    input.context = String(postId);
  }

  const response = await fetch(
    '/wp-json/wp-abilities/v1/abilities/ai/excerpt-generation/run',
    {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': wpApiSettings.nonce, // If using cookie auth
      },
      credentials: 'include', // Include cookies for authentication
      body: JSON.stringify({ input }),
    }
  );

  if (!response.ok) {
    const error = await response.json();
    throw new Error(error.message || 'Failed to generate excerpt');
  }

  return await response.text(); // Returns plain text string
}

// Usage
generateExcerpt('Your article content here...')
  .then(excerpt => console.log('Generated excerpt:', excerpt))
  .catch(error => console.error('Error:', error));
```

#### Example 5: Using WordPress API Fetch (in Gutenberg/Admin)

```javascript
import apiFetch from '@wordpress/api-fetch';

async function generateExcerpt(content, postId = null) {
  const input = { content };
  if (postId) {
    input.context = String(postId);
  }

  try {
    const excerpt = await apiFetch({
      path: '/wp-abilities/v1/abilities/ai/excerpt-generation/run',
      method: 'POST',
      data: { input },
    });
    return excerpt; // Plain text string
  } catch (error) {
    console.error('Error generating excerpt:', error);
    throw error;
  }
}
```

### Error Responses

The ability may return the following error codes:

- `post_not_found`: The provided post ID does not exist
- `content_not_provided`: No content was provided and no valid post ID was found
- `no_results`: The AI client did not return any results
- `insufficient_capabilities`: The current user does not have permission to generate excerpts

Example error response:

```json
{
  "code": "content_not_provided",
  "message": "Content is required to generate an excerpt suggestion.",
  "data": {
    "status": 400
  }
}
```

## Extending the Experiment

### Customizing the System Instruction

The system instruction that guides the AI can be customized by modifying:

```php
includes/Abilities/Excerpt_Generation/system-instruction.php
```

This file returns a string that instructs the AI on how to generate excerpts. You can modify the requirements, tone, length, or other parameters.

### Filtering Preferred Models

You can filter which AI models are used for excerpt generation using the `ai_experiments_preferred_models` filter:

```php
add_filter( 'ai_experiments_preferred_models', function( $models ) {
    // Prefer specific models
    return array(
        array( 'openai', 'gpt-4' ),
        array( 'openai', 'gpt-3.5-turbo' ),
    );
} );
```

### Customizing Content Normalization

The `normalize_content()` helper function processes content before sending it to the AI. You can filter the normalized content:

```php
// Filter content before normalization
add_filter( 'ai_experiments_pre_normalize_content', function( $content ) {
    // Custom preprocessing
    return $content;
} );

// Filter content after normalization
add_filter( 'ai_experiments_normalize_content', function( $content ) {
    // Custom post-processing
    return $content;
} );
```

### Customizing Post Context

When a post ID is provided, the ability uses `get_post_context()` to gather post information. You can extend this function or filter its output to include additional context.

### Adding Custom UI Elements

You can extend the React components to add custom UI elements:

1. **Modify the button component:**
   - Edit `src/experiments/excerpt-generation/components/ExcerptGeneration.tsx`

2. **Add custom hooks:**
   - Create new hooks in `src/experiments/excerpt-generation/components/`
   - Import and use them in the main component

3. **Customize the excerpt panel:**
   - The experiment uses `__experimentalPluginPostExcerpt` to inject into the excerpt panel
   - You can modify `src/experiments/excerpt-generation/index.tsx` to add additional UI

## Testing

### Manual Testing

1. **Enable the experiment:**
   - Go to `Settings → AI Experiments`
   - Toggle **Excerpt Generation** to enabled
   - Ensure you have valid AI credentials configured

2. **Test in the editor:**
   - Create or edit a post with content
   - Scroll to the excerpt panel (or enable it in Screen Options)
   - Click the "Generate excerpt" button
   - Verify the excerpt is generated and populated in the field
   - Click "Re-generate excerpt" to test regeneration

3. **Test with different post types:**
   - The experiment only loads for post types that support excerpts
   - Test with posts, pages, and custom post types that have excerpt support

4. **Test REST API:**
   - Use curl or Postman to test the REST endpoint
   - Verify authentication works
   - Test with different input combinations
   - Verify error handling for invalid inputs

### Automated Testing

Unit tests are located in:

- `tests/Integration/Includes/Abilities/Excerpt_GenerationTest.php`
- `tests/Integration/Includes/Experiments/Excerpt_Generation/Excerpt_GenerationTest.php`

Run tests with:

```bash
npm run test:php
```

## Notes & Considerations

### Requirements

- The experiment requires valid AI credentials to be configured
- The experiment only works for post types that support excerpts (`post_type_supports( $post_type, 'excerpt' )`)
- The experiment does not load for attachment post types
- Users must have `edit_posts` capability (or `read_post` for specific posts when using post ID context)

### Performance

- Excerpt generation is an AI operation and may take several seconds
- The UI shows a loading state while generation is in progress
- Consider implementing caching for frequently accessed posts if generating excerpts in bulk

### Content Processing

- Content is normalized before being sent to the AI (HTML stripped, shortcodes removed, etc.)
- The `normalize_content()` function handles this processing
- Additional context from post metadata (title, categories, tags) can be included when using post ID

### AI Model Selection

- The ability uses `get_preferred_models_for_text_generation()` to determine which AI models to use
- Models are tried in order until one succeeds
- Temperature is set to 0.7 for consistent but creative results

### System Instruction

The system instruction guides the AI to:

- Generate excerpts approximately 55 words in length
- Use plain text only (no markdown or formatting)
- Create complete, coherent summaries
- Optimize for clarity, engagement, and SEO
- Reflect the actual content accurately

### Limitations

- Excerpts are generated in real-time and not cached
- The ability does not support batch processing (one excerpt per request)
- Generated excerpts are suggestions and should be reviewed before publishing
- The experiment requires JavaScript to be enabled in the admin

## Related Files

- **Experiment:** `includes/Experiments/Excerpt_Generation/Excerpt_Generation.php`
- **Ability:** `includes/Abilities/Excerpt_Generation/Excerpt_Generation.php`
- **System Instruction:** `includes/Abilities/Excerpt_Generation/system-instruction.php`
- **React Entry:** `src/experiments/excerpt-generation/index.tsx`
- **React Components:** `src/experiments/excerpt-generation/components/`
- **Tests:** `tests/Integration/Includes/Abilities/Excerpt_GenerationTest.php`
- **Tests:** `tests/Integration/Includes/Experiments/Excerpt_Generation/Excerpt_GenerationTest.php`
