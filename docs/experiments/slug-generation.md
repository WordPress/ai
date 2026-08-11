# Slug Generation

## Summary

The Slug Generation experiment adds AI-assisted permalink suggestions to the WordPress post editor. It surfaces a **Generate Slug** button inside the permalink popover and a **Suggested Slugs** card in the pre-publish panel, both backed by the `ai/slug-generation` Ability, which can also be called directly over REST.

## Overview

### For End Users

When enabled, the experiment adds two entry points for permalink suggestions:

1. **Permalink popover** — open the URL / permalink field in the post settings sidebar and click **Generate Slug** (**Regenerate Slug** once a slug is set). A modal opens with suggestions that can be previewed, edited, regenerated, and inserted.
2. **Pre-publish panel** — clicking **Publish** reveals a **Suggested Slugs** card where suggestions can be generated and applied without leaving the publish flow.

**Key Features:**

- Suggestions derived from the post title, content, and post context (terms, post type)
- Multiple suggestions per request (3 by default, configurable from 1 to 10)
- Every suggestion is sanitized with `sanitize_title()` so it is always a valid WordPress slug
- Suggestions are checked against existing content, so a suggestion that is already taken comes back with a numeric suffix
- Hand-editable before insertion; edits are normalized with `cleanForSlug()`
- Gated behind a minimum content length (250 characters by default) so the model has something to work with
- Language-aware — slugs use the language of the supplied title/content

### For Developers

The experiment consists of two main components:

1. **Experiment Class** (`WordPress\AI\Experiments\Slug_Generation\Slug_Generation`): registration, asset enqueuing, and localized configuration.
2. **Ability Class** (`WordPress\AI\Abilities\Slug_Generation\Slug_Generation`): prompt construction, model invocation, and slug parsing/sanitization via the WordPress Abilities API.

The ability can be called directly via REST API for automation, bulk back-fills, or custom UI integrations.

## Architecture & Implementation

### Key Hooks & Entry Points

`register()` adds two hooks:

- `wp_abilities_api_init` → `register_abilities()`, which registers `ai/slug-generation`.
- `admin_enqueue_scripts` → `enqueue_assets()`, which loads the editor bundle.

Assets are only enqueued on `post.php` and `post-new.php`, and only when the current screen's post type supports `title` and is not `attachment`. Because `Loader` skips `register()` for disabled features, none of this runs unless the experiment is enabled.

### Assets & Data Flow

The JS entry point is `src/experiments/slug-generation/index.tsx`, built to `experiments/slug-generation`. It registers a `PluginPrePublishPanel` fill and attaches the **Generate Slug** button to the permalink panel.

Because the permalink panel renders inside a `Dropdown` popover that is created and destroyed on demand, the button is mounted into its own React root inside `.editor-post-url`. A `MutationObserver` watches for that panel appearing and disappearing. The observer is attached at the document level on purpose: when the editor does not render a `Popover.Slot`, `Popover` portals into a container appended directly to `document.body`, so a narrower observation root would miss the panel. Only element nodes that are — or contain — the permalink panel are considered relevant, and matches are debounced.

The button dispatches an `ai-trigger-slug-generation` window event carrying the post ID, title, and content; the plugin component listens for it, opens the modal, and calls the ability through `runAbility()`. Both entry points share `hooks/useSlugGeneration.ts`.

Localized configuration is exposed as `window.aiSlugGenerationData`:

| Key | Source | Default |
| --- | --- | --- |
| `enabled` | `$this->is_enabled()` | — |
| `minContentLength` | `get_min_content_length( 'slug-generation', 250 )` | `250` |
| `numberOfSuggestions` | `wpai_slug_generation_number_of_suggestions` filter, clamped to 1–10 | `3` |

### Input Schema

```php
array(
    'type'       => 'object',
    'properties' => array(
        'title'                 => array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'description'       => 'Title to generate slug suggestions for.',
        ),
        'content'               => array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'description'       => 'Content to generate slug suggestions for.',
        ),
        'context'               => array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'description'       => 'Additional context. Either a string of additional context, or a post ID (as a string) — when a numeric ID is supplied, the post title, content, and terms are fetched and used.',
        ),
        'number_of_suggestions' => array(
            'type'              => 'integer',
            'minimum'           => 1,
            'maximum'           => 10,
            'sanitize_callback' => 'absint',
            'default'           => 3,
            'description'       => 'Number of slug suggestions to return.',
        ),
    ),
)
```

Explicit `title` and `content` values take precedence over the values fetched from a numeric `context`, so the editor can send unsaved changes.

### Output Schema

```php
array(
    'type'       => 'object',
    'properties' => array(
        'slugs' => array(
            'type'        => 'array',
            'items'       => array( 'type' => 'string' ),
            'description' => 'Generated slug suggestions.',
        ),
    ),
)
```

### Permissions

- **If `context` is a numeric post ID:**
  - Verifies the post exists; returns `post_not_found` otherwise.
  - Checks `current_user_can( 'edit_post', $post_id )`.
  - Verifies the post type has `show_in_rest` enabled — otherwise the callback returns `false`.

- **If `context` is not a post ID:**
  - Checks `current_user_can( 'edit_posts' )`.

### Slug Uniqueness

Each suggestion is sanitized with `sanitize_title()` (underscores are converted to hyphens first), then made unique against existing content with `wp_unique_post_slug()` when a post is in context.

`wp_unique_post_slug()` returns the slug untouched for `draft`, `pending`, and `auto-draft` posts — which is the state a post is normally in while it is being edited. The ability therefore maps those statuses to `publish` before the uniqueness check, so a suggestion reflects the slug the post would actually receive once published. When no post is in context there is no post type or parent to resolve uniqueness against, so the sanitized suggestion is returned as-is.

Duplicate suggestions are collapsed both before the uniqueness lookup (so repeats from the model don't cost a database query) and after it (so two suggestions that resolve to the same unique slug don't both surface).

Note that this is a preview: WordPress enforces slug uniqueness again at save time, and a slug that was free during generation may have been taken by then.

## Using the Ability via REST API

### Endpoint

```text
POST /wp-json/wp-abilities/v1/abilities/ai/slug-generation/run
```

### Authentication

You can authenticate using either:

1. **Application Password** (Recommended)
2. **Cookie Authentication with Nonce**

See [TESTING_REST_API.md](../TESTING_REST_API.md) for detailed authentication instructions.

### Request Examples

#### Example 1: Generate slugs from a post ID

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/slug-generation/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "context": "123"
    }
  }'
```

**Response:**

```json
{
  "slugs": [
    "renewable-energy-modern-grid",
    "grid-scale-renewables-explained",
    "how-renewables-reshape-the-grid"
  ]
}
```

#### Example 2: Generate slugs from a title and content

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/slug-generation/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "title": "Async Decision Making for Distributed Teams",
      "content": "This article walks through how distributed teams can adopt asynchronous decision-making practices to reduce meeting load while preserving alignment.",
      "number_of_suggestions": 5
    }
  }'
```

#### Example 3: Using the JS helper inside the editor

```ts
import { runAbility } from '../../utils/run-ability';

const { slugs } = await runAbility< { slugs: string[] } >(
    'ai/slug-generation',
    {
        title: editedTitle,
        content: editedContent,
        context: String( postId ),
        number_of_suggestions: 3,
    }
);
```

### Error Responses

- `post_not_found` — `context` was a numeric post ID but no such post exists.
- `insufficient_data` — Neither a title nor content was available to generate from.
- `insufficient_capabilities` — Caller lacks `edit_post` (with a post ID) or `edit_posts` (without).
- `no_results` — The model returned nothing, or nothing that survived sanitization.
- A `WP_Error` from `ensure_text_generation_supported()` if no connected provider supports text generation.

Example:

```json
{
  "code": "insufficient_data",
  "message": "Post title or content is required to generate slug suggestions.",
  "data": { "status": 400 }
}
```

## Extending

### Changing the Number of Suggestions

The editor UI reads its default from a filter, clamped to 1–10:

```php
add_filter( 'wpai_slug_generation_number_of_suggestions', function (): int {
    return 5;
} );
```

REST callers can pass `number_of_suggestions` per request instead.

### Adjusting the Minimum Content Length

Both entry points are disabled until the post content reaches `minContentLength` characters (excluding spaces):

```php
add_filter( 'wpai_min_content_length', function ( int $length, string $feature_id ): int {
    return 'slug-generation' === $feature_id ? 100 : $length;
}, 10, 2 );
```

### Customizing the System Instruction

Edit `includes/Abilities/Slug_Generation/system-instruction.php`, or filter it per site:

```php
add_filter( 'wpai_system_instruction', function ( string $instruction, string $name ): string {
    if ( 'ai/slug-generation' !== $name ) {
        return $instruction;
    }
    return $instruction . "\nPrefer slugs of no more than four words.";
}, 10, 2 );
```

### Filtering the Prompt Builder

The ability calls `filter_prompt_builder()` with the experiment class, exposing:

```php
add_filter( 'wpai_slug_generation_prompt_builder', function ( $prompt_builder ) {
    return $prompt_builder->using_temperature( 0.2 );
} );
```

### Filtering Preferred Models

The ability resolves a model through the shared `wpai_preferred_text_models` filter:

```php
add_filter( 'wpai_preferred_text_models', function ( array $models ): array {
    return array(
        array( 'anthropic', 'claude-sonnet-4-6' ),
        array( 'openai',    'gpt-5.4-mini' ),
    );
} );
```

## Testing

### Manual Testing

1. **Enable the experiment:**
   - Go to `Settings → AI`
   - Toggle **Slug Generation** to enabled
   - Ensure an AI Connector with text generation support is configured

2. **Test the permalink popover:**
   - Create a post with a title and at least 250 characters of content, then save a draft
   - Open the URL / permalink field in the post settings sidebar
   - Click **Generate Slug**; verify the popover closes and the modal opens with suggestions
   - Edit a suggestion in **Selected slug**, click **Insert**, and verify the permalink updates
   - Reopen the popover and verify the button now reads **Regenerate Slug**

3. **Test the pre-publish panel:**
   - Click **Publish** and expand **Suggested Slugs**
   - Generate, select, and **Apply** a suggestion; verify the permalink updates
   - Verify **Apply** becomes **Applied** and is disabled once the selected slug matches the current one

4. **Test the content-length gate:**
   - With a post under 250 characters, verify the button is visible but disabled, with a tooltip explaining the minimum
   - Verify the pre-publish panel shows the same explanation instead of the generate controls

5. **Test slug uniqueness:**
   - Publish a post with a known slug, then draft a second post whose content would produce the same slug
   - Verify the suggestion comes back suffixed (e.g. `my-slug-2`) rather than colliding

6. **Test without a connector:**
   - Disable all connectors and click **Generate Slug**
   - Verify an error notice appears with a link to manage connectors, and the modal does not stay open

7. **Test REST API:**
   - Call the endpoint with a post ID, with freeform title/content, and with an invalid post ID
   - Verify `post_not_found` and `insufficient_data` error handling

## Notes & Considerations

### Requirements

- Requires an AI Connector that supports text generation.
- Only runs on `post.php` and `post-new.php`, for post types that support `title` and are not attachments.
- Users need `edit_post` (with a post ID) or `edit_posts` (without).

### Limitations

- The **Generate Slug** button is attached by DOM injection and assumes the standard `.editor-post-url` markup; heavily customized editors may need additional selectors.
- The modal is given an explicit `z-index` so it renders above the permalink popover, which relies on the popover's current stacking values.
- Suggestions are generated in real time and are not cached.
