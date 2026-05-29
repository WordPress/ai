<?php
/**
 * Post and page body content generation experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Content_Generation;

use WordPress\AI\Abilities\Content_Generation\Content_Generation as Content_Generation_Ability;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Experiments\Experiment_Category;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Post and page body content generation experiment.
 *
 * @since 1.0.0
 */
class Content_Generation extends Abstract_Feature {

	/**
	 * The menu slug for the "Generate Post" page.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private const POST_PAGE_SLUG = 'wpai-generate-post';

	/**
	 * The menu slug for the "Generate Page" page.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private const PAGE_PAGE_SLUG = 'wpai-generate-page';

	/**
	 * The admin-post action used by the generation form.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private const FORM_ACTION = 'wpai_generate_content';

	/**
	 * Allow-listed admin error messages, keyed by error slug.
	 *
	 * @since 1.0.0
	 *
	 * @var array<string, string>|null
	 */
	private ?array $notice_map = null;

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'content-generation';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Content Generation', 'ai' ),
			'description' => __( 'Generates full post and page body content from a title and brief. Requires an AI connector that includes support for text generation models.', 'ai' ),
			'category'    => Experiment_Category::EDITOR,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_pages' ) );
		add_action( 'admin_post_' . self::FORM_ACTION, array( $this, 'handle_form_submit' ) );
	}

	/**
	 * Registers any needed abilities.
	 *
	 * @since 1.0.0
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'ai/' . $this->get_id(),
			array(
				'label'         => $this->get_label(),
				'description'   => $this->get_description(),
				'ability_class' => Content_Generation_Ability::class,
			)
		);
	}

	/**
	 * Registers the "Generate Post" and "Generate Page" admin submenu pages.
	 *
	 * @since 1.0.0
	 */
	public function register_admin_pages(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		add_submenu_page(
			'edit.php',
			__( 'Generate Post', 'ai' ),
			__( 'Generate Post', 'ai' ),
			'edit_posts',
			self::POST_PAGE_SLUG,
			array( $this, 'render_post_page' )
		);

		add_submenu_page(
			'edit.php?post_type=page',
			__( 'Generate Page', 'ai' ),
			__( 'Generate Page', 'ai' ),
			'edit_pages',
			self::PAGE_PAGE_SLUG,
			array( $this, 'render_page_page' )
		);
	}

	/**
	 * Renders the "Generate Post" admin page.
	 *
	 * @since 1.0.0
	 */
	public function render_post_page(): void {
		$this->render_form( 'post' );
	}

	/**
	 * Renders the "Generate Page" admin page.
	 *
	 * @since 1.0.0
	 */
	public function render_page_page(): void {
		$this->render_form( 'page' );
	}

	/**
	 * Checks whether the current user may generate content for the given post type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $post_type The post type ('post' or 'page').
	 * @return bool True if the user has the required capability.
	 */
	private function current_user_can_generate( string $post_type ): bool {
		return 'page' === $post_type ? current_user_can( 'edit_pages' ) : current_user_can( 'edit_posts' );
	}

	/**
	 * Returns the admin URL of the generation form for the given post type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $post_type The post type ('post' or 'page').
	 * @return string The admin URL.
	 */
	private function form_url( string $post_type ): string {
		if ( 'page' === $post_type ) {
			return admin_url( 'edit.php?post_type=page&page=' . self::PAGE_PAGE_SLUG );
		}

		return admin_url( 'edit.php?page=' . self::POST_PAGE_SLUG );
	}

	/**
	 * Returns the allow-listed admin error messages.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string> Map of error key to message.
	 */
	private function get_notice_map(): array {
		if ( null === $this->notice_map ) {
			$this->notice_map = array(
				'no_connector'      => __( 'No connector with text-generation support is configured. Please configure an AI connector and try again.', 'ai' ),
				'generation_failed' => __( 'Content generation failed. Please try again.', 'ai' ),
				'input_required'    => __( 'A title or a brief is required to generate content.', 'ai' ),
				'insert_failed'     => __( 'The generated content could not be saved as a draft.', 'ai' ),
			);
		}

		return $this->notice_map;
	}

	/**
	 * Renders an error admin notice based on the allow-listed query key, if present.
	 *
	 * @since 1.0.0
	 */
	private function maybe_render_notice(): void {
		$map = $this->get_notice_map();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of an allow-listed notice key.
		$error_key = isset( $_GET['wpai_error'] ) ? sanitize_key( wp_unslash( $_GET['wpai_error'] ) ) : '';
		if ( '' === $error_key || ! isset( $map[ $error_key ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html( $map[ $error_key ] )
		);
	}

	/**
	 * Renders the server-rendered generation form for the given post type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $post_type The post type ('post' or 'page').
	 */
	private function render_form( string $post_type ): void {
		$post_type = 'page' === $post_type ? 'page' : 'post';

		if ( ! $this->current_user_can_generate( $post_type ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'ai' ) );
		}

		$heading = 'page' === $post_type
			? __( 'Generate Page', 'ai' )
			: __( 'Generate Post', 'ai' );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html( $heading ) . '</h1>';

		$this->maybe_render_notice();

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::FORM_ACTION ); ?>" />
			<input type="hidden" name="post_type" value="<?php echo esc_attr( $post_type ); ?>" />
			<?php wp_nonce_field( self::FORM_ACTION, 'wpai_nonce' ); ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="wpai-title"><?php esc_html_e( 'Title', 'ai' ); ?></label>
						</th>
						<td>
							<input name="title" type="text" id="wpai-title" class="regular-text" value="" />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wpai-prompt"><?php esc_html_e( 'Brief', 'ai' ); ?></label>
						</th>
						<td>
							<textarea name="prompt" id="wpai-prompt" class="large-text" rows="5"></textarea>
							<p class="description"><?php esc_html_e( 'Describe what the content should cover.', 'ai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wpai-keywords"><?php esc_html_e( 'Keywords', 'ai' ); ?></label>
						</th>
						<td>
							<input name="keywords" type="text" id="wpai-keywords" class="regular-text" value="" />
							<p class="description"><?php esc_html_e( 'Comma-separated focus keywords.', 'ai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wpai-tone"><?php esc_html_e( 'Tone', 'ai' ); ?></label>
						</th>
						<td>
							<select name="tone" id="wpai-tone">
								<option value="professional"><?php esc_html_e( 'Professional', 'ai' ); ?></option>
								<option value="casual"><?php esc_html_e( 'Casual', 'ai' ); ?></option>
								<option value="friendly"><?php esc_html_e( 'Friendly', 'ai' ); ?></option>
								<option value="authoritative"><?php esc_html_e( 'Authoritative', 'ai' ); ?></option>
								<option value="technical"><?php esc_html_e( 'Technical', 'ai' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wpai-target-length"><?php esc_html_e( 'Target length (words)', 'ai' ); ?></label>
						</th>
						<td>
							<input name="target_length" type="number" id="wpai-target-length" class="small-text" min="0" step="1" value="900" />
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Generate draft', 'ai' ) ); ?>
		</form>
		<?php

		echo '</div>';
	}

	/**
	 * Handles the generation form submission.
	 *
	 * @since 1.0.0
	 */
	public function handle_form_submit(): void {
		check_admin_referer( self::FORM_ACTION, 'wpai_nonce' );

		// Sanitize and validate the post type.
		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : 'post';
		$post_type = 'page' === $post_type ? 'page' : 'post';

		if ( ! $this->current_user_can_generate( $post_type ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'ai' ) );
		}

		// Sanitize the inputs.
		$title         = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$prompt        = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		$tone          = isset( $_POST['tone'] ) ? sanitize_text_field( wp_unslash( $_POST['tone'] ) ) : 'professional';
		$target_length = isset( $_POST['target_length'] ) ? absint( wp_unslash( $_POST['target_length'] ) ) : 900;

		$keywords_raw = isset( $_POST['keywords'] ) ? sanitize_text_field( wp_unslash( $_POST['keywords'] ) ) : '';
		$keywords     = array();
		if ( '' !== $keywords_raw ) {
			foreach ( explode( ',', $keywords_raw ) as $keyword ) {
				$keyword = trim( $keyword );
				if ( '' === $keyword ) {
					continue;
				}

				$keywords[] = $keyword;
			}
		}

		$input = array(
			'title'         => $title,
			'prompt'        => $prompt,
			'keywords'      => $keywords,
			'tone'          => $tone,
			'target_length' => $target_length,
			'post_type'     => $post_type,
		);

		$ability = wp_get_ability( 'ai/' . $this->get_id() );

		if ( ! $ability ) {
			$this->redirect_with_error( $post_type, 'no_connector' );
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			$error_code = $result->get_error_code();

			if ( 'unsupported_model' === $error_code ) {
				$notice = 'no_connector';
			} elseif ( 'input_required' === $error_code ) {
				$notice = 'input_required';
			} else {
				$notice = 'generation_failed';
			}

			$this->redirect_with_error( $post_type, $notice );
		}

		$generated = is_string( $result ) ? $result : '';

		$new_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => $generated,
				'post_type'    => $post_type,
				'post_status'  => 'draft',
			),
			true
		);

		if ( is_wp_error( $new_id ) || ! $new_id ) {
			$this->redirect_with_error( $post_type, 'insert_failed' );
		}

		// The draft opening in the block editor serves as the success confirmation.
		wp_safe_redirect( admin_url( 'post.php?post=' . $new_id . '&action=edit' ) );
		exit;
	}

	/**
	 * Redirects back to the form page with an allow-listed error key, then exits.
	 *
	 * @since 1.0.0
	 *
	 * @param string $post_type The post type the form was submitted for.
	 * @param string $error_key The allow-listed error key.
	 *
	 * @phpstan-return never
	 */
	private function redirect_with_error( string $post_type, string $error_key ): void {
		wp_safe_redirect( add_query_arg( 'wpai_error', $error_key, $this->form_url( $post_type ) ) );
		exit;
	}
}
