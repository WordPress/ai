<?php
/**
 * Topic Pages experiment.
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
			'description' => __( 'Generate guides for categories with five or more public posts. Preview and save each section on the Category edit screen.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
			'usage'       => array(
				'trigger'  => 'on_demand',
				'runs'     => __( 'Runs when an editor clicks Regenerate on the Category edit screen.', 'ai' ),
				'sends'    => __( 'Selected public post excerpts from eligible categories, including child categories', 'ai' ),
				'requests' => __( 'One text-generation request per regenerated section', 'ai' ),
				'details'  => __( 'Generated text stays private until an editor chooses Save. Provider charges may apply when generating.', 'ai' ),
			),
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
		add_action( 'save_post', array( Sources::class, 'post_changed' ), 20 );
		add_action( 'clean_term_cache', array( Sources::class, 'invalidate' ), 10, 2 );
		add_filter( 'category_template_hierarchy', array( new Template(), 'hierarchy' ) );
		( new Template() )->register();
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
		foreach ( array( 'summary', 'overview', 'timeline' ) as $section ) {
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
					'render_callback' => function ( array $attributes ) use ( $section, $labels, $store ): string {
						$term_id = (int) ( $attributes['termId'] ?? 0 );
						if ( ! $term_id ) {
							$term    = get_queried_object();
							$term_id = $term instanceof WP_Term ? $term->term_id : 0;
						}
						$content = 'timeline' === $section ? $this->timeline( $term_id ) : $store->render( $term_id, $section );
						if ( '' === $content ) {
							return '';
						}
						$classes = 'wpai-topic-block wpai-topic-' . $section;
						if ( 'overview' === $section ) {
							$content .= '<p class="wpai-topic-note">' . esc_html__( 'Based on selected article excerpts, rather than every post in this category.', 'ai' ) . '</p>';
						}
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
				'category'      => WPAI_DEFAULT_ABILITY_CATEGORY,
				'ability_class' => Topic_Overview::class,
			)
		);
	}

	/** Add the small review panel to the existing Category form. */
	public function term_panel( WP_Term $term ): void {
		if ( ! current_user_can( 'edit_term', $term->term_id ) ) {
			return;
		}
		echo '<tr class="form-field"><th scope="row">' . esc_html__( 'Topic guide', 'ai' ) . '</th><td><div id="ai-topic-review"></div></td></tr>';
	}

	/** Load only on an existing supported term's edit screen. */
	public function enqueue_admin( string $hook ): void {
		if ( 'term.php' !== $hook ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation; REST writes use nonces and capabilities.
		$id   = isset( $_GET['tag_ID'] ) ? absint( $_GET['tag_ID'] ) : 0;
		$term = ( new Sources() )->term( $id );
		if ( is_wp_error( $term ) || ! current_user_can( 'edit_term', $id ) ) {
			return;
		}
		wp_enqueue_style( 'wp-components' );
		Asset_Loader::enqueue_script( 'topic_review', 'experiments/topic-pages' );
		Asset_Loader::enqueue_style( 'topic_review', 'experiments/topic-pages' );
		Asset_Loader::localize_script(
			'topic_review',
			'TopicReviewData',
			array(
				'termId'     => $id,
				'archiveUrl' => get_term_link( $term ),
				'state'      => ( new Store() )->state( $id ),
			)
		);
	}

	/** Show at most ten real posts in date order, without an AI request. */
	public function timeline( int $term_id ): string {
		$sources = new Sources();
		if ( ! $sources->eligible( $term_id ) ) {
			return '';
		}
		$html = '<ol class="wpai-topic-events">';
		foreach ( $sources->sample( $term_id, 10 ) as $id ) {
			$title = get_the_title( $id );
			$title = '' !== trim( $title ) ? $title : __( '(no title)', 'ai' );
			$html .= '<li><time datetime="' . esc_attr( (string) get_the_date( 'c', $id ) ) . '">' . esc_html( (string) get_the_date( '', $id ) ) . '</time><a href="' . esc_url( (string) get_permalink( $id ) ) . '">' . esc_html( $title ) . '</a></li>';
		}
		return $html . '</ol><p class="wpai-topic-note">' . esc_html__( 'Selected posts across this category’s history. Browse all posts below.', 'ai' ) . '</p>';
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
