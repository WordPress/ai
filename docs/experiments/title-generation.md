# Title Generation

## Summary

The Title Generation experiment adds AI-assisted title suggestions to the WordPress post editor. It provides a Generate/Regenerate action near the title field and returns one suggestion at a time in a review modal before insertion. The experiment also registers a WordPress Ability (`ai/title-generation`) so the same behavior can be used programmatically through the Abilities REST API.

## Overview

### For End Users

When enabled, Title Generation helps writers quickly draft or refine post titles from the current post content.

**Key Features:**

- Generate a title suggestion for untitled drafts.
- Regenerate a new suggestion when a title already exists.
- Review and edit the suggestion in a modal before applying it.
- Insert the suggestion into the title field with one click.
- Works for post types that support titles (excluding attachments).

### For Developers

The experiment has two primary pieces:

1. **Experiment Class** (`WordPress\AI\Experiments\Title_Generation\Title_Generation`): Registers hooks, ability wiring, and editor assets.
2. **Ability Class** (`WordPress\AI\Abilities\Title_Generation\Title_Generation`): Executes title generation logic and enforces capability checks.

Developers can call the ability directly via REST API, integrate custom model preferences, and adjust system instructions.

## Architecture & Implementation

### Key Hooks and Entry Points

- `WordPress\AI\Experiments\Title_Generation\Title_Generation::register()`:
  - Hooks `wp_abilities_api_init` to register `ai/title-generation`.
  - Hooks `admin_enqueue_scripts` to load the editor integration.
- `WordPress\AI\Abilities\Title_Generation\Title_Generation`:
  - Normalizes content input.
  - Resolves optional post context when a post ID is supplied.
  - Generates a title using preferred text models.

### Editor UX Flow

1. The experiment enqueues `src/experiments/title-generation/index.tsx` on post edit screens.
2. In normal post editing mode, the plugin registers a standalone toolbar wrapper beside the title input.
3. In template/block editing mode, it injects controls into `core/post-title` block toolbar via `editor.BlockEdit`.
4. Clicking Generate/Regenerate calls `ai/title-generation` and opens a modal.
5. The modal supports:
   - Reviewing and editing the generated value.
   - Regenerating without closing the modal.
   - Inserting the final title into editor state.

### Input Schema

The ability accepts:

```php
array(
    'type'       => 'object',
    'properties' => array(
        'content' => array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'description'       => 'Content to generate title suggestions for.',
        ),
        'context' => array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'description'       => 'Additional context string or post ID (as string).',
        ),
    ),
)
```

If `context` is numeric, it is treated as a post ID and post context is resolved automatically.

### Output Schema

The ability returns:

```php
array(
    'type'       => 'object',
    'properties' => array(
        'title' => array(
            'type'        => 'string',
            'description' => 'Generated title suggestion.',
        ),
    ),
)
```

### Permissions

- If `context` is a post ID:
  - Verifies post existence.
  - Requires `current_user_can( 'edit_post', $post_id )`.
  - Requires post type visibility in REST (`show_in_rest`).
- Otherwise:
  - Requires `current_user_can( 'edit_posts' )`.

## Using the Ability via REST API

### Endpoint

```text
POST /wp-json/wp-abilities/v1/abilities/ai/title-generation/run
```

### Example Request

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/title-generation/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "content": "This post explains how to launch a local WordPress staging workflow with reproducible data and deployment previews."
    }
  }'
```

### Example Response

```json
{
  "title": "How to Build a Reliable Local WordPress Staging Workflow"
}
```

## Extending the Experiment

### Customizing the System Instruction

Edit:

```php
includes/Abilities/Title_Generation/system-instruction.php
```

### Filtering Preferred Text Models

Use `wpai_preferred_text_models` to control provider/model priority:

```php
add_filter( 'wpai_preferred_text_models', function( $models ) {
    return array(
        array( 'anthropic', 'claude-sonnet-4-6' ),
        array( 'openai', 'gpt-5.4-mini' ),
    );
} );
```

### Customizing Content Processing

You can hook content normalization before requests:

```php
add_filter( 'wpai_pre_normalize_content', function( $content ) {
    return $content;
} );
```

## Testing

### Manual Testing

1. Enable global AI Features and the **Title Generation** experiment.
2. Open a post editor screen for a post type that supports titles.
3. Click into the title field and use **Generate** (or **Regenerate**).
4. Verify modal behavior, regenerate behavior, and insertion behavior.
5. Confirm errors are surfaced when provider support is unavailable.

### Automated Testing

Relevant tests include:

- `tests/Integration/Includes/Abilities/Title_GenerationTest.php`
- `tests/Integration/Includes/Experiments/Title_Generation/Title_GenerationTest.php`
- `tests/e2e/specs/experiments/title-generation.spec.js`

## Related Files

- **Experiment:** `includes/Experiments/Title_Generation/Title_Generation.php`
- **Ability:** `includes/Abilities/Title_Generation/Title_Generation.php`
- **System Instruction:** `includes/Abilities/Title_Generation/system-instruction.php`
- **React Entry:** `src/experiments/title-generation/index.tsx`
- **React Components:** `src/experiments/title-generation/components/`
