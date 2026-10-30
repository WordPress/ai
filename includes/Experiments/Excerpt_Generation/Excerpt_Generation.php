<?php
/**
 * Excerpt generation experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Excerpt_Generation;

use WordPress\AI\Abilities\Excerpt_Generation\Excerpt_Generation as Excerpt_Generation_Ability;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Experiment_Category;

use function WordPress\AI\get_min_content_length;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Excerpt generation experiment.
 *
 * @since 0.2.0
 */
class Excerpt_Generation extends Abstract_Feature {

	/**
	 * The id of the script module providing the JavaScript parts of the
	 * excerpt field in WordPress 7.2+: its summary and control with the
	 * generate buttons. Built from `packages/excerpt-field`.
	 *
	 * @since n.e.x.t
	 */
	private const EXCERPT_FIELD_MODULE = '@ai/excerpt-field';

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'excerpt-generation';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Excerpt Generation', 'ai' ),
			'description' => __( 'Generates excerpt suggestions from content. Requires an AI connector that includes support for text generation models.', 'ai' ),
			'category'    => Experiment_Category::EDITOR,
			'usage'       => array(
				'trigger'  => 'on_demand',
				'runs'     => __( 'When you click Generate excerpt', 'ai' ),
				'sends'    => __( 'Post content, title, slug, author name, excerpt, content type, and assigned categories and tags', 'ai' ),
				'requests' => __( 'One request per click', 'ai' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_entity_fields_api_init', array( $this, 'update_excerpt_field' ) );
	}

	/**
	 * Registers any needed abilities.
	 *
	 * @since 0.2.0
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'ai/' . $this->get_id(),
			array(
				'label'         => $this->get_label(),
				'description'   => $this->get_description(),
				'ability_class' => Excerpt_Generation_Ability::class,
			),
		);
	}

	/**
	 * Whether the editor renders the excerpt with the Entity Fields API.
	 *
	 * WordPress 7.2 replaced the excerpt panel of the editor inspector with a
	 * form built from the registered entity fields.
	 *
	 * @since n.e.x.t
	 */
	private function uses_entity_fields_api(): bool {
		return function_exists( 'wp_update_entity_fields' );
	}

	/**
	 * Whether the experiment generates excerpts for a post type: those
	 * supporting excerpts, except attachments.
	 *
	 * @since n.e.x.t
	 *
	 * @param string $post_type The post type.
	 */
	private function supports_excerpt_generation( string $post_type ): bool {
		return post_type_supports( $post_type, 'excerpt' ) && 'attachment' !== $post_type;
	}

	/**
	 * Hands the JavaScript parts of the excerpt field to the plugin's script
	 * module, which adds the generate buttons to the field's summary and
	 * control in the editor inspector (WordPress 7.2+).
	 *
	 * Updating the field keeps core's definition (label, placeholder,
	 * description, textarea) and only replaces its `render` and `Edit`
	 * parts. Before 7.2, the `ai_excerpt_generation` script attaches the
	 * buttons to the legacy excerpt panel instead.
	 *
	 * @since n.e.x.t
	 */
	public function update_excerpt_field(): void {
		// The action only fires with the API: from core, or from Gutenberg on
		// older versions.
		if ( ! $this->is_enabled() ) {
			return;
		}

		// CI measures the coverage on the latest WordPress release, without
		// the Entity Fields API before 7.2; the trunk job runs the test of this block.
		// @codeCoverageIgnoreStart
		foreach ( get_post_types( array(), 'names' ) as $post_type ) {
			if ( ! $this->supports_excerpt_generation( $post_type ) ) {
				continue;
			}

			// Core registers no excerpt field for some post types supporting
			// excerpts (templates), and only registered fields can be updated.
			$fields = wp_get_registered_entity_fields( 'postType', $post_type );
			if ( ! in_array( 'excerpt', array_column( $fields, 'id' ), true ) ) {
				continue;
			}

			wp_update_entity_fields(
				'ai',
				'postType',
				$post_type,
				array( array( 'id' => 'excerpt' ) ),
				self::EXCERPT_FIELD_MODULE
			);
			// The hook is named by hand: wp_get_entity_view_config_hook_name()
			// is WordPress 7.1, and Gutenberg brings the API to 7.0 without it.
			add_filter(
				"get_entity_view_config_posttype_{$post_type}",
				array( $this, 'keep_excerpt_summary_when_empty' )
			);
		}

		if ( ! function_exists( 'wp_set_script_module_translations' ) ) {
			return;
		}
		wp_set_script_module_translations( self::EXCERPT_FIELD_MODULE, 'ai' );
		// @codeCoverageIgnoreEnd
	}

	/**
	 * Keeps the excerpt field's own summary in charge of empty excerpts.
	 *
	 * The panel layout can show the field's placeholder instead of its
	 * summary when the value is empty (`showPlaceholderIfEmpty`), which would
	 * drop the generate button exactly when it is most useful; the module's
	 * summary shows the placeholder itself. Core's post form does not set the
	 * option as of 7.2, so this is a no-op until it does, see
	 * https://github.com/WordPress/gutenberg/pull/85842.
	 *
	 * @since n.e.x.t
	 *
	 * @param \WP_View_Config_Data $data The view configuration of the post type.
	 * @return \WP_View_Config_Data The view configuration.
	 */
	public function keep_excerpt_summary_when_empty( $data ) {
		$data->merge(
			array(
				'form' => array(
					'fields' => array(
						array(
							'id'     => 'excerpt',
							'layout' => array( 'showPlaceholderIfEmpty' => false ),
						),
					),
				),
			),
			1
		);

		return $data;
	}

	/**
	 * Enqueues and localizes the admin script.
	 *
	 * @since 0.2.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		// Load asset in new post and edit post screens only.
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		$screen = get_current_screen();

		// Load the assets only if the post type supports excerpts and is not an attachment.
		if ( ! $screen || ! $this->supports_excerpt_generation( $screen->post_type ) ) {
			return;
		}

		$entity_fields_api = $this->uses_entity_fields_api();

		Asset_Loader::enqueue_script( 'excerpt_generation', 'experiments/excerpt-generation', array( 'include_core_abilities' => true ) );
		if ( $entity_fields_api ) {
			// Styles the excerpt field's summary rendered by the script module.
			Asset_Loader::enqueue_style( 'excerpt_generation', 'experiments/excerpt-generation' );
		}
		Asset_Loader::localize_script(
			'excerpt_generation',
			'ExcerptGenerationData',
			array(
				'enabled'          => $this->is_enabled(),
				'minContentLength' => get_min_content_length( 'excerpt-generation', 250 ),
				'entityFieldsApi'  => $entity_fields_api,
			)
		);
	}
}
