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

use function WordPress\AI\get_ai_connectors;
use function WordPress\AI\has_ai_credentials;
use function WordPress\AI\is_connector_configured;

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
		$has_credentials         = has_ai_credentials();
		$global_enabled          = (bool) get_option( Settings_Registration::GLOBAL_OPTION, false );
		$feature_setting_enabled = $this->has_any_enabled_feature_setting();

		if ( $has_credentials && $global_enabled && $feature_setting_enabled ) {
			$this->render_status();
		} else {
			$this->render_getting_started( $has_credentials, $global_enabled, $feature_setting_enabled );
		}
	}

	/**
	 * Renders the getting-started checklist.
	 *
	 * @since 0.8.0
	 *
	 * @param bool $has_credentials         Whether any AI provider credentials are configured.
	 * @param bool $global_enabled          Whether the global features toggle is on.
	 * @param bool $feature_setting_enabled Whether at least one feature setting is enabled.
	 */
	private function render_getting_started( bool $has_credentials, bool $global_enabled, bool $feature_setting_enabled ): void {
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
				'done'  => $feature_setting_enabled,
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

		foreach ( get_ai_connectors() as $slug => $connector_data ) {
			$auth       = $connector_data['authentication'];
			$configured = 'api_key' === $auth['method']
				&& is_connector_configured( $slug );

			/**
			 * Filters whether an AI connector is configured.
			 *
			 * Allows third-party plugins to declare credential availability for
			 * connectors that do not rely on API key settings.
			 *
			 * The dynamic portion of the hook name, `$slug`, refers to the connector slug.
			 * For example, if the connector slug is 'openai', the hook name
			 * will be 'wpai_is_openai_connector_configured'.
			 *
			 * @since 0.9.0
			 *
			 * @param bool $configured Whether the connector is configured.
			 * @param array<string, mixed> $connector_data The connector data.
			 */
			$configured = (bool) apply_filters( "wpai_is_{$slug}_connector_configured", $configured, $connector_data );

			$connectors[] = array(
				'name'       => $connector_data['name'] ?? $slug,
				'configured' => $configured,
			);
		}

		return $connectors;
	}

	/**
	 * Checks whether any registered feature has its individual setting enabled.
	 *
	 * @since 0.8.0
	 *
	 * @return bool True if at least one feature setting is enabled.
	 */
	private function has_any_enabled_feature_setting(): bool {
		foreach ( $this->registry->get_all_features() as $feature ) {
			if ( $feature->is_individually_enabled() ) {
				return true;
			}
		}

		return false;
	}
}
