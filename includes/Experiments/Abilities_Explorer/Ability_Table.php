<?php
/**
 * Ability Table Class
 *
 * Extends WP_List_Table to display abilities in a searchable, filterable table.
 *
 * @package WordPress\AI\Experiments\Abilities_Explorer
 * @since 0.2.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Abilities_Explorer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Ability Table Class
 *
 * Displays abilities in a table with search and filter functionality.
 *
 * @since 0.2.0
 */
class Ability_Table extends \WP_List_Table {

	/**
	 * Constructor.
	 *
	 * @since 0.2.0
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'ability',
				'plural'   => 'abilities',
				'ajax'     => false,
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string,mixed> Column definitions.
	 */
	public function get_columns(): array {
		return array(
			'cb'       => '<input type="checkbox" />',
			'name'     => __( 'Name', 'ai' ),
			'slug'     => __( 'Slug', 'ai' ),
			'provider' => __( 'Provider', 'ai' ),
			'actions'  => __( 'Actions', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string,mixed> Sortable column definitions.
	 */
	public function get_sortable_columns(): array {
		return array(
			'name'     => array( 'name', false ),
			'slug'     => array( 'slug', false ),
			'provider' => array( 'provider', false ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function prepare_items(): void {
		$columns  = $this->get_columns();
		$hidden   = array();
		$sortable = $this->get_sortable_columns();

		$this->_column_headers = array( $columns, $hidden, $sortable );

		// Get abilities.
		$abilities = Ability_Handler::get_all_abilities();

		// Apply search filter.
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $search ) ) {
			$abilities = array_filter(
				$abilities,
				static function ( $ability ) use ( $search ) {
					return stripos( $ability['name'], $search ) !== false
						|| stripos( $ability['slug'], $search ) !== false
						|| stripos( $ability['description'], $search ) !== false;
				}
			);
		}

		// Apply provider filter.
		$provider_filter = isset( $_REQUEST['provider'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['provider'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $provider_filter ) && 'all' !== $provider_filter ) {
			$abilities = array_filter(
				$abilities,
				static function ( $ability ) use ( $provider_filter ) {
					return $ability['provider'] === $provider_filter;
				}
			);
		}

		// Apply sorting.
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ) ) : 'name'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order   = isset( $_REQUEST['order'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) : 'asc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		usort(
			$abilities,
			static function ( $a, $b ) use ( $orderby, $order ) {
				$result = 0;

				if ( isset( $a[ $orderby ] ) && isset( $b[ $orderby ] ) ) {
					$result = strcasecmp( $a[ $orderby ], $b[ $orderby ] );
				}

				return 'asc' === $order ? $result : -$result;
			}
		);

		// Pagination.
		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$total_items  = count( $abilities );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);

		$this->items = array_slice( $abilities, ( $current_page - 1 ) * $per_page, $per_page );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $item Item data.
	 */
	public function column_default( $item, $column_name ): string {
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '—';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $item Item data.
	 */
	public function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="abilities[]" value="%s" />',
			esc_attr( $item['slug'] )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $item Item data.
	 */
	public function column_name( $item ): string {
		$detail_url = add_query_arg(
			array(
				'page'    => 'ai-abilities-explorer',
				'action'  => 'view',
				'ability' => $item['slug'],
			),
			admin_url( 'tools.php' )
		);

		return sprintf(
			'<strong><a href="%s">%s</a></strong><br /><small>%s</small>',
			esc_url( $detail_url ),
			esc_html( $item['name'] ),
			esc_html( wp_trim_words( $item['description'], 15 ) )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $item Item data.
	 */
	public function column_slug( $item ): string {
		return sprintf(
			'<code>%s</code>',
			esc_html( $item['slug'] )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $item Item data.
	 */
	public function column_provider( $item ): string {
		$provider = $item['provider'];
		$class    = 'ability-provider ability-provider-' . strtolower( $provider );

		return sprintf(
			'<span class="%s">%s</span>',
			esc_attr( $class ),
			esc_html( $provider )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $item Item data.
	 */
	public function column_actions( $item ): string {
		$detail_url = add_query_arg(
			array(
				'page'    => 'ai-abilities-explorer',
				'action'  => 'view',
				'ability' => $item['slug'],
			),
			admin_url( 'tools.php' )
		);

		$test_url = add_query_arg(
			array(
				'page'    => 'ai-abilities-explorer',
				'action'  => 'test',
				'ability' => $item['slug'],
			),
			admin_url( 'tools.php' )
		);

		return sprintf(
			'<a href="%s" class="button button-small">%s</a> <a href="%s" class="button button-small button-primary">%s</a>',
			esc_url( $detail_url ),
			esc_html__( 'View', 'ai' ),
			esc_url( $test_url ),
			esc_html__( 'Test', 'ai' )
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$provider_filter = isset( $_REQUEST['provider'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['provider'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		?>
		<div class="alignleft actions">
			<select name="provider" id="filter-by-provider">
				<option value="all" <?php selected( $provider_filter, 'all' ); ?>><?php esc_html_e( 'All Providers', 'ai' ); ?></option>
				<option value="Core" <?php selected( $provider_filter, 'Core' ); ?>><?php esc_html_e( 'Core', 'ai' ); ?></option>
				<option value="Plugin" <?php selected( $provider_filter, 'Plugin' ); ?>><?php esc_html_e( 'Plugins', 'ai' ); ?></option>
				<option value="Theme" <?php selected( $provider_filter, 'Theme' ); ?>><?php esc_html_e( 'Theme', 'ai' ); ?></option>
			</select>

			<?php submit_button( __( 'Filter', 'ai' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}
}
