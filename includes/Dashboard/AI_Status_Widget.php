<?php
/**
 * AI Status dashboard widget.
 *
 * Displays a getting-started checklist or provider/experiment status
 * depending on whether initial setup is complete.
 *
 * @package WordPress\AI\Dashboard
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Dashboard;

use WordPress\AI\Experiment_Registry;
use WordPress\AI\Settings\Settings_Registration;

use function WordPress\AI\has_ai_credentials;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the AI Status dashboard widget.
 *
 * @since x.x.x
 */
class AI_Status_Widget {

	/**
	 * The experiment registry instance.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiment_Registry
	 */
	private Experiment_Registry $registry;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Experiment_Registry $registry The experiment registry.
	 */
	public function __construct( Experiment_Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Renders the widget content.
	 *
	 * Determines whether to show the getting-started checklist or
	 * the full status view based on setup completion.
	 *
	 * @since x.x.x
	 */
	public function render(): void {
		$has_credentials   = has_ai_credentials();
		$global_enabled    = (bool) get_option( Settings_Registration::GLOBAL_OPTION, false );
		$any_experiment_on = $this->has_any_enabled_experiment();

		if ( $has_credentials && $global_enabled && $any_experiment_on ) {
			$this->render_status();
		} else {
			$this->render_getting_started( $has_credentials, $global_enabled, $any_experiment_on );
		}
	}

	/**
	 * Renders the getting-started checklist.
	 *
	 * @since x.x.x
	 *
	 * @param bool $has_credentials   Whether any AI provider credentials are configured.
	 * @param bool $global_enabled    Whether the global experiments toggle is on.
	 * @param bool $any_experiment_on Whether at least one experiment is enabled.
	 */
	private function render_getting_started( bool $has_credentials, bool $global_enabled, bool $any_experiment_on ): void {
		$steps = array(
			array(
				'done'  => $has_credentials,
				'label' => __( 'Configure an AI provider', 'ai' ),
				'url'   => admin_url( 'options-connectors.php' ),
			),
			array(
				'done'  => $global_enabled,
				'label' => __( 'Globally enable AI Experiments', 'ai' ),
				'url'   => admin_url( 'options-general.php?page=ai-experiments' ),
			),
			array(
				'done'  => $any_experiment_on,
				'label' => __( 'Enable an individual experiment', 'ai' ),
				'url'   => admin_url( 'options-general.php?page=ai-experiments' ),
			),
			array(
				'done'  => false,
				'label' => __( 'Try it out', 'ai' ),
				'url'   => admin_url( 'post-new.php' ),
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
	 * @since x.x.x
	 *
	 */
	private function render_status(): void {
		$connectors  = $this->get_ai_connectors();
		$experiments = $this->registry->get_all_experiments();
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
						<?php esc_html_e( 'Manage', 'ai' ); ?>
					</a>
				</div>

				<div class="ai-dashboard-status__column">
					<h4 class="ai-dashboard-status__section-title"><?php esc_html_e( 'Experiments', 'ai' ); ?></h4>
					<ul class="ai-dashboard-status__list">
						<?php foreach ( $experiments as $experiment ) : ?>
							<li class="ai-dashboard-status__list-item">
								<?php if ( $experiment->is_enabled() ) : ?>
									<span class="dashicons dashicons-yes-alt ai-dashboard-status__icon--success"></span>
								<?php else : ?>
									<span class="dashicons dashicons-no ai-dashboard-status__icon--error"></span>
								<?php endif; ?>
								<?php echo esc_html( $experiment->get_label() ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<a class="ai-dashboard-status__column-link" href="<?php echo esc_url( admin_url( 'options-general.php?page=ai-experiments' ) ); ?>">
						<?php esc_html_e( 'Manage', 'ai' ); ?>
					</a>
				</div>
			</div>
		</div>

		<?php
	}

	/**
	 * Returns AI provider connectors with their configuration status.
	 *
	 * @since x.x.x
	 *
	 * @return list<array{name: string, configured: bool}> Connector info.
	 */
	private function get_ai_connectors(): array {
		$connectors = array();

		if ( ! function_exists( 'wp_get_connectors' ) ) {
			return $connectors;
		}

		foreach ( wp_get_connectors() as $slug => $connector_data ) {
			if ( 'ai_provider' !== $connector_data['type'] ) {
				continue;
			}

			$auth       = $connector_data['authentication'];
			$configured = ( 'api_key' === $auth['method']
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
	 * Checks whether any registered experiment is individually enabled.
	 *
	 * @since x.x.x
	 *
	 * @return bool True if at least one experiment is enabled.
	 */
	private function has_any_enabled_experiment(): bool {
		foreach ( $this->registry->get_all_experiments() as $experiment ) {
			if ( $experiment->is_enabled() ) {
				return true;
			}
		}

		return false;
	}
}
