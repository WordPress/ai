<?php
/**
 * AI Status dashboard widget.
 *
 * Displays a getting-started checklist or provider/feature status
 * depending on whether initial setup is complete.
 *
 * @package WordPress\AI\Admin\Dashboard
 *
 * @since 0.8.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Admin\Dashboard;

use WordPress\AI\Features\Registry;
use WordPress\AI\Settings\Settings_Registration;

use function WordPress\AI\has_ai_credentials;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the AI Status dashboard widget.
 *
 * @since 0.8.0
 */
class AI_Status_Widget {

	/**
	 * The feature registry instance.
	 *
	 * @since 0.8.0
	 *
	 * @var \WordPress\AI\Features\Registry
	 */
	private Registry $registry;

	/**
	 * Constructor.
	 *
	 * @since 0.8.0
	 *
	 * @param \WordPress\AI\Features\Registry $registry The feature registry.
	 */
	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Renders the widget content.
	 *
	 * Determines whether to show the getting-started checklist or
	 * the full status view based on setup completion.
	 *
	 * @since 0.8.0
	 */
	public function render(): void {
		$has_credentials = has_ai_credentials();
		$global_enabled  = (bool) get_option( Settings_Registration::GLOBAL_OPTION, false );
		$any_feature_on  = $this->has_any_enabled_feature();

		if ( $has_credentials && $global_enabled && $any_feature_on ) {
			$this->render_status();
		} else {
			$this->render_getting_started( $has_credentials, $global_enabled, $any_feature_on );
		}
	}

	/**
	 * Renders the getting-started checklist.
	 *
	 * @since 0.8.0
	 *
	 * @param bool $has_credentials   Whether any AI provider credentials are configured.
	 * @param bool $global_enabled    Whether the global features toggle is on.
	 * @param bool $any_feature_on Whether at least one feature is enabled.
	 */
	private function render_getting_started( bool $has_credentials, bool $global_enabled, bool $any_feature_on ): void {
		$steps = array(
			array(
				'done'  => $has_credentials,
				'label' => __( 'Configure an AI provider', 'ai' ),
				'url'   => admin_url( 'options-connectors.php' ),
			),
			array(
				'done'  => $global_enabled,
				'label' => __( 'Globally enable AI Features', 'ai' ),
				'url'   => admin_url( 'options-general.php?page=ai-wp-admin' ),
			),
			array(
				'done'  => $any_feature_on,
				'label' => __( 'Enable a feature or experiment', 'ai' ),
				'url'   => admin_url( 'options-general.php?page=ai-wp-admin' ),
			),
		);
		?>

		<div class="ai-dashboard-status">
			<p class="ai-dashboard-status__intro">
				<?php esc_html_e( 'Complete these steps to get started with the AI plugin:', 'ai' ); ?>
			</p>
			<ol class="ai-dashboard-status__checklist">
				<?php foreach ( $steps as $step ) : ?>
					<li class="ai-dashboard-status__step">
						<span class="dashicons <?php echo $step['done'] ? 'dashicons-yes-alt ai-dashboard-status__icon--success' : 'dashicons-dismiss ai-dashboard-status__icon--error'; ?>"></span>
						<a href="<?php echo esc_url( $step['url'] ); ?>">
							<?php echo esc_html( $step['label'] ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>

		<?php
	}

	/**
	 * Renders the full status view.
	 *
	 * @since 0.8.0
	 *
	 */
	private function render_status(): void {
		$connectors            = $this->get_ai_connectors();
		$stable_features       = $this->registry->get_features_by_stability( 'stable' );
		$experimental_features = $this->registry->get_features_by_stability( 'experimental' );
		?>

		<div class="ai-dashboard-status">
			<div class="ai-dashboard-status__columns">
				<div class="ai-dashboard-status__column">
					<h4 class="ai-dashboard-status__section-title"><?php esc_html_e( 'Connectors', 'ai' ); ?></h4>
					<ul class="ai-dashboard-status__list">
						<?php foreach ( $connectors as $connector ) : ?>
							<li class="ai-dashboard-status__list-item">
								<?php if ( $connector['configured'] ) : ?>
									<span class="dashicons dashicons-yes-alt ai-dashboard-status__icon--success"></span>
								<?php else : ?>
									<span class="dashicons dashicons-no ai-dashboard-status__icon--error"></span>
								<?php endif; ?>
								<?php echo esc_html( $connector['name'] ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<a class="ai-dashboard-status__column-link" href="<?php echo esc_url( admin_url( 'options-connectors.php' ) ); ?>">
						<?php esc_html_e( 'Manage Connectors', 'ai' ); ?>
					</a>
				</div>

				<div class="ai-dashboard-status__column">
					<h4 class="ai-dashboard-status__section-title"><?php esc_html_e( 'Features', 'ai' ); ?></h4>
					<ul class="ai-dashboard-status__list">
						<?php foreach ( $stable_features as $feature ) : ?>
							<li class="ai-dashboard-status__list-item">
								<?php if ( $feature->is_enabled() ) : ?>
									<span class="dashicons dashicons-yes-alt ai-dashboard-status__icon--success"></span>
								<?php else : ?>
									<span class="dashicons dashicons-no ai-dashboard-status__icon--error"></span>
								<?php endif; ?>
								<?php echo esc_html( $feature->get_label() ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<a class="ai-dashboard-status__column-link" href="<?php echo esc_url( admin_url( 'options-general.php?page=ai-wp-admin' ) ); ?>">
						<?php esc_html_e( 'Manage Features', 'ai' ); ?>
					</a>
				</div>

				<div class="ai-dashboard-status__column">
					<h4 class="ai-dashboard-status__section-title"><?php esc_html_e( 'Experiments', 'ai' ); ?></h4>
					<ul class="ai-dashboard-status__list">
						<?php foreach ( $experimental_features as $feature ) : ?>
							<li class="ai-dashboard-status__list-item">
								<?php if ( $feature->is_enabled() ) : ?>
									<span class="dashicons dashicons-yes-alt ai-dashboard-status__icon--success"></span>
								<?php else : ?>
									<span class="dashicons dashicons-no ai-dashboard-status__icon--error"></span>
								<?php endif; ?>
								<?php echo esc_html( $feature->get_label() ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<a class="ai-dashboard-status__column-link" href="<?php echo esc_url( admin_url( 'options-general.php?page=ai-wp-admin' ) ); ?>">
						<?php esc_html_e( 'Manage Experiments', 'ai' ); ?>
					</a>
				</div>
			</div>
		</div>

		<?php
	}

	/**
	 * Returns AI provider connectors with their configuration status.
	 *
	 * @since 0.8.0
	 *
	 * @return list<array{name: string, configured: bool}> Connector info.
	 */
	private function get_ai_connectors(): array {
		$connectors = array();

		foreach ( wp_get_connectors() as $slug => $connector_data ) {
			if ( 'ai_provider' !== $connector_data['type'] ) {
				continue;
			}

			$auth       = $connector_data['authentication'];
			$configured = ( $this->is_connector_plugin_active( $connector_data )
				&& 'api_key' === $auth['method']
				&& ! empty( $auth['setting_name'] )
				&& '' !== get_option( $auth['setting_name'], '' ) );

			$connectors[] = array(
				'name'       => $connector_data['name'] ?? $slug,
				'configured' => $configured,
			);
		}

		return $connectors;
	}

	/**
	 * Checks whether the connector's related plugin is currently active.
	 *
	 * If plugin metadata is not provided for a connector, it is treated as active.
	 *
	 * @since 0.8.0
	 *
	 * @param array<string, mixed> $connector_data Connector metadata.
	 * @return bool True if the connector plugin is active or unknown, false if known inactive.
	 */
	private function is_connector_plugin_active( array $connector_data ): bool {
		if ( empty( $connector_data['plugin'] ) || ! is_array( $connector_data['plugin'] ) ) {
			return true;
		}

		$plugin_file = '';

		if ( ! empty( $connector_data['plugin']['file'] ) && is_string( $connector_data['plugin']['file'] ) ) {
			$plugin_file = $connector_data['plugin']['file'];
		} elseif ( ! empty( $connector_data['plugin']['plugin_file'] ) && is_string( $connector_data['plugin']['plugin_file'] ) ) {
			$plugin_file = $connector_data['plugin']['plugin_file'];
		} elseif ( ! empty( $connector_data['plugin']['pluginFile'] ) && is_string( $connector_data['plugin']['pluginFile'] ) ) {
			$plugin_file = $connector_data['plugin']['pluginFile'];
		}

		if ( '' === $plugin_file ) {
			return true;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( is_plugin_active( $plugin_file ) ) {
			return true;
		}

		return is_multisite() && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $plugin_file );
	}

	/**
	 * Checks whether any registered feature is individually enabled.
	 *
	 * @since 0.8.0
	 *
	 * @return bool True if at least one feature is enabled.
	 */
	private function has_any_enabled_feature(): bool {
		foreach ( $this->registry->get_all_features() as $feature ) {
			if ( $feature->is_enabled() ) {
				return true;
			}
		}

		return false;
	}
}
