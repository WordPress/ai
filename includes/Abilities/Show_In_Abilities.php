<?php
/**
 * Polyfills the core `show_in_abilities` flag onto curated core objects.
 *
 * @package WordPress\AI
 *
 * @since 1.1.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Show_In_Abilities
 *
 * WordPress core does not yet ship the `show_in_abilities` flag consumed by the
 * `core/read-settings` ability (and, in the future, post type and meta abilities). This
 * component polyfills that flag onto a curated set of core objects so the abilities
 * return data on a stock site, before/without the equivalent core change.
 *
 * It is intentionally object-type-agnostic: today it marks settings; post types and
 * meta can be marked here the same way when those abilities land.
 *
 * Timing: the `core/read-settings` ability snapshots the exposed settings when it registers
 * on `wp_abilities_api_init`. A setting therefore has to be flagged with `show_in_abilities`
 * before that hook fires — i.e. its `register_setting()` call must run before abilities
 * init — for the ability to pick it up.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since 1.1.0
 */
final class Show_In_Abilities {

	/**
	 * Registers the hooks that mark core objects as exposed to abilities.
	 *
	 * @since 1.1.0
	 */
	public function register(): void {
		add_filter( 'register_setting_args', array( $this, 'mark_setting' ), 10, 4 );
	}

	/**
	 * Adds the `show_in_abilities` flag to curated core settings as they are registered.
	 *
	 * Respects an explicit `show_in_abilities` value already present on the setting (for
	 * example once core ships it natively), only filling it in when absent.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed                $args         The setting registration arguments.
	 * @param array<string, mixed> $defaults     The default registration arguments.
	 * @param string               $option_group The settings group.
	 * @param string               $option_name  The option name.
	 * @return mixed The (possibly amended) registration arguments.
	 */
	public function mark_setting( $args, $defaults, $option_group, $option_name ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}

		$settings = $this->settings_map();

		if ( isset( $settings[ $option_name ] ) && empty( $args['show_in_abilities'] ) ) {
			$args['show_in_abilities'] = $settings[ $option_name ];
		}

		return $args;
	}

	/**
	 * Returns the curated core settings to expose, keyed by option name.
	 *
	 * The value is whatever `show_in_abilities` should contain: `true`, or an array with
	 * optional `name` and `schema` keys (mirroring the `show_in_rest` shape).
	 *
	 * This list is kept 1:1 with the settings core flags `show_in_abilities` on in
	 * `register_initial_settings()` (wp-includes/option.php), preserving the same group order.
	 * Keep the two in sync when adding or removing entries.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, bool|array<string, mixed>> Settings map keyed by option name.
	 */
	private function settings_map(): array {
		return array(
			// General.
			'blogname'               => true,
			'blogdescription'        => true,
			'siteurl'                => true,
			'admin_email'            => array( 'schema' => array( 'format' => 'email' ) ),
			'timezone_string'        => true,
			'date_format'            => true,
			'time_format'            => true,
			'start_of_week'          => true,
			'WPLANG'                 => true,
			// Writing.
			'use_smilies'            => true,
			'default_category'       => true,
			'default_post_format'    => true,
			// Reading.
			'posts_per_page'         => true,
			'show_on_front'          => true,
			'page_on_front'          => true,
			'page_for_posts'         => true,
			// Discussion.
			'default_ping_status'    => array( 'schema' => array( 'enum' => array( 'open', 'closed' ) ) ),
			'default_comment_status' => array( 'schema' => array( 'enum' => array( 'open', 'closed' ) ) ),
		);
	}
}
