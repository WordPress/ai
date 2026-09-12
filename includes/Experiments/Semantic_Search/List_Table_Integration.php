<?php
/**
 * Adds "Search semantically" checkbox to wp-admin/edit.php.
 *
 * When the checkbox is checked, the standard keyword search is replaced with
 * a cosine-similarity ranked result set from the indexed embeddings.
 *
 * @package WordPress\AI\Experiments\Semantic_Search
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Semantic_Search;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Integrates semantic search into the posts list table.
 *
 * Hooks restrict_manage_posts to inject a checkbox next to the search input
 * and pre_get_posts to intercept the main WP_Query when the checkbox is checked,
 * replacing the default LIKE-based keyword search with a cosine-similarity ranked
 * result set. Falls back to standard WordPress search silently when the embedding
 * provider is unavailable or returns no results.
 *
 * @internal
 * @since x.x.x
 */
class List_Table_Integration {

	/**
	 * GET parameter name that signals a semantic search request.
	 *
	 * Submitted as a URL parameter by the auto-submitting checkbox so that the
	 * pre_get_posts handler can detect it on the resulting page load.
	 *
	 * @since x.x.x
	 * @var string
	 */
	private const PARAM = 'wpai_semantic';

	/**
	 * Registers the restrict_manage_posts and pre_get_posts hooks.
	 *
	 * @since x.x.x
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'restrict_manage_posts', array( $this, 'render_checkbox' ) );
		add_action( 'pre_get_posts', array( $this, 'maybe_apply_semantic_search' ) );
	}

	/**
	 * Renders the "Search semantically" checkbox inline next to the search input.
	 *
	 * The checkbox auto-submits the form on change via an onchange handler so the
	 * user does not need to click a separate submit button. Only rendered on admin
	 * screens to avoid affecting front-end query forms.
	 *
	 * @since x.x.x
	 *
	 * @return void
	 */
	public function render_checkbox(): void {
		if ( ! is_admin() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$checked = isset( $_GET[ self::PARAM ] ) ? ' checked' : '';
		?>
		<label style="margin-left:6px; line-height:28px;">
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::PARAM ); ?>"
				value="1"
				<?php echo esc_attr( $checked ); ?>
				onchange="this.form.submit()"
			/>
			<?php esc_html_e( 'Search semantically', 'ai' ); ?>
		</label>
		<?php
	}

	/**
	 * Intercepts the main WP_Query on edit.php and re-ranks results semantically.
	 *
	 * Runs only when all of the following conditions are true:
	 *   - The current request is in the admin.
	 *   - The query is the main page query.
	 *   - The wpai_semantic GET parameter is present.
	 *   - The query has a non-empty search term.
	 *   - The Vector_Search provider reports itself as available.
	 *
	 * When conditions are met, the method clears the `s` parameter and replaces
	 * it with `post__in` (the ranked post IDs) and `orderby: post__in` to preserve
	 * the semantic relevance order. If the provider is unavailable or returns no
	 * results, the method returns without modifying the query so WordPress falls
	 * back to its default keyword search.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Query $query The current WP_Query instance, passed by reference.
	 * @return void
	 */
	public function maybe_apply_semantic_search( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET[ self::PARAM ] ) ) {
			return;
		}

		$search_term = $query->get( 's' );

		if ( ! $search_term ) {
			return;
		}

		$vector_search = new Vector_Search();

		if ( ! $vector_search->is_available() ) {
			return;
		}

		$post_types = array_values(
			array_filter( array_map( 'strval', (array) $query->get( 'post_type' ) ) )
		);

		if ( empty( $post_types ) ) {
			$post_types = array( 'post', 'page' );
		}

		$results = $vector_search->search(
			$search_term,
			array(
				'limit'     => 50,
				'post_type' => $post_types,
			)
		);

		if ( empty( $results ) ) {
			return;
		}

		$ids = array_column( $results, 'id' );

		$query->set( 's', '' );
		$query->set( 'post__in', $ids );
		$query->set( 'orderby', 'post__in' );
	}
}
