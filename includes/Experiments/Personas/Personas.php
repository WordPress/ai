<?php
/**
 * Persona-driven content generation experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Personas;

use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Services\Personas as Personas_Service;
use WordPress\AI\Settings\Settings_Registration;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persona-driven content generation experiment.
 *
 * Personas describe a role, an audience, and a brand voice. The active persona
 * is appended to the system instruction of every ability that opts in, so a
 * single definition shapes the tone of every generation across experiments.
 *
 * @since x.x.x
 */
class Personas extends Abstract_Feature {

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'personas';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Personas', 'ai' ),
			'description' => __( 'Define reusable personas — a role, an audience, and a brand voice — and apply one across content generation experiments so generated content stays on voice. Set a site-wide default and override it per post.', 'ai' ),
			'category'    => Experiment_Category::EDITOR,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		// Features are registered on `init`, so these run inline rather than
		// through another `init` callback that would never fire.
		$this->register_post_type();
		$this->register_post_meta();

		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_assets' ) );

		// Abilities receive the post they act on as input rather than as an
		// argument to the system instruction, so the executing post is captured
		// centrally and read back when the persona is resolved.
		add_action( 'wp_before_execute_ability', array( $this, 'capture_ability_context' ), 10, 2 );
		add_action( 'wp_after_execute_ability', array( $this, 'release_ability_context' ) );
	}

	/**
	 * Registers the personas post type.
	 *
	 * Personas are edited with the standard post UI: the title names the
	 * persona and the content describes its voice.
	 *
	 * @since x.x.x
	 */
	public function register_post_type(): void {
		if ( post_type_exists( Personas_Service::POST_TYPE ) ) {
			return;
		}

		$args = array(
			'labels'             => array(
				'name'               => __( 'Personas', 'ai' ),
				'singular_name'      => __( 'Persona', 'ai' ),
				'add_new_item'       => __( 'Add Persona', 'ai' ),
				'edit_item'          => __( 'Edit Persona', 'ai' ),
				'new_item'           => __( 'New Persona', 'ai' ),
				'view_item'          => __( 'View Persona', 'ai' ),
				'search_items'       => __( 'Search Personas', 'ai' ),
				'not_found'          => __( 'No personas found.', 'ai' ),
				'not_found_in_trash' => __( 'No personas found in Trash.', 'ai' ),
				'all_items'          => __( 'Personas', 'ai' ),
				'menu_name'          => __( 'Personas', 'ai' ),
			),
			'public'             => false,
			'show_ui'            => true,
			'show_in_menu'       => 'options-general.php',
			'show_in_rest'       => true,
			'publicly_queryable' => false,
			'has_archive'        => false,
			'rewrite'            => false,
			'query_var'          => false,
			'map_meta_cap'       => true,
			'capability_type'    => 'post',
			'supports'           => array( 'title', 'editor', 'revisions' ),
			'menu_icon'          => 'dashicons-admin-users',
		);

		/**
		 * Filters the arguments used to register the personas post type.
		 *
		 * Use this to change where personas appear in the admin menu or to
		 * restrict who may manage them.
		 *
		 * @since x.x.x
		 *
		 * @param array<string, mixed> $args Arguments passed to `register_post_type()`.
		 */
		$args = apply_filters( 'wpai_persona_post_type_args', $args );

		// phpcs:ignore WordPress.NamingConventions.ValidPostTypeSlug.NotStringLiteral -- The slug is the `wpai_persona` literal on the service.
		register_post_type( Personas_Service::POST_TYPE, $args );
	}

	/**
	 * Registers the per-post persona override meta key.
	 *
	 * @since x.x.x
	 */
	public function register_post_meta(): void {
		$post_types = get_post_types( array( 'show_in_rest' => true ), 'names' );

		foreach ( $post_types as $post_type ) {
			if ( 'attachment' === $post_type || Personas_Service::POST_TYPE === $post_type ) {
				continue;
			}

			register_post_meta(
				$post_type,
				Personas_Service::META_KEY,
				array(
					'show_in_rest'      => true,
					'single'            => true,
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_key',
					'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', (int) $post_id );
					},
				)
			);
		}
	}

	/**
	 * Records the post an ability is executing against.
	 *
	 * @since x.x.x
	 *
	 * @param string $ability_name The name of the ability being executed.
	 * @param mixed  $input        The input data for the ability.
	 */
	public function capture_ability_context( string $ability_name, $input ): void {
		Personas_Service::push_context( $this->find_post_id_in_input( $input ) );
	}

	/**
	 * Discards the recorded ability context once execution finishes.
	 *
	 * @since x.x.x
	 */
	public function release_ability_context(): void {
		Personas_Service::pop_context();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function register_settings(): void {
		register_setting(
			Settings_Registration::OPTION_GROUP,
			static::get_field_option_name( 'default_persona' ),
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
				'show_in_rest'      => array(
					'schema' => array(
						'type' => 'string',
					),
				),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function get_settings_fields(): array {
		return array(
			array(
				'id'       => 'default_persona',
				'label'    => __( 'Default persona', 'ai' ),
				'type'     => 'text',
				'default'  => '',
				'elements' => $this->get_persona_elements(),
			),
		);
	}

	/**
	 * Enqueues and localizes the block editor script.
	 *
	 * @since x.x.x
	 */
	public function enqueue_assets(): void {
		$screen = get_current_screen();

		if ( ! $screen || 'post' !== $screen->base || Personas_Service::POST_TYPE === $screen->post_type ) {
			return;
		}

		Asset_Loader::enqueue_script( 'personas', 'experiments/personas' );
		Asset_Loader::localize_script(
			'personas',
			'PersonasData',
			array(
				'enabled'        => $this->is_enabled(),
				'metaKey'        => Personas_Service::META_KEY,
				'personas'       => $this->get_persona_elements(),
				'defaultPersona' => Personas_Service::get_instance()->get_default_persona_id(),
				'manageUrl'      => admin_url( 'edit.php?post_type=' . Personas_Service::POST_TYPE ),
			)
		);
	}

	/**
	 * Returns the registered personas as selectable options.
	 *
	 * @since x.x.x
	 *
	 * @return list<array{value: string, label: string}> Persona options.
	 */
	private function get_persona_elements(): array {
		$elements = array(
			array(
				'value' => '',
				'label' => __( 'None', 'ai' ),
			),
		);

		foreach ( Personas_Service::get_instance()->get_personas() as $id => $persona ) {
			$elements[] = array(
				'value' => (string) $id,
				'label' => $persona['label'],
			);
		}

		return $elements;
	}

	/**
	 * Extracts the post ID an ability is acting on from its input.
	 *
	 * Abilities in this plugin name that argument `post_id` or pass it as a
	 * numeric `context`. Anything else is treated as having no post context,
	 * which falls back to the site-wide default persona.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The input data for the ability.
	 * @return int|null The post ID, or null when the input has no post context.
	 */
	private function find_post_id_in_input( $input ): ?int {
		if ( ! is_array( $input ) ) {
			return null;
		}

		foreach ( array( 'post_id', 'context' ) as $key ) {
			if ( ! isset( $input[ $key ] ) || ! is_numeric( $input[ $key ] ) ) {
				continue;
			}

			$post_id = (int) $input[ $key ];

			if ( $post_id > 0 ) {
				return $post_id;
			}
		}

		return null;
	}
}
