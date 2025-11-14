<?php
/**
 * Settings page for AI Experiments.
 *
 * @package WordPress\AI
 *
 * @since 0.1.0
 */

declare(strict_types=1);

namespace WordPress\AI\Admin;

use WordPress\AI\Experiment_Registry;

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
	 * The option name for the global experiments toggle.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const GLOBAL_OPTION = 'ai_experiments_enabled';

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
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * Registers the admin menu item.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_options_page(
			__( 'AI Experiments', 'ai' ),
			__( 'AI Experiments', 'ai' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Registers all settings for experiments.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_settings(): void {
		// Register the global toggle.
		register_setting(
			'ai_experiments',
			self::GLOBAL_OPTION,
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);

		// Register settings for each experiment.
		foreach ( $this->registry->get_all_experiments() as $experiment ) {
			$experiment_id     = $experiment->get_id();
			$experiment_option = "ai_experiment_{$experiment_id}_enabled";

			register_setting(
				'ai_experiments',
				$experiment_option,
				array(
					'type'              => 'boolean',
					'default'           => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
				)
			);

			// Allow experiments to register their own custom settings.
			if ( ! method_exists( $experiment, 'register_settings' ) ) {
				continue;
			}

			$experiment->register_settings();
		}
	}

	/**
	 * Enqueues styles for the settings page.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_styles( string $hook_suffix ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		// Enqueue WordPress components styles for block editor UI.
		wp_enqueue_style( 'wp-components' );

		// Enqueue settings page styles.
		wp_enqueue_style(
			'ai-experiments-settings',
			AI_PLUGIN_URL . 'assets/css/settings-page.css',
			array( 'wp-components' ),
			AI_VERSION
		);
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

		$global_enabled = (bool) get_option( self::GLOBAL_OPTION, false );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'ai_experiments' );
				?>

				<div class="ai-experiments">
					<!-- Global Toggle Section -->
					<div class="ai-experiments__card ai-experiments__card--global">
						<h2><?php esc_html_e( 'General Settings', 'ai' ); ?></h2>
						<p class="description">
							<?php esc_html_e( 'Control whether AI experiments are enabled for your site. When disabled, all experiments will be inactive regardless of their individual settings.', 'ai' ); ?>
						</p>

						<div class="ai-experiments__toggle">
							<label class="components-toggle-control">
								<input
									type="checkbox"
									name="<?php echo esc_attr( self::GLOBAL_OPTION ); ?>"
									id="<?php echo esc_attr( self::GLOBAL_OPTION ); ?>"
									value="1"
									<?php checked( $global_enabled ); ?>
								/>
								<span class="ai-experiments__toggle-label">
									<?php esc_html_e( 'Enable Experiments', 'ai' ); ?>
								</span>
							</label>
						</div>
					</div>

					<!-- Individual Experiments Section -->
					<?php if ( ! empty( $this->registry->get_all_experiments() ) ) : ?>
						<div class="ai-experiments__card">
							<h2><?php esc_html_e( 'Available Experiments', 'ai' ); ?></h2>

							<?php if ( ! $global_enabled ) : ?>
								<div class="notice notice-info inline ai-experiments__notice">
									<p><?php esc_html_e( 'Enable experiments above to configure individual experiment settings.', 'ai' ); ?></p>
								</div>
							<?php endif; ?>

							<div class="ai-experiments__list">
								<?php foreach ( $this->registry->get_all_experiments() as $experiment ) : ?>
									<?php
									$experiment_id      = $experiment->get_id();
									$experiment_option  = "ai_experiment_{$experiment_id}_enabled";
									$experiment_enabled = (bool) get_option( $experiment_option, false );
									$disabled_class     = ! $global_enabled ? 'ai-experiments__item--disabled' : '';
									?>
									<div class="ai-experiments__item <?php echo esc_attr( $disabled_class ); ?>">
										<div class="ai-experiments__item-header">
											<label class="components-toggle-control">
												<input
													type="checkbox"
													name="<?php echo esc_attr( $experiment_option ); ?>"
													id="<?php echo esc_attr( $experiment_option ); ?>"
													value="1"
													<?php checked( $experiment_enabled ); ?>
													<?php disabled( ! $global_enabled ); ?>
												/>
												<span>
													<strong><?php echo esc_html( $experiment->get_label() ); ?></strong>
												</span>
											</label>
										</div>
										<?php if ( $experiment->get_description() ) : ?>
											<p class="description">
												<?php echo esc_html( $experiment->get_description() ); ?>
											</p>
										<?php endif; ?>
										<?php
										// Allow experiments to render their own custom settings fields.
										if ( method_exists( $experiment, 'render_settings_fields' ) ) {
											$experiment->render_settings_fields();
										}
										?>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>
				</div>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
