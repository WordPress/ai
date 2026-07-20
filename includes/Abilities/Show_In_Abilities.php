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
 * It is intentionally object-type-agnostic: today it marks settings and post types; meta
 * can be marked here the same way when those abilities land.
 *
 * Timing: the `core/read-settings` ability ensures core's initial settings are registered,
 * then snapshots the exposed settings when it registers on `wp_abilities_api_init`. Any
 * other setting therefore has to be flagged with `show_in_abilities` before that hook fires
 * — i.e. its `register_setting()` call must run before abilities init — for the ability to
 * pick it up.
 *
 * Post types must be registered with `show_in_abilities` before `core/read-content` is
 * registered so they are included in the ability's input schema.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since 1.1.0
 * @since 1.2.0 Also marks curated post types.
 */
final class Show_In_Abilities {

	/**
	 * Registers the hooks that mark core objects as exposed to abilities.
	 *
	 * @since 1.1.0
	 * @since 1.2.0 Also marks curated post types.
	 */
	public function register(): void {
		add_filter( 'register_setting_args', array( $this, 'mark_setting' ), 10, 4 );
		add_filter( 'register_post_type_args', array( $this, 'mark_post_type' ), 10, 2 );

		/*
		 * Core post types (post, page) are registered very early — during bootstrap and on
		 * `init` priority 0 — which is typically before this component runs, so the filter
		 * above would miss them. Mark any already-registered curated post types directly.
		 */
		$this->mark_registered_post_types();
	}

	/**
	 * Checks whether WordPress core declares the setting flag natively.
	 *
	 * `register_setting()` applies the `register_setting_args` filter before merging the
	 * caller's arguments over its defaults, so the defaults array is core's own statement of
	 * which arguments it understands. Once `show_in_abilities` appears there, core owns the
	 * flag: it picks the default and each setting opts in through `register_setting()`, the
	 * way `show_in_rest` already works.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $defaults The default registration arguments.
	 * @return bool True when core declares `show_in_abilities` as a setting argument.
	 */
	private function core_declares_setting_flag( $defaults ): bool {
		return is_array( $defaults ) && array_key_exists( 'show_in_abilities', $defaults );
	}

	/**
	 * Adds the `show_in_abilities` flag to curated core settings as they are registered.
	 *
	 * Respects an explicit `show_in_abilities` value already present in the registration
	 * arguments, including an explicit `false` opt-out, only filling it in when the key is
	 * absent entirely. Does nothing once core declares the flag natively, so the polyfill
	 * never overrides a default core chose.
	 *
	 * @since 1.1.0
	 * @since 1.2.0 Respects an explicit falsy value, and stands down once core declares the flag.
	 *
	 * @param mixed                $args         The setting registration arguments.
	 * @param array<string, mixed> $defaults     The default registration arguments.
	 * @param string               $option_group The settings group.
	 * @param string               $option_name  The option name.
	 * @return mixed The (possibly amended) registration arguments.
	 */
	public function mark_setting( $args, $defaults, $option_group, $option_name ) {
		if ( ! is_array( $args ) || $this->core_declares_setting_flag( $defaults ) ) {
			return $args;
		}

		$settings = $this->settings_map();

		if ( isset( $settings[ $option_name ] ) && ! array_key_exists( 'show_in_abilities', $args ) ) {
			$args['show_in_abilities'] = $settings[ $option_name ];
		}

		return $args;
	}

	/**
	 * Checks whether WordPress core declares the post type flag natively.
	 *
	 * Passing a class name to {@see property_exists()} reports only properties declared by
	 * the class. Passing an object also reports dynamic properties, which is what
	 * {@see self::mark_registered_post_types()} sets, so the two forms answer different
	 * questions and must not be interchanged.
	 *
	 * Once core declares the property, core owns the flag: it decides the default and honors
	 * `register_post_type()` arguments itself, and this polyfill must step aside rather than
	 * try to distinguish "core defaulted it to false" from "a site opted out".
	 *
	 * @since 1.2.0
	 *
	 * @return bool True when core declares `show_in_abilities` on {@see WP_Post_Type}.
	 */
	private function core_declares_post_type_flag(): bool {
		return property_exists( \WP_Post_Type::class, 'show_in_abilities' );
	}

	/**
	 * Adds the `show_in_abilities` flag to curated core post types as they are registered.
	 *
	 * Respects an explicit `show_in_abilities` value already present in the registration
	 * arguments — including an explicit `false` opt-out — only filling it in when the key is
	 * absent entirely. Does nothing once core declares the flag natively.
	 *
	 * @since 1.2.0
	 *
	 * @param array<string, mixed> $args      The post type registration arguments.
	 * @param string               $post_type The post type key.
	 * @return array<string, mixed> The (possibly amended) registration arguments.
	 */
	public function mark_post_type( array $args, string $post_type ): array {
		if ( $this->core_declares_post_type_flag() ) {
			return $args;
		}

		$post_types = $this->post_types_map();

		if ( isset( $post_types[ $post_type ] ) && ! array_key_exists( 'show_in_abilities', $args ) ) {
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
	 * A `show_in_abilities` property already set on the object — including an explicit
	 * `false` opt-out — is left untouched.
	 *
	 * Both this method and {@see self::mark_post_type()} stand down once core declares the
	 * flag, so the two exposure paths cannot disagree.
	 *
	 * @since 1.2.0
	 */
	public function mark_registered_post_types(): void {
		if ( $this->core_declares_post_type_flag() ) {
			return;
		}

		foreach ( $this->post_types_map() as $post_type => $show ) {
			$object = get_post_type_object( $post_type );

			// The class does not declare the property, so this only matches a value already set.
			if ( ! ( $object instanceof \WP_Post_Type ) || property_exists( $object, 'show_in_abilities' ) ) {
				continue;
			}

			$object->show_in_abilities = $show; // @phpstan-ignore property.notFound (WP_Post_Type permits dynamic properties; core is expected to declare this one.)
		}
	}

	/**
	 * Returns the curated core post types to expose, keyed by post type key.
	 *
	 * The value is whatever `show_in_abilities` should contain: `true`, or an array
	 * reserved for enabling specific operations in the future. This matches the set
	 * marked natively by the core `core/read-content` implementation (`post` and `page`).
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, bool|array<string, mixed>> Post types map keyed by post type key.
	 */
	private function post_types_map(): array {
		return array(
			'post' => true,
			'page' => true,
		);
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
