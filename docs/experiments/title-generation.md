# Title Generation Experiment

## Summary

Adds a “Generate/Re-generate” button to the post editor title toolbar so authors can request AI-crafted titles based on the current post content. Suggestions appear in a modal where editors can tweak or select a title before applying it to the post.

## Key Hooks & Entry Points

- `wp_abilities_api_init` → registers the `ai/title-generation` ability backed by `WordPress\AI\Abilities\Title_Generation`.
- `admin_enqueue_scripts` (for `post.php` and `post-new.php`) → enqueues the React bundle `experiments/title-generation`.
- `src/experiments/title-generation/index.tsx` mounts the toolbar button inside Gutenberg.

## Assets & Data Flow

- JS entry: `src/experiments/title-generation/components/TitleToolbar.tsx`.
- Localized data: `TitleGenerationData.enabled` toggles the UI based on experiment state.
- Ability payload: `{ post_id, content, candidates }`.
- Ability response: `{ titles: string[] }`, where the UI renders each candidate with inline editing.

## Testing

1. Open any post type that supports titles (excluding attachments) in the block editor.
2. Locate the AI “Generate/Re-generate” button beside the title.
3. Click it to open the modal and ensure titles populate; select one to update the post title.
4. Confirm error notices appear if the ability call fails or no content is available.

## Notes

- The toolbar component uses `@wordpress/abilities` to execute the ability and `@wordpress/notices` for error surfacing.
- Ability enforces capability checks (`read_post`, `edit_posts`) and ensures the target post type is REST-enabled.
