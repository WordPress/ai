<?php
/**
 * Stand-in for the generated settings route render function.
 *
 * `Requirements::check()` short-circuits its asset check under `WPAI_IS_TEST`, so
 * `build/pages/ai/page-wp-admin.php` -- which defines `ai_ai_wp_admin_render_page()` --
 * is never loaded during PHPUnit runs. Without that function, `Settings_Page::init()`
 * takes its fallback branch and never registers the settings page or its script module
 * data filter, leaving that wiring untestable.
 *
 * Defined only if the real function is absent, and declared in the global namespace so
 * the `function_exists()` check in `Settings_Page::init()` resolves to it.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Settings
 */

namespace {
	if ( function_exists( 'ai_ai_wp_admin_render_page' ) ) {
		return;
	}

	/**
	 * Renders nothing; the tests only need the function to exist.
	 */
	function ai_ai_wp_admin_render_page() {} // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Mirrors the generated route function name.
}
