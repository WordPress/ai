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
class Show_In_Abilities {

	/**
	 * Registers the hooks that mark core objects as exposed to abilities.
	 *
	 * @since 1.1.0
	 */
	public static function register(): void {
		add_filter( 'register_setting_args', array( self::class, 'mark_setting' ), 10, 4 );
		add_filter( 'register_post_type_args', array( self::class, 'mark_post_type' ), 10, 2 );

		/*
		 * Core post types (post, page) are registered very early — during bootstrap and on
		 * `init` priority 0 — which is typically before this component runs, so the filter
		 * above would miss them. Mark any already-registered curated post types directly.
		 */
		self::mark_registered_post_types();
	}

	/**
	 * Adds the `show_in_abilities` flag to curated core settings as they are registered.
	 *
	 * Respects an explicit `show_in_abilities` value already present on the setting (for
	 * example once core ships it natively), only filling it in when absent.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $args         The setting registration arguments.
	 * @param array<string, mixed> $defaults     The default registration arguments.
	 * @param string               $option_group The settings group.
	 * @param string               $option_name  The option name.
	 * @return array<string, mixed> The (possibly amended) registration arguments.
	 */
	public static function mark_setting( array $args, array $defaults, string $option_group, string $option_name ): array {
		$settings = self::settings_map();

		if ( isset( $settings[ $option_name ] ) && empty( $args['show_in_abilities'] ) ) {
			$args['show_in_abilities'] = $settings[ $option_name ];
		}

		return $args;
	}

	/**
	 * Adds the `show_in_abilities` flag to curated core post types as they are registered.
	 *
	 * Mirrors {@see self::mark_setting()}: respects an explicit `show_in_abilities` value
	 * already present on the post type (for example once core ships it natively), only
	 * filling it in when absent.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $args      The post type registration arguments.
	 * @param string               $post_type The post type key.
	 * @return array<string, mixed> The (possibly amended) registration arguments.
	 */
	public static function mark_post_type( array $args, string $post_type ): array {
		$post_types = self::post_types_map();

		if ( isset( $post_types[ $post_type ] ) && empty( $args['show_in_abilities'] ) ) {
			$args['show_in_abilities'] = $post_types[ $post_type ];
		}

		return $args;
	}

	/**
	 * Marks already-registered curated post types as exposed to abilities.
	 *
	 * The `register_post_type_args` filter only affects post types registered after it is
	 * added, but core post types are registered during bootstrap. This patches the existing
	 * post type objects directly so the polyfill works regardless of when it runs.
	 * {@see WP_Post_Type} allows dynamic properties, so this is safe on stock WordPress.
	 *
	 * @since 1.1.0
	 */
	public static function mark_registered_post_types(): void {
		foreach ( self::post_types_map() as $post_type => $show ) {
			$object = get_post_type_object( $post_type );
			if ( ! ( $object instanceof \WP_Post_Type ) || ! empty( $object->show_in_abilities ) ) {
				continue;
			}

			$object->show_in_abilities = $show;
		}
	}

	/**
	 * Returns the curated core post types to expose, keyed by post type key.
	 *
	 * The value is whatever `show_in_abilities` should contain: `true`, or an array
	 * reserved for enabling specific operations in the future. This matches the set
	 * marked natively by the core `core/content` implementation (`post` and `page`).
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, bool|array<string, mixed>> Post types map keyed by post type key.
	 */
	public static function post_types_map(): array {
		return array(
			'post' => true,
			'page' => true,
		);
	}

	/**
	 * Returns the curated core settings to expose, keyed by option name.
	 *
	 * The value is whatever `show_in_abilities` should contain: `true`, or an array with
	 * optional `name` and `schema` keys (mirroring the `show_in_rest` shape). This matches
	 * the set marked natively by the core `core/settings` implementation.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, bool|array<string, mixed>> Settings map keyed by option name.
	 */
	public static function settings_map(): array {
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
