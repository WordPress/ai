# Type Ahead

## Summary
Adds inline "ghost text" completions to the block editor. When the experiment and global toggle are enabled, typing inside supported blocks (paragraphs by default, optional headings) displays translucent suggestions that can be accepted via keyboard shortcuts.

## Key Hooks & Entry Points
- `WordPress\AI\Experiments\Type_Ahead\Type_Ahead::register()` hooks `wp_abilities_api_init` to register the AI ability and `enqueue_block_editor_assets` to load assets only when the block editor runs.
- `Type_Ahead::register_settings()` stores experiment-specific options under the Experiments settings screen (`ai_experiment_type_ahead_*`).
- `src/experiments/type-ahead/index.tsx` registers an `editor.BlockEdit` filter (HOC) that adds the overlay for each supported block and wires keyboard handlers.
- Ability implementation lives at `includes/Abilities/Type_Ahead/Type_Ahead.php` and is registered as `ai/type-ahead`.

## Assets & Data Flow
1. PHP enqueues the `experiments/type-ahead` script and `experiments/style-type-ahead` stylesheet, then localizes `aiTypeAheadData` with flags like `completionMode`, `triggerDelay`, and `abilityName`.
2. The React entry point polls for the localized data, then wraps block edits to render the overlay component and keyboard hints, and calls `executeAbility( 'ai/type-ahead', ... )` when it is time to request a suggestion.
3. The ability sanitizes input (post ID, caret position, snippets of block content) and composes a structured payload via `prepare_prompt_context()`.
4. `AI_Client::prompt_with_wp_error()` is invoked with the system instruction in `includes/Abilities/Type_Ahead/system-instruction.php`, returning JSON `{ suggestion, confidence }` that the ability validates before passing back to JS.
5. The UI caches the last suggestion per block/caret, displays it via a portal near the caret, and dispatches synthetic `input` events when the user accepts a suggestion so Gutenberg updates its state.

## Testing
1. Enable Experiments globally and toggle **Type-ahead Text** under `Settings > AI Experiments`. (Optional: adjust mode/delay/confidence/headings.)
2. Open the block editor, insert a Paragraph block, and start typing sentences until the caret is at the end of a sentence. After the configured delay a faint suggestion should appear.
3. Press `Tab` to accept the full suggestion, `Cmd/Ctrl + Right Arrow` for a word, or `Cmd/Ctrl + Shift + Right Arrow` for a sentence. The accepted text should become real content and the remainder should stay ghosted if available.
4. Press `Esc` to dismiss a suggestion. Use `Cmd/Ctrl + Space` to force a manual fetch even when the caret context would not auto-trigger.
5. Toggle the "Enable in headings" checkbox in settings, then verify `core/heading` blocks now receive suggestions.

## Notes
- Suggestions are cached briefly (45 seconds) per block/caret combination to reduce provider calls.
- Permissions require `edit_post` when a post ID is provided, otherwise `edit_posts`.
- The ability trims contexts to 5000 characters and normalizes HTML content before sending it to the model.
- Settings are stored via the standard options API; sanitizers enforce safe ranges (delay 200-2000 ms, confidence 0-100, etc.).
