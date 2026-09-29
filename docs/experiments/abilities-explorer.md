# Abilities Explorer

## Summary

The Abilities Explorer experiment provides an admin interface for discovering, inspecting, testing, and documenting all abilities registered via the WordPress Abilities API. It adds a menu item ("Abilities Explorer") under the "Tools" menu in the WordPress admin that displays a searchable, filterable table of all registered abilities with the ability to view details and invoke abilities directly from the UI.

## Overview

### For End Users

When enabled, the Abilities Explorer adds an "Abilities Explorer" menu item under the "Tools" menu in the WordPress admin sidebar. This provides:

**Key Features:**

- Dashboard with statistics showing total abilities by provider (Core, Plugin, Theme)
- Searchable, sortable table of all registered abilities
- Filter abilities by provider type
- Detailed view for each ability showing input/output schemas
- Built-in test runner to invoke abilities with custom JSON input
- JSON validation against input schemas
- Copy-to-clipboard functionality for schemas

### For Developers

The experiment consists of four main classes:

1. **Abilities_Explorer** (`Abilities_Explorer.php`): Main experiment class handling registration and asset loading
2. **Admin_Page** (`Admin_Page.php`): Manages admin menu, page rendering, and AJAX handlers
3. **Ability_Handler** (`Ability_Handler.php`): Interfaces with the WordPress Abilities API to fetch, format, validate, and invoke abilities
4. **Ability_Table** (`Ability_Table.php`): Extends `WP_List_Table` for the abilities listing

## Architecture & Implementation

### Key Hooks & Entry Points

- `WordPress\AI\Experiments\Abilities_Explorer\Abilities_Explorer::register()` wires everything once enabled:
  - `admin_enqueue_scripts` → enqueues JS/CSS on the `toplevel_page_ai-abilities-explorer` screen
  - `admin_menu` → adds the top-level "Abilities" menu page
  - `wp_ajax_ai_ability_explorer_invoke` → handles AJAX ability invocation

### Assets & Data Flow

1. **PHP Side:**
   - `enqueue_assets()` loads `experiments/abilities-explorer` bundle and localizes `window.AbilityExplorer` with:
     - `enabled`: Whether the experiment is enabled
     - `ajaxUrl`: WordPress AJAX URL
     - `nonce`: Security nonce for AJAX requests
     - `strings`: Localized UI strings

2. **JavaScript Side:**
   - Handles test runner functionality (invoke, validate, clear)
   - Real-time JSON validation in the input textarea
   - Copy-to-clipboard for schemas
   - Auto-formatting of JSON input

### Admin Page Views

The admin page supports three views:

1. **List View** (`action=list`, default): Statistics dashboard + searchable table
2. **Detail View** (`action=view&ability=slug`): Full ability details with schemas
3. **Test Runner** (`action=test&ability=slug`): Interactive ability testing interface

### Provider Detection

Abilities are categorized by provider:

- **Core**: Abilities with `wordpress`, `wp`, or `core` namespace prefix
- **Theme**: Abilities matching the active theme's stylesheet or template name
- **Plugin**: All other abilities (default)

### Input Validation

The experiment performs JSON Schema validation:

- Required field checking
- Type validation (string, number, integer, boolean, array, object)
- Real-time JSON syntax validation in the UI

## Using the Test Runner

### Testing an Ability

1. Navigate to **Abilities Explorer** in the "Tools" menu in the admin menu
2. Find the ability you want to test
3. Click the **Test** button
4. Edit the JSON input (pre-populated with example values from the schema)
5. Click **Validate Input** to check JSON syntax and schema compliance
6. Click **Invoke Ability** to execute
7. View the result in the Result section

### Example Input Generation

The test runner automatically generates example input based on the ability's input schema:

- Uses `default` values if specified
- Falls back to `example` values
- Generates type-appropriate defaults (empty string, 0, false, empty array/object)

## Permissions

- Requires `manage_options` capability to access the admin page
- AJAX requests verify nonce and capability before invoking abilities

## Testing

### Manual Testing

1. **Enable the experiment:**
   - Go to `Settings → AI`
   - Toggle **Abilities Explorer** to enabled

2. **Test the list view:**
   - Navigate to **Abilities Explorer** in the "Tools" menu in the admin menu
   - Verify statistics are displayed
   - Search for abilities by name or description
   - Filter by provider type
   - Sort columns

3. **Test the detail view:**
   - Click on an ability name or **View** button
   - Verify input/output schemas are displayed
   - Test copy buttons

4. **Test the test runner:**
   - Click **Test** on any ability
   - Modify the JSON input
   - Click **Validate Input**
   - Click **Invoke Ability**
   - Verify results are displayed

## Notes & Considerations

### Requirements

- Requires the WordPress Abilities API (`wp_get_abilities`, `wp_get_ability` functions)
- Users must have `manage_options` capability

### Limitations

- Provider detection is heuristic-based and may not be accurate for all abilities
- Input validation is basic JSON Schema validation (required fields, types)
- Does not support nested object validation

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
