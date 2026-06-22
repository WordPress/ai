# Content Translation

## Summary

The Content Translation experiment adds AI-powered block translation to the WordPress post editor. It provides a "Generate Translation" button in the post status panel, lets users choose a target language, and translates eligible blocks in the post one batch at a time. The experiment registers a WordPress Ability (`ai/content-translation`) that can be called from the editor UI or directly through the REST API.

## Overview

### For End Users

When enabled, the Content Translation experiment adds a "Generate Translation" button to the post status panel in the WordPress post editor. Clicking the button opens a modal where users choose the target language and can optionally translate the post title. The experiment then translates supported text blocks and applies the translated content back to each block.

**Key Features:**

- One-click access from the post status panel
- Language picker for supported target languages
- Optional post title translation
- Block-by-block translation for `core/paragraph` and `core/heading`
- Batch processing with progress shown in the button label
- Partial success handling: failed blocks are counted and reported without discarding successful translations

### For Developers

The experiment consists of three main components:

1. **Experiment Class** (`WordPress\AI\Experiments\Content_Translation\Content_Translation`): handles registration, asset enqueuing, localized editor settings, and ability registration.
2. **Ability Class** (`WordPress\AI\Abilities\Content_Translation\Content_Translation`): implements the translation logic through the WordPress Abilities API.
3. **Languages Class** (`WordPress\AI\Abilities\Content_Translation\Languages`): defines the supported target language list and exposes it to both PHP and JavaScript.

The ability is block-agnostic: it translates any content string sent to it. The shipping editor UI limits translation to paragraph and heading blocks.

## Architecture & Implementation

### Input Schema

```php
array(
    'type'       => 'object',
    'properties' => array(
        'post_id'         => array(
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'description'       => 'The ID of the post to translate content for.',
        ),
        'content'         => array(
            'type'        => 'string',
            'description' => 'The block content to translate.',
        ),
        'target_language' => array(
            'type'              => 'string',
            'enum'              => Languages::get_codes(),
            'default'           => Languages::get_default_target_language(),
            'sanitize_callback' => 'sanitize_key',
            'description'       => 'The target language for translation.',
        ),
    ),
)
```

### Output Schema

The ability returns a string with translated content:

```php
array(
    'type'        => 'string',
    'description' => 'The translated content.',
)
```

### Supported Languages

The default target language is `en-us` (English US). The supported language list is:

- `ar` - Arabic
- `zh-cn` - Chinese (Simplified)
- `zh-tw` - Chinese (Traditional)
- `nl-nl` - Dutch
- `en-gb` - English (UK)
- `en-us` - English (US)
- `fr-fr` - French
- `de-de` - German
- `hi` - Hindi
- `it-it` - Italian
- `ja` - Japanese
- `ko` - Korean
- `pt-br` - Portuguese (Brazil)
- `es-es` - Spanish

The list is filterable with `wpai_content_translation_languages`.

### Permissions

The ability checks permissions based on the input:

- **If `post_id` is provided:**
  - Verifies the post exists; returns `post_not_found` otherwise.
  - Checks `current_user_can( 'edit_post', $post_id )`.
  - Requires the post type to have `show_in_rest` enabled.

- **If `post_id` is not provided:**
  - Checks `current_user_can( 'edit_posts' )`.

## Using the Ability via REST API

### Endpoint

```text
POST /wp-json/wp-abilities/v1/abilities/ai/content-translation/run
```

### Authentication

You can authenticate using either:

1. **Application Password** (Recommended)
2. **Cookie Authentication with Nonce**

See [TESTING_REST_API.md](../TESTING_REST_API.md) for detailed authentication instructions.

### Request Examples

#### Example 1: Translate a paragraph to French

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/content-translation/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "content": "Our new platform helps teams collaborate more effectively, share files securely, and track progress in real time.",
      "target_language": "fr-fr",
      "post_id": 123
    }
  }'
```

#### Example 2: Translate content with inline HTML

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/content-translation/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "content": "Read the <a href=\"https://example.com\">launch notes</a> before publishing.",
      "target_language": "es-es"
    }
  }'
```

The system instruction tells the model to preserve inline HTML such as links, emphasis, and code.

#### Example 3: Use the default target language

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/content-translation/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "content": "Translate this sentence using the default target language."
    }
  }'
```

When `target_language` is omitted, the ability uses `en-us`.

#### Example 4: Using JavaScript (Fetch API)

```javascript
const response = await fetch(
    '/wp-json/wp-abilities/v1/abilities/ai/content-translation/run',
    {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': wpApiSettings.nonce, // If using cookie auth
        },
        credentials: 'include', // Include cookies for authentication
        body: JSON.stringify( {
            input: {
                content: blockContent,
                target_language: 'pt-br',
                post_id: postId,
            },
        } ),
    }
);
```

#### Example 5: Using WordPress API Fetch (in Gutenberg/Admin)

```javascript
import apiFetch from '@wordpress/api-fetch';

const translated = await apiFetch({
    path: '/wp-abilities/v1/abilities/ai/content-translation/run',
    method: 'POST',
    data: {
        input: {
            content: blockContent,
            target_language: 'pt-br',
            post_id: postId,
        },
    },
});
```

### Error Responses

The ability may return the following error codes:

- `content_not_provided` - `content` was missing or empty.
- `content_too_short` - Content contains fewer than 1 word after stripping HTML.
- `invalid_target_language` - `target_language` is not in the supported language list.
- `post_not_found` - A `post_id` was supplied but the post does not exist.
- `insufficient_permissions` - Caller lacks `edit_post` (with `post_id`) or `edit_posts` (without).
- `no_results` - The AI client did not return translated text.
- A WP_Error from `ensure_text_generation_supported()` if no connected provider supports text generation.

## Extending the Experiment

### Customizing Supported Languages

Use the `wpai_content_translation_languages` filter to replace or extend the target language list:

```php
add_filter( 'wpai_content_translation_languages', function ( array $languages ): array {
    $languages['sv'] = __( 'Swedish', 'my-plugin' );
    return $languages;
} );
```

The filtered list is used for the ability schema, PHP validation, and the editor language picker.

## Testing

### Manual Testing

1. **Enable the experiment:**
   - Go to `Settings -> AI`
   - Toggle **Content Translation** to enabled
   - Ensure you have valid AI credentials configured

2. **Test in the editor:**
   - Create or edit a post with enough post content to meet the minimum length
   - Open the post sidebar and click **Generate Translation**
   - Choose a target language
   - Toggle **Also translate the title** and click **Translate**
   - Verify the title updates when the toggle is enabled
   - Verify paragraph and heading blocks are replaced with translated text
   - Verify the button shows progress while blocks are translating

3. **Test disabled states:**
   - Disable all experiments and verify the translation UI is hidden
   - Disable only Content Translation and verify the translation UI is hidden
   - Use content shorter than the minimum length and verify the button is disabled

4. **Test REST API:**
   - Use curl or Postman to test the REST endpoint
   - Test each supported language code
   - Verify `invalid_target_language` for an unsupported code
   - Verify `post_not_found` and permission errors when using invalid or inaccessible posts

## Notes & Considerations

### Requirements

- The experiment requires a configured AI connector/provider that supports text generation.
- Users must have `edit_post` when invoking with a `post_id`, or `edit_posts` when invoking without one.

### Content Processing

- The content sent to the model is wrapped in `<content>` tags.
- The result is sanitized with `wp_kses_post()`.

### System Instruction

The system instruction guides the AI to:

- Translate into the selected target language.
- Return only the translated text.
- Avoid preamble, explanation, or commentary.
- Preserve inline HTML and the original format where possible.
- Maintain the original perspective and voice.

### Limitations

- The editor UI only translates paragraph and heading blocks.
- There is no batch REST endpoint; the editor performs multiple ability calls in batches of 4.
- Translations are generated in real time and are not cached.
- Failed block translations are skipped; successful blocks remain applied.
- The UI replaces the current block content directly, so users should review changes before saving.
