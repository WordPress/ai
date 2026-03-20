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
 * @since 0.6.0
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
			'label'       => __( 'Meta Description', 'ai' ),
			'description' => __( 'Generates meta description suggestions with SEO plugin integration', 'ai' ),
			'category'    => Experiment_Category::EDITOR,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.6.0
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'init', array( $this, 'register_post_meta' ) );
	}

	/**
	 * Registers the meta description ability.
	 *
	 * @since 0.6.0
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
	 * @since 0.6.0
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
					'auth_callback' => static function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	}

	/**
	 * Enqueues and localizes the admin script.
	 *
	 * @since 0.6.0
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
}
