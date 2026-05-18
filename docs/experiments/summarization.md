# Content Summarization

## Summary

The Content Summarization experiment adds AI-powered content summarization to the WordPress post editor. It provides a "Generate AI Summary" button in the post status panel that uses AI to create concise summaries of post content. The generated summary is inserted as a group variation block at the top of the post content. The experiment registers a WordPress Ability (`ai/summarization`) that can be used both through the admin UI and directly via REST API requests.

## Overview

### For End Users

When enabled, the Content Summarization experiment adds a "Generate AI Summary" button to the post status panel in the WordPress post editor. Users can click this button to automatically generate a summary of the current post content. The generated summary is inserted as a group variation block at the top of the post content. The summary is also saved to post meta for programmatic access.

**Key Features:**

- One-click summary generation from post content
- Automatically creates a group variation block with the summary
- Summary block can be regenerated from block toolbar
- Summary is saved to post meta (`ai_generated_summary`)
- Works with any post type that supports the editor

### For Developers

The experiment consists of two main components:

1. **Experiment Class** (`WordPress\AI\Experiments\Summarization\Summarization`): Handles registration, asset enqueuing, UI integration, and post meta registration
2. **Ability Class** (`WordPress\AI\Abilities\Summarization\Summarization`): Implements the core summarization logic via the WordPress Abilities API

The ability can be called directly via REST API, making it useful for automation, bulk processing, or custom integrations.

## Architecture & Implementation

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
            'type'        => 'string',
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
   - Click "Regenerate AI Summary" to test regeneration
   - Select the summary block and use the toolbar button to regenerate

3. **Test with different post types:**
   - The experiment loads for all post types that use the block editor
   - Test with posts, pages, and custom post types

4. **Test REST API:**
   - Use curl or Postman to test the REST endpoint
   - Verify authentication works
   - Test with different input combinations (content, context, length)
   - Verify error handling for invalid inputs

## Notes & Considerations

### Requirements

- The experiment requires valid AI credentials to be configured
- The experiment works with any post type that uses the block editor
- Users must have `edit_posts` capability (or `read_post` for specific posts when using post ID context)

### Performance

- Summary generation is an AI operation and may take several seconds
- The UI shows a loading state while generation is in progress

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

- The summary is inserted as a `core/group` block variation with a custom attribute (`aiGeneratedSummary`)
- The block has a special class name (`ai-summarization-summary`) for styling
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
- The summary block replaces any existing summary block when regenerated
- Summary length is approximate; actual length may vary slightly
