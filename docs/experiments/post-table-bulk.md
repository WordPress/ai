# Post Table Bulk Actions Experiment

## Summary

Extends the classic Posts list table so editors can request AI-generated category and tag suggestions without leaving `edit.php`. It hooks into both Bulk Edit and Quick Edit panels, fetches recommendations for the selected posts, and writes accepted terms into the existing taxonomy controls before the standard bulk save runs.

## Key Hooks & Entry Points

- `wp_abilities_api_init` -> registers `ai/post-table-bulk/taxonomy-suggestions` (implemented in `includes/Abilities/Post_Table_Bulk/Taxonomy_Suggestions.php`).
- `admin_enqueue_scripts` (only on `edit.php`) -> enqueues `experiments/post-table-bulk` JS bundle and localizes screen data.
- `bulk_edit_custom_box` / `quick_edit_custom_box` -> inject React mount points inside the "Categories" column of the inline forms.

## Assets & Data Flow

- JS entry: `src/experiments/post-table-bulk/index.tsx` + `components/Assistant.tsx`.
- Localized payload (`PostTableBulkData`):
  - `ability`: ability slug to execute.
  - `taxonomies`: subset of supported taxonomies (currently `category` + `post_tag`).
  - `maxBatchSize`: limit (default 20) to keep client-side requests responsive.
  - `suggestionLimit`: per-taxonomy suggestion cap (default 5).
- Ability input: `{ post_ids, taxonomies, limit, locale }`.
- Ability output: structured `suggestions[post_id][taxonomy] = [{ term_id|null, name, confidence, is_new }]`.
- JS aggregates per-post responses (bulk mode) and toggles the actual checkboxes/tag text field so the classic inline-save AJAX request persists the data.

## Testing

1. Navigate to `wp-admin/edit.php` for a post type that supports categories/tags.
2. **Bulk flow:** select multiple posts -> choose "Edit" bulk action -> Apply -> in the inline panel click "Suggest categories & tags." After results load, apply a taxonomy suggestion and click "Update" to persist.
3. **Quick flow:** hover a post -> click "Quick Edit" -> use the AI button to suggest terms for that single post, apply them, then update.
4. Verify new terms marked "New term" in the UI appear in the tag text box (tags) or leave category checkboxes untouched if the term doesn't already exist.

## Notes

- The ability builds a taxonomy catalog of up to 200 terms per taxonomy to map AI suggestions back to real IDs; unmatched terms are flagged as "new" for editor review.
- Without a dedicated job queue, the UI batches up to `maxBatchSize` posts per request to keep calls synchronous; adjust this value if you need larger runs.
- Currently limited to `category` and `post_tag` because those are the only taxonomies exposed in the classic Bulk Edit form by default.
