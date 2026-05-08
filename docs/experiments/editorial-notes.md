# AI Editorial Notes

## Summary

The AI Editorial Notes experiment adds a block-by-block AI editorial review to the WordPress post editor. Clicking "Generate Editorial Notes" in the post sidebar triggers the AI to examine each reviewable block and create WordPress Notes directly on the relevant blocks with concise, actionable suggestions across four categories: **Accessibility**, **Readability**, **Grammar**, and **SEO**.

## Overview

### For End Users

When enabled, a "Generate Editorial Notes" button appears in the post status info panel (the sidebar area below the post status). Clicking it triggers a review pass:

1. The button label updates to show review progress (`Reviewing blocks… (2 of 8)`)
2. Each content block is sent individually to the AI for analysis
3. Notes with suggestions appear directly on the blocks inside the Notes panel
4. After completion, a count of new suggestions is shown beneath the button

**Key Features:**

- Block-level Notes with suggestions scoped to each block's content and type
- Four review categories: Accessibility, Readability, Grammar, SEO
- Accumulating history: subsequent review runs append replies to existing Note threads rather than creating duplicate threads
- Prior suggestions are sent back to the AI as context so it avoids repeating itself
- Blocks whose Note thread has been resolved (marked as approved) are skipped on re-run
- Works with common block types: paragraphs, headings, images, lists, tables, quotes, and more

### For Developers

The experiment consists of:

1. **Experiment Class** (`WordPress\AI\Experiments\Editorial_Notes\Editorial_Notes`): Registers the ability, enqueues the block editor asset, and wires server-side hooks for Note author override and block metadata cleanup
2. **Ability Class** (`WordPress\AI\Abilities\Editorial_Notes\Editorial_Notes`): Receives a single block's content and returns structured JSON suggestions
3. **React Plugin** (`src/experiments/editorial-notes/`): Drives the UI and orchestrates block traversal, Note creation, and thread management via WordPress data stores

## Architecture & Implementation

### Key Hooks & Entry Points

`WordPress\AI\Experiments\Editorial_Notes\Editorial_Notes::register()` wires everything once the experiment is enabled:

- `wp_abilities_api_init` → registers the `ai/editorial-notes` ability
- `enqueue_block_editor_assets` → enqueues the React bundle whenever the block editor loads
- `rest_pre_insert_comment` (filter) → `maybe_set_ai_author()` — overrides the comment author to "WordPress AI" when `meta.ai_note` is `true`, so AI-generated Notes are not attributed to the authenticated user's account

### Assets & Data Flow

1. **PHP Side:**
   - `enqueue_assets()` loads `experiments/editorial-notes` and localizes `window.aiEditorialNotesData`:
     - `enabled`: Whether the experiment is currently enabled

2. **React Side:**
   - `index.tsx` registers the `ai-editorial-notes` plugin
   - `EditorialNotesPlugin.tsx` renders the button inside `PluginPostStatusInfo`
   - `useEditorialNotes.ts` hook manages all state and orchestration:
     - Flattens the block tree to get all descendants
     - Filters to reviewable block types with sufficient content (≥ 20 chars), capped at 25 blocks
     - Fetches Notes in two parallel requests:
       - `GET /wp/v2/comments?type=note&status=hold&post=<id>&per_page=100` — pending Notes used as context to avoid repeating suggestions
       - `GET /wp/v2/comments?type=note&status=approve&post=<id>&per_page=100` — resolved Note IDs; blocks with a resolved Note are skipped entirely
     - Processes blocks in parallel batches of 4, calling the ability for each
     - Creates new Note threads via `POST /wp/v2/comments` (with `meta: { ai_note: true }` to trigger the AI author override) and updates block `metadata.noteId`
     - Subsequent runs append replies to existing Note threads

3. **Ability Execution:**
   - Receives one block's content at a time (block type, plain text, post context, prior Notes, review types)
   - Builds a structured prompt and sends it to the AI with the system instruction and a JSON schema for structured output
   - Parses the JSON response, sanitizes each suggestion, and returns `{ suggestions: [...] }`
   - Returns `{ suggestions: [] }` when the AI finds no issues
   - Deduplicates against `existing_notes`: if a Note already contains a `[TYPE]` marker for a given review type, that type is skipped in the current run

### Block Types Reviewed

```typescript
const REVIEWABLE_BLOCK_TYPES = [
  'core/paragraph',
  'core/heading',
  'core/list',
  'core/list-item',
  'core/quote',
  'core/verse',
  'core/image',
  'core/table',
  'core/preformatted',
  'core/pullquote',
];
```

Blocks with fewer than 20 characters of text content are skipped. The review is capped at 25 blocks per run to control cost.

### Input Schema

```php
array(
    'block_type'     => array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'description'       => 'The block type, e.g. core/paragraph, core/heading.',
    ),
    'block_content'  => array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'description'       => 'The plain-text content of the block to review.',
    ),
    'context'        => array(
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'description'       => 'Surrounding content to improve review relevance.',
    ),
    'post_id'        => array(
        'type'              => 'integer',
        'sanitize_callback' => 'absint',
        'description' => 'ID of the post being reviewed.',
    ),
    'existing_notes' => array(
        'type'        => 'array',
        'items'       => array( 'type' => 'string' ),
        'description' => 'Existing Note texts for this block from prior review runs, used to avoid repeating suggestions.',
    ),
    'review_types'   => array(
        'type'        => 'array',
        'items'       => array( 'type' => 'string', 'enum' => array( 'accessibility', 'readability', 'grammar', 'seo' ) ),
        'description' => 'Review types to perform.',
    ),
)
```

### Output Schema

```php
array(
    'type'       => 'object',
    'properties' => array(
        'suggestions' => array(
            'type'  => 'array',
            'items' => array(
                'type'       => 'object',
                'properties' => array(
                    'review_type' => array( 'type' => 'string' ),
                    'text'        => array( 'type' => 'string' ),
                ),
            ),
        ),
    ),
)
```

### Permissions

The ability's `permission_callback` has two paths:

- **With a numeric `post_id` (post ID):** Validates that the post exists, the current user has `edit_post` capability for that specific post, and the post type is registered with `show_in_rest => true`. Returns `false` if the post type is not REST-accessible.
- **Without a post ID:** Requires `current_user_can( 'edit_posts' )`.

In both cases, users without the required capability receive an `insufficient_capabilities` WP_Error.

### Note Association

Notes are `WP_Comment` objects with `comment_type = 'note'` and `status = 'hold'`. Block association is maintained via block metadata:

- **New thread**: `POST /wp/v2/comments` with `parent: 0` → response `id` stored in `block.attributes.metadata.noteId` via `updateBlockAttributes`
- **Reply**: `POST /wp/v2/comments` with `parent: existingNoteId` → block metadata unchanged (association already set)
- **AI author**: All Notes created by this experiment include `meta: { ai_note: true }`. The `rest_pre_insert_comment` filter intercepts this and sets the author to "WordPress AI" with no email, URL, or user ID, so Notes are not attributed to the authenticated user's account.
- **Resolved Notes**: Notes with `status = 'approve'` (resolved) cause their associated block to be skipped entirely on the next review run.

## Using the Ability via REST API

### Endpoint

```text
POST /wp-json/wp-abilities/v1/abilities/ai/editorial-notes/run
```

### Authentication

See [TESTING_REST_API.md](../TESTING_REST_API.md) for authentication details (application passwords or cookie + nonce).

### Request Examples

#### Review a Paragraph Block

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/editorial-notes/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "block_type": "core/paragraph",
      "block_content": "The committee was formed by the director in order to study the problem and make recommendations.",
      "review_types": ["readability", "grammar"],
      "existing_notes": [],
      "post_id": 42
    }
  }'
```

**Response:**

```json
{
  "suggestions": [
    {
      "review_type": "readability",
      "text": "Rewrite in active voice: \"The director formed a committee to study the problem and make recommendations.\""
    }
  ]
}
```

#### Review an Image Block (Accessibility Only)

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/editorial-notes/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "block_type": "core/image",
      "block_content": "",
      "review_types": ["accessibility"],
      "existing_notes": []
    }
  }'
```

**Response (missing alt text):**

```json
{
  "suggestions": [
    {
      "review_type": "accessibility",
      "text": "Add descriptive alt text to this image so screen reader users understand its content."
    }
  ]
}
```

#### Using WordPress API Fetch (in Gutenberg)

```javascript
import apiFetch from '@wordpress/api-fetch';

async function reviewBlock( blockType, blockContent, existingNotes = [] ) {
  const result = await apiFetch( {
    path: '/wp-abilities/v1/abilities/ai/editorial-notes/run',
    method: 'POST',
    data: {
      input: {
        block_type: blockType,
        block_content: blockContent,
        review_types: [ 'accessibility', 'readability', 'grammar', 'seo' ],
        existing_notes: existingNotes,
        context: String( postId ), // numeric post ID as string
      },
    },
  } );

  return result.suggestions; // Array of { review_type, text }
}
```

### Error Responses

| Code | Meaning |
|---|---|
| `block_content_required` | `block_content` was empty |
| `post_not_found` | The post ID passed does not exist |
| `insufficient_capabilities` | User lacks `edit_posts` (or `edit_post` for the specific post) |

## Extending the Experiment

### Customizing the System Instruction

Edit `includes/Abilities/Editorial_Notes/system-instruction.php` to adjust:

- Which review types apply to which block types
- How strictly prior suggestions are de-duplicated

### Filtering Preferred Models

```php
add_filter( 'wpai_preferred_text_models', function( $models ) {
    return array(
        array( 'openai', 'gpt-4o' ),
        array( 'openai', 'gpt-4o-mini' ),
    );
} );
```

### Disabling the Experiment Programmatically

```php
add_filter( 'wpai_feature_editorial-notes_enabled', '__return_false' );
```

### Adding Custom Review Types

The `review_types` input field accepts any string values. Pass additional type names from the JS side and update the system instruction to provide guidance for those types:

```javascript
// In your custom JS
await runAbility( 'ai/editorial-notes', {
  block_type: 'core/paragraph',
  block_content: '...',
  review_types: [ 'accessibility', 'readability', 'grammar', 'seo', 'tone' ],
  existing_notes: [],
} );
```

Then add guidance for the `tone` type to `system-instruction.php`.

## Testing

### Manual Testing Steps

1. **Enable the experiment:**
   - Go to `Settings → AI`
   - Enable the global toggle
   - Enable **AI Editorial Notes**
   - Ensure valid AI credentials are configured

2. **Run a review:**
   - Create or open a post with a mix of block types (headings, paragraphs, an image without alt text, a list)
   - Open the post sidebar (click the **Settings** button in the toolbar)
   - Click **Generate Editorial Notes** in the post info panel
   - Watch the progress counter advance (`Reviewing blocks… 2 of 8`)
   - After completion, open the **Notes** panel (via the block toolbar or the comments icon)
   - Verify Notes appear on relevant blocks, formatted as `[REVIEW_TYPE] Suggestion text.`
   - Verify Notes show "WordPress AI" as the author rather than your account name

3. **Re-run accumulation:**
   - Click **Generate Editorial Notes** a second time
   - Verify existing Note threads gain replies rather than new top-level Notes
   - Verify prior suggestions are not repeated

4. **Resolved Notes:**
   - Mark a Note as resolved in the Notes panel
   - Run the review again
   - Verify the resolved block is skipped entirely

5. **Note deletion cleanup:**
   - Delete a Note from the Notes panel
   - Save the post
   - Verify the deleted block no longer has a `noteId` in its block metadata (inspect via the Code Editor)

6. **Edge cases:**
   - Post with only very short blocks → button completes instantly with "No new suggestions found."
   - All blocks already have Notes → second run skips repeats
   - Disable experiment → button disappears from sidebar

### Automated Testing

**PHPUnit integration tests:**

```bash
npm run test:php
```

Test files:
- `tests/Integration/Includes/Abilities/Editorial_NotesTest.php` — Ability class tests
- `tests/Integration/Includes/Experiments/Editorial_Notes/Editorial_NotesTest.php` — Experiment class tests

Covers:
- Input/output schema structure
- `suggestions_schema()` OpenAI wrapper structure (name, strict, schema keys; inner type must be object)
- Empty content validation
- Mock-based suggestion return and structure
- Content sanitization
- Permission callbacks: no post ID path (editor, subscriber, logged-out), and post-specific path (valid post, missing post, insufficient edit_post, non-REST post type)
- `execute_callback` with missing post ID → WP_Error
- `get_existing_review_types_from_notes()`: type extraction, case normalisation, multiple types per Note, Notes without brackets
- Experiment hook registration (rest_pre_insert_comment)
- `ai_note` comment meta registered with `show_in_rest`
- `maybe_set_ai_author()`: overrides author when `ai_note` is true, passes through otherwise, handles WP_Error

**Playwright E2E tests:**

```bash
npm run wp-env:test start # Start the test environment
npm run test:e2e -- --grep "AI Editorial Notes"
```

Test file: `tests/e2e/editorial-notes.spec.ts`

Covers:
- Button visibility in editor sidebar
- Button busy/disabled state during review
- Suggestion count feedback after completion
- Empty result handling
- Button hidden when experiment is disabled
- No-op when post has no reviewable blocks

## Notes & Considerations

### Requirements

- WordPress 6.9+ (Notes feature required for block-level comment association)
- Valid AI credentials configured in `Settings → Connectors`
- User must have `edit_posts` capability (or `edit_post` for the specific post when a post ID is provided)
- The block editor must be active (classic editor is not supported)

### Performance

- Each block generates one API call; blocks are processed in parallel batches of 4
- The review is capped at 25 blocks per run to control cost
- Blocks with fewer than 20 characters of text are skipped
- AI temperature is set to 0.7

### Note Storage

- Notes are stored as WordPress comments with `comment_type = 'note'` and `comment_author = 'WordPress AI'`
- Block association is stored in `block.attributes.metadata.noteId`
- Block metadata is saved as part of the post content when the editor saves
- Note threads accumulate across review runs by design
- Deleting or trashing a root Note automatically clears `metadata.noteId` from its associated block

### Limitations

- Image block review is limited to alt text presence; it does not analyze the image itself
- Block metadata (`noteId`) is only persisted after the post is saved
- The 25-block cap means very long posts will have only the first 25 reviewable blocks analyzed per run
- Resolved blocks (approved Notes) are skipped in full; they will not receive new suggestions until the Note is un-resolved or deleted

## Related Files

- **Experiment:** `includes/Experiments/Editorial_Notes/Editorial_Notes.php`
- **Ability:** `includes/Abilities/Editorial_Notes/Editorial_Notes.php`
- **System Instruction:** `includes/Abilities/Editorial_Notes/system-instruction.php`
- **React Entry:** `src/experiments/editorial-notes/index.tsx`
- **React Plugin Component:** `src/experiments/editorial-notes/components/EditorialNotesPlugin.tsx`
- **React Hook:** `src/experiments/editorial-notes/hooks/useEditorialNotes.ts`
- **PHPUnit Tests (Ability):** `tests/Integration/Includes/Abilities/Editorial_NotesTest.php`
- **PHPUnit Tests (Experiment):** `tests/Integration/Includes/Experiments/Editorial_Notes/Editorial_NotesTest.php`
- **E2E Tests:** `tests/e2e/editorial-notes.spec.ts`
- **Mock Fixtures:** `tests/e2e-request-mocking/responses/OpenAI/editorial-notes-suggestions.json`
