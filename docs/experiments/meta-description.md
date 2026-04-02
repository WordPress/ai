# Meta Description

## Summary

The Meta Description experiment adds AI-powered meta description generation to the WordPress post editor. It provides a "Meta Description" sidebar panel with a modal workflow for generating, selecting, editing, and applying meta descriptions. The experiment automatically detects active SEO plugins (Yoast SEO, Rank Math, All in One SEO, SEOPress) and writes to the correct meta field. It registers a WordPress Ability (`ai/meta-description`) that can be used both through the admin UI and directly via REST API requests.

## Overview

### For End Users

When enabled, the Meta Description experiment adds a "Meta Description" panel to the post editor sidebar. Users can generate AI-powered meta description suggestions optimized for search engines, select or edit a suggestion, and apply it to their post.

**Key Features:**

- Generates multiple meta description suggestions (default: 3) from post content and title
- Suggestions target the optimal 140–160 character range for search engine display
- Editable textarea allows fine-tuning suggestions before applying
- Live character count with color-coded indicator (green for 140–160, yellow outside range)
- Automatic SEO plugin detection — writes to the correct meta field for Yoast SEO, Rank Math, All in One SEO, and SEOPress
- Falls back to a standard post meta field (`_meta_description`) when no SEO plugin is active
- Copy to clipboard functionality for use with unsupported SEO plugins or external tools
- Works with any post type that has `show_in_rest` enabled

**Workflow:**

1. Open or create a post in the editor
2. Find the "Meta Description" panel in the sidebar
3. Click "Generate meta description" to open the modal
4. Review the AI-generated suggestions and select one (or edit the textarea directly)
5. Click "Apply" to save the description to the appropriate meta field
6. Save/update the post as usual

### For Developers

The experiment consists of three main components:

1. **Experiment Class** (`WordPress\AI\Experiments\Meta_Description\Meta_Description`): Handles registration, asset enqueuing, post meta registration, and SEO plugin detection
2. **Ability Class** (`WordPress\AI\Abilities\Meta_Description\Meta_Description`): Implements the core meta description generation logic via the WordPress Abilities API
3. **SEO Integration** (`WordPress\AI\Abilities\Meta_Description\SEO_Integration`): Utility class for detecting active SEO plugins and resolving the correct meta key

The ability can be called directly via REST API, making it useful for automation, bulk processing, or custom integrations.

## Architecture & Implementation

### Key Hooks & Entry Points

- `WordPress\AI\Experiments\Meta_Description\Meta_Description::register()` wires everything once the experiment is enabled:
  - `wp_abilities_api_init` → registers the `ai/meta-description` ability (`includes/Abilities/Meta_Description/Meta_Description.php`)
  - `admin_enqueue_scripts` → enqueues the React bundle and styles on `post.php` and `post-new.php` screens for REST-enabled post types
  - `init` → registers the fallback post meta key for REST API access (only when no SEO plugin is active)

### Assets & Data Flow

1. **PHP Side:**
   - `enqueue_assets()` loads `experiments/meta-description` (`src/experiments/meta-description/index.tsx`) and localizes `window.aiMetaDescriptionData` with:
     - `enabled`: Whether the experiment is enabled
     - `metaKey`: The resolved meta key for the active SEO plugin (or fallback)
     - `seoPlugin`: The slug of the detected SEO plugin, or `null`

2. **React Side:**
   - The React entry point (`index.tsx`) registers a `PluginDocumentSettingPanel` in the editor sidebar
   - `MetaDescriptionPanel` component shows the current description with edit/regenerate actions, or a generate button if none exists
   - Clicking generate or edit opens `MetaDescriptionModal` which displays suggestion cards and an editable textarea
   - `useMetaDescription` hook:
     - Gets current post ID, content, title, and existing meta description from the editor store
     - Calls the ability via `runAbility()` when generation is triggered
     - Updates the post meta via `editPost()` when a description is applied
     - Copy to clipboard uses WordPress's `useCopyToClipboard` from `@wordpress/compose`

3. **Ability Execution:**
   - Accepts `content` (string), `title` (string), and `post_id` (integer) as input
   - If `post_id` is provided, fetches post content and context using `get_post_context()`
   - Normalizes content using `normalize_content()` helper
   - Sends content to AI client with system instruction targeting 140–160 character descriptions
   - Returns an object with an array of description suggestions, each including the text and character count

### Input Schema

The ability accepts the following input parameters:

```php
array(
    'content' => array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'description'       => 'Post content to generate a meta description for.',
    ),
    'title'   => array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'description'       => 'The post title, used to avoid duplication in the generated description.',
    ),
    'post_id' => array(
        'type'              => 'integer',
        'sanitize_callback' => 'absint',
        'description'       => 'The post ID to generate a meta description for. If provided without content, the post content will be used.',
    ),
)
```

### Output Schema

The ability returns an object containing an array of description suggestions:

```php
array(
    'type'       => 'object',
    'properties' => array(
        'descriptions' => array(
            'type'  => 'array',
            'items' => array(
                'type'       => 'object',
                'properties' => array(
                    'text'            => array( 'type' => 'string' ),
                    'character_count' => array( 'type' => 'integer' ),
                ),
            ),
        ),
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

### SEO Plugin Detection

The `SEO_Integration` utility class detects active SEO plugins and resolves the correct meta key:

| Plugin | Slug | Meta Key |
|--------|------|----------|
| Yoast SEO | `yoast-seo` | `_yoast_wpseo_metadesc` |
| Rank Math | `rank-math` | `rank_math_description` |
| All in One SEO | `all-in-one-seo` | `_aioseo_description` |
| SEOPress | `seopress` | `_seopress_titles_desc` |
| None (fallback) | — | `_meta_description` |

When no SEO plugin is active, the experiment registers the fallback `_meta_description` meta key for REST-enabled post types so it can be read and written through the WordPress data layer.

## Using the Ability via REST API

The meta description ability can be called directly via REST API, making it useful for automation, bulk processing, or custom integrations.

### Endpoint

```text
POST /wp-json/wp-abilities/v1/abilities/ai/meta-description/run
```

### Authentication

You can authenticate using either:

1. **Application Password** (Recommended)
2. **Cookie Authentication with Nonce**

See [TESTING_REST_API.md](../TESTING_REST_API.md) for detailed authentication instructions.

### Request Examples

#### Example 1: Generate from Content and Title

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/meta-description/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "content": "This is a comprehensive article about artificial intelligence and machine learning. AI has revolutionized many industries including healthcare, finance, and transportation.",
      "title": "How AI is Transforming Industries"
    }
  }'
```

**Response:**

```json
{
  "descriptions": [
    {
      "text": "Discover how artificial intelligence and machine learning are revolutionizing healthcare, finance, and transportation with data-driven insights and automation.",
      "character_count": 156
    },
    {
      "text": "Learn how AI transforms industries from healthcare to transportation through advanced machine learning algorithms and predictive analytics capabilities.",
      "character_count": 153
    },
    {
      "text": "Explore the impact of AI and machine learning across healthcare, finance, and transportation sectors, reshaping how industries process data and make decisions.",
      "character_count": 160
    }
  ]
}
```

#### Example 2: Generate from Post ID

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/meta-description/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "post_id": 123
    }
  }'
```

This will automatically fetch the content and title from post ID 123 and generate meta description suggestions.

#### Example 3: Using WordPress API Fetch (in Gutenberg/Admin)

```javascript
import apiFetch from '@wordpress/api-fetch';

async function generateMetaDescriptions(content, title, postId = null) {
  const input = { content, title };
  if (postId) {
    input.post_id = postId;
  }

  try {
    const result = await apiFetch({
      path: '/wp-abilities/v1/abilities/ai/meta-description/run',
      method: 'POST',
      data: { input },
    });
    return result.descriptions; // Array of { text, character_count }
  } catch (error) {
    console.error('Error generating meta descriptions:', error);
    throw error;
  }
}
```

### Error Responses

The ability may return the following error codes:

- `post_not_found`: The provided post ID does not exist
- `content_not_provided`: No content was provided and no valid post ID was found
- `no_results`: The AI client did not return any results
- `insufficient_capabilities`: The current user does not have permission to generate meta descriptions

Example error response:

```json
{
  "code": "content_not_provided",
  "message": "Content is required to generate a meta description.",
  "data": {
    "status": 400
  }
}
```

## Extending the Experiment

### Customizing the System Instruction

The system instruction that guides the AI can be customized by modifying:

```php
includes/Abilities/Meta_Description/system-instruction.php
```

This file returns a string that instructs the AI on how to generate meta descriptions. You can modify the character length requirements, tone, style, or other parameters.

### Filtering the Prompt Content

You can filter the assembled prompt before it is sent to the AI model:

```php
add_filter( 'wpai_meta_description_prompt', function( $prompt, $content, $title ) {
    // Append custom instructions to the prompt
    $prompt .= "\n\n<instruction>Focus on the environmental impact angle.</instruction>";
    return $prompt;
}, 10, 3 );
```

### Filtering the Number of Suggestions

You can change how many description candidates are generated:

```php
add_filter( 'wpai_meta_description_candidate_count', function( $count ) {
    return 5; // Generate 5 suggestions instead of the default 3
} );
```

### Filtering the Result Temperature

You can adjust the AI temperature for more creative or more consistent results:

```php
add_filter( 'wpai_meta_description_result_temperature', function( $temperature ) {
    return 0.3; // Lower temperature for more consistent output
} );
```

### Registering Additional SEO Plugins

You can add support for additional SEO plugins:

```php
add_filter( 'wpai_meta_description_seo_plugins', function( $plugins ) {
    $plugins['my-seo-plugin'] = array(
        'file'     => 'my-seo-plugin/my-seo-plugin.php',
        'meta_key' => '_my_seo_meta_description',
    );
    return $plugins;
} );
```

### Overriding the Meta Key

You can override the resolved meta key regardless of which SEO plugin is detected:

```php
add_filter( 'wpai_meta_description_meta_key', function( $key, $plugin_slug ) {
    return '_custom_meta_description_key';
}, 10, 2 );
```

### Filtering Preferred Models

You can filter which AI models are used for meta description generation using the `ai_experiments_preferred_models_for_text_generation` filter:

```php
add_filter( 'wpai_experiments_preferred_models_for_text_generation', function( $models ) {
    return array(
        array( 'openai', 'gpt-4' ),
        array( 'anthropic', 'claude-haiku-4-5' ),
    );
} );
```

### Customizing Content Normalization

The `normalize_content()` helper function processes content before sending it to the AI. You can filter the normalized content:

```php
// Filter content before normalization
add_filter( 'wpai_experiments_pre_normalize_content', function( $content ) {
    // Custom preprocessing
    return $content;
} );

// Filter content after normalization
add_filter( 'wpai_experiments_normalize_content', function( $content ) {
    // Custom post-processing
    return $content;
} );
```

## Testing

### Manual Testing

1. **Enable the experiment:**
   - Go to `Settings → AI Experiments`
   - Toggle **Meta Description** to enabled
   - Ensure you have valid AI credentials configured

2. **Test in the editor:**
   - Create or edit a post with content
   - Find the "Meta Description" panel in the editor sidebar
   - Click "Generate meta description" to open the modal
   - Verify that 3 suggestions are generated with character counts
   - Select a suggestion and verify it populates the textarea
   - Edit the text and verify the character count updates live
   - Click "Apply" and verify the description appears in the sidebar panel
   - Click "Edit description" and verify the modal opens with the current text
   - Click the regenerate icon and verify new suggestions are generated
   - Test "Copy to clipboard" and verify the text is copied

3. **Test SEO plugin integration:**
   - With Yoast SEO active, verify the description is saved to `_yoast_wpseo_metadesc`
   - Without any SEO plugin, verify the description is saved to `_meta_description`
   - Verify the correct meta key is displayed in the localized data

4. **Test with different post types:**
   - The experiment loads for any REST-enabled post type except attachments
   - Test with posts, pages, and custom post types

5. **Test REST API:**
   - Use curl or Postman to test the REST endpoint
   - Verify authentication works
   - Test with different input combinations
   - Verify error handling for invalid inputs

### Automated Testing

Tests are located in:

- `tests/Integration/Includes/Abilities/Meta_DescriptionTest.php`
- `tests/Integration/Includes/Abilities/Meta_Description/SEO_IntegrationTest.php`
- `tests/Integration/Includes/Experiments/Meta_Description/Meta_DescriptionTest.php`

Run tests with:

```bash
npm run test:php
```

## Notes & Considerations

### Requirements

- The experiment requires valid AI credentials to be configured
- The experiment loads for any post type with `show_in_rest` enabled, except attachments
- Users must have `edit_posts` capability (or `edit_post` for specific posts when using post ID)

### Performance

- Meta description generation is an AI operation and may take several seconds
- The UI shows a loading state (busy button) while generation is in progress
- Multiple candidates are generated in a single API call using `candidate_count`

### Content Processing

- Content is normalized before being sent to the AI (HTML stripped, shortcodes removed, etc.)
- The `normalize_content()` function handles this processing
- Additional context from post metadata (title, categories, tags) can be included when using post ID
- The post title is passed separately to prevent duplication in the generated description

### AI Model Selection

- The ability uses `get_preferred_models_for_text_generation()` to determine which AI models to use
- Models are tried in order until one succeeds
- Temperature defaults to 0.7 for balanced creativity and consistency (filterable via `ai_meta_description_result_temperature`)

### System Instruction

The system instruction guides the AI to:

- Generate descriptions between 140 and 160 characters
- Use plain text only (no markdown, HTML, or special formatting)
- Avoid duplicating or closely mirroring the post title
- Avoid keyword stuffing or repetitive terms
- Use active, action-oriented language that encourages click-through
- Accurately reflect the actual content

### Limitations

- Descriptions are generated in real-time and not cached
- The ability does not support batch processing (one request generates multiple candidates for a single post)
- Generated descriptions are suggestions and should be reviewed before publishing
- SEO plugin integration is read/write only for supported plugins — unsupported plugins require the `ai_meta_description_seo_plugins` filter or copy-to-clipboard
- The experiment requires JavaScript to be enabled in the admin

## Related Files

- **Experiment:** `includes/Experiments/Meta_Description/Meta_Description.php`
- **Ability:** `includes/Abilities/Meta_Description/Meta_Description.php`
- **SEO Integration:** `includes/Abilities/Meta_Description/SEO_Integration.php`
- **System Instruction:** `includes/Abilities/Meta_Description/system-instruction.php`
- **React Entry:** `src/experiments/meta-description/index.tsx`
- **React Components:** `src/experiments/meta-description/components/`
- **Styles:** `src/experiments/meta-description/index.scss`
- **Types:** `src/experiments/meta-description/types.ts`
- **Tests:** `tests/Integration/Includes/Abilities/Meta_DescriptionTest.php`
- **Tests:** `tests/Integration/Includes/Abilities/Meta_Description/SEO_IntegrationTest.php`
- **Tests:** `tests/Integration/Includes/Experiments/Meta_Description/Meta_DescriptionTest.php`
