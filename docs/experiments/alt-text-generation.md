# Alt Text Generation

## Summary
Adds an "AI Alt Text" experience across the Image block inspector, the media modal, and the Media Library attachment edit screen. When enabled, editors can generate or regenerate alt text from any of these surfaces via the shared `ai/alt-text-generation` ability and decide whether to apply the suggestion before saving.

## Key Hooks & Entry Points
- `WordPress\AI\Experiments\Alt_Text_Generation\Alt_Text_Generation::register()` adds:
  - `wp_abilities_api_init` -> `register_abilities()` which calls `wp_register_ability( 'ai/alt-text-generation', Alt_Text_Generation_Ability::class )`.
  - `enqueue_block_editor_assets` -> `enqueue_editor_assets()` which loads the React bundle when the block editor runs and ensures the media script is available inside the editor.
  - `wp_enqueue_media` -> `enqueue_media_frame_assets()` so every media modal invocation (block editor, classic editor, site editor, etc.) loads the DOM-based integration.
  - `admin_enqueue_scripts` -> `maybe_enqueue_media_library_assets()` to cover `upload.php`, `media-new.php`, and `post.php` when editing individual attachments outside the modal.
- `src/experiments/alt-text-generation/index.tsx` hooks `editor.BlockEdit` with `addFilter( 'editor.BlockEdit', 'ai/alt-text-generation', ... )` to inject `<AltTextControls />` into every `core/image` block.
- `src/experiments/alt-text-generation/media.ts` observes `.media-sidebar` and `#attachment_alt` fields, injecting Generate/Regenerate controls directly into the media sidebar and attachment edit form without React.
- Ability implementation: `includes/Abilities/Alt_Text_Generation/Alt_Text_Generation.php` (extends `Abstract_Ability`) handles validation, permission checks, and calls `AI_Client::prompt_with_wp_error()->with_file()->generate_text()` using the system prompt at `includes/Abilities/Alt_Text_Generation/system-instruction.php`.

## Assets & Data Flow
1. `enqueue_editor_assets()` loads the `alt_text_generation` script handle that maps to the webpack entry `experiments/alt-text-generation` (`src/experiments/alt-text-generation/index.tsx`) and localizes `window.aiAltTextGenerationData`.
2. `wp_enqueue_media` / `admin_enqueue_scripts` load the additional `alt_text_generation_media` handle (`src/experiments/alt-text-generation/media.ts`) with `window.aiAltTextGenerationMediaData` so long as the experiment is enabled.
3. `<AltTextControls />` uses `executeAbility( 'ai/alt-text-generation', params )` where `params` include `attachment_id` or `image_url` plus optional context pulled from the block.
4. The media script watches for `.media-sidebar .setting[data-setting="alt"] textarea` and `#attachment_alt`, injecting a Generate/Regenerate button and status text directly into the native forms. It calls the same ability via `runAbility`, prefers `attachment_id`, and falls back to the File URL if the ID cannot be resolved.
5. The PHP ability sanitizes input (absint/esc_url_raw/sanitize_textarea_field), fetches attachment URLs when IDs are provided, streams the image through the AI client, trims/truncates to 125 characters, and returns `{ alt_text: '...' }`.
6. React renders the inspector panel for blocks, while the DOM integration updates the existing textarea value and dispatches `input/change` events so core saves the field automatically.

## Testing
1. Go to `Settings -> AI Experiments`, enable the global toggle, then enable **Alt Text Generation**.
2. Open the block editor for any post, insert or select an Image block with either an uploaded image (preferred) or external URL.
3. In the inspector sidebar, locate the "AI Alt Text" panel and click **Generate Alt Text**. Confirm a spinner shows and a textarea with generated content appears.
4. Click **Apply** to copy the generated text into the block's alt attribute; verify it also appears in the block sidebar's Alt Text field.
5. Try **Regenerate Alt Text** to ensure repeated calls work, and test an error path (e.g., remove the image URL) to confirm notices are displayed.
6. Anywhere the media modal appears (Insert Media button, block editor media picker, etc.), select an image and verify the new **Generate Alt Text** button appears under the Alt Text field inside the sidebar. Generate text, confirm the textarea updates automatically, and retry to ensure Regenerate works.
7. Visit `Media -> Library` (grid view), open an image, and repeat the previous step to confirm the button renders in the standalone modal as well.
8. Edit an individual attachment (`Media -> Library -> Edit`), locate the "Alternative Text" textarea, and confirm the button status text and spinner render below it. Generate, ensure the field populates, then update the attachment to save.

## Notes
- Ability access follows WordPress capabilities: editing an attachment requires `edit_post`; URL-only requests require `upload_files`.
- Server-side toggle: the experiment is only registered when both the global experiments flag (`ai_experiments_enabled`) and this experiment's option (`ai_experiment_alt-text-generation_enabled`) are true.
- Output is truncated to 125 characters to match accessibility guidance for concise alt text. Adjust `MAX_ALT_TEXT_LENGTH` if future UX calls for longer descriptions.
