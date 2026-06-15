<?php
/**
 * Plugin name: AI E2E Sample Settings
 * Description: Registers a setting flagged with `show_in_abilities`, used by E2E tests to verify the `core/settings` ability exposes settings registered by other active plugins.
 * Version: 0.1.0
 * Author: WordPress.org Contributors
 * Author URI: https://make.wordpress.org/ai/
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'init',
	static function (): void {
		register_setting(
			'general',
			'ai_e2e_sample_setting',
			array(
				'type'              => 'string',
				'label'             => 'AI E2E Sample Setting',
				'description'       => 'A sample setting exposed to the Abilities API for end-to-end testing.',
				'show_in_abilities' => true,
				'default'           => 'sample-default',
			)
		);
	}
);
