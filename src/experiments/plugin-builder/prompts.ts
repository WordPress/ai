import { PluginFile, PluginPlan } from './types';

export function getSystemPrompt(
	role: 'planner' | 'coder' | 'reviewer' | 'detector' | 'analyzer',
	fileType?: string
): string {
	switch ( role ) {
		case 'planner':
			return 'You are an expert WordPress plugin architect. Return only valid JSON.';
		case 'coder':
			const type = ( fileType || 'PHP' ).toUpperCase();
			return `You are an expert WordPress ${ type } developer. Output ONLY raw code, no markdown.`;
		case 'reviewer':
			return 'You are a senior WordPress security reviewer. Return only valid JSON.';
		case 'analyzer':
			return 'You are an expert WordPress plugin analyzer. Return only valid JSON.';
		case 'detector':
			return `You are an intent classifier for a WordPress AI Plugin Builder. Your job is to determine what the user wants.

Classify the user's intent into one of these categories:
- plugin_request: User wants to create a new WordPress plugin (most common)
- modification_request: User wants to modify or improve a previously generated plugin
- question: User is asking a question about WordPress, plugins, or how to do something
- other: Request is unclear, off-topic, or inappropriate

Return ONLY valid JSON in this exact format:
{
    "intent": "plugin_request|modification_request|question|other",
    "confidence": 0.0-1.0,
    "response": "If intent is 'question' or 'other', provide a helpful response here. Otherwise null."
}

Examples:
- "Create a contact form plugin" → plugin_request
- "Build me a plugin that adds a maintenance mode" → plugin_request
- "A dashboard widget showing posts" → plugin_request
- "Rename the plugin to XYZ" → modification_request (if previous context exists)
- "Add a settings page" → modification_request (if previous context exists)
- "Can I modify existing plugins?" → question
- "How do WordPress hooks work?" → question
- "What is the best way to add custom fields?" → question
- "Hello" → other
- "Make me money" → other`;
		default:
			return '';
	}
}

export function getIntentPrompt(
	description: string,
	previousPlan: PluginPlan | null
): string {
	let context = '';
	if ( previousPlan ) {
		context = `\n\nPREVIOUS CONTEXT: The user previously generated a plugin named "${
			previousPlan.plugin_name || 'Unknown'
		}" (slug: ${
			previousPlan.plugin_slug || 'unknown'
		}). If they're asking to modify, rename, or improve it, classify as modification_request.`;
	}
	return `User input: "${ description }"${ context }`;
}

export function getPlannerPrompt(
	description: string,
	complexity: string,
	maxFiles: number,
	previousPlan: PluginPlan | null
): string {
	let contextSection = '';
	if ( previousPlan ) {
		const prevName = previousPlan.plugin_name || 'Unknown';
		const prevSlug = previousPlan.plugin_slug || 'unknown';
		let prevFilesList = '';

		if ( previousPlan.files && previousPlan.files.length > 0 ) {
			for ( const pf of previousPlan.files ) {
				prevFilesList += `  - ${ pf.path }: ${ pf.description }\n`;
			}
		}

		contextSection = `
## PREVIOUS GENERATION CONTEXT
The user previously generated a plugin. Consider their new request as a potential modification or iteration.

**Previous Plugin**: ${ prevName } (slug: \`${ prevSlug }\`)
**Previous Files**:
${ prevFilesList }

### Modification Guidelines
If the user is asking to modify, rename, fix, or improve the previous plugin:
- Keep the SAME plugin_slug unless they specifically want to rename it
- Only include files that need to be created or modified
- Reference the existing code structure and maintain consistency
- Set "is_modification": true in your response

If the user is asking for something completely different (new plugin), ignore the previous context.
`;
	}

	return `You are an expert WordPress plugin architect. Given a user's description of a plugin they want, produce a detailed implementation plan as JSON.

## Rules
- Keep the implementation simple and avoid over-engineering.
- Do not write any actual code — only the plan.
- Only plan for PHP, CSS, JS, and JSON files. No build steps, no npm, no composer dependencies.
- The main plugin file must be at the root level (e.g., \`my-plugin.php\`), not inside a subdirectory.
- All function and class names MUST be prefixed with the plugin slug (e.g., \`recipe_manager_register_cpt\`).
- For simple plugins: 1-2 files. For complex plugins: up to ${ maxFiles } files.
- Use WordPress coding standards and best practices.
- IMPORTANT: Generate unique, descriptive plugin slugs (e.g., \`acme-maintenance-mode-2024\` instead of just \`maintenance-mode\`) to avoid conflicts with existing WordPress.org plugins.

## Architecture Guidelines (from WordPress Agent Skills)
- Main plugin file contains the plugin header and bootstraps the plugin.
- Minimal boot file — a loader/class that registers hooks.
- Admin-only code behind \`is_admin()\` or admin hooks to reduce frontend overhead.
- Register activation/deactivation hooks at top-level scope in the main file, never inside other hooks.
- If the plugin registers CPTs/taxonomies, plan for rewrite rule flushing on activation.
- If the plugin stores options, plan for \`register_setting()\` with \`sanitize_callback\`.
- If the plugin creates custom tables, plan for schema versioning and upgrade routines.
- If the plugin needs background tasks, plan for WP-Cron with idempotent callbacks.
- Plan an \`uninstall.php\` file for any plugin that stores data in \`wp_options\` or custom tables.

## Security Planning
- Nonces for CSRF protection on ALL form submissions and AJAX handlers.
- Capability checks (\`current_user_can()\`) for authorization — nonces alone are not enough.
- Sanitize/validate input early, escape output late.
- \`$wpdb->prepare()\` for ALL database queries with variables — never concatenate user input into SQL.
- If the plugin registers REST endpoints: always provide \`permission_callback\`, use \`WP_REST_Request\` for params (never \`$_GET\`/\`$_POST\` directly), define \`args\` with \`validate_callback\`/\`sanitize_callback\`.
${ contextSection }
## Output Format
Return ONLY valid JSON matching this exact schema:

\`\`\`json
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
\`\`\`

## User's Plugin Description
${ description }

## Requested Complexity
${ complexity }
`;
}

export function getCoderPrompt(
	plan: PluginPlan,
	fileInfo: PluginFile,
	previousFiles: PluginFile[] = []
): string {
	const fileType = fileInfo.type || 'php';
	const previousContext = buildContext( previousFiles );
	const planJson = JSON.stringify( plan, null, 2 );

	if ( fileType === 'css' ) {
		return `You are an expert WordPress frontend developer. Generate CSS code for a WordPress plugin admin/frontend stylesheet.

## Rules
- Use clean, well-organized CSS.
- Prefix all class names with the plugin slug to avoid conflicts (e.g., \`.${ plan.plugin_slug }-wrapper\`).
- Use WordPress admin color variables where appropriate (\`--wp-admin-theme-color\`).
- Mobile-responsive where applicable.
- No CSS frameworks — plain CSS only.

## Plugin Plan
\`\`\`json
${ planJson }
\`\`\`

## File to Generate
- **Path**: \`${ fileInfo.path }\`
- **Purpose**: ${ fileInfo.description }
${ previousContext }
## Instructions
Generate ONLY the CSS code for \`${ fileInfo.path }\`. Do not include markdown code fences — output raw CSS.
`;
	}

	if ( fileType === 'js' ) {
		return `You are an expert WordPress JavaScript developer. Generate JavaScript code for a WordPress plugin.

## Rules
- Use vanilla JavaScript (ES6+). No jQuery unless the plugin specifically requires it.
- Wrap in an IIFE or use \`DOMContentLoaded\` to avoid polluting the global scope.
- Prefix any global variables/functions with the plugin slug.
- Use \`wp.ajax\` or \`fetch()\` for AJAX calls — include the nonce in requests.
- No build step required — the JS must work as-is when enqueued.

## Plugin Plan
\`\`\`json
${ planJson }
\`\`\`

## File to Generate
- **Path**: \`${ fileInfo.path }\`
- **Purpose**: ${ fileInfo.description }
${ previousContext }
## Instructions
Generate ONLY the JavaScript code for \`${ fileInfo.path }\`. Do not include markdown code fences — output raw JavaScript.
`;
	}

	// Default to PHP
	let mainSection = '';
	if ( fileInfo.is_main ) {
		mainSection = `
## Main Plugin File Requirements
This is the main plugin file. It MUST start with the WordPress plugin header:

\`\`\`php
<?php
/**
 * Plugin Name: ${ plan.plugin_name }
 * Description: ${ plan.description }
 * Version: 1.0.0
 * Author: WordPress AI Plugin Builder
 * License: GPL-2.0-or-later
 * Text Domain: ${ plan.plugin_slug }
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
\`\`\`
`;
	}

	return `You are an expert WordPress PHP developer. Generate the PHP code for a single file in a WordPress plugin.

## WordPress Coding Standards (MUST follow)
- Use tabs for indentation (not spaces).
- Opening braces on the same line for functions/classes.
- Use \`snake_case\` for function names, \`$snake_case\` for variables.
- Use Yoda conditions: \`if ( 'value' === $var )\`.
- Spaces inside parentheses: \`if ( $condition )\`, \`function_call( $arg )\`.
- Always use strict comparison (\`===\`, \`!==\`).
- PHP 7.4+ minimum compatibility.

## Security Requirements (MANDATORY — from WordPress Agent Skills)
- NEVER use \`$_GET\`, \`$_POST\`, \`$_REQUEST\` without sanitization. Read explicit keys only, never process the entire array.
- Use \`wp_unslash()\` before sanitizing when reading from superglobals.
- Use \`sanitize_text_field()\`, \`sanitize_email()\`, \`absint()\`, \`wp_kses_post()\` for input validation.
- Use \`esc_html()\`, \`esc_attr()\`, \`esc_url()\`, \`wp_kses_post()\` for output escaping. Golden rule: sanitize on input, escape on output.
- Use \`wp_verify_nonce()\` on ALL form submissions and AJAX handlers. Nonces prevent CSRF but are NOT authorization — always pair with capability checks.
- Use \`current_user_can()\` for capability checks on ALL privileged operations.
- Use \`$wpdb->prepare()\` for ALL database queries with variables. Never concatenate or interpolate user input into SQL strings.
- Prefix ALL functions, classes, constants, and option names with the plugin slug.
- No \`eval()\`, \`exec()\`, \`system()\`, \`passthru()\`, \`shell_exec()\`, \`proc_open()\`.

## Plugin Lifecycle (MUST follow)
- Register \`register_activation_hook()\` and \`register_deactivation_hook()\` at TOP-LEVEL scope in the main plugin file — never inside other hooks or callbacks.
- If the plugin registers CPTs or custom rewrite rules, flush rewrite rules on activation ONLY after registering them (call the registration function first, then \`flush_rewrite_rules()\`).
- On deactivation, clean up scheduled cron events with \`wp_clear_scheduled_hook()\`.
- If the plugin stores data (options, custom tables), provide an \`uninstall.php\` that checks \`defined( 'WP_UNINSTALL_PLUGIN' )\` before deleting data.

## Settings API (when applicable)
- Use \`register_setting()\` with a \`sanitize_callback\` for all options.
- Use \`add_settings_section()\` and \`add_settings_field()\` for settings pages.
- Use capability checks (typically \`manage_options\`) for settings screens.
- Escape option values on output with \`esc_attr()\`, \`esc_html()\`.

## REST API Endpoints (when applicable)
- Register routes on \`rest_api_init\` with \`register_rest_route()\`.
- Use a unique namespace (e.g., \`plugin-slug/v1\`). Never use the \`wp/\` namespace.
- ALWAYS provide \`permission_callback\` — use \`__return_true\` only for intentionally public endpoints.
- Use \`WP_REST_Request\` methods to read params — never \`$_GET\`/\`$_POST\` directly.
- Define \`args\` with \`type\`, \`sanitize_callback\`, and \`validate_callback\` for each parameter.
- Return data via \`rest_ensure_response()\` or \`new WP_REST_Response()\`.
- Return errors via \`new WP_Error()\` with an explicit HTTP \`status\` code.

## Data Storage (when applicable)
- Prefer Options API (\`get_option\`/\`update_option\`) for small config and state.
- Use custom tables only when truly needed. Store a schema version in an option and provide an upgrade routine.
- For cron tasks, ensure callbacks are idempotent (they may run late or multiple times).
${ mainSection }

## Plugin Plan
\`\`\`json
${ planJson }
\`\`\`

## File to Generate
- **Path**: \`${ fileInfo.path }\`
- **Purpose**: ${ fileInfo.description }
${ previousContext }
## Instructions
Generate ONLY the PHP code for \`${ fileInfo.path }\`. Do not include markdown code fences — output raw PHP starting with \`<?php\`.
`;
}

function buildContext( previousFiles: PluginFile[] ): string {
	if ( ! previousFiles || previousFiles.length === 0 ) {
		return '';
	}

	const maxLines = previousFiles.length <= 5 ? 2000 : 1000;
	let prevSection =
		'\n## Previously Generated Files (for reference/consistency)\n';

	for ( const pf of previousFiles ) {
		const content = pf.content || '';
		const lines = content.split( '\n' );
		let truncatedContent = content;

		if ( lines.length > maxLines ) {
			const omitted = lines.length - maxLines;
			truncatedContent =
				lines.slice( 0, maxLines ).join( '\n' ) +
				`\n// ... truncated (${ omitted } lines omitted)`;
		}

		prevSection += `### ${ pf.path }\n\`\`\`\n${ truncatedContent }\n\`\`\`\n`;
	}

	return prevSection;
}

export function getAnalyzerPrompt(
	files: PluginFile[],
	existingCommands: { name: string; label: string }[]
): string {
	let filesContext = '';
	for ( const f of files ) {
		filesContext += `\n### ${ f.path }\n\`\`\`${ f.type }\n${ f.content || '' }\n\`\`\`\n`;
	}

	const cmdsJson = JSON.stringify( existingCommands, null, 2 );

	return `Analyze the following newly generated WordPress plugin files to determine how a user can interact with it.

## Your Task
1. Check if the plugin registers any settings pages, admin menus, custom post types, or distinct frontend functionalities.
2. Review the provided list of \`existingCommands\`.
3. If the plugin adds an admin interface (like exactly an options page or a new menu item) that isn't already covered by \`existingCommands\`, you must register a new command for it. Provide its \`name\` (e.g., \`myplugin/settings\`), a clear \`label\` (e.g., \`Go to: MyPlugin Settings\`), and the exact \`url\` required to reach it (e.g., \`options-general.php?page=my-plugin\`).
4. Finally, suggest 1 to 3 command \`name\`s that the user should likely execute next to observe the plugin in action. Use a mix of your newly registered commands or existing core commands. Order them by preference.

## Existing Commands
\`\`\`json
${ cmdsJson }
\`\`\`

## Plugin Files
${ filesContext }

Return ONLY valid JSON matching this exact schema:

\`\`\`json
{
  "new_commands": [
    {
      "name": "myplugin/settings",
      "label": "Go to: MyPlugin Settings",
      "url": "options-general.php?page=my-plugin"
    }
  ],
  "suggested_commands": [
    "myplugin/settings",
    "core/dashboard"
  ]
}
\`\`\``;
}
