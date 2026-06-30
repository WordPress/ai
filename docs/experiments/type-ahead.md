# Type Ahead

## Summary

The Type Ahead experiment adds inline ghost-text completions to the block editor. When enabled, supported blocks show AI suggestions at the caret that can be accepted fully or incrementally with keyboard shortcuts.

## Overview

### For End Users

When enabled, type-ahead suggestions appear while writing in supported blocks:

1. Suggestions are generated when the caret is at the end of a selected supported block.
2. Suggestions can appear in empty paragraph blocks as well as non-empty blocks.
3. In empty blocks, the Gutenberg placeholder (`Type / to choose a block`) is hidden while ghost text is visible to prevent overlap.
4. Keyboard shortcuts:
   - `Tab`: accept the full suggestion.
   - `Cmd/Ctrl + Right Arrow`: accept the next word.
   - `Cmd/Ctrl + Shift + Right Arrow`: accept the next sentence.
   - `Esc`: dismiss current suggestion.
   - `Cmd/Ctrl + Space`: manual trigger.

### For Developers

The experiment has three parts:

1. **Experiment Class** (`WordPress\AI\Experiments\Type_Ahead\Type_Ahead`): registers the ability, enqueues editor assets, and registers settings.
2. **Ability Class** (`WordPress\AI\Abilities\Type_Ahead\Type_Ahead`): validates input, builds prompt context, calls AI, and returns structured `{ suggestion, confidence }`.
3. **React Editor Integration** (`src/experiments/type-ahead/`): wraps supported blocks, tracks caret/context, requests suggestions, and renders ghost text.

## Architecture & Implementation

### Key Hooks & Entry Points

`WordPress\AI\Experiments\Type_Ahead\Type_Ahead::register()` wires:

- `wp_abilities_api_init` -> registers `ai/type-ahead`.
- `enqueue_block_assets` -> enqueues `experiments/type-ahead` JS and `experiments/type-ahead` CSS.

Editor bootstrap:

- `src/experiments/type-ahead/index.tsx` reads `window.aiTypeAheadData`, creates the allowed block set, and registers an `editor.BlockEdit` HOC (`withAITypeAhead`).

### Assets & Data Flow

1. **PHP side**
   - Enqueues script: `experiments/type-ahead`.
   - Enqueues stylesheet: `experiments/type-ahead`.
   - Localizes `window.aiTypeAheadData` with:
     - `enabled`
     - `completionMode`
     - `triggerDelay`
     - `confidence`
     - `maxWords`
     - `showHeadings`

2. **React side**
   - `TypeAheadBlock` resolves block/editable DOM nodes and caret details.
   - `useTypeAheadContext` extracts plain block content, neighboring context, and post ID.
   - `useTypeAheadSuggestion` debounces requests and calls `runAbility( 'ai/type-ahead', input )`.
   - Suggestions are rendered:
     - inline ghost span when caret is not at end;
     - overlay text when caret is at end.
   - Accepted text is inserted and an `input` event is dispatched so Gutenberg persists the change.

3. **Ability side**
   - Truncates context fields to 5000 chars.
   - Builds prompt JSON via `prepare_prompt_context()`.
   - Uses JSON schema output (`suggestion`, `confidence`) and validates/parses response.
   - Caches per `(block_content, preceding_text, mode, max_words)` for 45 seconds.

### Trigger Behavior

- Requests run only when:
  - experiment is enabled,
  - block type is allowed (`core/paragraph` plus optional `core/heading`),
  - current block is selected,
  - caret is at block end.
- Empty block content is allowed.
- In `word` mode, auto-trigger additionally requires punctuation/context from `shouldTriggerFromContext()`.
- Manual trigger (`Cmd/Ctrl + Space`) bypasses the `word` mode context gate.

### Input Schema (Ability)

```php
array(
    'post_id'             => array( 'type' => 'integer' ),
    'block_content'       => array( 'type' => 'string' ),
    'preceding_text'      => array( 'type' => 'string' ),
    'following_text'      => array( 'type' => 'string' ),
    'surrounding_context' => array( 'type' => 'string' ),
    'cursor_position'     => array( 'type' => 'integer' ),
    'mode'                => array( 'type' => 'string', 'enum' => array( 'word', 'sentence', 'paragraph', 'smart' ) ),
    'max_words'           => array( 'type' => 'integer' ),
    'manual_trigger'      => array( 'type' => 'boolean' ),
)
```

### Output Schema (Ability)

```php
array(
    'type'       => 'object',
    'properties' => array(
        'suggestion'      => array( 'type' => 'string' ),
        'confidence'      => array( 'type' => 'number' ),
        'cursor_position' => array( 'type' => 'integer' ),
    ),
)
```

### Permissions

The ability's `permission_callback` has two paths:

- **With `post_id`:** requires existing post, `edit_post` capability, and `show_in_rest` post type.
- **Without `post_id`:** requires `edit_posts`.

## Testing

### Manual Testing

1. Enable global experiments and **Type-ahead Text** in settings.
2. Open block editor and verify suggestions appear in:
   - non-empty paragraphs;
   - empty paragraph blocks.
3. In an empty paragraph with active ghost text, verify placeholder text does not overlap.
4. Verify keyboard controls (`Tab`, `Cmd/Ctrl + Right`, `Cmd/Ctrl + Shift + Right`, `Esc`, `Cmd/Ctrl + Space`).
5. Enable headings in settings and verify `core/heading` support.
6. In `word` mode, verify auto-trigger only happens in triggering contexts, while manual trigger still works.
