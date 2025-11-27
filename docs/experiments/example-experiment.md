# Example Experiment

## Summary

Demonstrates the scaffolding for an AI experiment without touching the editor or admin UI. When enabled, it appends a diagnostic HTML comment in the site footer, tweaks the document title in debug mode, and exposes a simple REST endpoint so developers can confirm the experiment loader is working.

## Key Hooks & Entry Points

- `wp_footer` → outputs `<!-- Example Experiment: AI Plugin Active -->` for logged-in users.
- `document_title_parts` → appends ` [AI]` to the site title while `WP_DEBUG` is true.
- `rest_api_init` → registers `GET /wp-json/ai/v1/example`.

## Assets & Data Flow

- No abilities or JavaScript bundles. The REST route returns metadata about the experiment (ID, label, enabled state) for quick verification.

## Testing

1. Enable the experiment in the AI settings screen (or ensure it’s active by default).
2. Visit the front end while logged in and inspect the page source—look for the footer comment.
3. Hit `/wp-json/ai/v1/example` as an admin; expect a JSON payload describing the experiment.

## Notes

- Permission callback for the REST route requires `manage_options`, ensuring only admins fetch metadata.
- Useful as a template when creating new experiments—copy the structure, rename, and expand with real functionality.
