<?php
/**
 * Plugin name: AI E2E Sample Content
 * Description: Registers a post type flagged with `show_in_abilities` and seeds a sample post, used by E2E tests to verify the `core/content` ability exposes content registered by other active plugins.
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
		register_post_type(
			'ai_e2e_sample',
			array(
				'label'             => 'AI E2E Sample',
				'public'            => true,
				'show_in_rest'      => true,
				'show_in_abilities' => true,
				'supports'          => array( 'title', 'editor', 'excerpt', 'author' ),
			)
		);
	},
	5
);

// Seed a published sample post once, after the post type is registered.
add_action(
	'init',
	static function (): void {
		if ( get_page_by_path( 'ai-e2e-sample-content', OBJECT, 'ai_e2e_sample' ) ) {
			return;
		}

		wp_insert_post(
			array(
				'post_type'    => 'ai_e2e_sample',
				'post_name'    => 'ai-e2e-sample-content',
				'post_title'   => 'AI E2E Sample Content',
				'post_content' => 'Sample content body for end-to-end testing.',
				'post_status'  => 'publish',
			)
		);
	},
	20
);
