# Revision Change Summary

## Summary

The Revision Change Summary experiment explains, in plain language, what changed between two revisions of a post. It adds a "Summarize changes" action to the visual revisions screen in the block editor and to the classic revisions screen. The summary is read-only: nothing in the post or its revisions is changed or saved. The experiment registers a WordPress Ability (`ai/revision-change-summary`) that can be used through the admin UI and directly via REST API requests.

## Overview

### For End Users

The revisions screens show an exact comparison of two revisions, which can be slow to read on long posts. With this experiment enabled:

- **Visual revisions (block editor):** open a revision. A "Change summary" panel with a "Summarize changes" button appears in the Revision tab of the editor sidebar. The selected revision is compared with the revision before it, the same comparison core shows.
- **Classic revisions screen (`revision.php`):** a "Change summary" panel with a "Summarize changes" button appears below the revision slider. It compares the two revisions shown, including in "Compare any two revisions" mode. Sites with meta boxes in the editor always use this screen.

Small edits, with one or two changes, get a short summary only, for example "Fixed a typo in the second paragraph." Larger edits get a one-sentence overview plus a list of up to 10 specific changes. List items that repeat the summary or each other are removed. The summary covers changes to the title and content, the fields shown on the revisions screens.

**Key Features:**

- Generates only when the user clicks the button
- Read-only: never edits, saves or applies anything
- One summary is generated at a time, and the button is hidden once a summary is shown
- The latest result is kept for the page view, so moving back to that revision shows its summary again
- On both screens, the button is disabled for the oldest revision, as there is nothing earlier to compare with
- Screen reader announcements for progress, results and errors

### For Developers

The experiment consists of two main components:

1. **Experiment Class** (`WordPress\AI\Experiments\Revision_Change_Summary\Revision_Change_Summary`): Registers the ability and enqueues the scripts for the block editor and for `revision.php`.
2. **Ability Class** (`WordPress\AI\Abilities\Revision_Change_Summary\Revision_Change_Summary`): Validates the revisions, builds a compact diff and asks the model for a structured summary.

## Architecture & Implementation

### Input Schema

```php
array(
    'type'                 => 'object',
    'properties'           => array(
        'post_id'          => array( 'type' => 'integer' ), // Post the revisions belong to.
        'revision_id'      => array( 'type' => 'integer' ), // Newer revision.
        'from_revision_id' => array( 'type' => 'integer' ), // Optional older revision.
    ),
    'required'             => array( 'post_id', 'revision_id' ),
    'additionalProperties' => false,
)
```

If `from_revision_id` is left out, the revision saved just before `revision_id` is used (newest first by date, then ID, autosaves included). If the two revisions are passed in the wrong order, they are swapped so the summary always reads from older to newer.

### Output Schema

```php
array(
    'type'       => 'object',
    'properties' => array(
        'summary'          => array( 'type' => 'string' ),  // Short summary, or an overview when changes are listed.
        'changes'          => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ), // Up to 10 changes, or empty for small edits.
        'truncated'        => array( 'type' => 'boolean' ), // True when only part of the changes was summarized.
        'from_revision_id' => array( 'type' => 'integer' ),
        'revision_id'      => array( 'type' => 'integer' ),
    ),
)
```

### How the Diff Is Built

1. The title is compared as plain text.
2. Content is split into one line per block (nested blocks included), using the saved block markup. Classic (non-block) content is split into paragraphs, also when it was saved without line breaks. Blocks, shortcodes and dynamic content are **not rendered**, so nothing runs and the result reflects the revision, not the current site.
3. Lines are normalized with `normalize_content()` and compared with core's `Text_Diff`. Removed lines start with `- `, added lines with `+ `, and the unchanged line next to each change is kept as context (starting with two spaces). Other unchanged lines are replaced with a `(unchanged lines left out)` line, which the system instruction explains. Lines are only left out when that saves more than one line.
4. Angle brackets in the diff are escaped before it is wrapped in `<changes>` tags, so revision text can't break out of them. The task is repeated after the changes, so instructions in the post text are less likely to be followed.
5. If nothing changed in the title or content (for example, only meta changed), a fixed message is returned and **no model request is made**.
6. Diffs longer than the limit are cut, on a line boundary when one is close, and `truncated` is set to `true`.

### Permissions

- The user must be able to edit the post (`edit_post`), the same check core uses for revisions.
- Both revisions must pass `read_post`, so plugins that limit access to single revisions through `map_meta_cap` are respected.
- Both revisions must belong to that post. Otherwise the request fails with `invalid_revision`.
- The post type must be shown in REST and support revisions, and revisions must be turned on for the post (`wp_revisions_enabled()`).

### Errors

| Code | When |
| --- | --- |
| `post_not_found` | The post doesn't exist. |
| `insufficient_capabilities` | The user can't edit the post or read one of the revisions. |
| `revisions_disabled` | Revisions are turned off for the post. |
| `invalid_revision` | An ID is not a revision of the post (HTTP 400). |
| `same_revision` | Both IDs are the same revision (HTTP 400). |
| `no_previous_revision` | No `from_revision_id` was given and the revision is the oldest (HTTP 400). |
| `invalid_response` / `no_results` | The model returned an unusable response. |
| `unsupported_model` | No connected provider supports text generation. |

## Using the Ability via REST API

### Endpoint

```
POST /wp-json/wp-abilities/v1/abilities/ai/revision-change-summary/run
```

### Authentication

Use cookie authentication with a REST nonce, or an application password. See [TESTING_REST_API.md](../TESTING_REST_API.md).

### Request Example

```bash
curl -X POST "https://example.com/wp-json/wp-abilities/v1/abilities/ai/revision-change-summary/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{"input":{"post_id":123,"revision_id":456}}'
```

Response:

```json
{
  "summary": "Updated the start and the end of the post.",
  "changes": [
    "Changed the first paragraph to describe the new release.",
    "Added a closing paragraph with a call to action.",
    "Changed the title to name the release."
  ],
  "truncated": false,
  "from_revision_id": 455,
  "revision_id": 456
}
```

## Customization

### Maximum Diff Length

```php
// Send up to 40,000 characters of changes to the model. Minimum 1,000. Default 20,000.
add_filter( 'wpai_revision_change_summary_max_diff_length', function () {
    return 40000;
} );
```

### Prompts

The system instruction, prompt and prompt builder can be changed with the standard filters, see [PROMPT_CUSTOMIZATION.md](../PROMPT_CUSTOMIZATION.md):

- `wpai_revision_change_summary_system_instruction`
- `wpai_revision_change_summary_prompt` (arguments: prompt, diff, truncated, revision)
- `wpai_revision_change_summary_prompt_builder` (arguments: builder, prompt, truncated)

Whatever the prompt asks for, list items that repeat the summary or each other are removed, and when the model returns fewer than three distinct items the list is left out and only the summary is shown.

## Testing

### Manual Testing

1. Enable the experiment in **Settings > AI** and connect a provider that supports text generation.
2. Create a post and save it at least three times with different content.
3. Open the post, then **Revisions** in the document sidebar. In the **Change summary** panel of the Revision tab, click **Summarize changes**.
4. Move the revision slider. The previous summary is hidden; click **Summarize changes** again for the new revision. While a summary is being generated, the button is disabled for other revisions.
5. Move the slider to the oldest revision. The button is disabled with "There is no earlier revision to compare with.", before anything is generated.
6. Open **Open classic revisions screen** and repeat. Try **Compare any two revisions**.
7. Check that the post content is unchanged.

## Notes & Considerations

### Requirements

- WordPress 7.0+ and a connected AI provider with text generation support.
- Post types must support revisions and be shown in REST, with revisions turned on.

### Limitations

- Core's visual revisions sidebar has no public extension point (`PluginDocumentSettingPanel` is not shown in revisions mode). The panel is added after the revision summary section of the sidebar, and the selected revision is read from its "Open classic revisions screen" link. If core changes that markup, the panel is not shown, but nothing breaks. The classic screen keeps working.
- The excerpt is not summarized, because the revisions screens don't show it.
- Meta and taxonomy changes are not summarized yet.
- The site editor (templates, template parts, patterns) is not supported yet.
- When an autosave is a post's only revision, core compares it with the post itself on the classic screen. That comparison can't be summarized.
