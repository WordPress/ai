# Abilities Explorer

## Summary

The Abilities Explorer experiment adds an admin screen for finding, inspecting and testing every ability registered through the WordPress Abilities API, and for controlling which abilities the AI Workspace assistant may call. It lives at **Tools → Abilities Explorer** (`tools.php?page=ai-abilities-explorer`) and requires the `manage_options` capability.

The screen is a React application built on `@wordpress/dataviews`, backed by four `ai/v1` REST routes.

## What the screen does

### List

- Statistics for Total, Core, Plugins and Theme, counted by each ability's origin. They are computed in the browser from the whole list, so search and filters do not change them.
- A table of every registered ability, including abilities that are not opted into REST, with Name, Slug, Provider and "Exposed in" columns. Search covers name, slug and description. The table sorts by name, slug and provider, shows 20 rows per page, and has a **Refresh** button.
- Filters:
  - **Provider** (single choice). Core, Plugin and Theme match the ability's origin; any other option is a custom `meta.provider` label and matches that exact label. An ability with a custom label from a plugin therefore appears under both "Plugin" and its own label.
  - **Category**. Each category label is listed once and rendered as text.
  - **Exposed in**. Filters by assistant state: "Assistant", "Assistant (eligible)" or "Not the assistant".
- The "Exposed in" column shows REST and MCP badges, the assistant state, the reason an ability is not on the assistant (except "not public", the common case), and, for abilities on the assistant, the "Text the model sees".
- Row actions: **View**, **Test**, and **Remove from assistant** or **Return to assistant**. The list also has a site-wide **Turn policy off / Turn policy on** control for the assistant admission policy. Each change waits for the server, disables its control while pending, and confirms with a notice.

### Detail view and test runner

- The detail view shows the description, provider, input schema, output schema and raw data. Each JSON block has a Copy button.
- The test runner prefills example input generated from the input schema, validates it in the browser (**Validate Input**), invokes the ability (**Invoke Ability**) and clears the result and validation panels (**Clear Result**). The JSON input is labelled "Ability test input (JSON)".
- Validation checks the input's top-level type, required fields, and each top-level property's type, `enum`, `minimum` and `maximum`. Nested objects are not validated.

### Views and links

Views are addressed by the same query arguments as before, so existing links keep working:

- List: `tools.php?page=ai-abilities-explorer`
- Detail: `&action=view&ability=<name>`
- Test runner: `&action=test&ability=<name>`

Moving between views updates the URL, so browser Back and Forward work, and each view has a single `h1`, "Abilities Explorer".

The list remembers its layout, visible columns, sort and page size in the browser's local storage (`ai.abilitiesExplorer.view`). Search, filters and the current page are not saved.

## REST routes

All four routes are registered only while the experiment is enabled. They share one permission check: the user must have `manage_options` and must be authenticated by the logged-in cookie with the REST nonce. Requests authenticated any other way, including application passwords, are refused with a 403. None of the routes is registered as an ability.

Ability names are passed as a parameter, never in the path, because names contain `/`.

| Method | Route | Parameters | Returns |
|---|---|---|---|
| `GET` | `ai/v1/abilities` | none | `items` (summary fields and `meta` for every ability), `policy`, `sequence` |
| `GET` | `ai/v1/abilities/item` | `name` | One ability with `input_schema`, `output_schema`, `raw_data` and `example_input` |
| `POST` | `ai/v1/abilities/invoke` | `name`, `input` (a JSON string; empty means no input) | `success` with `data`, or `success: false` with `error.code`, `error.message` and `error.data` |
| `POST` | `ai/v1/abilities/surface` | `change` (`remove`, `restore`, `disable_policy`, `enable_policy`), `name` for `remove` and `restore` | The updated row (`item`) for `remove` and `restore`, or the full list (`items`) for a policy change, plus `changed`, `policy` and `sequence` |

The invoke route answers 404 for an unknown ability and 400 for malformed JSON or input that fails the Explorer's schema check. The ability's own outcome, including its permission check and core's input validation, is a 200 with `success: false`. Invoking writes no AI request log row.

A field of a list item that cannot be encoded as JSON is sent as `null` and named in that item's `unencodable_fields`, so one ability cannot stop the list from loading.

## Behavior changes from the previous screen

The screen used to be a PHP `WP_List_Table` with admin-ajax handlers. Compared with that version:

- **Invoke runs under REST.** Abilities are invoked from a REST request, so `is_admin()` is `false` during the call. The route loads `wp-admin/includes/admin.php` first, so admin functions such as `get_plugins()` remain available.
- **Errors show a code, message and data.** A failed invoke shows the error's `code`, `message` and `data` in the error panel. The old `trace` field, which was always empty, is gone.
- **Back to List keeps the view's layout.** Returning from the detail view or the test runner keeps the list's layout, visible columns, sort and page size. Search and filters reset.
- **The list, detail and runner come from REST.** The screen builds all three from REST requests, where `is_admin()` is `false`. An ability that a plugin registers only when `is_admin()` is `true` no longer appears.
- **The routes accept only an administrator's browser session.** The `ai/v1` Explorer routes require `manage_options`, cookie authentication from a logged-in session, and a valid `wp_rest` nonce (the `X-WP-Nonce` header, or the `_wpnonce` parameter). Application passwords and other non-cookie authentication are refused, so scripts and custom clients cannot call them.

## Permissions

- The screen and all four routes require `manage_options`.
- Invoking an ability still runs that ability's own permission check and input validation through `WP_Ability::execute()`.

## Testing

### Automated

- PHP: `tests/Integration/Includes/Experiments/Abilities_Explorer/` (`Admin_PageTest`, `Ability_HandlerTest`, `Abilities_ExplorerTest`, `REST/Abilities_ControllerTest`).
- End to end: `tests/e2e/specs/experiments/abilities-explorer-*.spec.js` (list, surface, runner, navigation), with helpers in `tests/e2e/utils/abilities-explorer.js`, fixture abilities from `tests/e2e-testing/e2e-testing.php` and the field extension fixture in `tests/e2e-plugins/abilities-explorer-field`.

### Manual

1. Go to **Settings → AI**, turn AI on and enable **Abilities Explorer**.
2. Open **Tools → Abilities Explorer**. Check the statistics, search, the Provider, Category and "Exposed in" filters, and sorting.
3. Use **View** on a row. Check the schemas and the Copy buttons.
4. Use **Test** on a row. Edit the input, then use **Validate Input**, **Invoke Ability** and **Clear Result**.
5. On a row whose "Exposed in" column reads "Assistant", use **Remove from assistant**, then **Return to assistant**. Use **Turn policy off** and **Turn policy on**. Check that each change shows a notice.

## Adding fields to the table

Other plugins can add read-only columns to the Abilities Explorer list through the `ai.abilitiesExplorer.fields` JavaScript filter. The list is built with `@wordpress/dataviews`, and each column is a DataViews field.

### Enqueueing the script

Enqueue the script on the Explorer screen (its hook suffix is `tools_page_ai-abilities-explorer`) and make it depend on `wp-hooks`. Do not depend on the Explorer's own script handle: the Explorer bundle loads deferred, and its handle is not a public API. The filter is read when the list renders and again whenever a callback is added to or removed from it, so the column shows whether your script runs before or after the Explorer.

```php
add_action(
	'admin_enqueue_scripts',
	static function ( string $hook_suffix ): void {
		if ( 'tools_page_ai-abilities-explorer' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'my-plugin-explorer-field',
			plugins_url( 'explorer-field.js', __FILE__ ),
			array( 'wp-hooks' ),
			'1.0.0',
			true
		);
	}
);
```

### Adding a field

The filter receives the current fields, built-ins first, and returns the full list. Append your field and return the array.

```js
wp.hooks.addFilter(
	'ai.abilitiesExplorer.fields',
	'my-plugin/slug-length',
	( fields ) => [
		...fields,
		{
			id: 'my-plugin/slug-length',
			label: 'Slug length',
			type: 'integer',
			enableSorting: true,
			getValue: ( { item } ) => item.slug.length,
		},
	]
);
```

Each row (`item`) is one ability as returned by `GET ai/v1/abilities`, so a field can read properties such as `slug`, `name`, `provider`, `category` and `meta`. Several of them, including `name`, `provider`, `category` and `meta`, can be `null`, for example when the server could not encode that part of an ability, so read them with a fallback. Prefix the field ID with your plugin's namespace so it cannot clash with another extension.

### What a field may carry

An extension field keeps only these DataViews properties: `id`, `type`, `label`, `header`, `description`, `render`, `getValue`, `getValueFormatted`, `sort`, `format`, `elements`, `filterBy`, `enableSorting`, `enableHiding` and `enableGlobalSearch`. Anything else is dropped, including edit controls (`Edit`, `setValue`, `isValid`) and anything like `actions`, and every extension field is marked `readOnly`. Extension fields never add row actions.

- **Built-in fields win.** A field whose ID matches a built-in field (`name`, `slug`, `provider`, `category`, `surface`, `description`) is ignored, and returning a list without the built-ins does not remove them. If two extensions use the same ID, the first one wins.
- **Failures fall back.** If a filter callback throws or returns something other than an array, the list shows only the built-in fields. A `getValue`, `getValueFormatted` or `sort` callback that throws yields an empty value (or no reordering), and a `render` callback that throws renders an empty cell, so one extension cannot break the table.
- **Saved views keep your column.** A new field is added to the visible columns the first time the Explorer sees it, including on a first visit with nothing saved. After that it is the user's choice to hide it. If your plugin is deactivated, its column's place in the user's saved view is kept, and it shows again when the plugin is reactivated.
