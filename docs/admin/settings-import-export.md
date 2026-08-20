# Settings Import/Export

## Summary

The Settings Import/Export feature lets administrators move a site's **non-sensitive** AI configuration between environments (for example, staging → production, or across a multisite network) as a portable JSON file. It exposes two REST endpoints (`GET /ai/v1/settings/export` and `POST /ai/v1/settings/import`) and adds **Export settings** / **Import settings** actions to the AI settings menu. API keys, tokens, and other credentials are never included in an export and are never overwritten on import.

## Overview

### For End Users

On the AI settings page (**Settings → AI**), the actions menu (the three-dot "Developer Tools" button) gains a **Settings** group with two items:

- **Export settings** — downloads an `ai-settings-export.json` file containing your current feature toggles and developer model selections. No API keys or secrets are included.
- **Import settings** — prompts you to choose a previously exported JSON file, shows a confirmation modal ("This will overwrite your existing AI settings."), and, on confirmation, applies the settings. The UI updates immediately, with no page reload required.

If an imported file contains values that fail validation, the success notice reports how many settings were imported and how many were rejected. If the file was produced by an incompatible (newer) plugin version, the error notice explains that the schema version is unsupported.

### For Developers

## REST API

Both endpoints require the `manage_options` capability.

### `GET /ai/v1/settings/export`

Returns the non-sensitive AI configuration as a portable JSON structure matching the import schema:

```json
{
  "version": 1,
  "exported_at": "2026-07-24T12:00:00Z",
  "plugin_version": "1.2.3",
  "providers": {
    "wpai_feature_<id>_field_developer": { "provider": "openai", "model": "gpt-4.1-mini" }
  },
  "settings": {
    "wpai_features_enabled": true,
    "wpai_feature_<id>_enabled": true
  }
}
```

- `settings` holds boolean feature toggles and the global enable switch.
- `providers` holds per-feature developer model configuration objects (option names containing `_field_developer`).
- Options that have never been saved are exported with their registered default value, so an export always fully describes the source environment.

### `POST /ai/v1/settings/import`

Accepts a payload in the export shape. Behavior:

- Rejects the request with `422 unsupported_schema_version` if `version` does not match the current `SCHEMA_VERSION`.
- Only options that are **currently registered** in the plugin's option group **and not flagged as sensitive** are eligible; all other keys in the payload are silently ignored.
- Each eligible value is validated with `rest_validate_value_from_schema` and sanitized with `rest_sanitize_value_from_schema` against the option's registered schema before `update_option` is called. Values that fail validation are counted as rejected rather than written.

Response:

```json
{ "imported": 5, "rejected": 1, "message": "Settings imported successfully. 5 setting(s) imported, 1 rejected due to invalid values." }
```

## Security & the non-sensitive guarantee

The export/import surface is constrained on several axes so that credentials cannot leak or be tampered with:

- **Option group scoping.** Only options registered in the plugin's own settings group (`Settings_Registration::OPTION_GROUP`) are ever considered. Connector API keys live in separate `connectors_ai_*` options outside this group and are therefore structurally out of scope.
- **Sensitive-name filtering (defense in depth).** Any in-group option whose name contains a sensitive segment (`key`, `token`, `secret`, `credential`, `password`, `auth`) is excluded from both export and import. Matching is done per underscore-delimited segment, so `wpai_default_author` is *not* flagged while `wpai_openai_api_key` is.
- **Schema validation on import.** Values are validated and sanitized against the option's registered type/shape, so a malformed file cannot write arbitrary data to the database.
- **Capability check.** Both endpoints require `manage_options`.

Because filtering is name-based within the plugin's option group, treat "non-sensitive" as a guarantee about *credentials* specifically. A custom feature that stores a private value in an option with a non-sensitive name would have that value exported; name such options accordingly (or register them outside the plugin's option group).

## Schema versioning

`Settings_IO_Controller::SCHEMA_VERSION` (currently `1`) is embedded in every export and checked on import. Increment it whenever the export format changes in a backward-incompatible way so that older or newer files are rejected with a clear error rather than partially applied.
