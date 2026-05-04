# Content Resizing

## Summary

The Content Resizing experiment lets editors transform the content of an individual block in three ways — **Shorten**, **Expand**, or **Rephrase** — directly from the block toolbar. The experiment registers a WordPress Ability (`ai/content-resizing`) that returns the transformed text while preserving inline HTML (links, emphasis, code, etc.). The ability can be called both through the in-editor UI and directly via REST API for automation.

## Overview

### For End Users

When enabled, the Content Resizing experiment adds a dropdown to the block toolbar of every selected paragraph block. Users pick **Shorten**, **Expand**, or **Rephrase**, and the experiment opens a modal showing the original text alongside the AI-generated replacement, with a word-count delta badge. Users can **Accept** the suggestion (which replaces the block content and visually flags the block as AI-resized), **Regenerate** for another pass, or close the modal to discard.

**Key Features:**

- Toolbar control on every `core/paragraph` block
- Three actions: shorten (~50% of original), expand (~150–200% of original), rephrase (same length)
- Side-by-side original vs. suggested preview with word-count delta
- Inline HTML preservation — links, `<strong>`, `<em>`, etc. survive the round-trip
- Visual flag (`aiResized` block attribute) on accepted suggestions
- Shorten action requires the block to contain at least 5 words

### For Developers

The experiment consists of two main components:

1. **Experiment Class** (`WordPress\AI\Experiments\Content_Resizing\Content_Resizing`): handles registration, asset enqueuing, and UI integration.
2. **Ability Class** (`WordPress\AI\Abilities\Content_Resizing\Content_Resizing`): implements the resizing logic via the WordPress Abilities API.

The ability can be called directly via REST API, making it useful for batch transforms or custom integrations outside the editor.

## Architecture & Implementation

### Input Schema

```php
array(
    'type'       => 'object',
    'properties' => array(
        'post_id' => array(
            'type'        => 'integer',
            'description' => 'The ID of the post to resize content for.',
        ),
        'content' => array(
            'type'        => 'string',
            'description' => 'The block content to resize.',
        ),
        'action'  => array(
            'type'        => 'string',
            'enum'        => array( 'shorten', 'expand', 'rephrase' ),
            'default'     => 'rephrase',
            'description' => 'The resizing action to perform.',
        ),
    ),
)
```

### Output Schema

The ability returns a plain text string (with inline HTML preserved):

```php
array(
    'type'        => 'string',
    'description' => 'The resized content.',
)
```

### Permissions

- **If `post_id` is provided:**
  - Verifies the post exists; returns `post_not_found` otherwise.
  - Checks `current_user_can( 'edit_post', $post_id )`.
  - **Note:** unlike most other AI abilities, content resizing does *not* require the post type to have `show_in_rest` enabled — the check is purely capability-based.

- **If `post_id` is not provided:**
  - Checks `current_user_can( 'edit_posts' )`.

## Using the Ability via REST API

### Endpoint

```text
POST /wp-json/wp-abilities/v1/abilities/ai/content-resizing/run
```

### Authentication

You can authenticate using either:

1. **Application Password** (Recommended)
2. **Cookie Authentication with Nonce**

See [TESTING_REST_API.md](../TESTING_REST_API.md) for detailed authentication instructions.

### Request Examples

#### Example 1: Rephrase a paragraph

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/content-resizing/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "content": "Our new platform helps teams collaborate more effectively, share files securely, and track progress in real time.",
      "action": "rephrase",
      "post_id": 123
    }
  }'
```

**Response:**

```json
"Teams can work together more efficiently with our latest platform, exchanging files safely and monitoring progress as it happens."
```

#### Example 2: Shorten content with inline HTML

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/content-resizing/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "content": "We are pleased to announce the <a href=\"https://example.com\">launch of our brand new product</a>, which represents months of careful planning and engineering work by our entire team.",
      "action": "shorten"
    }
  }'
```

The returned string preserves the `<a>` tag.

#### Example 3: Expand a brief sentence

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/content-resizing/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "content": "We launched today.",
      "action": "expand"
    }
  }'
```

> **Note:** the **shorten** action requires at least 5 words (HTML stripped) in the input. Shorter inputs return `content_too_short`. The 5-word floor does not apply to **expand** or **rephrase**.

#### Example 4: Using the JS helper inside the editor

```ts
import { runAbility } from '../../utils/run-ability';

const resized = await runAbility< string >( 'ai/content-resizing', {
    content: blockContent,
    action: 'shorten',
    post_id: postId,
} );
```

### Error Responses

The ability may return the following error codes:

- `content_not_provided` — `content` was missing or empty.
- `content_too_short` — `action: 'shorten'` was requested on input shorter than 5 words.
- `post_not_found` — A `post_id` was supplied but the post doesn't exist.
- `insufficient_capabilities` — Caller lacks `edit_post` (with `post_id`) or `edit_posts` (without).
- `no_results` — The AI client did not return any text.
- A WP_Error from `ensure_text_generation_supported()` if no connected provider supports text generation.

Example:

```json
{
  "code": "content_too_short",
  "message": "A minimum of 5 words is required to shorten the content.",
  "data": { "status": 400 }
}
```

## Extending the Experiment

### Customizing the System Instruction

Each action selects a different goal paragraph in `includes/Abilities/Content_Resizing/system-instruction.php` (the `$action` variable is passed through `Abstract_Ability::get_system_instruction()`'s `extract()` mechanism). Modify this file to change tone, length targets, or per-action behavior.

To customize *site-wide* — without forking — register a `wpai_system_instruction` filter:

```php
add_filter( 'wpai_system_instruction', function ( string $instruction, string $name, array $data ): string {
    if ( 'ai/content-resizing' !== $name ) {
        return $instruction;
    }
    return $instruction . "\nAlways match the brand tone described in <site-context>.";
}, 10, 3 );
```

### Filtering Preferred Models

The ability uses `WordPress\AI\get_preferred_models_for_text_generation()`. Use the cross-cutting `wpai_preferred_text_models` filter to override:

```php
add_filter( 'wpai_preferred_text_models', function ( array $models ): array {
    return array(
        array( 'anthropic', 'claude-sonnet-4-6' ),
        array( 'openai',    'gpt-5.4-mini' ),
    );
} );
```

### Extending to Other Block Types

The shipping experiment only adds the toolbar to `core/paragraph` (and only registers the `aiResized` attribute on that block type). To target other text-bearing blocks, fork the React entry or write a companion plugin that hooks into `editor.BlockEdit` for additional block names and calls the same `ai/content-resizing` ability — the ability itself is block-agnostic and just resizes whatever string you send in.

## Testing

### Manual Testing

1. **Enable the experiment:**
   - Go to `Settings → AI`
   - Toggle **Content Resizing** to enabled
   - Ensure you have valid AI credentials configured

2. **Test in the editor:**
   - Create or edit a post and add a paragraph block with at least one full sentence
   - Select the block; the AI dropdown appears in the block toolbar
   - Try each of **Shorten**, **Expand**, **Rephrase**
   - Verify the modal shows original vs. suggested with the correct word-delta badge
   - Click **Accept** and verify the block content updates
   - Click **Regenerate** and verify a fresh suggestion replaces the previous one
   - Add a `<a>` link or `<strong>` to the block and verify they survive a rephrase

3. **Test the 5-word floor:**
   - With a paragraph of fewer than 5 words, click **Shorten**
   - Verify a "Text is too short to shorten further" notice appears and no API call is made

4. **Test REST API:**
   - Use curl or Postman to test the REST endpoint
   - Verify authentication works
   - Test with each of the three actions
   - Verify `content_too_short` is returned for `shorten` on inputs with fewer than 5 words

## Notes & Considerations

### Requirements

- The experiment requires valid AI credentials to be configured.
- The toolbar only appears on `core/paragraph` blocks.
- Users must have `edit_post` (when invoked with a `post_id`) or `edit_posts` (without) to call the ability.

### Content Processing

- **Content is *not* normalized** before being sent to the AI — this is intentional, so inline HTML survives the round-trip.
- The system instruction tells the model to preserve any inline HTML and match the original language.
- The returned string is filtered through `wp_kses_post()` before leaving the ability.

### AI Model Selection

- The ability uses `get_preferred_models_for_text_generation()` to choose a model.
- Models are tried in order until one succeeds.
- Temperature is set to 0.7 for some natural variation across regenerations.

### System Instruction

The action-specific goals encoded in `system-instruction.php`:

- **Rephrase:** different wording, same meaning, tone, detail, and approximate length.
- **Shorten:** condense to roughly half the original length while preserving core meaning and tone.
- **Expand:** grow to roughly 1.5–2× the original length with consistent supporting detail.

All three share these requirements: return only the transformed text (no preamble), preserve inline HTML, match the original language, and maintain perspective and voice.

### Limitations

- One block per request — there is no batch endpoint.
- Suggestions are generated in real time and not cached.
- The toolbar is hard-coded to `core/paragraph`; other blocks need a custom integration (see "Extending to Other Block Types" above).
- The `aiResized` flag is purely informational — it doesn't change how the block renders on the front end.
