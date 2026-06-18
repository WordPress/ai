<?php
/**
 * Content Translation experiment class.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Content_Translation;

use WordPress\AI\Abilities\Content_Translation\Content_Translation as Content_Translation_Ability;
use WordPress\AI\Abilities\Content_Translation\Languages;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Experiment_Category;

use function WordPress\AI\get_min_content_length;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content Translation experiment.
 *
 * @since x.x.x
 */
class Content_Translation extends Abstract_Feature {

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'content-translation';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Content Translation', 'ai' ),
			'description' => __( 'Translate block content into a different language. Requires an AI connector that includes support for text generation models.', 'ai' ),
			'category'    => Experiment_Category::EDITOR,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'enqueue_block_assets', array( $this, 'enqueue_block_assets' ) );
	}

	/**
	 * Registers required abilities
	 *
	 * @since x.x.x
	 */
	public function register_abilities(): void {
		wp_register_ability(
			sprintf( 'ai/%s', $this->get_id() ),
			array(
				'label'         => $this->get_label(),
				'description'   => $this->get_description(),
				'ability_class' => Content_Translation_Ability::class,
			)
		);
	}

	public function enqueue_assets( string $hook_suffix ): void {
		// Enqueue assets only on the post editor screen.
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		Asset_Loader::enqueue_script(
			'content_translation',
			'experiments/content-translation',
			array(
				'include_core_abilities' => true,
			)
		);

		Asset_Loader::enqueue_style(
			'content_translation',
			'experiments/content-translation'
		);

		Asset_Loader::localize_script(
			'content_translation',
			'ContentTranslationData',
			array(
				'enabled'          => $this->is_enabled(),
				'minContentLength' => get_min_content_length( 'content-translation', 15 ),
				'languages'        => Languages::get_supported_languages_for_js(),
			)
		);
	}

	/**
	 * Enqueues the block stylesheet for the editor canvas.
	 *
	 * @since x.x.x
	 */
	public function enqueue_block_assets(): void {
		Asset_Loader::enqueue_style(
			'content_translation',
			'experiments/content-translation'
		);
	}
}
