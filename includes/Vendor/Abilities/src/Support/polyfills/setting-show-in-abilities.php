<?php
/**
 * Polyfill for the `show_in_abilities` setting registration argument.
 *
 * For WordPress 6.9-7.1, where the argument does not exist yet
 * but abilities exposing settings, such as `core/settings-get`, read
 * `get_registered_settings()` and keep the settings flagged with it.
 *
 * Semantics mirrored from core trunk (wp-includes/option.php):
 *
 * - `show_in_abilities` defaults to false for every setting. Settings opt in
 *   by passing the argument to register_setting(). On 6.9-7.1,
 *   register_setting() keeps unknown arguments in the registered setting
 *   data, so a plugin's own `show_in_abilities` value already behaves like
 *   core's declared argument. An explicit value is never widened or
 *   overridden.
 * - Core opts in exactly the settings register_initial_settings() flags on
 *   trunk. This polyfill injects that same default through the
 *   `register_setting_args` filter, and catches up the ones registered before
 *   it loaded.
 * - Trunk also rewrote the `siteurl` description in 7.2. The polyfill gives
 *   that setting an equivalent `show_in_abilities` array instead of `true`,
 *   carrying trunk's description, so the ability's schema matches trunk while
 *   the host's own registration, and its REST settings schema, stay as they
 *   are.
 * - Trunk registers `wp_page_for_privacy_policy` in register_initial_settings()
 *   since 7.2, exposed as `page_for_privacy_policy`. Older hosts do not
 *   register it at all, so the polyfill registers it for abilities only:
 *   with `show_in_rest` left false, so the REST settings endpoint of the host
 *   is unchanged, and the exposed name given in `show_in_abilities` instead,
 *   in trunk's place among the registered settings.
 * - Core registers its initial settings on `rest_api_init`, which fires
 *   lazily and independently of `wp_abilities_api_init`. Trunk registers them
 *   early through _wp_register_initial_settings_for_abilities(), at priority 1
 *   of `wp_abilities_api_init`, restoring `$new_allowed_options` so the
 *   options screens are not affected. This polyfill does the same when it
 *   loads, which for a deferred registration is that same priority (see
 *   Registrar::queue_feature_preloads()), before the requiring ability takes
 *   its exposed-settings snapshot.
 *
 * Loading: through require_once, only from the Registrar (via
 * Support\Features) when an ability requires the feature and core lacks it.
 * Never autoloaded.
 *
 * Removal cue: delete this file and its Features::$polyfills map entry when
 * the package's minimum supported WordPress version rises to 7.2 — every
 * consuming site then has core's declared setting argument and
 * _wp_register_initial_settings_for_abilities(), the polyfill can never load
 * again, and Features::is_available() reports the native support.
 *
 * @package WordPress\Abilities
 */

/**
 * The settings trunk's register_initial_settings() flags with
 * `show_in_abilities`, keyed by option name, in trunk's order, each with the
 * `show_in_abilities` value the polyfill gives it.
 *
 * Keep in sync with trunk when a fixture refresh changes the list.
 */
$wordpress_abilities_exposed_core_settings = array(
	// General.
	'blogname'                   => true,
	'blogdescription'            => true,
	// Trunk's 7.2 description; the host's show_in_rest name and schema, restated.
	'siteurl'                    => array(
		'name'   => 'url',
		'schema' => array(
			'format'      => 'uri',
			'description' => __( 'The URL where WordPress core files are served. May differ from the public site URL.' ),
		),
	),
	'admin_email'                => true,
	'timezone_string'            => true,
	'date_format'                => true,
	'time_format'                => true,
	'start_of_week'              => true,
	'WPLANG'                     => true,
	// Writing.
	'use_smilies'                => true,
	'default_category'           => true,
	'default_post_format'        => true,
	// Reading.
	'posts_per_page'             => true,
	'show_on_front'              => true,
	'page_on_front'              => true,
	'page_for_posts'             => true,
	'wp_page_for_privacy_policy' => true,
	// Discussion.
	'default_ping_status'        => true,
	'default_comment_status'     => true,
);

/**
 * Injects the core default `show_in_abilities` value into registration args.
 *
 * Only fills the default for the settings core itself exposes on trunk; any
 * explicit value already present in the args is kept as-is.
 *
 * @param array<mixed> $args         Registration arguments for the setting.
 * @param array<mixed> $defaults     Default registration arguments.
 * @param string       $option_group The settings group.
 * @param string       $option_name  The option name.
 * @return array<mixed> The filtered registration arguments.
 */
$wordpress_abilities_setting_show_in_abilities_polyfill = static function ( $args, $defaults, $option_group, $option_name ) use ( $wordpress_abilities_exposed_core_settings ) {
	if ( ! is_array( $args ) || array_key_exists( 'show_in_abilities', $args ) ) {
		return $args;
	}

	if ( isset( $wordpress_abilities_exposed_core_settings[ $option_name ] ) ) {
		$args['show_in_abilities'] = $wordpress_abilities_exposed_core_settings[ $option_name ];
	}

	return $args;
};

add_filter( 'register_setting_args', $wordpress_abilities_setting_show_in_abilities_polyfill, 10, 4 );
unset( $wordpress_abilities_setting_show_in_abilities_polyfill );

/*
 * Catch-up for core settings registered before this polyfill loaded, such as
 * when `rest_api_init` already fired in this request. An explicit value on
 * the registered setting is never overridden.
 */
if ( isset( $GLOBALS['wp_registered_settings'] ) && is_array( $GLOBALS['wp_registered_settings'] ) ) {
	foreach ( $wordpress_abilities_exposed_core_settings as $polyfill_option_name => $polyfill_show ) {
		if ( isset( $GLOBALS['wp_registered_settings'][ $polyfill_option_name ] )
			&& is_array( $GLOBALS['wp_registered_settings'][ $polyfill_option_name ] )
			&& ! array_key_exists( 'show_in_abilities', $GLOBALS['wp_registered_settings'][ $polyfill_option_name ] ) ) {
			$GLOBALS['wp_registered_settings'][ $polyfill_option_name ]['show_in_abilities'] = $polyfill_show;
		}
	}
	unset( $polyfill_option_name, $polyfill_show );
}
unset( $wordpress_abilities_exposed_core_settings );

/*
 * Registers core's initial settings now, as trunk's
 * _wp_register_initial_settings_for_abilities() does on `wp_abilities_api_init`,
 * plus the privacy policy page setting older hosts lack. Registering a setting
 * also allows it on the options screen of its group, so the list is restored
 * afterwards: saving Settings > General must not try to save `admin_email`,
 * which that screen sends as `new_admin_email`.
 */
$polyfill_allowed_options = $GLOBALS['new_allowed_options'] ?? null;

if ( ! did_action( 'rest_api_init' ) || doing_action( 'rest_api_init' ) ) {
	register_initial_settings();
}

if ( ! isset( get_registered_settings()['wp_page_for_privacy_policy'] ) ) {
	register_setting(
		'reading',
		'wp_page_for_privacy_policy',
		array(
			'show_in_rest'      => false,
			'show_in_abilities' => array(
				'name' => 'page_for_privacy_policy',
			),
			'type'              => 'integer',
			'description'       => __( 'The ID of the page that should be displayed as the privacy policy page' ),
		)
	);

	/*
	 * Trunk registers it right after `page_for_posts`, and the registration order is the
	 * order of the exposed settings in the ability's schemas and output.
	 */
	if ( isset( $GLOBALS['wp_registered_settings']['page_for_posts'] ) ) {
		$polyfill_settings = array();
		foreach ( $GLOBALS['wp_registered_settings'] as $polyfill_option_name => $polyfill_setting ) {
			if ( 'wp_page_for_privacy_policy' === $polyfill_option_name ) {
				continue;
			}
			$polyfill_settings[ $polyfill_option_name ] = $polyfill_setting;
			if ( 'page_for_posts' === $polyfill_option_name ) {
				$polyfill_settings['wp_page_for_privacy_policy'] = $GLOBALS['wp_registered_settings']['wp_page_for_privacy_policy'];
			}
		}
		$GLOBALS['wp_registered_settings'] = $polyfill_settings; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Same settings, reordered to trunk's registration order.
		unset( $polyfill_settings, $polyfill_option_name, $polyfill_setting );
	}
}

$GLOBALS['new_allowed_options'] = $polyfill_allowed_options; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the core global to its state before the early registration, as trunk does.
unset( $polyfill_allowed_options );
