<?php
/**
 * Label generation experiment class.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Label_Generation;

use WordPress\AI\Abilities\Label_Generation\Label_Generation as Label_Generation_Ability;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Experiment_Category;

use function WordPress\AI\get_min_content_length;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Label generation experiment.
 *
 * @since x.x.x
 */
class Label_Generation extends Abstract_Feature {

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public static function get_id(): string {
		return 'label-generation';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Label Generation', 'ai' ),
			'description' => __( 'Generate concise labels for content blocks. Requires an AI connector that includes support for text generation models.', 'ai' ),
			'category'    => Experiment_Category::EDITOR,
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
	 * Registers the ability.
	 *
	 * @since x.x.x
	 */
	public function register_ability(): void {
		wp_register_ability(
			sprintf( 'ai/%s', $this->get_id() ),
			array(
				'label'         => $this->get_label(),
				'description'   => $this->get_description(),
				'ability_class' => Label_Generation_Ability::class,
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
			'label-generation',
			'experiments/label-generation',
			array(
				'include_core_abilities' => true,
			)
		);

		Asset_Loader::enqueue_style(
			'label-generation',
			'experiments/label-generation',
		);

		Asset_Loader::localize_script(
			'label-generation',
			'LabelGenerationData',
			array(
				'enabled'          => $this->is_enabled(),
				'minContentLength' => get_min_content_length(
					'label-generation',
					Label_Generation_Ability::DEFAULT_MIN_CONTENT_LENGTH
				),
			)
		);
	}
}
