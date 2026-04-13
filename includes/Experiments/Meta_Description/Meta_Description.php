<?php
/**
 * Meta description experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Meta_Description;

use WordPress\AI\Abilities\Meta_Description\Meta_Description as Meta_Description_Ability;
use WordPress\AI\Abilities\Meta_Description\SEO_Integration;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Experiment_Category;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta description experiment.
 *
 * Provides AI-generated meta description suggestions in the post editor with
 * automatic SEO plugin integration for storing descriptions in the correct meta field.
 *
 * @since 0.7.0
 */
class Meta_Description extends Abstract_Feature {

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'meta-description';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Meta Description Generation', 'ai' ),
			'description' => __( 'Generates meta description suggestions and integrates those with various SEO plugins. Requires an AI connector that includes support for text generation models.', 'ai' ),
			'category'    => Experiment_Category::EDITOR,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.7.0
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'deactivated_plugin', array( $this, 'clear_active_plugin_cache' ) );

		$this->maybe_output_meta_description();
		$this->register_post_meta();
	}

	/**
	 * Registers the meta description ability.
	 *
	 * @since 0.7.0
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'ai/' . $this->get_id(),
			array(
				'label'         => $this->get_label(),
				'description'   => $this->get_description(),
				'ability_class' => Meta_Description_Ability::class,
			),
		);
	}

	/**
	 * Registers the fallback post meta key for REST API access.
	 *
	 * This ensures the meta key is accessible through the WordPress data layer
	 * when no SEO plugin is active to manage it.
	 *
	 * @since 0.7.0
	 */
	public function register_post_meta(): void {
		$meta_key   = SEO_Integration::get_meta_key();
		$seo_plugin = SEO_Integration::detect_active_plugin();

		// Only register the fallback meta key. SEO plugins register their own.
		if ( null !== $seo_plugin ) {
			return;
		}

		$post_types = get_post_types( array( 'show_in_rest' => true ), 'names' );

		foreach ( $post_types as $post_type ) {
			if ( 'attachment' === $post_type ) {
				continue;
			}

			register_post_meta(
				$post_type,
				$meta_key,
				array(
					'show_in_rest'  => true,
					'single'        => true,
					'type'          => 'string',
					'auth_callback' => static function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			);
		}
	}

	/**
	 * Enqueues and localizes the admin script.
	 *
	 * @since 0.7.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		$screen = get_current_screen();

		if (
			! $screen ||
			! in_array( $screen->post_type, get_post_types( array( 'show_in_rest' => true ), 'names' ), true ) ||
			'attachment' === $screen->post_type
		) {
			return;
		}

		$seo_plugin = SEO_Integration::detect_active_plugin();

		Asset_Loader::enqueue_script( 'meta_description', 'experiments/meta-description' );
		Asset_Loader::enqueue_style( 'meta_description', 'experiments/meta-description' );
		Asset_Loader::localize_script(
			'meta_description',
			'MetaDescriptionData',
			array(
				'enabled'   => $this->is_enabled(),
				'metaKey'   => SEO_Integration::get_meta_key( $seo_plugin ),
				'seoPlugin' => $seo_plugin,
			)
		);
	}

	/**
	 * Clears the active SEO plugin cache when a plugin is deactivated.
	 *
	 * @since 0.7.0
	 */
	public function clear_active_plugin_cache(): void {
		delete_transient( 'wpai_active_seo_plugin' );
	}

	/**
	 * Determines if the meta description should be rendered by the experiment.
	 *
	 * @since 0.7.0
	 */
	protected function maybe_output_meta_description(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$seo_plugin = SEO_Integration::detect_active_plugin();

		// Let the SEO plugin handle the output if found.
		if ( ! empty( $seo_plugin ) ) {
			return;
		}

		add_action( 'wp_head', array( $this, 'output_meta_description' ), 1 );
	}

	/**
	 * Injects the meta description into the head if it exists.
	 *
	 * @since 0.7.0
	 */
	public function output_meta_description(): void {
		if ( ! is_singular() ) {
			return;
		}

		$meta_description = get_post_meta( (int) get_the_ID(), SEO_Integration::get_meta_key(), true );

		/**
		 * Filter the meta description output.
		 * An empty string will prevent output.
		 *
		 * @since 0.7.0
		 *
		 * @param string $meta_description The meta description.
		 * @return string The filtered meta description.
		 */
		$meta_description = apply_filters( 'wpai_meta_description', $meta_description );

		if ( empty( $meta_description ) ) {
			return;
		}

		echo '<meta name="description" content="' . esc_attr( $meta_description ) . '" />';
	}
}
