# Content Summarization

## Summary

The Content Summarization experiment adds AI-powered content summarization to the WordPress post editor. It provides a "Generate AI Summary" button in the post status panel that uses AI to create concise summaries of post content. The generated summary is inserted as a paragraph block at the top of the post content. The experiment registers a WordPress Ability (`ai/summarization`) that can be used both through the admin UI and directly via REST API requests.

## Overview

### For End Users

When enabled, the Content Summarization experiment adds a "Generate AI Summary" button to the post status panel in the WordPress post editor. Users can click this button to automatically generate a summary of the current post content. The generated summary is inserted as a paragraph block at the top of the post content and can be customized with different length options (short, medium, long). The summary is also saved to post meta for programmatic access.

**Key Features:**

- One-click summary generation from post content
- Automatically creates a paragraph block with the summary
- Configurable summary length (short, medium, long)
- Summary block can be regenerated from block toolbar
- Summary is saved to post meta (`ai_generated_summary`)
- Works with any post type that supports the editor

### For Developers

The experiment consists of two main components:

1. **Experiment Class** (`WordPress\AI\Experiments\Summarization\Summarization`): Handles registration, asset enqueuing, UI integration, and post meta registration
2. **Ability Class** (`WordPress\AI\Abilities\Summarization\Summarization`): Implements the core summarization logic via the WordPress Abilities API

The ability can be called directly via REST API, making it useful for automation, bulk processing, or custom integrations.

## Architecture & Implementation

### Key Hooks & Entry Points

- `WordPress\AI\Experiments\Summarization\Summarization::register()` wires everything once the experiment is enabled:
  - `register_post_meta()` → registers `ai_generated_summary` post meta for the `post` post type
  - `wp_abilities_api_init` → registers the `ai/summarization` ability (`includes/Abilities/Summarization/Summarization.php`)
  - `admin_enqueue_scripts` → enqueues the React bundle on `post.php` and `post-new.php` screens

### Assets & Data Flow

1. **PHP Side:**
   - `enqueue_assets()` loads `experiments/summarization` (`src/experiments/summarization/index.tsx`) and localizes `window.aiSummarizationData` with:
     - `enabled`: Whether the experiment is enabled

2. **React Side:**
   - The React entry point (`index.tsx`) registers:
     - A WordPress plugin (`SummarizationPlugin`) that adds a button to the post status panel
     - A block variation for `core/paragraph` with `aiGeneratedSummary` attribute
     - Block controls (`SummarizationBlockControls`) that add toolbar buttons to summary blocks
     - A custom attribute (`aiGeneratedSummary`) added to `core/paragraph` blocks
   - `SummarizationPlugin` component:
     - Renders a button in the post status panel
     - Uses `useSummaryGeneration()` hook to handle generation
   - `useSummaryGeneration` hook:
     - Gets current post ID and content from the editor store
     - Checks for existing summary blocks
     - Calls `generateSummary()` function when button is clicked
     - Creates or replaces a paragraph block with the generated summary
     - Saves summary to post meta
     - Handles loading states and error notifications
   - `SummarizationBlockControls` component:
     - Adds toolbar controls to summary blocks
     - Allows regenerating the summary from the block toolbar

3. **Ability Execution:**
   - Accepts `content` (string), `context` (string or post ID), and `length` (enum: 'short', 'medium', 'long') as input
   - If `context` is numeric, treats it as a post ID and fetches post content using `get_post_context()`
   - Normalizes content using `normalize_content()` helper
   - Sends content to AI client with system instruction for summarization (length-aware)
   - Returns a plain text summary

### Input Schema

The ability accepts the following input parameters:

```php
array(
    'type'       => 'object',
    'properties' => array(
        'content' => array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'description'       => 'Content to summarize.',
        ),
        'context' => array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'description'       => 'Additional context to use when summarizing the content. Can be a string of additional context or a post ID (as string) that will be used to get context from that post. If no content is provided but a valid post ID is used, the content from that post will be used.',
        ),
        'length'  => array(
            'type'        => 'enum',
            'enum'        => array( 'short', 'medium', 'long' ),
            'default'     => 'medium',
            'description' => 'The length of the summary.',
        ),
    ),
)
```

### Output Schema

The ability returns a plain text string:

```php
array(
    'type'        => 'string',
    'description' => 'The summary of the content.',
)
```

### Summary Length Options

The `length` parameter controls the target length of the generated summary:

- **`short`**: 1 sentence; ≤ 25 words
- **`medium`** (default): 2-3 sentences; 25-80 words
- **`long`**: 4-6 sentences; 80-160 words

The system instruction is dynamically adjusted based on the selected length.

### Permissions

The ability checks permissions based on the input:

- **If `context` is a post ID:**
  - Verifies the post exists
  - Checks `current_user_can( 'edit_post', $post_id )`
  - Ensures the post type has `show_in_rest` enabled

- **If `context` is not a post ID:**
  - Checks `current_user_can( 'edit_posts' )`

## Using the Ability via REST API

The summarization ability can be called directly via REST API, making it useful for automation, bulk processing, or custom integrations.

### Endpoint

```text
POST /wp-json/wp-abilities/v1/abilities/ai/summarization/run
```

### Authentication

You can authenticate using either:

1. **Application Password** (Recommended)
2. **Cookie Authentication with Nonce**

See [TESTING_REST_API.md](../TESTING_REST_API.md) for detailed authentication instructions.

### Request Examples

#### Example 1: Generate Summary from Content String

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/summarization/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "content": "This is a comprehensive article about artificial intelligence and machine learning. AI has revolutionized many industries including healthcare, finance, and transportation. Machine learning algorithms can now process vast amounts of data to identify patterns and make predictions that were previously impossible. The future of AI looks promising with advances in natural language processing, computer vision, and autonomous systems. These technologies are transforming how we work, communicate, and solve complex problems.",
      "length": "medium"
    }
  }'
```

**Response:**

```json
"Artificial intelligence and machine learning are transforming industries like healthcare, finance, and transportation by enabling algorithms to process large datasets and make predictions. Advances in natural language processing, computer vision, and autonomous systems are reshaping how we work and solve problems."
```

#### Example 2: Generate Summary from Post ID

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/summarization/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "context": "123"
    }
  }'
```

This will automatically fetch the content from post ID 123 and generate a medium-length summary (default).

#### Example 3: Generate Short Summary

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/summarization/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "content": "Your long article content here...",
      "length": "short"
    }
  }'
```

#### Example 4: Generate Long Summary with Additional Context

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/summarization/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "content": "Your article content here...",
      "context": "Focus on technical details and implementation",
      "length": "long"
    }
  }'
```

#### Example 5: Using JavaScript (Fetch API)

```javascript
async function generateSummary(content, postId = null, length = 'medium') {
  const input = { content, length };
  if (postId) {
    input.context = String(postId);
  }

  const response = await fetch(
    '/wp-json/wp-abilities/v1/abilities/ai/summarization/run',
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
    throw new Error(error.message || 'Failed to generate summary');
  }

  return await response.text(); // Returns plain text string
}

// Usage
generateSummary('Your article content here...', null, 'short')
  .then(summary => console.log('Generated summary:', summary))
  .catch(error => console.error('Error:', error));
```

#### Example 6: Using WordPress API Fetch (in Gutenberg/Admin)

```javascript
import apiFetch from '@wordpress/api-fetch';

async function generateSummary(content, postId = null, length = 'medium') {
  const input = { content, length };
  if (postId) {
    input.context = String(postId);
  }

  try {
    const summary = await apiFetch({
      path: '/wp-abilities/v1/abilities/ai/summarization/run',
      method: 'POST',
      data: { input },
    });
    return summary; // Plain text string
  } catch (error) {
    console.error('Error generating summary:', error);
    throw error;
  }
}
```

### Error Responses

The ability may return the following error codes:

- `post_not_found`: The provided post ID does not exist
- `content_not_provided`: No content was provided and no valid post ID was found
- `no_results`: The AI client did not return any results
- `insufficient_capabilities`: The current user does not have permission to summarize content

Example error response:

```json
{
  "code": "content_not_provided",
  "message": "Content is required to generate a summary.",
  "data": {
    "status": 400
  }
}
```

## Extending the Experiment

### Customizing the System Instruction

The system instruction that guides the AI can be customized by modifying:

```php
includes/Abilities/Summarization/system-instruction.php
```

This file returns a string that instructs the AI on how to generate summaries. The instruction is dynamically adjusted based on the `length` parameter. You can modify the requirements, tone, or other parameters.

### Filtering Preferred Models

You can filter which AI models are used for summarization using the `ai_experiments_preferred_models` filter:

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

1. **Modify the plugin component:**
   - Edit `src/experiments/summarization/components/SummarizationPlugin.tsx`

2. **Customize block controls:**
   - Edit `src/experiments/summarization/components/SummarizationBlockControls.tsx`

3. **Add custom hooks:**
   - Create new hooks in `src/experiments/summarization/functions/`
   - Import and use them in the components

4. **Customize the block variation:**
   - Modify the block variation registration in `src/experiments/summarization/index.tsx`
   - Change the block type, attributes, or styling

### Customizing Summary Length Options

You can add custom length options by:

1. **Extending the enum in the ability:**
   - Modify the `length` property in `input_schema()` in `includes/Abilities/Summarization/Summarization.php`
   - Add corresponding logic in `system-instruction.php` to handle the new length

2. **Adding UI controls:**
   - Add a dropdown or toggle in `SummarizationPlugin.tsx` to let users select length
   - Pass the selected length to the `generateSummary()` function

### Accessing Summary from Post Meta

The summary is saved to post meta as `ai_generated_summary`. You can access it programmatically:

```php
$summary = get_post_meta( $post_id, 'ai_generated_summary', true );
```

Or via REST API:

```bash
GET /wp-json/wp/v2/posts/123?context=edit
# Response includes: meta.ai_generated_summary
```

## Testing

### Manual Testing

1. **Enable the experiment:**
   - Go to `Settings → AI`
   - Toggle **Content Summarization** to enabled
   - Ensure you have valid AI credentials configured

2. **Test in the editor:**
   - Create or edit a post with content
   - Scroll to the post status panel (right sidebar)
   - Click the "Generate AI Summary" button
   - Verify a paragraph block is created at the top with the summary
   - Verify the summary is saved to post meta
   - Click "Re-generate AI Summary" to test regeneration
   - Select the summary block and use the toolbar button to regenerate

3. **Test with different post types:**
   - The experiment loads for all post types that use the block editor
   - Test with posts, pages, and custom post types

4. **Test REST API:**
   - Use curl or Postman to test the REST endpoint
   - Verify authentication works
   - Test with different input combinations (content, context, length)
   - Verify error handling for invalid inputs

### Automated Testing

Unit tests are located in:

- `tests/Integration/Includes/Abilities/SummarizationTest.php`
- `tests/Integration/Includes/Experiments/Summarization/SummarizationTest.php`

Run tests with:

```bash
npm run test:php
```

## Notes & Considerations

### Requirements

- The experiment requires valid AI credentials to be configured
- The experiment works with any post type that uses the block editor
- Users must have `edit_posts` capability (or `read_post` for specific posts when using post ID context)
- The experiment requires JavaScript to be enabled in the admin

### Performance

- Summary generation is an AI operation and may take several seconds
- The UI shows a loading state while generation is in progress
- Consider implementing caching for frequently accessed posts if generating summaries in bulk

### Content Processing

- Content is normalized before being sent to the AI (HTML stripped, shortcodes removed, etc.)
- The `normalize_content()` function handles this processing
- Additional context from post metadata (title, categories, tags) can be included when using post ID

### AI Model Selection

- The ability uses `get_preferred_models()` to determine which AI models to use
- Models are tried in order until one succeeds
- Temperature is set to 0.9 for more creative and varied summaries

### System Instruction

The system instruction guides the AI to:

- Generate concise, factual, and neutral summaries
- Use complete sentences, avoid persuasive or stylistic language
- Not use humor or exaggeration
- Not introduce information not present in the source
- Avoid generic introductions like "This article is about..."
- Target specific word counts based on the length parameter
- Use plain text only (no markdown or formatting)

### Block Integration

- The summary is inserted as a `core/paragraph` block with a custom attribute (`aiGeneratedSummary`)
- The block has a special class name (`ai-summarization-summary`) for styling
- A block variation is registered to make it easy to insert summary blocks
- Block controls allow regenerating the summary from the toolbar

### Post Meta Storage

- The summary is stored in post meta as `ai_generated_summary`
- This meta is registered for the `post` post type and is available in REST API
- The meta is updated each time a summary is generated
- The meta can be accessed programmatically for custom use cases

### Limitations

- Summaries are generated in real-time and not cached
- The ability does not support batch processing (one summary per request)
- Generated summaries are suggestions and should be reviewed before publishing
- The experiment requires JavaScript to be enabled in the admin
- The summary block replaces any existing summary block when regenerated
- Summary length is approximate; actual length may vary slightly

## Related Files

- **Experiment:** `includes/Experiments/Summarization/Summarization.php`
- **Ability:** `includes/Abilities/Summarization/Summarization.php`
- **System Instruction:** `includes/Abilities/Summarization/system-instruction.php`
- **React Entry:** `src/experiments/summarization/index.tsx`
- **React Components:** `src/experiments/summarization/components/`
- **React Functions:** `src/experiments/summarization/functions/`
- **Tests:** `tests/Integration/Includes/Abilities/SummarizationTest.php`
- **Tests:** `tests/Integration/Includes/Experiments/Summarization/SummarizationTest.php`
