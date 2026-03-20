<?php
/**
 * Prompts generation class.
 *
 * @package WordPress\AI\Experiments
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Plugin_Builder\Ai;

/**
 * Prompt templates.
 *
 * @since x.x.x
 */
class Prompts {

	/**
	 * Planner prompt — generates a plugin architecture plan as JSON.
	 *
	 * @since x.x.x
	 *
	 * @param string     $description    User's plugin description.
	 * @param string     $complexity     'simple' or 'complex'.
	 * @param int        $max_files      Max number of files allowed.
	 * @param array|null $previous_plan  Previous plan for context (modification requests).
	 * @param array|null $previous_plan  Previous plan for context (modification requests).
	 * @return string The prompt string.
	 */
	public static function planner(
		string $description,
		string $complexity,
		int $max_files,
		?array $previous_plan = null
	): string {
		$context_section = '';
		if ( $previous_plan ) {
			$prev_plugin_name = $previous_plan['plugin_name'] ?? 'Unknown';
			$prev_plugin_slug = $previous_plan['plugin_slug'] ?? 'unknown';
			$prev_files_list  = '';

			if ( ! empty( $previous_plan['files'] ) ) {
				foreach ( $previous_plan['files'] as $pf ) {
					$prev_files_list .= "  - {$pf['path']}: {$pf['description']}\n";
				}
			}

			$context_section = <<<CONTEXT

## PREVIOUS GENERATION CONTEXT
The user previously generated a plugin. Consider their new request as a potential modification or iteration.

**Previous Plugin**: {$prev_plugin_name} (slug: `{$prev_plugin_slug}`)
**Previous Files**:
{$prev_files_list}

### Modification Guidelines
If the user is asking to modify, rename, fix, or improve the previous plugin:
- Keep the SAME plugin_slug unless they specifically want to rename it
- Only include files that need to be created or modified
- Reference the existing code structure and maintain consistency
- Set "is_modification": true in your response

If the user is asking for something completely different (new plugin), ignore the previous context.
CONTEXT;
		}

		return <<<PROMPT
You are an expert WordPress plugin architect. Given a user's description of a plugin they want, produce a detailed implementation plan as JSON.

## Rules
- Keep the implementation simple and avoid over-engineering.
- Do not write any actual code — only the plan.
- Only plan for PHP, CSS, JS, and JSON files. No build steps, no npm, no composer dependencies.
- The main plugin file must be at the root level (e.g., `my-plugin.php`), not inside a subdirectory.
- All function and class names MUST be prefixed with the plugin slug (e.g., `recipe_manager_register_cpt`).
- For simple plugins: 1-2 files. For complex plugins: up to {$max_files} files.
- Use WordPress coding standards and best practices.
- IMPORTANT: Generate unique, descriptive plugin slugs (e.g., `acme-maintenance-mode-2024` instead of just `maintenance-mode`) to avoid conflicts with existing WordPress.org plugins.

## Architecture Guidelines (from WordPress Agent Skills)
- Main plugin file contains the plugin header and bootstraps the plugin.
- Minimal boot file — a loader/class that registers hooks.
- Admin-only code behind `is_admin()` or admin hooks to reduce frontend overhead.
- Register activation/deactivation hooks at top-level scope in the main file, never inside other hooks.
- If the plugin registers CPTs/taxonomies, plan for rewrite rule flushing on activation.
- If the plugin stores options, plan for `register_setting()` with `sanitize_callback`.
- If the plugin creates custom tables, plan for schema versioning and upgrade routines.
- If the plugin needs background tasks, plan for WP-Cron with idempotent callbacks.
- Plan an `uninstall.php` file for any plugin that stores data in `wp_options` or custom tables.

## Security Planning
- Nonces for CSRF protection on ALL form submissions and AJAX handlers.
- Capability checks (`current_user_can()`) for authorization — nonces alone are not enough.
- Sanitize/validate input early, escape output late.
- `\$wpdb->prepare()` for ALL database queries with variables — never concatenate user input into SQL.
- If the plugin registers REST endpoints: always provide `permission_callback`, use `WP_REST_Request` for params (never `\$_GET`/`\$_POST` directly), define `args` with `validate_callback`/`sanitize_callback`.
{$context_section}
## Output Format
Return ONLY valid JSON matching this exact schema:

```json
{
  "plugin_name": "Human Readable Plugin Name",
  "plugin_slug": "kebab-case-slug",
  "description": "One-sentence description of what the plugin does",
  "complexity": "simple|complex",
  "is_modification": false,
  "files": [
    {
      "path": "plugin-slug.php",
      "type": "php",
      "description": "What this file does and what hooks/functions it contains",
      "is_main": true
    },
    {
      "path": "assets/admin.css",
      "type": "css",
      "description": "What styles this file provides",
      "is_main": false
    }
  ],
  "hooks_used": ["init", "admin_menu", "save_post"],
  "wp_apis_used": ["register_post_type", "add_meta_box", "wp_enqueue_style"],
  "security_notes": [
    "Nonce verification on all form submissions",
    "sanitize_text_field() on all text inputs",
    "current_user_can('manage_options') check on settings page"
  ],
  "architecture": "Brief description of how the files work together"
}
```

## User's Plugin Description
{$description}

## Requested Complexity
{$complexity}
PROMPT;
	}

	/**
	 * Coder prompt for PHP files.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $plan           Full plan as associative array.
	 * @param string $file_path      Path of the file to generate.
	 * @param string $file_description Purpose of the file.
	 * @param bool   $is_main        Whether this is the main plugin file.
	 * @param array<int, array<string, mixed>>  $previous_files Previously generated files [{path, content}, ...].
	 * @return string The prompt string.
	 */
	public static function coder_php( array $plan, string $file_path, string $file_description, bool $is_main, array $previous_files = array() ): string {
		$plan_json   = wp_json_encode( $plan, JSON_PRETTY_PRINT );
		$plugin_name = $plan['plugin_name'];
		$plugin_slug = $plan['plugin_slug'];
		$plugin_desc = $plan['description'];

		$main_section = '';
		if ( $is_main ) {
			$main_section = <<<MAIN

## Main Plugin File Requirements
This is the main plugin file. It MUST start with the WordPress plugin header:

```php
<?php
/**
 * Plugin Name: {$plugin_name}
 * Description: {$plugin_desc}
 * Version: 1.0.0
 * Author: WordPress AI Plugin Builder
 * License: GPL-2.0-or-later
 * Text Domain: {$plugin_slug}
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
```
MAIN;
		}

		$prev_section = '';
		if ( ! empty( $previous_files ) ) {
			$prev_section = "\n## Previously Generated Files (for reference/consistency)\n";
			foreach ( $previous_files as $pf ) {
				$content       = $pf['content'];
				$prev_section .= "### {$pf['path']}\n```php\n{$content}\n```\n";
			}
		}

		return <<<PROMPT
You are an expert WordPress PHP developer. Generate the PHP code for a single file in a WordPress plugin.

## WordPress Coding Standards (MUST follow)
- Use tabs for indentation (not spaces).
- Opening braces on the same line for functions/classes.
- Use `snake_case` for function names, `\$snake_case` for variables.
- Use Yoda conditions: `if ( 'value' === \$var )`.
- Spaces inside parentheses: `if ( \$condition )`, `function_call( \$arg )`.
- Always use strict comparison (`===`, `!==`).
- PHP 7.4+ minimum compatibility.

## Security Requirements (MANDATORY — from WordPress Agent Skills)
- NEVER use `\$_GET`, `\$_POST`, `\$_REQUEST` without sanitization. Read explicit keys only, never process the entire array.
- Use `wp_unslash()` before sanitizing when reading from superglobals.
- Use `sanitize_text_field()`, `sanitize_email()`, `absint()`, `wp_kses_post()` for input validation.
- Use `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()` for output escaping. Golden rule: sanitize on input, escape on output.
- Use `wp_verify_nonce()` on ALL form submissions and AJAX handlers. Nonces prevent CSRF but are NOT authorization — always pair with capability checks.
- Use `current_user_can()` for capability checks on ALL privileged operations.
- Use `\$wpdb->prepare()` for ALL database queries with variables. Never concatenate or interpolate user input into SQL strings.
- Prefix ALL functions, classes, constants, and option names with the plugin slug.
- No `eval()`, `exec()`, `system()`, `passthru()`, `shell_exec()`, `proc_open()`.

## Plugin Lifecycle (MUST follow)
- Register `register_activation_hook()` and `register_deactivation_hook()` at TOP-LEVEL scope in the main plugin file — never inside other hooks or callbacks.
- If the plugin registers CPTs or custom rewrite rules, flush rewrite rules on activation ONLY after registering them (call the registration function first, then `flush_rewrite_rules()`).
- On deactivation, clean up scheduled cron events with `wp_clear_scheduled_hook()`.
- If the plugin stores data (options, custom tables), provide an `uninstall.php` that checks `defined( 'WP_UNINSTALL_PLUGIN' )` before deleting data.

## Settings API (when applicable)
- Use `register_setting()` with a `sanitize_callback` for all options.
- Use `add_settings_section()` and `add_settings_field()` for settings pages.
- Use capability checks (typically `manage_options`) for settings screens.
- Escape option values on output with `esc_attr()`, `esc_html()`.

## REST API Endpoints (when applicable)
- Register routes on `rest_api_init` with `register_rest_route()`.
- Use a unique namespace (e.g., `plugin-slug/v1`). Never use the `wp/` namespace.
- ALWAYS provide `permission_callback` — use `__return_true` only for intentionally public endpoints.
- Use `WP_REST_Request` methods to read params — never `\$_GET`/`\$_POST` directly.
- Define `args` with `type`, `sanitize_callback`, and `validate_callback` for each parameter.
- Return data via `rest_ensure_response()` or `new WP_REST_Response()`.
- Return errors via `new WP_Error()` with an explicit HTTP `status` code.

## Data Storage (when applicable)
- Prefer Options API (`get_option`/`update_option`) for small config and state.
- Use custom tables only when truly needed. Store a schema version in an option and provide an upgrade routine.
- For cron tasks, ensure callbacks are idempotent (they may run late or multiple times).
{$main_section}

## Plugin Plan
```json
{$plan_json}
```

## File to Generate
- **Path**: `{$file_path}`
- **Purpose**: {$file_description}
{$prev_section}
## Instructions
Generate ONLY the PHP code for `{$file_path}`. Do not include markdown code fences — output raw PHP starting with `<?php`.
PROMPT;
	}

	/**
	 * Coder prompt for CSS files.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $plan           Full plan as associative array.
	 * @param string $file_path      Path of the file to generate.
	 * @param string $file_description Purpose of the file.
	 * @param array<int, array<string, mixed>>  $previous_files Previously generated files.
	 * @return string The prompt string.
	 */
	public static function coder_css( array $plan, string $file_path, string $file_description, array $previous_files = array() ): string {
		$plan_json   = wp_json_encode( $plan, JSON_PRETTY_PRINT );
		$plugin_slug = $plan['plugin_slug'];

		$prev_section = '';
		if ( ! empty( $previous_files ) ) {
			$prev_section = "\n## Previously Generated Files (for reference)\n";
			foreach ( $previous_files as $pf ) {
				$content       = mb_substr( $pf['content'], 0, 4000 );
				$prev_section .= "### {$pf['path']}\n```\n{$content}\n```\n";
			}
		}

		return <<<PROMPT
You are an expert WordPress frontend developer. Generate CSS code for a WordPress plugin admin/frontend stylesheet.

## Rules
- Use clean, well-organized CSS.
- Prefix all class names with the plugin slug to avoid conflicts (e.g., `.{$plugin_slug}-wrapper`).
- Use WordPress admin color variables where appropriate (`--wp-admin-theme-color`).
- Mobile-responsive where applicable.
- No CSS frameworks — plain CSS only.

## Plugin Plan
```json
{$plan_json}
```

## File to Generate
- **Path**: `{$file_path}`
- **Purpose**: {$file_description}
{$prev_section}
## Instructions
Generate ONLY the CSS code for `{$file_path}`. Do not include markdown code fences — output raw CSS.
PROMPT;
	}

	/**
	 * Coder prompt for JS files.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $plan           Full plan as associative array.
	 * @param string $file_path      Path of the file to generate.
	 * @param string $file_description Purpose of the file.
	 * @param array<int, array<string, mixed>>  $previous_files Previously generated files.
	 * @return string The prompt string.
	 */
	public static function coder_js( array $plan, string $file_path, string $file_description, array $previous_files = array() ): string {
		$plan_json = wp_json_encode( $plan, JSON_PRETTY_PRINT );

		$prev_section = '';
		if ( ! empty( $previous_files ) ) {
			$prev_section = "\n## Previously Generated Files (for reference)\n";
			foreach ( $previous_files as $pf ) {
				$content       = mb_substr( $pf['content'], 0, 4000 );
				$prev_section .= "### {$pf['path']}\n```\n{$content}\n```\n";
			}
		}

		return <<<PROMPT
You are an expert WordPress JavaScript developer. Generate JavaScript code for a WordPress plugin.

## Rules
- Use vanilla JavaScript (ES6+). No jQuery unless the plugin specifically requires it.
- Wrap in an IIFE or use `DOMContentLoaded` to avoid polluting the global scope.
- Prefix any global variables/functions with the plugin slug.
- Use `wp.ajax` or `fetch()` for AJAX calls — include the nonce in requests.
- No build step required — the JS must work as-is when enqueued.

## Plugin Plan
```json
{$plan_json}
```

## File to Generate
- **Path**: `{$file_path}`
- **Purpose**: {$file_description}
{$prev_section}
## Instructions
Generate ONLY the JavaScript code for `{$file_path}`. Do not include markdown code fences — output raw JavaScript.
PROMPT;
	}

	/**
	 * Get the system prompt for a given role.
	 *
	 * @since x.x.x
	 *
	 * @param string $role     Role (e.g. planner, coder).
	 * @param string $file_type File type (optional).
	 * @return string The system prompt string.
	 */
	public static function system_prompt( string $role, string $file_type = '' ): string {
		switch ( $role ) {
			case 'planner':
				return 'You are an expert WordPress plugin architect. Return only valid JSON.';
			case 'coder':
				$type = strtoupper( '' !== $file_type ? $file_type : 'PHP' );
				return "You are an expert WordPress {$type} developer. Output ONLY raw code, no markdown.";
			case 'reviewer':
				return 'You are a senior WordPress security reviewer. Return only valid JSON.';
			default:
				return '';
		}
	}
}
