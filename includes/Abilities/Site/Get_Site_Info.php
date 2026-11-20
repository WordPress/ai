<?php
/**
 * The `ai/get-site-info` WordPress Ability.
 *
 * @package WordPress\AI
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Site;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Get_Site_Info
 *
 * Registers the read-only `ai/get-site-info` ability: public site facts, narrower than `core/get-site-info`.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Get_Site_Info {

	/**
	 * The ability name.
	 *
	 * @since x.x.x
	 * @var string
	 */
	public const ABILITY = 'ai/get-site-info';

	/**
	 * Returns the ability's registration args.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The registration args.
	 */
	public function get_ability_args(): array {
		return array(
			'label'               => __( 'Get Site Info', 'ai' ),
			'description'         => __( 'Returns facts about this site: its name, tagline, home URL, language, timezone, date and time formats, active theme and whether it is a block theme. The WordPress and PHP versions are included only for users who can view Site Health. Takes no input. Requires an authenticated user.', 'ai' ),
			'category'            => WPAI_DEFAULT_ABILITY_CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				// No empty `properties`: it encodes as `[]`, which OpenAI rejects.
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => $this->get_output_schema(),
			'execute_callback'    => array( $this, 'execute_get_site_info' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
					'open_world'  => false,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Permission callback for the `ai/get-site-info` ability.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True if the request may proceed.
	 */
	public function check_permission( $input = array() ): bool {
		unset( $input );

		return is_user_logged_in();
	}

	/**
	 * Executes the `ai/get-site-info` ability.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, string|bool> The site facts.
	 */
	public function execute_get_site_info( $input = array() ): array {
		unset( $input );

		$info = array(
			// Stored HTML-escaped; decoded as the Settings screen shows them.
			'name'           => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'tagline'        => wp_specialchars_decode( (string) get_bloginfo( 'description' ), ENT_QUOTES ),
			'home_url'       => (string) home_url( '/' ),
			'language'       => (string) get_locale(),
			'timezone'       => (string) wp_timezone_string(),
			'date_format'    => (string) get_option( 'date_format' ),
			'time_format'    => (string) get_option( 'time_format' ),
			'theme'          => (string) wp_get_theme()->get( 'Name' ),
			'is_block_theme' => wp_is_block_theme(),
		);

		// Versions reveal applicable vulnerabilities; gate them like Site Health does.
		if ( current_user_can( 'view_site_health_checks' ) ) {
			$info['wp_version']  = (string) get_bloginfo( 'version' );
			$info['php_version'] = PHP_VERSION;
		}

		return $info;
	}

	/**
	 * Builds the output schema for the `ai/get-site-info` ability.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	private function get_output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'name', 'tagline', 'home_url', 'language', 'timezone', 'date_format', 'time_format', 'theme', 'is_block_theme' ),
			'properties'           => array(
				'name'           => array(
					'type'        => 'string',
					'description' => __( 'The site title.', 'ai' ),
				),
				'tagline'        => array(
					'type'        => 'string',
					'description' => __( 'The site tagline, which may be empty.', 'ai' ),
				),
				'home_url'       => array(
					'type'        => 'string',
					'format'      => 'uri',
					'description' => __( 'The address of the site\'s front page.', 'ai' ),
				),
				'language'       => array(
					'type'        => 'string',
					'description' => __( 'The site language, as a locale such as en_US.', 'ai' ),
				),
				'timezone'       => array(
					'type'        => 'string',
					'description' => __( 'The site timezone, as a named zone such as America/Denver or a UTC offset such as -05:00.', 'ai' ),
				),
				'date_format'    => array(
					'type'        => 'string',
					'description' => __( 'The site date format, in PHP date() notation.', 'ai' ),
				),
				'time_format'    => array(
					'type'        => 'string',
					'description' => __( 'The site time format, in PHP date() notation.', 'ai' ),
				),
				'theme'          => array(
					'type'        => 'string',
					'description' => __( 'The name of the active theme.', 'ai' ),
				),
				'is_block_theme' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the active theme is a block theme.', 'ai' ),
				),
				'wp_version'     => array(
					'type'        => 'string',
					'description' => __( 'The WordPress version. Present only for users who can view Site Health.', 'ai' ),
				),
				'php_version'    => array(
					'type'        => 'string',
					'description' => __( 'The PHP version. Present only for users who can view Site Health.', 'ai' ),
				),
			),
		);
	}
}
