<?php
/**
 * Table caption generation experiment.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Table_Caption_Generation;

use WordPress\AI\Abilities\Table_Caption_Generation\Table_Caption_Generation as Ability;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Experiment_Category;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a reviewable caption suggestion to selected Table blocks.
 *
 * @since x.x.x
 */
class Table_Caption_Generation extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public static function get_id(): string {
		return 'table-caption-generation';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Table Caption Generation', 'ai' ),
			'description' => __( 'Suggests a concise caption for a Table block using its cells and nearby post content.', 'ai' ),
			'category'    => Experiment_Category::EDITOR,
			'usage'       => array(
				'trigger'  => 'on_demand',
				'runs'     => __( 'When you click Generate caption in a Table block’s settings', 'ai' ),
				'sends'    => __( 'Table headers and cells, the current post title, and text from the preceding and following blocks', 'ai' ),
				'requests' => __( 'One request per click when the table contains eligible text', 'ai' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registers the caption generation ability.
	 *
	 * @since x.x.x
	 */
	public function register_ability(): void {
		wp_register_ability(
			'ai/table-caption-generation',
			array(
				'label'         => $this->get_label(),
				'description'   => $this->get_description(),
				'category'      => WPAI_DEFAULT_ABILITY_CATEGORY,
				'ability_class' => Ability::class,
			)
		);
	}

	/**
	 * Enqueues controls only in the post block editor.
	 *
	 * @since x.x.x
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		$screen = get_current_screen();
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) || ! $screen || ! $screen->is_block_editor() ) {
			return;
		}
		$post_type = get_post_type_object( $screen->post_type );
		if ( ! $post_type || ! $post_type->show_in_rest || ! post_type_supports( $screen->post_type, 'editor' ) ) {
			return;
		}
		Asset_Loader::enqueue_script( 'table-caption-generation', 'experiments/table-caption-generation', array( 'include_core_abilities' => true ) );
		Asset_Loader::enqueue_style( 'table-caption-generation', 'experiments/table-caption-generation' );
		Asset_Loader::localize_script(
			'table-caption-generation',
			'TableCaptionGenerationData',
			array(
				'enabled' => $this->is_enabled(),
			)
		);
	}
}
