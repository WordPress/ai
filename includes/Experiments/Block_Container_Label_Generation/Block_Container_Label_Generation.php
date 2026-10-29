<?php
/**
 * Block Container Label Generation experiment class.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Block_Container_Label_Generation;

use WordPress\AI\Abilities\Block_Container_Label_Generation\Block_Container_Label_Generation as Block_Container_Label_Generation_Ability;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Experiment_Category;

use function WordPress\AI\get_min_content_length;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Block Container Label Generation experiment.
 *
 * @since x.x.x
 */
class Block_Container_Label_Generation extends Abstract_Feature {

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public static function get_id(): string {
		return 'block-container-label-generation';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Block Container Label Generation', 'ai' ),
			'description' => __( 'Generate concise labels for content blocks. Requires an AI connector that includes support for text generation models.', 'ai' ),
			'category'    => Experiment_Category::EDITOR,
			'usage'       => array(
				'trigger'  => 'on_demand',
				'runs'     => __( 'When you click Generate Block Labels in List View or Generate label in the Rename modal', 'ai' ),
				'sends'    => __( 'The text of each container block and its inner blocks, the post title, and a short outline of the other container blocks and their labels', 'ai' ),
				'requests' => __( 'One request per unlabeled container block, or one per click in the Rename modal', 'ai' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function register_feature(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registers the ability.
	 *
	 * @since x.x.x
	 */
	public function register_abilities(): void {
		wp_register_ability(
			sprintf( 'ai/%s', $this->get_id() ),
			array(
				'label'         => $this->get_label(),
				'description'   => $this->get_description(),
				'ability_class' => Block_Container_Label_Generation_Ability::class,
			)
		);
	}

	/**
	 * Enqueues and localizes the admin script.
	 *
	 * @since x.x.x
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		// Enqueue assets only on the post editor screen.
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		Asset_Loader::enqueue_script(
			'block-container-label-generation',
			'experiments/block-container-label-generation',
			array(
				'include_core_abilities' => true,
			)
		);

		Asset_Loader::enqueue_style(
			'block-container-label-generation',
			'experiments/block-container-label-generation',
		);

		Asset_Loader::localize_script(
			'block-container-label-generation',
			'BlockContainerLabelGenerationData',
			array(
				'enabled'          => $this->is_enabled(),
				'minContentLength' => get_min_content_length(
					'block-container-label-generation',
					Block_Container_Label_Generation_Ability::DEFAULT_MIN_CONTENT_LENGTH
				),
			)
		);
	}
}
