# Writing Assistant

## Summary
Provides a Gutenberg sidebar that combines a Pomodoro-style writing session timer, live session stats, and a multi-category suggestion stream rendered through the `@wordpress/dataviews` component. Writers can start/stop focused sessions, pick timer presets, toggle which suggestion categories they care about, and trigger on-demand analysis or rely on automatic refreshes after configurable word deltas.

## Key Hooks & Entry Points
- `WordPress\AI\Experiments\Writing_Assistant\Writing_Assistant::register()` wires the experiment into `wp_abilities_api_init` (ability registration) and `enqueue_block_editor_assets` (JS/CSS + localized config).
- `Writing_Assistant::register_settings()` stores the default timer duration and automatic suggestion word delta as options to expose on the Experiments admin screen.
- Frontend UI lives in `src/experiments/writing-assistant/index.tsx`, which registers a `PluginSidebar` / `PluginSidebarMoreMenuItem`, manages session/timer state, and renders the suggestion stream via `DataViews`.
- Ability implementation is `includes/Abilities/Writing_Assistant/Writing_Suggestions.php`, registered as `ai/writing-assistant`. It validates inputs, calls `AI_Client` with the system instruction, and falls back to deterministic heuristics when the provider fails.

## Assets & Data Flow
1. PHP enqueues `build/experiments/writing-assistant.js` + `.css` and localizes `aiWritingAssistantData` with ability name, timer presets, word trigger, and suggestion type metadata (labels, descriptions, dashicons).
2. The React app pulls localized data, tracks current session state (ID, timer, stats, selected categories), consumes `core/editor` selectors for post content/ID, and computes word counts on the fly.
3. Session controls let writers set a timer, start/stop, and trigger manual suggestions. An effect monitors word deltas; once the configured threshold is exceeded it automatically calls the ability.
4. Ability requests contain the post ID (if available), normalized content, requested types, trigger metadata, and session stats. Responses are merged into local state, maintaining `pending/applied/dismissed` statuses.
5. Suggestions are rendered inside a DataView table with built-in searching, filtering (type/priority/status), and action buttons. Apply/dismiss buttons only update local state for now, but they keep accurate stats for session summaries.

## Testing
1. Enable Experiments globally and toggle **Writing Assistant** on the AI Experiments settings page. Optionally adjust the default timer seconds or word delta threshold.
2. Open the post editor, click the “AI Writing Assistant” sidebar menu item, and press **Start Session**. The timer and stats cards should reset.
3. Type at least the number of words configured by `word_delta`. Once the threshold is surpassed the sidebar should automatically fetch new suggestions (visible via spinner + DataView rows).
4. Use **Generate suggestions** to trigger manual runs. Verify the request respects the currently checked categories by toggling them and ensuring irrelevant types do not appear.
5. Apply/dismiss a few suggestions and ensure the “Suggestions applied” stat increments while the record badges update to the selected status.
6. Let a timed session run to zero or click **End Session**; the timer should reset and auto-refreshes should stop until the next session starts.

## Notes
- Suggestion categories ship with dashicon identifiers so both PHP (metadata) and JS (badges/toggles) stay in sync; translations happen in PHP when building the localized payload.
- The ability requests all selected categories in one pass. If the AI response cannot be parsed or the provider errors, the fallback heuristics still yield basic readability/SEO/structure tips so the UI never empties.
- Automatic refreshes are gated by `ai_experiment_writing_assistant_word_trigger`; manual refreshes are always available but require an active session to keep stats consistent.
- Timer presets are defined in PHP (`[300, 900, 1500, 0]` seconds). Editors can also supply a custom minute value; `0` always represents “no timer”.
- Permission checks mirror other editor-centric abilities: `edit_post` when analyzing a specific post, otherwise `edit_posts`.
