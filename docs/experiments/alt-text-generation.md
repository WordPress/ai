# Alt Text Generation

## Summary
Adds an "AI Alt Text" panel to the Image block inspector. When enabled, the inspector shows a Generate/Regenerate button that calls the `ai/alt-text-generation` ability, receives candidate text from vision-capable models, and lets authors apply or dismiss the result before saving the block.

## Key Hooks & Entry Points
- `WordPress\AI\Experiments\Alt_Text_Generation\Alt_Text_Generation::register()` adds:
  - `wp_abilities_api_init` -> `register_abilities()` which calls `wp_register_ability( 'ai/alt-text-generation', Alt_Text_Generation_Ability::class )`.
  - `enqueue_block_editor_assets` -> `enqueue_editor_assets()` which loads the React bundle when the block editor runs.
- `src/experiments/alt-text-generation/index.tsx` hooks `editor.BlockEdit` with `addFilter( 'editor.BlockEdit', 'ai/alt-text-generation', ... )` to inject `<AltTextControls />` into every `core/image` block.
- Ability implementation: `includes/Abilities/Alt_Text_Generation/Alt_Text_Generation.php` (extends `Abstract_Ability`) handles validation, permission checks, and calls `AI_Client::prompt_with_wp_error()->with_file()->generate_text()` using the system prompt at `includes/Abilities/Alt_Text_Generation/system-instruction.php`.

## Assets & Data Flow
1. `enqueue_editor_assets()` loads the `alt_text_generation` script handle that maps to the webpack entry `experiments/alt-text-generation` (`src/experiments/alt-text-generation/index.tsx`).
2. `Asset_Loader::localize_script()` exposes `{ enabled: $this->is_enabled() }` on `window.aiAltTextGenerationData`. The HOC checks this flag before rendering controls.
3. `<AltTextControls />` uses `@wordpress/abilities`' `executeAbility( 'ai/alt-text-generation', params )` where `params` include `attachment_id` or `image_url` plus optional context pulled from the block.
4. The PHP ability sanitizes input (absint/esc_url_raw/sanitize_textarea_field), fetches attachment URLs when IDs are provided, streams the image through the AI client, trims/truncates to 125 characters, and returns `{ alt_text: '...' }`.
5. React shows a textarea preview, handles Apply/Dismiss buttons, and writes back to the block via `setAttributes( { alt: generatedAlt } )`.

## Testing
1. Go to `Settings -> AI Experiments`, enable the global toggle, then enable **Alt Text Generation**.
2. Open the block editor for any post, insert or select an Image block with either an uploaded image (preferred) or external URL.
3. In the inspector sidebar, locate the "AI Alt Text" panel and click **Generate Alt Text**. Confirm a spinner shows and a textarea with generated content appears.
4. Click **Apply** to copy the generated text into the block's alt attribute; verify it also appears in the block sidebar's Alt Text field.
5. Try **Regenerate Alt Text** to ensure repeated calls work, and test an error path (e.g., remove the image URL) to confirm notices are displayed.

## Notes
- Ability access follows WordPress capabilities: editing an attachment requires `edit_post`; URL-only requests require `upload_files`.
- Server-side toggle: the experiment is only registered when both the global experiments flag (`ai_experiments_enabled`) and this experiment's option (`ai_experiment_alt-text-generation_enabled`) are true.
- Output is truncated to 125 characters to match accessibility guidance for concise alt text. Adjust `MAX_ALT_TEXT_LENGTH` if future UX calls for longer descriptions.
