# Refine from Notes

## Summary

The Refine from Notes experiment enables users to automatically apply pending editorial feedback/notes to their WordPress post content using AI. Clicking "Refine from Notes" in the post sidebar triggers the AI to contextually examine all blocks with pending Notes and modify the text according to the provided suggestions.

## Overview

### For End Users

When enabled, a "Refine from Notes" button appears in the post status info panel (the sidebar area below the post status) assuming there is at least one Note pending on any block in the post. Clicking it triggers the refinement process:

1. The button label updates to show progression across blocks (`Refining block (2 of 4)…`)
2. Each block that has a pending Note attached is sent to the AI alongside the Note's content.
3. The AI precisely updates the block content resolving the note feedback.
4. The post is saved, creating a revision checkpoint.
5. After completion, a success snackbar with a "Review in Revisions" action lets the user review the diff safely and rollback if necessary.

**Key Features:**

- Precise block-level content refinement guided strictly by user/editorial feedback comments.
- Asynchronous batched block processing for improved performance and reliability.
- Robust state rollback using native WordPress Revisions viewer so users can diff the changes smoothly.

### For Developers

The experiment consists of:

1. **Experiment Class** (`WordPress\AI\Experiments\Refine_Notes\Refine_Notes`): Registers the ability and enqueues the block editor asset.
2. **Ability Class** (`WordPress\AI\Abilities\Refine_Notes\Refine_Notes`): Receives a single block's content, surrounding context, and associated notes, parsing the resulting AI output back into plain string replacements.
3. **React Plugin** (`src/experiments/refine-notes/`): Drives the sidebar UI, discovers threaded Notes via WordPress data stores, processes block attributes iteratively, and manages Editor saving workflows.

## Architecture & Implementation

### Key Hooks & Entry Points

`WordPress\AI\Experiments\Refine_Notes\Refine_Notes::register()` wires everything once the experiment is enabled:

- `wp_abilities_api_init` → registers the `ai/refine-notes` ability
- `enqueue_block_editor_assets` → enqueues the React bundle whenever the block editor loads

### Assets & Data Flow

1. **PHP Side:**

   - `enqueue_assets()` loads `experiments/refine-notes` and localizes `window.RefineNotesData`:
     - `enabled`: Whether the experiment is currently enabled

2. **React Side:**

   - `index.tsx` registers the `ai-refine-notes` plugin.
   - `RefineNotesPlugin.tsx` conditionally renders the button inside `PluginPostStatusInfo`.
   - `useRefineNotes.ts` hook manages all state and orchestration:
     - Flattens the active block tree.
     - Fetches active pending Notes via `GET /wp/v2/comments?type=note&status=hold&post=<id>&per_page=100`.
     - Maps notes and child threaded-replies directly to their parent `blockClientId`.
     - Skips any blocks that do not have active pending notes attached.
     - Processes qualifying blocks in parallel batches of 4.
     - Dispatches an `updateBlockAttributes` directly to the `core/block-editor` store with the returned refactored content.
     - Triggers `wp.data.dispatch( 'core/editor' ).savePost()` to persist changes and create a revision.

3. **Ability Execution:**
   - Receives target block type, current content, note texts, and optionally surrounding text context.
   - Builds a standard prompt matching against the system instruction.
   - Extracts plain string response from the AI and returns the direct replacement to the block content.

### Block Types Supported

Can safely run against any block. Output targets formatting of standard block markup (e.g. inner wrappers).

### Input Schema

```php
array(
    'block_type'     => array(
        'type'        => 'string',
        'description' => 'The block type, e.g. core/paragraph, core/heading.',
    ),
    'block_content'  => array(
        'type'        => 'string',
        'description' => 'The content of the block to refine.',
    ),
    'notes' => array(
        'type'        => 'array',
        'items'       => array( 'type' => 'string' ),
        'description' => 'The feedback Notes to apply to the block.',
    ),
    'context'        => array(
        'type'        => 'string',
        'description' => 'Optional surrounding content for context.',
    ),
    'post_id'        => array(
        'type'        => 'integer',
        'description' => 'ID of the post being modified.',
    ),
)
```

### Output Schema

```php
array(
    'type'        => 'string',
    'description' => 'The updated block content after applying feedback.',
)
```

### Permissions

The ability's `permission_callback` operates via two paths:

- **With a numeric `post_id` (post ID):** Validates that the post exists, the current user has `edit_post` capability for that specific post, and the post type is registered with `show_in_rest => true`. Returns `false` if the post type is not REST-accessible.
- **Without a post ID:** Requires `current_user_can( 'edit_posts' )`.

In both cases, users without the required capability receive an `insufficient_capabilities` WP_Error.

## Using the Ability via REST API

### Endpoint

```text
POST /wp-json/wp-abilities/v1/abilities/ai/refine-notes/run
```

### Authentication

See [TESTING_REST_API.md](../TESTING_REST_API.md) for authentication details (application passwords or cookie + nonce).

### Request Examples

#### Refine a Paragraph Block

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/refine-notes/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{
    "input": {
      "block_type": "core/paragraph",
      "block_content": "We shuld try an fix up this stuff.",
      "notes": ["Fix typos and grammar."],
      "post_id": 42
    }
  }'
```

**Response:**

```json
"We should try and fix up this stuff."
```

### Error Responses

| Code                        | Meaning                                                        |
| --------------------------- | -------------------------------------------------------------- |
| `block_content_required`    | `block_content` was empty                                      |
| `notes_required`            | No valid notes were supplied in the array                      |
| `post_not_found`            | The post ID passed does not exist                              |
| `insufficient_capabilities` | User lacks `edit_posts` (or `edit_post` for the specific post) |

## Related Files

- **Experiment:** `includes/Experiments/Refine_Notes/Refine_Notes.php`
- **Ability:** `includes/Abilities/Refine_Notes/Refine_Notes.php`
- **System Instruction:** `includes/Abilities/Refine_Notes/system-instruction.php`
- **React Entry:** `src/experiments/refine-notes/index.tsx`
- **React Plugin Component:** `src/experiments/refine-notes/components/RefineNotesPlugin.tsx`
- **React Hook:** `src/experiments/refine-notes/hooks/useRefineNotes.ts`
- **PHPUnit Tests (Ability):** `tests/Integration/Includes/Abilities/Refine_NotesTest.php`
- **PHPUnit Tests (Experiment):** `tests/Integration/Includes/Experiments/Refine_Notes/Refine_NotesTest.php`
- **E2E Tests:** `tests/e2e/specs/experiments/refine-notes.spec.js`
- **Mock Fixtures:** `tests/e2e-request-mocking/responses/OpenAI/refine-notes-completions.json` and `tests/e2e-request-mocking/responses/OpenAI/refine-notes-responses.json`
