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
		<div class="wrap ai-experiments-page">
			<div class="ai-admin-header">
				<div class="ai-admin-header__inner">
					<div class="ai-admin-header__left">
						<span class="ai-admin-header__icon">
							<svg width="1em" height="1em" viewBox="0 0 100 100" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
								<path d="M97.8823 47.0151L77.3637 39.9372C69.2494 37.1494 62.8508 30.7507 60.063 22.6363L52.9851 2.11795C52.0201 -0.705983 47.9801 -0.705983 47.0151 2.11795L39.9372 22.6363C37.1494 30.7507 30.7507 37.1494 22.6363 39.9372L2.11795 47.0151C-0.705983 47.9801 -0.705983 52.0201 2.11795 52.9851L22.6363 60.063C30.7507 62.8508 37.1494 69.2494 39.9372 77.3637L47.0151 97.8823C47.9801 100.706 52.0201 100.706 52.9851 97.8823L60.063 77.3637C62.8508 69.2494 69.2494 62.8508 77.3637 60.063L97.8823 52.9851C100.706 52.0201 100.706 47.9801 97.8823 47.0151ZM73.9323 51.5194L63.673 55.058C59.598 56.4523 56.4165 59.6694 55.0222 63.7087L51.4837 73.968C50.983 75.398 48.9815 75.398 48.4808 73.968L44.9422 63.7087C43.5479 59.6337 40.3308 56.4523 36.2915 55.058L26.0322 51.5194C24.6024 51.0187 24.6024 49.0172 26.0322 48.5165L36.2915 44.9779C40.3665 43.5837 43.5479 40.3665 44.9422 36.3272L48.4808 26.068C48.9815 24.6381 50.983 24.6381 51.4837 26.068L55.0222 36.3272C56.4165 40.4022 59.6337 43.5837 63.673 44.9779L73.9323 48.5165C75.3623 49.0172 75.3623 51.0187 73.9323 51.5194Z" />
							</svg>
						</span>
						<div class="ai-admin-header__title">
							<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
						</div>
					</div>
				</div>
			</div>

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

							<div class="ai-experiments__grid">
								<?php foreach ( $this->registry->get_all_experiments() as $experiment ) : ?>
									<?php
									$experiment_id      = $experiment->get_id();
									$experiment_option  = "ai_experiment_{$experiment_id}_enabled";
									$experiment_enabled = (bool) get_option( $experiment_option, false );
									$disabled_class     = ! $global_enabled ? 'ai-experiments__item--disabled' : '';
									$desc_id            = "ai-experiment-{$experiment_id}-desc";
									$settings_id        = "ai-experiment-{$experiment_id}-settings";
									$has_settings       = $experiment->has_settings();
									?>
									<div class="ai-experiments__item <?php echo esc_attr( $disabled_class ); ?>">
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
											<?php if ( $has_settings ) : ?>
												<button
													type="button"
													class="ai-experiments__settings-toggle"
													aria-expanded="false"
													aria-controls="<?php echo esc_attr( $settings_id ); ?>"
													title="<?php esc_attr_e( 'Toggle settings', 'ai' ); ?>"
												>
													<span class="dashicons dashicons-admin-generic"></span>
													<span class="screen-reader-text"><?php esc_html_e( 'Settings', 'ai' ); ?></span>
												</button>
											<?php endif; ?>
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
										<?php if ( $has_settings ) : ?>
											<div
												id="<?php echo esc_attr( $settings_id ); ?>"
												class="ai-experiments__settings-drawer"
												hidden
											>
												<?php $experiment->render_settings_fields(); ?>
											</div>
										<?php endif; ?>
									</div>
								<?php endforeach; ?>
							</div>
							<script>
								( function() {
									document.querySelectorAll( '.ai-experiments__settings-toggle' ).forEach( function( btn ) {
										btn.addEventListener( 'click', function() {
											var expanded = btn.getAttribute( 'aria-expanded' ) === 'true';
											var drawerId = btn.getAttribute( 'aria-controls' );
											var drawer = document.getElementById( drawerId );
											if ( drawer ) {
												btn.setAttribute( 'aria-expanded', String( ! expanded ) );
												if ( expanded ) {
													drawer.setAttribute( 'hidden', '' );
												} else {
													drawer.removeAttribute( 'hidden' );
												}
											}
										} );
									} );
								} )();
							</script>
						</div>
					<?php endif; ?>
				</div>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
