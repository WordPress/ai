<?php
/**
 * Plugin name: E2E Abilities Explorer Field
 * Description: E2E fixture that adds a "Slug length" column to the Abilities Explorer through the `ai.abilitiesExplorer.fields` JavaScript filter.
 * Version: 0.1.0
 * Author: WordPress.org Contributors
 * Author URI: https://make.wordpress.org/ai/
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_enqueue_scripts', 'ai_e2e_abilities_explorer_field_enqueue' );

/**
 * Enqueues the field script on the Abilities Explorer screen only.
 *
 * The script depends on `wp-hooks`, not on the Explorer's own handle: the
 * Explorer bundle loads deferred, so the filter must be added through the
 * shared hooks instance before (or after) the Explorer renders.
 *
 * @param string $hook_suffix The current admin page.
 */
function ai_e2e_abilities_explorer_field_enqueue( string $hook_suffix ): void {
	if ( 'tools_page_ai-abilities-explorer' !== $hook_suffix ) {
		return;
	}

	wp_enqueue_script(
		'ai-e2e-abilities-explorer-field',
		plugins_url( 'field.js', __FILE__ ),
		array( 'wp-hooks' ),
		'0.1.0',
		true
	);
}
