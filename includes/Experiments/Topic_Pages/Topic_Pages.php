<?php
/**
 * Topic Pages proof of concept.
 *
 * @package WordPress\AI
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Topic_Pages;

use WP_Term;
use WordPress\AI\Abilities\Topic_Overview\Topic_Overview;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Experiment_Category;

/**
 * Connects the topic editor and saved-section blocks to WordPress.
 *
 * @since x.x.x
 */
class Topic_Pages extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public static function get_id(): string {
		return 'topic-pages';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Topic Pages', 'ai' ),
			'description' => __( 'Proof of concept: generate, edit, and approve sourced topic summaries, overviews, and timelines for category and tag archives.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'rest_api_init', array( new Controller(), 'register_routes' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_blocks' ) );
		foreach ( Sources::taxonomies() as $taxonomy ) {
			add_action( $taxonomy . '_edit_form_fields', array( $this, 'term_panel' ) );
		}
		$labels     = array(
			'summary'  => __( 'Summary', 'ai' ),
			'overview' => __( 'Narrative overview', 'ai' ),
			'timeline' => __( 'Timeline', 'ai' ),
		);
		$store      = new Store();
		$style_path = WPAI_PLUGIN_DIR . 'build-scripts/experiments/topic-pages-frontend.css';
		foreach ( Store::SECTIONS as $section ) {
			wp_enqueue_block_style(
				'ai/topic-' . $section,
				array(
					'handle' => 'ai-topic-pages',
					'src'    => WPAI_PLUGIN_URL . 'build-scripts/experiments/topic-pages-frontend.css',
					'path'   => $style_path,
					'ver'    => file_exists( $style_path ) ? (string) filemtime( $style_path ) : WPAI_VERSION,
				)
			);
			register_block_type(
				'ai/topic-' . $section,
				// @phpstan-ignore argument.type (WordPress uses an integer API version; the current stub declares string.)
				array(
					'api_version'     => 3,
					'supports'        => array(
						'html'       => false,
						'align'      => array( 'wide', 'full' ),
						'color'      => array(
							'background' => true,
							'text'       => true,
						),
						'spacing'    => array(
							'margin'  => true,
							'padding' => true,
						),
						'typography' => array(
							'fontSize'   => true,
							'lineHeight' => true,
						),
					),
					'attributes'      => array(
						'termId' => array(
							'type'    => 'number',
							'default' => 0,
						),
					),
					'render_callback' => static function ( array $attributes ) use ( $section, $labels, $store ): string {
						$term_id = (int) ( $attributes['termId'] ?? 0 );
						if ( ! $term_id ) {
							$term = get_queried_object();
							$term_id = $term instanceof WP_Term ? $term->term_id : 0;
						}
						$content = $store->render( $term_id, $section );
						if ( '' === $content ) {
							return '';
						}
						$classes = 'wpai-topic-block wpai-topic-' . $section;
						return '<section ' . get_block_wrapper_attributes( array( 'class' => $classes ) ) . '>' .
							'<header class="wpai-topic-header"><h2 class="wpai-topic-title">' . esc_html( $labels[ $section ] ) . '</h2></header>' .
							'<div class="wpai-topic-content">' . $content . '</div>' .
							'</section>';
					},
				)
			);
		}
	}

	/**
	 * Register reusable generation, following the Suggest Reply experiment.
	 *
	 * @since x.x.x
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'ai/topic-overview',
			array(
				'label'         => __( 'Topic Overview', 'ai' ),
				'description'   => __( 'Generates sourced topic sections for editorial review without saving or publishing them.', 'ai' ),
				'ability_class' => Topic_Overview::class,
			)
		);
	}

	/**
	 * Hidden editor reached from the existing term edit screen.
	 *
	 * @since x.x.x
	 */
	public function register_admin_page(): void {
		$hook = add_submenu_page( 'edit.php', __( 'Topic editor', 'ai' ), __( 'Topic editor', 'ai' ), 'read', 'ai-topic-editor', array( $this, 'render_admin' ) );
		remove_submenu_page( 'edit.php', 'ai-topic-editor' );
		// A hidden submenu no longer supplies the title to admin-header.php.
		add_action(
			'load-' . $hook,
			static function (): void {
				global $title;
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Admin page title needs to be set for hidden submenus.
				$title = __( 'Topic editor', 'ai' );
			}
		);
	}

	/**
	 * Get the editor's term with a permission check, not a public query parameter.
	 *
	 * @since x.x.x
	 *
	 * @return \WP_Term|null Editable topic, or null when permission is missing.
	 */
	private function editor_term(): ?WP_Term {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation; writes use authenticated REST.
		$id   = isset( $_GET['term_id'] ) ? absint( $_GET['term_id'] ) : 0;
		$term = ( new Sources() )->term( $id );
		if ( is_wp_error( $term ) ) {
			return null;
		}
		$taxonomy = get_taxonomy( $term->taxonomy );
		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- WordPress supplies the registered taxonomy capability mapping.
		return $taxonomy && current_user_can( $taxonomy->cap->manage_terms ) && current_user_can( 'edit_term', $id ) ? $term : null;
	}

	/**
	 * Link from the term form without changing its submission.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Term $term Topic term.
	 */
	public function term_panel( WP_Term $term ): void {
		if ( ! current_user_can( 'edit_term', $term->term_id ) ) {
			return;
		}
		$count = ( new Sources() )->query( $term, 1 )->found_posts;
		$min   = Sources::minimum();
		echo '<tr class="form-field wpai-topic-form-field"><th><label>' . esc_html__( 'Topic Page', 'ai' ) . '</label></th><td>';
		echo '<p class="description">';
		/* translators: 1: number of public items, 2: minimum required. */
		printf( esc_html__( '%1$d public items (%2$d required to generate a topic page).', 'ai' ), (int) $count, (int) $min );
		echo '</p>';
		echo '<p><a class="button button-secondary" href="' . esc_url( admin_url( 'edit.php?page=ai-topic-editor&term_id=' . $term->term_id ) ) . '">' . esc_html__( 'Open Topic Guide Editor', 'ai' ) . '</a></p>';
		if ( $count < $min ) {
			echo '<p class="description" style="color: #b32d2e;">' . esc_html__( 'Add more public articles to this topic before generating its content.', 'ai' ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Render the standalone editor's mount point.
	 *
	 * @since x.x.x
	 */
	public function render_admin(): void {
		if ( ! $this->editor_term() ) {
			wp_die( esc_html__( 'You cannot edit this topic.', 'ai' ), '', array( 'response' => 403 ) );
		}
		echo '<div id="ai-topic-editor"></div>';
	}

	/**
	 * Load the admin application only on its own screen.
	 *
	 * @since x.x.x
	 *
	 * @param string $hook Current admin screen.
	 */
	public function enqueue_admin( string $hook ): void {
		if ( 'posts_page_ai-topic-editor' !== $hook ) {
			return;
		}
		$term = $this->editor_term();
		if ( ! $term ) {
			return;
		}
		wp_enqueue_script( 'wp-format-library' );
		wp_enqueue_style( 'wp-format-library' );
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'wp-block-editor' );
		wp_enqueue_style( 'wp-block-library' );
		wp_enqueue_style( 'wp-edit-blocks' );
		Asset_Loader::enqueue_script( 'topic_editor', 'experiments/topic-pages' );
		Asset_Loader::enqueue_style( 'topic_editor', 'experiments/topic-pages' );
		Asset_Loader::localize_script(
			'topic_editor',
			'TopicEditorData',
			array(
				'termId'        => $term->term_id,
				'name'          => $term->name,
				'archiveUrl'    => get_term_link( $term ),
				'editUrl'       => get_edit_term_link( $term->term_id, $term->taxonomy ),
				'count'         => ( new Sources() )->query( $term, 1 )->found_posts,
				'minimum'       => Sources::minimum(),
				'allowedBlocks' => Store::ALLOWED_BLOCKS,
				'state'         => ( new Store() )->state( $term->term_id ),
			)
		);
	}

	/**
	 * Register public display blocks in the Site Editor and post editor.
	 *
	 * @since x.x.x
	 */
	public function enqueue_blocks(): void {
		Asset_Loader::enqueue_script( 'topic_blocks', 'experiments/topic-pages-blocks' );
		$terms   = get_terms(
			array(
				'taxonomy'   => Sources::taxonomies(),
				'hide_empty' => false,
				'number'     => 100,
			)
		);
		$options = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$options[] = array(
					'value' => $term->term_id,
					'label' => $term->name . ' (' . $term->taxonomy . ')',
				);
			}
		}
		Asset_Loader::localize_script( 'topic_blocks', 'TopicBlockData', array( 'terms' => $options ) );
	}
}
