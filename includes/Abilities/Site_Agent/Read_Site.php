<?php
/**
 * The `ai/site-agent-read-site` ability.
 *
 * @package WordPress\AI\Abilities\Site_Agent
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Site_Agent;

use WP_Error;
use WP_Post;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Reads a small, explicit slice of site data for the Site Agent.
 *
 * Every section is read with WordPress APIs and filtered by the current user's
 * capabilities. There is no arbitrary option, post type, or route input.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Read_Site {

	/**
	 * The ability name.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const ABILITY = 'ai/site-agent-read-site';

	/**
	 * Hooks the ability into the Abilities API.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register' ), 11 );
	}

	/**
	 * Registers the ability.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		if ( wp_has_ability( self::ABILITY ) ) {
			return;
		}

		wp_register_ability(
			self::ABILITY,
			array(
				'label'               => __( 'Read site information', 'ai' ),
				'description'         => __( 'Reads a small, explicit slice of site data: the site environment, recent posts or pages, installed plugins, or a fixed set of site settings. The response is filtered by the current user\'s capabilities.', 'ai' ),
				'category'            => WPAI_DEFAULT_ABILITY_CATEGORY,
				'input_schema'        => $this->get_input_schema(),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'public'       => false,
					'show_in_rest' => false,
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Checks the capability required by the requested section.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The ability input.
	 * @return bool|\WP_Error True when permitted, WP_Error otherwise.
	 */
	public function check_permission( $input = null ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'site_agent_not_logged_in',
				__( 'You must be signed in to read site information.', 'ai' ),
				array( 'status' => 401 )
			);
		}

		$section = is_array( $input ) && isset( $input['section'] ) ? sanitize_key( (string) $input['section'] ) : '';

		/*
		 * An input-free check is the agent's coarse pre-filter. It passes when
		 * the user could read at least one section; the requested section is
		 * checked below when the ability actually executes.
		 */
		if ( '' === $section ) {
			return $this->can_read_any_section();
		}

		$capability = $this->section_capability( $section );

		if ( '' === $capability ) {
			return new WP_Error(
				'site_agent_invalid_section',
				__( 'That site information section is not available.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- The capability comes from a fixed section map.
		if ( ! current_user_can( $capability ) ) {
			return new WP_Error(
				'site_agent_read_denied',
				__( 'You do not have permission to read that site information.', 'ai' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Executes the ability.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The ability input.
	 * @return array<string, mixed>|\WP_Error The site data, or an error.
	 */
	public function execute( $input = null ) {
		$input   = is_array( $input ) ? $input : array();
		$section = isset( $input['section'] ) ? sanitize_key( (string) $input['section'] ) : 'environment';

		switch ( $section ) {
			case 'environment':
				return $this->read_environment();
			case 'posts':
			case 'pages':
				return $this->read_content( $section, $input );
			case 'plugins':
				return $this->read_plugins();
			case 'settings':
				return $this->read_settings();
		}

		return new WP_Error(
			'site_agent_invalid_section',
			__( 'That site information section is not available.', 'ai' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Reports whether the current user may read at least one section.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when at least one section is available.
	 */
	private function can_read_any_section(): bool {
		foreach ( array( 'manage_options', 'edit_posts', 'edit_pages', 'activate_plugins' ) as $capability ) {
			// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Fixed capability map.
			if ( current_user_can( $capability ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Maps a section to its capability.
	 *
	 * @since x.x.x
	 *
	 * @param string $section The section.
	 * @return string The capability, or an empty string.
	 */
	private function section_capability( string $section ): string {
		switch ( $section ) {
			case 'environment':
			case 'settings':
				return 'manage_options';
			case 'posts':
				return 'edit_posts';
			case 'pages':
				return 'edit_pages';
			case 'plugins':
				return 'activate_plugins';
		}

		return '';
	}

	/**
	 * Builds the input schema.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'section' ),
			'additionalProperties' => false,
			'properties'           => array(
				'section' => array(
					'type'        => 'string',
					'enum'        => array( 'environment', 'posts', 'pages', 'plugins', 'settings' ),
					'description' => __( 'The site information to read.', 'ai' ),
				),
				'search'  => array(
					'type'        => 'string',
					'description' => __( 'Optional search text for posts or pages.', 'ai' ),
				),
				'id'      => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Optional post or page ID to read one item, including its content.', 'ai' ),
				),
				'limit'   => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 20,
					'default'     => 5,
					'description' => __( 'How many items to return when listing posts or pages.', 'ai' ),
				),
			),
		);
	}

	/**
	 * Reads the site environment and available updates.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The environment.
	 */
	private function read_environment(): array {
		$theme   = wp_get_theme();
		$active  = (array) get_option( 'active_plugins', array() );
		$plugins = array();
		$core    = get_site_transient( 'update_core' );
		$updates = get_site_transient( 'update_plugins' );
		$themes  = get_site_transient( 'update_themes' );

		foreach ( $active as $plugin_file ) {
			if ( ! is_string( $plugin_file ) ) {
				continue;
			}

			$plugins[] = array(
				'plugin'  => $plugin_file,
				'name'    => $this->plugin_name( $plugin_file ),
				'version' => $this->plugin_version( $plugin_file ),
			);
		}

		$core_update = '';
		if ( is_object( $core ) && isset( $core->updates[0] ) && is_object( $core->updates[0] ) ) {
			$next = $core->updates[0];

			if ( isset( $next->response ) && 'upgrade' === $next->response && isset( $next->current ) ) {
				$core_update = (string) $next->current;
			}
		}

		return array(
			'section'        => 'environment',
			'site_name'      => (string) get_bloginfo( 'name' ),
			'home_url'       => (string) home_url( '/' ),
			'site_url'       => (string) site_url( '/' ),
			'wordpress'      => (string) get_bloginfo( 'version' ),
			'php'            => PHP_VERSION,
			'database'       => (string) $GLOBALS['wpdb']->db_version(),
			'multisite'      => is_multisite(),
			'active_theme'   => array(
				'name'    => (string) $theme->get( 'Name' ),
				'version' => (string) $theme->get( 'Version' ),
			),
			'active_plugins' => $plugins,
			'updates'        => array(
				'core'    => $core_update,
				'plugins' => $this->update_versions( $updates ),
				'themes'  => $this->update_versions( $themes ),
			),
		);
	}

	/**
	 * Reads recent posts or pages.
	 *
	 * @since x.x.x
	 *
	 * @param string               $section The section (posts or pages).
	 * @param array<string, mixed> $input   The input.
	 * @return array<string, mixed>|\WP_Error The content, or an error.
	 */
	private function read_content( string $section, array $input ) {
		$post_type = 'pages' === $section ? 'page' : 'post';
		$id        = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id > 0 ) {
			$post = get_post( $id );

			if ( ! $post instanceof WP_Post || $post->post_type !== $post_type ) {
				return new WP_Error(
					'site_agent_content_not_found',
					__( 'That item was not found.', 'ai' ),
					array( 'status' => 404 )
				);
			}

			if ( ! current_user_can( 'read_post', $post->ID ) ) {
				return new WP_Error(
					'site_agent_content_denied',
					__( 'You do not have permission to read that item.', 'ai' ),
					array( 'status' => 403 )
				);
			}

			return array(
				'section' => $section,
				'items'   => array( $this->map_post( $post, true ) ),
			);
		}

		$limit = isset( $input['limit'] ) ? absint( $input['limit'] ) : 5;
		$limit = max( 1, min( 20, $limit ) );

		$args = array(
			'post_type'        => $post_type,
			'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'numberposts'      => $limit,
			'orderby'          => 'date',
			'order'            => 'DESC',
			'suppress_filters' => false,
		);

		if ( isset( $input['search'] ) && is_scalar( $input['search'] ) && '' !== trim( (string) $input['search'] ) ) {
			$args['s'] = sanitize_text_field( (string) $input['search'] );
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.get_posts_get_posts -- suppress_filters is false, so the query is cached.
		$posts = get_posts( $args );
		$items = array();

		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post || ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}

			$items[] = $this->map_post( $post, false );
		}

		return array(
			'section' => $section,
			'items'   => $items,
		);
	}

	/**
	 * Reads installed plugins.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The plugins.
	 */
	private function read_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = get_plugins();
		$active    = (array) get_option( 'active_plugins', array() );
		$updates   = get_site_transient( 'update_plugins' );
		$items     = array();

		foreach ( array_slice( $installed, 0, 50 ) as $file => $data ) {
			$items[] = array(
				'plugin'  => (string) $file,
				'name'    => isset( $data['Name'] ) ? (string) $data['Name'] : '',
				'version' => isset( $data['Version'] ) ? (string) $data['Version'] : '',
				'active'  => in_array( $file, $active, true ),
				'update'  => $this->plugin_update_version( $updates, (string) $file ),
			);
		}

		return array(
			'section' => 'plugins',
			'items'   => $items,
		);
	}

	/**
	 * Reads the fixed settings allowlist.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The settings.
	 */
	private function read_settings(): array {
		return array(
			'section'  => 'settings',
			'settings' => array(
				'blogname'        => (string) get_option( 'blogname' ),
				'blogdescription' => (string) get_option( 'blogdescription' ),
				'timezone_string' => (string) get_option( 'timezone_string' ),
				'date_format'     => (string) get_option( 'date_format' ),
				'time_format'     => (string) get_option( 'time_format' ),
			),
		);
	}

	/**
	 * Maps a post to the fields the agent may see.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post          The post.
	 * @param bool     $include_content Whether to include the full content.
	 * @return array<string, mixed> The mapped post.
	 */
	private function map_post( WP_Post $post, bool $include_content ): array {
		$item = array(
			'id'      => $post->ID,
			'type'    => $post->post_type,
			'title'   => (string) get_the_title( $post ),
			'status'  => $post->post_status,
			'date'    => $post->post_date_gmt,
			'link'    => (string) get_permalink( $post ),
			'excerpt' => wp_trim_words( '' !== $post->post_excerpt ? $post->post_excerpt : $post->post_content, 40 ),
			'author'  => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
		);

		if ( $include_content ) {
			$item['content'] = $post->post_content;
		}

		return $item;
	}

	/**
	 * Reads a plugin's display name without loading it.
	 *
	 * @since x.x.x
	 *
	 * @param string $plugin_file The plugin file.
	 * @return string The name.
	 */
	private function plugin_name( string $plugin_file ): string {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$path = trailingslashit( WP_PLUGIN_DIR ) . $plugin_file;

		if ( ! file_exists( $path ) ) {
			return '';
		}

		$data = get_plugin_data( $path, false, false );

		return isset( $data['Name'] ) ? (string) $data['Name'] : '';
	}

	/**
	 * Reads a plugin's version.
	 *
	 * @since x.x.x
	 *
	 * @param string $plugin_file The plugin file.
	 * @return string The version.
	 */
	private function plugin_version( string $plugin_file ): string {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$path = trailingslashit( WP_PLUGIN_DIR ) . $plugin_file;

		if ( ! file_exists( $path ) ) {
			return '';
		}

		$data = get_plugin_data( $path, false, false );

		return isset( $data['Version'] ) ? (string) $data['Version'] : '';
	}

	/**
	 * Maps an update transient to a name => version list.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $transient The update transient.
	 * @return array<string, string> The updates.
	 */
	private function update_versions( $transient ): array {
		$versions = array();

		if ( ! is_object( $transient ) || ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			return $versions;
		}

		foreach ( $transient->response as $key => $item ) {
			if ( ! is_object( $item ) || ! isset( $item->new_version ) ) {
				continue;
			}

			$versions[ (string) $key ] = (string) $item->new_version;
		}

		return $versions;
	}

	/**
	 * Returns the offered update version for one plugin.
	 *
	 * @since x.x.x
	 *
	 * @param mixed  $transient The update transient.
	 * @param string $plugin_file The plugin file.
	 * @return string The new version, or an empty string.
	 */
	private function plugin_update_version( $transient, string $plugin_file ): string {
		if ( ! is_object( $transient ) || ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			return '';
		}

		if ( ! isset( $transient->response[ $plugin_file ] ) || ! is_object( $transient->response[ $plugin_file ] ) ) {
			return '';
		}

		return isset( $transient->response[ $plugin_file ]->new_version )
			? (string) $transient->response[ $plugin_file ]->new_version
			: '';
	}
}
