# Title Generation

## Summary

The Title Generation experiment adds AI-powered title suggestions to the WordPress post editor. It exposes a **Generate** / **Regenerate** button next to the post title and opens a modal where the suggestion can be edited before insertion. The experiment registers a WordPress Ability (`ai/title-generation`) that can be called both through the in-editor UI and directly via REST API. It is also the first built-in ability that opts into the editorial **Guidelines** service (`site`, `copy` categories), so per-site tone and brand guidance is automatically injected into every title prompt.

## Overview

### For End Users

When enabled, the Title Generation experiment surfaces a button next to the post title field that reads **Generate** when the title is empty and **Regenerate** when it already has text. Clicking it sends the post content to the AI and opens a modal showing the suggestion in an editable text area. Users can:

- **Edit** the suggested title inline before inserting.
- **Regenerate** to replace the current suggestion with a new one (without closing the modal).
- **Insert** to write the suggestion into the post title field.

**Key Features:**

- One-click title generation from the current post content
- Suggestions are constrained to ≤ 80 characters, plain text, no markdown or quotes
- Editable suggestion before insertion
- Regenerate without losing the modal context
- Works for any post type that supports titles
- Integrates with site Editorial Guidelines (`site`, `copy` categories) when configured

### For Developers

The experiment consists of two main components:

1. **Experiment Class** (`WordPress\AI\Experiments\Title_Generation\Title_Generation`): handles registration, asset enqueuing, and UI integration.
2. **Ability Class** (`WordPress\AI\Abilities\Title_Generation\Title_Generation`): implements the title generation logic via the WordPress Abilities API.

The ability can be called directly via REST API for automation, bulk back-fills, or custom UI integrations.

## Architecture & Implementation

### Key Hooks & Entry Points

- `WordPress\AI\Experiments\Title_Generation\Title_Generation::register()` wires everything once the experiment is enabled:
  - `wp_abilities_api_init` → registers the `ai/title-generation` ability (`includes/Abilities/Title_Generation/Title_Generation.php`)
  - `admin_enqueue_scripts` → enqueues the React bundle and stylesheet on `post.php` and `post-new.php` screens, but only when the post type supports `title` and is not an attachment

### Assets & Data Flow

1. **PHP Side:**
   - `enqueue_assets()` loads `experiments/title-generation` (`src/experiments/title-generation/index.tsx`) and localizes `window.aiTitleGenerationData` with:
     - `enabled`: Whether the experiment is enabled

2. **React Side:** The experiment supports two editor modes, registered as a single bundle:
   - **Block mode** (template editor / site editor / any context where the title is a `core/post-title` block): an `editor.BlockEdit` filter wraps the block to add a `BlockControls` toolbar containing `<TitleToolbar />`.
   - **Normal editing mode** (classic post editor where the title is an `<input>`): the bundle also calls `registerPlugin( 'ai-title-generation-normal-mode', { render: TitleToolbarWrapper } )`. `TitleToolbarWrapper` uses a `MutationObserver` to find `.editor-post-title__input` inside the editor iframe, wraps it, and renders a floating toolbar via `createRoot`. The toolbar is shown on focus and hidden on blur.
   - Both modes render the same `TitleToolbar` component (`src/experiments/title-generation/components/TitleToolbar.tsx`), which is gated on `aiTitleGenerationData.enabled` and additionally wrapped in `<PostTypeSupportCheck supportKeys="title">` so it self-disables for post types without title support.

3. **Ability call:**
   - The toolbar reads the current post ID and content from `editorStore`, then calls `runAbility< { title: string } >( 'ai/title-generation', { context: String( postId ), content } )`.
   - The button label flips between **Generate**, **Regenerate**, and **Generating…** based on whether a title already exists and whether a request is in flight.
   - Errors are surfaced as a dismissible notice with the ID `ai_title_generation_error`.

4. **Ability Execution:**
   - Accepts `content` (string) and `context` (string or post ID as a string).
   - If `context` is numeric, treats it as a post ID and fetches the rest of the post context via `get_post_context()`. If `content` is also passed, the passed content takes precedence (normalized via `normalize_content()`); the post-derived context (title, taxonomies) is still appended as `<additional-context>`.
   - If `context` is non-numeric, treats it as freeform text and appends it as `<additional-context>`.
   - Wraps the content in `<content>...</content>`, sends to the AI client at temperature 0.7, with the system instruction (which has `<site-context>` and `<copy-guidelines>` blocks prepended automatically when the Editorial Guidelines service is configured).
   - The returned text is trimmed of surrounding spaces and quotes, run through `sanitize_text_field()`, and wrapped as `{ title: string }`.

### Input Schema

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
            'description'       => 'Additional context. Either a string of additional context, or a post ID (as a string) — when a numeric ID is supplied, the post content is fetched and used.',
        ),
    ),
)
```

### Output Schema

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

- **If `context` is a numeric post ID:**
  - Verifies the post exists; returns `post_not_found` otherwise.
  - Checks `current_user_can( 'edit_post', $post_id )`.
  - Verifies the post type has `show_in_rest` enabled — otherwise the callback returns `false`.

- **If `context` is not a post ID:**
  - Checks `current_user_can( 'edit_posts' )`.

### Editorial Guidelines integration

`Title_Generation` declares:

```php
protected function guideline_categories(): array {
    return array( 'site', 'copy' );
}
```

When the `wp_guideline` CPT is registered (Gutenberg ≥ 23.0) and the corresponding `_guideline_site` / `_guideline_copy` post-meta values are set, `Abstract_Ability::get_system_instruction()` automatically prepends them to the title-generation system prompt as `<guidelines><site-context>...</site-context><copy-guidelines>...</copy-guidelines></guidelines>`. See [INTEGRATION_GUIDE §4.5](../INTEGRATION_GUIDE.md#45-editorial-guidelines) for the full Guidelines surface.

## Using the Ability via REST API

### Endpoint

```text
POST /wp-json/wp-abilities/v1/abilities/ai/title-generation/run
```

### Authentication

You can authenticate using either:

1. **Application Password** (Recommended)
2. **Cookie Authentication with Nonce**

See [TESTING_REST_API.md](../TESTING_REST_API.md) for detailed authentication instructions.

### Request Examples

#### Example 1: Generate a title from a post ID

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/title-generation/run" \
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
{ "title": "How Renewable Energy Is Reshaping the Modern Grid" }
```

#### Example 2: Generate a title from a content string

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/title-generation/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "content": "This article walks through how distributed teams can adopt asynchronous decision-making practices to reduce meeting load while preserving alignment."
    }
  }'
```

#### Example 3: Generate a title with extra hint context

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/title-generation/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "content": "Detailed walkthrough of the new caching layer.",
      "context": "Audience: senior backend engineers; tone: technical, no marketing fluff."
    }
  }'
```

#### Example 4: Using the JS helper inside the editor

```ts
import { runAbility } from '../../utils/run-ability';

const { title } = await runAbility< { title: string } >(
    'ai/title-generation',
    {
        context: String( postId ),
        content: editedContent,
    }
);
```

### Error Responses

- `post_not_found` — `context` was a numeric post ID but no such post exists.
- `content_not_provided` — Neither `content` nor a usable post `context` produced any text.
- `insufficient_capabilities` — Caller lacks `edit_post` (with a post ID) or `edit_posts` (without).
- `no_results` — The AI client did not return any text.
- A WP_Error from `ensure_text_generation_supported()` if no connected provider supports text generation.

Example:

```json
{
  "code": "content_not_provided",
  "message": "Content is required to generate title suggestions.",
  "data": { "status": 400 }
}
```

## Extending the Experiment

### Customizing the System Instruction

Edit `includes/Abilities/Title_Generation/system-instruction.php` to change the length cap, tone constraints, or output requirements.

For per-site tweaks without forking, register a `wpai_system_instruction` filter:

```php
add_filter( 'wpai_system_instruction', function ( string $instruction, string $name ): string {
    if ( 'ai/title-generation' !== $name ) {
        return $instruction;
    }
    return $instruction . "\nAvoid all-caps and exclamation marks.";
}, 10, 2 );
```

### Adjusting Editorial Guidelines

Because the ability declares `guideline_categories(): ['site', 'copy']`, populating the `_guideline_site` and `_guideline_copy` post-meta on the latest `wp_guideline` post is enough to reshape every title generation prompt site-wide. Use `wpai_max_guideline_length` to cap how much of each category gets injected (default 5000 characters), and `wpai_use_guidelines` (`__return_false`) to disable injection on staging.

### Filtering Preferred Models

The ability uses `WordPress\AI\get_preferred_models_for_text_generation()`. Override the cross-cutting `wpai_preferred_text_models` filter:

```php
add_filter( 'wpai_preferred_text_models', function ( array $models ): array {
    return array(
        array( 'anthropic', 'claude-sonnet-4-6' ),
        array( 'openai',    'gpt-5.4-mini' ),
    );
} );
```

### Customizing Content Normalization

Content passed to the ability is run through `normalize_content()` (HTML stripped, shortcodes removed, etc.). Filter the result:

```php
add_filter( 'wpai_pre_normalize_content', function ( string $content ): string {
    // Pre-normalization tweak.
    return $content;
} );

add_filter( 'wpai_normalize_content', function ( string $content ): string {
    // Post-normalization tweak.
    return $content;
} );
```

### Adjusting Post Context

When the caller supplies a post ID, `get_post_context()` (`includes/helpers.php`) gathers post details and terms via the `ai/get-post-details` and `ai/get-post-terms` utility abilities. To shape that context, hook the corresponding filters:

```php
add_filter( 'wpai_get_post_details', function ( array $details, int $post_id, array $fields ): array {
    // Drop the slug, append computed reading time, etc.
    return $details;
}, 10, 3 );
```

## Testing

### Manual Testing

1. **Enable the experiment:**
   - Go to `Settings → AI`
   - Toggle **Title Generation** to enabled
   - Ensure you have valid AI credentials configured

2. **Test in normal editing mode (classic post editor):**
   - Create or edit a post; focus the title input
   - Verify the floating toolbar appears above the title field
   - With an empty title, click the button — it should read **Generate**
   - Verify the modal opens with a suggestion in an editable text area
   - Edit the suggestion, click **Insert**, verify the title updates
   - With a non-empty title, focus the title and click again — the button should read **Regenerate**
   - Open the modal and click **Regenerate** inside it — verify a new suggestion appears without closing the modal

3. **Test in block mode (template/site editor):**
   - Edit a template that contains a `core/post-title` block
   - Select the block and verify the toolbar appears in `BlockControls`
   - Generate, edit, and insert a suggestion as above

4. **Test with a non-title post type:**
   - Edit a post type that does not declare `title` support
   - Verify no toolbar / button appears (the asset enqueue is skipped)

5. **Test guideline injection:**
   - With a populated `wp_guideline` post (`_guideline_site`, `_guideline_copy`), verify generated titles reflect the configured tone
   - Set `add_filter( 'wpai_use_guidelines', '__return_false' )` and re-test — guidelines should no longer affect output

6. **Test REST API:**
   - Use curl or Postman to test the REST endpoint
   - Verify authentication works
   - Test with a valid post ID, with freeform content, and with both
   - Verify error handling for invalid inputs (`post_not_found`, `content_not_provided`)

### Automated Testing

Tests are located in:

- `tests/Integration/Includes/Abilities/Title_GenerationTest.php`
- `tests/Integration/Includes/Experiments/Title_Generation/Title_GenerationTest.php`

Run tests with:

```bash
npm run test:php
```

## Notes & Considerations

### Requirements

- Requires valid AI credentials.
- Only runs on `post.php` and `post-new.php` admin screens.
- Only attaches when the post type supports `title` and is not an attachment.
- Users must have `edit_post` (with a `post_id` context) or `edit_posts` (without).

### Content Processing

- Content is normalized before being sent to the AI (HTML stripped, shortcodes removed).
- When a post ID is supplied as `context`, the post's title and assigned terms are appended as `<additional-context>` to help the model produce a more relevant suggestion.

### AI Model Selection

- The ability uses `get_preferred_models_for_text_generation()` to pick a model.
- Models are tried in order until one succeeds.
- Temperature is set to 0.7 for natural variation across regenerations.

### System Instruction

The system instruction guides the model to:

- Produce titles ≤ 80 characters.
- Output plain text only — no markdown, bullets, numbering, quotes, or code fences.
- Match the language of the input.
- Reflect the actual content rather than producing generic clickbait.
- Respond with **only** the title — no preamble, no closing remarks.

When the Editorial Guidelines service is configured, `<site-context>` and `<copy-guidelines>` blocks are prepended via `Abstract_Ability::get_system_instruction()`.

### Limitations

- One title per request — no batch endpoint.
- The `TitleToolbarWrapper` reaches into the editor iframe via DOM querying and a `MutationObserver`. It is resilient to late-loading editors but assumes the standard `editor-canvas` / `wp-block-editor-iframe__iframe` markup; heavily customized editors may need additional selectors.
- Suggestions are generated in real time and not cached.
- The output of the ability is sanitized with `sanitize_text_field()` and stripped of surrounding `"`/`'` characters; titles that legitimately need leading/trailing quotes will lose them.

## Related Files

- **Experiment:** `includes/Experiments/Title_Generation/Title_Generation.php`
- **Ability:** `includes/Abilities/Title_Generation/Title_Generation.php`
- **System Instruction:** `includes/Abilities/Title_Generation/system-instruction.php`
- **React Entry:** `src/experiments/title-generation/index.tsx`
- **React Components:** `src/experiments/title-generation/components/TitleToolbar.tsx`, `src/experiments/title-generation/components/TitleToolbarWrapper.tsx`
- **Styles:** `src/experiments/title-generation/index.scss`
- **Types:** `src/experiments/title-generation/types.ts`
- **Tests:** `tests/Integration/Includes/Abilities/Title_GenerationTest.php`
- **Tests:** `tests/Integration/Includes/Experiments/Title_Generation/Title_GenerationTest.php`
