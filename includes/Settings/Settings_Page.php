<?php
/**
 * Settings page for AI Experiments.
 *
 * @package WordPress\AI
 *
 * @since 0.1.0
 */

declare(strict_types=1);

namespace WordPress\AI\Settings;

use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiment_Registry;

use function WordPress\AI\has_ai_credentials;
use function WordPress\AI\has_valid_ai_credentials;

/**
 * Manages the admin settings page for AI experiments.
 *
 * @since 0.1.0
 */
class Settings_Page {

	/**
	 * The experiment registry instance.
	 *
	 * @since 0.1.0
	 *
	 * @var \WordPress\AI\Experiment_Registry
	 */
	private Experiment_Registry $registry;

	/**
	 * The settings page slug.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PAGE_SLUG = 'ai-experiments';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param \WordPress\AI\Experiment_Registry $registry The experiment registry.
	 */
	public function __construct( Experiment_Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Initializes the settings page hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Registers the admin menu item.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$page_hook = add_options_page(
			__( 'AI Experiments', 'ai' ),
			__( 'AI Experiments', 'ai' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		// Hook into the specific page load to enqueue styles.
		if ( ! $page_hook ) {
			return;
		}

		add_action( "load-{$page_hook}", array( $this, 'init_page' ) );
	}

	/**
	 * Handles the page load event for the settings page.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function init_page(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * Enqueues styles for the settings page.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function enqueue_styles(): void {
		// Enqueue settings page styles.
		Asset_Loader::enqueue_style( 'experiments-settings', 'admin/settings' );
	}

	/**
	 * Renders the settings page.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$global_enabled = (bool) get_option( Settings_Registration::GLOBAL_OPTION, false );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php
			// If we don't have proper credentials, show an error message and return early.
			if ( ! has_valid_ai_credentials() ) {
				if ( ! has_ai_credentials() ) {
					$error_message = sprintf(
						/* translators: 1: Link to the AI credentials settings page. */
						__( 'Before you can enable experiments, you need to ensure you have one or more AI credentials set <a href="%s">here</a>', 'ai' ),
						admin_url( 'options-general.php?page=wp-ai-client' )
					);
				} else {
					$error_message = sprintf(
						/* translators: 1: Link to the AI credentials settings page. */
						__( 'Before you can enable experiments, you need to ensure you have set valid AI credentials <a href="%s">here</a>', 'ai' ),
						admin_url( 'options-general.php?page=wp-ai-client' )
					);
				}

				wp_admin_notice( $error_message, array( 'type' => 'error' ) );
				return;
			}
			?>

			<?php settings_errors(); ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( Settings_Registration::OPTION_GROUP );
				?>

				<div class="ai-experiments">
					<!-- Global Toggle Section -->
					<div class="ai-experiments__card ai-experiments__card--global">
						<div class="ai-experiments__card-heading">
							<h2><?php esc_html_e( 'General Settings', 'ai' ); ?></h2>
							<p class="description" id="ai-experiments-global-desc">
								<?php esc_html_e( 'Control whether AI experiments are enabled for your site. When disabled, all experiments will be inactive regardless of their individual settings.', 'ai' ); ?>
							</p>
						</div>

						<div class="ai-experiments__toggle">
							<label class="components-toggle-control" for="<?php echo esc_attr( Settings_Registration::GLOBAL_OPTION ); ?>">
								<input
									type="checkbox"
									name="<?php echo esc_attr( Settings_Registration::GLOBAL_OPTION ); ?>"
									id="<?php echo esc_attr( Settings_Registration::GLOBAL_OPTION ); ?>"
									value="1"
									<?php checked( $global_enabled ); ?>
									aria-describedby="ai-experiments-global-desc"
								/>
								<span class="ai-experiments__toggle-label">
									<?php esc_html_e( 'Enable Experiments', 'ai' ); ?>
								</span>
							</label>
						</div>
					</div>

					<!-- Individual Experiments Section -->
					<?php if ( ! empty( $this->registry->get_all_experiments() ) ) : ?>
						<div class="ai-experiments__card" role="region" aria-labelledby="ai-experiments-list-heading">
							<div class="ai-experiments__card-heading">
								<h2 id="ai-experiments-list-heading"><?php esc_html_e( 'Available Experiments', 'ai' ); ?></h2>
								<p class="description" id="ai-experiments-list-desc">
									<?php esc_html_e( 'Try out the following experiments to see how AI can help your site.', 'ai' ); ?>
								</p>

								<?php if ( ! $global_enabled ) : ?>
									<div class="notice notice-info inline ai-experiments__notice" role="status" aria-live="polite">
										<p id="ai-experiments-disabled-notice"><?php esc_html_e( 'Enable experiments above to configure individual experiment settings.', 'ai' ); ?></p>
									</div>
								<?php endif; ?>
							</div>

							<ul class="ai-experiments__list">
								<?php foreach ( $this->registry->get_all_experiments() as $experiment ) : ?>
									<?php
									$experiment_id      = $experiment->get_id();
									$experiment_option  = "ai_experiment_{$experiment_id}_enabled";
									$experiment_enabled = (bool) get_option( $experiment_option, false );
									$disabled_class     = ! $global_enabled ? 'ai-experiments__item--disabled' : '';
									$desc_id            = "ai-experiment-{$experiment_id}-desc";
									?>
									<li class="ai-experiments__item <?php echo esc_attr( $disabled_class ); ?>">
										<div class="ai-experiments__item-header">
											<label class="components-toggle-control" for="<?php echo esc_attr( $experiment_option ); ?>">
												<input
													type="checkbox"
													name="<?php echo esc_attr( $experiment_option ); ?>"
													id="<?php echo esc_attr( $experiment_option ); ?>"
													value="1"
													<?php checked( $experiment_enabled ); ?>
													<?php disabled( ! $global_enabled ); ?>
													<?php if ( $experiment->get_description() ) : ?>
														aria-describedby="<?php echo esc_attr( $desc_id ); ?>"
													<?php endif; ?>
												/>
												<span>
													<strong><?php echo esc_html( $experiment->get_label() ); ?></strong>
												</span>
											</label>
										</div>
										<?php if ( $experiment->get_description() ) : ?>
											<p class="description" id="<?php echo esc_attr( $desc_id ); ?>">
												<?php
												echo wp_kses(
													$experiment->get_description(),
													array(
														'a'      => array(
															'href'   => array(),
															'title'  => array(),
															'target' => array(),
															'rel'    => array(),
														),
														'b'      => array(),
														'strong' => array(),
														'em'     => array(),
														'i'      => array(),
													)
												);
												?>
											</p>
										<?php endif; ?>
										<?php
										// Allow experiments to render their own custom settings fields.
										if ( method_exists( $experiment, 'render_settings_fields' ) ) {
											$experiment->render_settings_fields();
										}
										?>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>
				</div>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
