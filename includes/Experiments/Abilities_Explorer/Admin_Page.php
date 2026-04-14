<?php
/**
 * Admin Page Class
 *
 * Handles admin menu, pages, and UI rendering.
 *
 * @package WordPress\AI\Experiments\Abilities_Explorer
 * @since 0.2.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Abilities_Explorer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Page Class
 *
 * Manages the admin interface for Abilities Explorer.
 *
 * @since 0.2.0
 */
class Admin_Page {

	/**
	 * Initialize admin functionality.
	 *
	 * @since 0.2.0
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'wp_ajax_ai_ability_explorer_invoke', array( $this, 'ajax_invoke_ability' ) );
	}

	/**
	 * Add admin menu item.
	 *
	 * @since 0.2.0
	 */
	public function add_admin_menu(): void {
		$hook = add_submenu_page(
			'tools.php',
			__( 'Abilities Explorer', 'ai' ),
			__( 'Abilities Explorer', 'ai' ),
			'manage_options',
			'ai-abilities-explorer',
			array( $this, 'render_page' )
		);

		if ( ! $hook ) {
			return;
		}

		add_action( "load-{$hook}", array( $this, 'add_help_tabs' ) );
	}

	/**
	 * Render the main page.
	 *
	 * @since 0.2.0
	 */
	public function render_page(): void {
		// Check user capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'ai' ) );
		}

		// Get current action.
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap ability-explorer-wrap">';
		echo '<h1>' . esc_html__( 'Ability Explorer', 'ai' ) . '</h1>';

		// Render appropriate view based on action.
		switch ( $action ) {
			case 'view':
				$this->render_detail_view();
				break;

			case 'test':
				$this->render_test_runner();
				break;

			case 'list':
			default:
				$this->render_statistics();
				$this->render_list_view();
				break;
		}

		echo '</div>';
	}

	/**
	 * Render statistics dashboard.
	 *
	 * @since 0.2.0
	 */
	private function render_statistics(): void {
		$stats = Ability_Handler::get_statistics();

		?>
		<div class="ability-explorer-stats">
			<div class="ability-stat-card">
				<div class="ability-stat-number"><?php echo absint( $stats['total'] ); ?></div>
				<div class="ability-stat-label"><?php esc_html_e( 'Total Abilities', 'ai' ); ?></div>
			</div>

			<div class="ability-stat-card">
				<div class="ability-stat-number"><?php echo absint( $stats['by_provider']['Core'] ?? 0 ); ?></div>
				<div class="ability-stat-label"><?php esc_html_e( 'Core', 'ai' ); ?></div>
			</div>

			<div class="ability-stat-card">
				<div class="ability-stat-number"><?php echo absint( $stats['by_provider']['Plugin'] ?? 0 ); ?></div>
				<div class="ability-stat-label"><?php esc_html_e( 'Plugins', 'ai' ); ?></div>
			</div>

			<div class="ability-stat-card">
				<div class="ability-stat-number"><?php echo absint( $stats['by_provider']['Theme'] ?? 0 ); ?></div>
				<div class="ability-stat-label"><?php esc_html_e( 'Theme', 'ai' ); ?></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render list view.
	 *
	 * @since 0.2.0
	 */
	private function render_list_view(): void {
		$table = new Ability_Table();
		$table->prepare_items();

		?>
		<form method="get">
			<input type="hidden" name="page" value="ai-abilities-explorer" />
			<?php
			$table->search_box( __( 'Search Abilities', 'ai' ), 'ability' );
			$table->display();
			?>
		</form>
		<?php
	}

	/**
	 * Render detail view.
	 *
	 * @since 0.2.0
	 */
	private function render_detail_view(): void {
		$ability_slug = isset( $_GET['ability'] ) ? sanitize_text_field( wp_unslash( $_GET['ability'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( empty( $ability_slug ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'No ability specified.', 'ai' ) . '</p></div>';
			return;
		}

		$ability = Ability_Handler::get_ability( $ability_slug );

		if ( ! $ability ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Ability not found.', 'ai' ) . '</p></div>';
			return;
		}

		$back_url = admin_url( 'tools.php?page=ai-abilities-explorer' );
		$test_url = add_query_arg(
			array(
				'page'    => 'ai-abilities-explorer',
				'action'  => 'test',
				'ability' => $ability_slug,
			),
			admin_url( 'tools.php' )
		);

		?>
		<div class="ability-explorer-detail">
			<div class="ability-detail-header">
				<a href="<?php echo esc_url( $back_url ); ?>" class="button">&larr; <?php esc_html_e( 'Back to List', 'ai' ); ?></a>
				<a href="<?php echo esc_url( $test_url ); ?>" class="button button-primary"><?php esc_html_e( 'Test Ability', 'ai' ); ?></a>
			</div>

			<h2><?php echo esc_html( $ability['name'] ); ?></h2>
			<p class="ability-detail-slug"><code><?php echo esc_html( $ability['slug'] ); ?></code></p>

			<?php if ( ! empty( $ability['description'] ) ) : ?>
				<div class="ability-detail-section">
					<h3><?php esc_html_e( 'Description', 'ai' ); ?></h3>
					<p><?php echo esc_html( $ability['description'] ); ?></p>
				</div>
			<?php endif; ?>

			<div class="ability-detail-section">
				<h3><?php esc_html_e( 'Details', 'ai' ); ?></h3>
				<table class="ability-detail-table">
					<tr>
						<th><?php esc_html_e( 'Provider', 'ai' ); ?></th>
						<td><span class="ability-provider ability-provider-<?php echo esc_attr( strtolower( $ability['provider'] ) ); ?>"><?php echo esc_html( Ability_Handler::get_provider_label( $ability['provider'] ) ); ?></span></td>
					</tr>
				</table>
			</div>

			<?php if ( ! empty( $ability['input_schema'] ) ) : ?>
				<div class="ability-detail-section">
					<h3><?php esc_html_e( 'Input Schema', 'ai' ); ?></h3>
					<div class="ability-schema-wrapper">
						<button type="button" class="button button-small ability-copy-btn" data-copy="input-schema"><?php esc_html_e( 'Copy', 'ai' ); ?></button>
						<pre class="ability-schema-display" id="input-schema"><?php echo esc_html( (string) wp_json_encode( $ability['input_schema'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $ability['output_schema'] ) ) : ?>
				<div class="ability-detail-section">
					<h3><?php esc_html_e( 'Output Schema', 'ai' ); ?></h3>
					<div class="ability-schema-wrapper">
						<button type="button" class="button button-small ability-copy-btn" data-copy="output-schema"><?php esc_html_e( 'Copy', 'ai' ); ?></button>
						<pre class="ability-schema-display" id="output-schema"><?php echo esc_html( (string) wp_json_encode( $ability['output_schema'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
					</div>
				</div>
			<?php endif; ?>

			<div class="ability-detail-section">
				<h3><?php esc_html_e( 'Raw Data', 'ai' ); ?></h3>
				<div class="ability-schema-wrapper">
					<button type="button" class="button button-small ability-copy-btn" data-copy="raw-data"><?php esc_html_e( 'Copy', 'ai' ); ?></button>
					<pre class="ability-schema-display" id="raw-data"><?php echo esc_html( (string) wp_json_encode( $ability['raw_data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render test runner.
	 *
	 * @since 0.2.0
	 */
	private function render_test_runner(): void {
		$ability_slug = isset( $_GET['ability'] ) ? sanitize_text_field( wp_unslash( $_GET['ability'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( empty( $ability_slug ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'No ability specified.', 'ai' ) . '</p></div>';
			return;
		}

		$ability = Ability_Handler::get_ability( $ability_slug );

		if ( ! $ability ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Ability not found.', 'ai' ) . '</p></div>';
			return;
		}

		$back_url   = admin_url( 'tools.php?page=ai-abilities-explorer' );
		$detail_url = add_query_arg(
			array(
				'page'    => 'ai-abilities-explorer',
				'action'  => 'view',
				'ability' => $ability_slug,
			),
			admin_url( 'tools.php' )
		);

		// Generate example input from input schema.
		$example_input = $this->generate_example_input( $ability['input_schema'] );

		?>
		<div class="ability-explorer-test-runner">
			<div class="ability-detail-header">
				<a href="<?php echo esc_url( $back_url ); ?>" class="button">&larr; <?php esc_html_e( 'Back to List', 'ai' ); ?></a>
				<a href="<?php echo esc_url( $detail_url ); ?>" class="button"><?php esc_html_e( 'View Details', 'ai' ); ?></a>
			</div>

			<h2><?php esc_html_e( 'Test Ability:', 'ai' ); ?> <?php echo esc_html( $ability['name'] ); ?></h2>
			<p class="ability-detail-slug"><code><?php echo esc_html( $ability['slug'] ); ?></code></p>

			<?php if ( ! empty( $ability['description'] ) ) : ?>
				<p class="description"><?php echo esc_html( $ability['description'] ); ?></p>
			<?php endif; ?>

			<div class="ability-test-editor">
				<h3><?php esc_html_e( 'Input Data', 'ai' ); ?></h3>
				<?php if ( empty( $ability['input_schema'] ) ) : ?>
					<div class="notice notice-warning inline" style="margin: 10px 0;">
						<p>
							<strong><?php esc_html_e( 'No Input Required', 'ai' ); ?></strong><br>
							<?php esc_html_e( 'This ability does not accept any input parameters. Simply click "Invoke Ability" to execute it.', 'ai' ); ?>
						</p>
					</div>
				<?php else : ?>
					<p class="description">
						<?php
						esc_html_e( 'Edit the JSON input below to test the ability. The input will be validated against the input schema if available.', 'ai' );
						?>
					</p>
					<div class="notice notice-info inline" style="margin: 10px 0;">
						<p>
							<strong><?php esc_html_e( 'How to test:', 'ai' ); ?></strong><br>
							<ol>
								<li><?php esc_html_e( 'Edit the JSON input below with your test data', 'ai' ); ?></li>
								<li><?php esc_html_e( 'Click "Validate Input" to check your JSON is correct', 'ai' ); ?></li>
								<li><?php esc_html_e( 'Click "Invoke Ability" to execute the ability with your input', 'ai' ); ?></li>
								<li><?php esc_html_e( 'View the results below', 'ai' ); ?></li>
							</ol>
						</p>
					</div>
				<?php endif; ?>

				<textarea id="ability-test-payload" rows="12"><?php echo esc_textarea( (string) wp_json_encode( $example_input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></textarea>

				<div class="ability-test-actions">
					<button type="button" id="ability-test-invoke" class="button button-primary" data-ability="<?php echo esc_attr( $ability_slug ); ?>">
						<?php esc_html_e( 'Invoke Ability', 'ai' ); ?>
					</button>
					<button type="button" id="ability-test-validate" class="button">
						<?php esc_html_e( 'Validate Input', 'ai' ); ?>
					</button>
					<button type="button" id="ability-test-clear" class="button">
						<?php esc_html_e( 'Clear Result', 'ai' ); ?>
					</button>
				</div>

				<div id="ability-test-validation" class="ability-test-validation" style="display: none;"></div>
			</div>

			<div class="ability-test-result-container" id="ability-test-result-container" style="display: none;">
				<h3><?php esc_html_e( 'Result', 'ai' ); ?></h3>
				<div id="ability-test-result"></div>
			</div>

			<?php if ( ! empty( $ability['input_schema'] ) ) : ?>
				<div class="ability-test-schema">
					<h3><?php esc_html_e( 'Input Schema Reference', 'ai' ); ?></h3>
					<div class="ability-schema-wrapper">
						<button type="button" class="button button-small ability-copy-btn" data-copy="test-input-schema"><?php esc_html_e( 'Copy', 'ai' ); ?></button>
						<pre class="ability-schema-display" id="test-input-schema"><?php echo esc_html( (string) wp_json_encode( $ability['input_schema'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<script type="application/json" id="ability-input-schema">
			<?php echo wp_json_encode( $ability['input_schema'] ); ?>
		</script>
		<?php
	}

	/**
	 * Generate example input from input schema.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string,mixed> $schema Input schema.
	 * @return array<string,mixed> Example input.
	 */
	private function generate_example_input( array $schema ): array {
		if ( empty( $schema ) || ! isset( $schema['properties'] ) ) {
			return array();
		}

		$input = array();

		foreach ( $schema['properties'] as $prop_name => $prop_schema ) {
			$input[ $prop_name ] = $this->get_example_value( $prop_schema );
		}

		return $input;
	}

	/**
	 * Get example value for a schema property.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string,mixed> $prop_schema Property schema.
	 * @return mixed Example value.
	 */
	private function get_example_value( array $prop_schema ) {
		if ( isset( $prop_schema['default'] ) ) {
			return $prop_schema['default'];
		}

		if ( isset( $prop_schema['example'] ) ) {
			return $prop_schema['example'];
		}

		$type = $prop_schema['type'] ?? 'string';

		switch ( $type ) {
			case 'string':
				return '';
			case 'number':
			case 'integer':
				return 0;
			case 'boolean':
				return false;
			case 'array':
				return array();
			case 'object':
				return new \stdClass();
			default:
				return null;
		}
	}

	/**
	 * AJAX handler for invoking abilities.
	 *
	 * @since 0.2.0
	 */
	public function ajax_invoke_ability(): void {
		// Verify nonce.
		check_ajax_referer( 'ai_ability_explorer_invoke', 'nonce' );

		// Check user capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Insufficient permissions.', 'ai' ),
				)
			);
		}

		// Get parameters.
		$ability_slug = isset( $_POST['ability'] ) ? sanitize_text_field( wp_unslash( $_POST['ability'] ) ) : '';
		$input        = isset( $_POST['input'] ) ? json_decode( wp_unslash( $_POST['input'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( empty( $ability_slug ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Ability slug is required.', 'ai' ),
				)
			);
		}

		// Get ability to validate.
		$ability = Ability_Handler::get_ability( $ability_slug );

		if ( ! $ability ) {
			wp_send_json_error(
				array(
					'message' => __( 'Ability not found.', 'ai' ),
				)
			);
		}

		// Validate input.
		if ( ! empty( $ability['input_schema'] ) ) {
			$validation = Ability_Handler::validate_input( $ability['input_schema'], $input );

			if ( ! $validation['valid'] ) {
				wp_send_json_error(
					array(
						'message' => __( 'Input validation failed.', 'ai' ),
						'errors'  => $validation['errors'],
					)
				);
			}
		}

		// Invoke the ability.
		$result = Ability_Handler::invoke_ability( $ability_slug, $input );

		if ( $result['success'] ) {
			wp_send_json_success(
				array(
					'message' => __( 'Ability invoked successfully.', 'ai' ),
					'data'    => $result['data'] ?? null,
				)
			);
		} else {
			wp_send_json_error(
				array(
					'message' => $result['error'] ?? __( 'Unknown error occurred.', 'ai' ),
					'trace'   => $result['trace'] ?? null,
				)
			);
		}
	}

	/**
	 * Add contextual help tabs to the screen.
	 *
	 * @since 0.4.0
	 */
	public function add_help_tabs(): void {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$screen->add_help_tab(
			array(
				'id'      => 'abilities-overview',
				'title'   => __( 'Overview', 'ai' ),
				'content' =>
					'<p>' . esc_html__( 'Abilities are a standardized way for WordPress core, plugins, and themes to expose discrete units of functionality. Each ability has a name, optional input/output schemas, and can be invoked programmatically.', 'ai' ) . '</p>' .
					'<p>' . esc_html__( 'The Abilities Explorer lets you browse every registered ability, inspect its schemas, and test it with custom input right from the admin.', 'ai' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'abilities-providers',
				'title'   => esc_html__( 'Providers', 'ai' ),
				'content' =>
					'<p>' . esc_html__( 'Every ability is associated with a provider that indicates where it comes from:', 'ai' ) . '</p>' .
					'<ul>' .
						'<li><strong>' . esc_html__( 'Core', 'ai' ) . '</strong>: ' . esc_html__( 'Built into WordPress itself.', 'ai' ) . '</li>' .
						'<li><strong>' . esc_html__( 'Plugin', 'ai' ) . '</strong>: ' . esc_html__( 'Registered by an active plugin.', 'ai' ) . '</li>' .
						'<li><strong>' . esc_html__( 'Theme', 'ai' ) . '</strong>: ' . esc_html__( 'Registered by the active theme.', 'ai' ) . '</li>' .
					'</ul>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'abilities-testing',
				'title'   => esc_html__( 'Testing', 'ai' ),
				'content' =>
					'<p>' . esc_html__( 'You can test any ability directly from this screen:', 'ai' ) . '</p>' .
					'<ol>' .
						'<li>' . __( 'Click "Test" next to an ability in the list.', 'ai' ) . '</li>' .
						'<li>' . __( 'Edit the pre-filled Input Data if the ability accepts JSON parameters.', 'ai' ) . '</li>' .
						'<li>' . __( 'Use "Validate Input" to check your JSON against the schema.', 'ai' ) . '</li>' .
						'<li>' . __( 'Click "Invoke Ability" to execute it and see the result.', 'ai' ) . '</li>' .
					'</ol>',
			)
		);

		$screen->set_help_sidebar(
			'<p><strong>' . esc_html__( 'For more information:', 'ai' ) . '</strong></p>' .
			'<p><a href="https://developer.wordpress.org/apis/abilities/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Abilities API Documentation', 'ai' ) . '</a></p>'
		);
	}
}
