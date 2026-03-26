<?php
/**
 * Settings page for the AI plugin.
 *
 * @package WordPress\AI
 *
 * @since 0.1.0
 */

declare(strict_types=1);

namespace WordPress\AI\Settings;

use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Features\Feature_Category;
use WordPress\AI\Features\Registry;
use WordPress\AI\Permissions\Permissions_Manager;
use function WordPress\AI\get_preferred_models_for_text_generation;
use function WordPress\AI\has_ai_credentials;
use function WordPress\AI\has_valid_ai_credentials;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the admin settings page for the AI plugin.
 *
 * @since 0.1.0
 */
class Settings_Page {

	/**
	 * The experiment registry instance.
	 *
	 * @since 0.1.0
	 *
	 * @var \WordPress\AI\Features\Registry
	 */
	private Registry $registry;

	/**
	 * The settings page slug.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PAGE_SLUG = 'ai';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param \WordPress\AI\Features\Registry $registry The feature registry.
	 */
	public function __construct( Registry $registry ) {
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
			__( 'AI', 'ai' ),
			__( 'AI', 'ai' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			2
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
						/* translators: 1: Link to the Connectors settings page. */
						__( 'The AI plugin requires a valid AI Connector to function properly. Verify you have one or more AI Connectors configured <a href="%s">here</a>.', 'ai' ),
						admin_url( 'options-connectors.php' )
					);
				} else {
					$error_message = sprintf(
						/* translators: 1: Link to the Connectors settings page. */
						__( 'The AI plugin requires a valid AI Connector to function properly. Please <a href="%s">review</a> the AI Connectors you have configured to ensure they are valid.', 'ai' ),
						admin_url( 'options-connectors.php' )
					);
				}

				wp_admin_notice( $error_message, array( 'type' => 'error' ) );
			}
			?>

			<?php settings_errors( 'ai_experiments' ); ?>

			<form method="post" action="options.php" id="ai-experiments-form">
				<?php
				settings_fields( Settings_Registration::OPTION_GROUP );
				?>

				<div class="ai-experiments">
					<!-- Global Toggle Section -->
					<div class="ai-experiments__card ai-experiments__card--global">
						<div class="ai-experiments__card-heading">
							<h2><?php esc_html_e( 'General Settings', 'ai' ); ?></h2>
							<p class="description" id="ai-experiments-global-desc">
								<?php esc_html_e( 'Control whether AI is enabled for your site. When disabled, all features and experiments will be inactive regardless of their individual settings.', 'ai' ); ?>
							</p>
						</div>

						<div class="ai-experiments__toggle">
							<input
								type="hidden"
								name="<?php echo esc_attr( Settings_Registration::GLOBAL_OPTION ); ?>"
								id="ai-experiments-enabled"
								value="<?php echo $global_enabled ? '1' : '0'; ?>"
							/>
							<button
								type="button"
								class="button <?php echo $global_enabled ? 'button-secondary' : 'button-primary'; ?> ai-experiments__toggle-button"
								data-ai-toggle-global="<?php echo $global_enabled ? '0' : '1'; ?>"
								aria-describedby="ai-experiments-global-desc"
							>
								<?php echo $global_enabled ? esc_html__( 'Disable AI', 'ai' ) : esc_html__( 'Enable AI', 'ai' ); ?>
							</button>
						</div>
					</div>

					<?php $this->render_plugin_permissions_section( $global_enabled ); ?>

				<?php
					// Group experiments by category, normalizing unknown categories to OTHER.
					$known_categories        = array( Experiment_Category::EDITOR, Experiment_Category::ADMIN );
					$experiments_by_category = array();
				foreach ( $this->registry->get_all_features() as $experiment ) {
					$category                               = in_array( $experiment->get_category(), $known_categories, true )
						? $experiment->get_category()
						: Feature_Category::OTHER;
					$experiments_by_category[ $category ][] = $experiment;
				}

					$this->render_experiments_section(
						'ai-experiments-editor-heading',
						__( 'Editor Experiments', 'ai' ),
						__( 'AI-powered experiments for the block editor, including content generation and enhancement tools.', 'ai' ),
						$experiments_by_category[ Experiment_Category::EDITOR ] ?? array(),
						$global_enabled
					);

					$this->render_experiments_section(
						'ai-experiments-admin-heading',
						__( 'Admin Experiments', 'ai' ),
						__( 'AI-powered experiments for the WordPress admin area, including exploration and testing tools.', 'ai' ),
						$experiments_by_category[ Experiment_Category::ADMIN ] ?? array(),
						$global_enabled
					);

					$this->render_experiments_section(
						'ai-experiments-other-heading',
						__( 'Other Experiments', 'ai' ),
						__( 'Additional experiments that do not fit into a specific category.', 'ai' ),
						$experiments_by_category[ Feature_Category::OTHER ] ?? array(),
						$global_enabled
					);
				?>
				</div>

				<?php submit_button(); ?>
			</form>

			<script>
				(function() {
					const form = document.getElementById('ai-experiments-form');
					const toggleButton = form ? form.querySelector('[data-ai-toggle-global]') : null;
					const globalInput = document.getElementById('ai-experiments-enabled');

					if (!form || !toggleButton || !globalInput) {
						return;
					}

					toggleButton.addEventListener('click', function() {
						globalInput.value = this.dataset.aiToggleGlobal || '';

						if (form.requestSubmit) {
							form.requestSubmit();
							return;
						}

						form.submit();
					});
				})();
			</script>
		</div>
		<?php
	}

	/**
	 * Renders the plugin permissions section.
	 *
	 * Displays a card allowing site admins to grant or revoke AI provider access
	 * for each third-party plugin that has registered via `wpai_register_plugins`.
	 * Also allows configuring per-plugin provider routing preferences.
	 *
	 * The section is hidden if no plugins have registered themselves.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $global_enabled Whether the global AI toggle is currently on.
	 * @return void
	 */
	private function render_plugin_permissions_section( bool $global_enabled ): void {
		$permissions_manager = Permissions_Manager::get_instance();
		$plugins             = $permissions_manager->get_plugin_registry()->get_all_plugins();

		if ( empty( $plugins ) ) {
			return;
		}

		// Build the list of available provider slugs from the global preferences.
		$known_providers = array_unique(
			array_column( get_preferred_models_for_text_generation(), 0 )
		);
		?>

		<div class="ai-experiments__card" role="region" aria-labelledby="ai-plugin-permissions-heading">
			<div class="ai-experiments__card-heading">
				<h2 id="ai-plugin-permissions-heading"><?php esc_html_e( 'Plugin Permissions', 'ai' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Control which plugins are allowed to use connected AI providers. Only plugins that have declared their intent to use AI appear here.', 'ai' ); ?>
				</p>

				<?php if ( ! $global_enabled ) : ?>
					<div class="notice notice-info inline ai-experiments__notice" role="status" aria-live="polite">
						<p><?php esc_html_e( 'Enable AI above to configure plugin permissions.', 'ai' ); ?></p>
					</div>
				<?php endif; ?>
			</div>

			<ul class="ai-experiments__list">
				<?php foreach ( $plugins as $plugin ) : ?>
					<?php
					$plugin_key       = $permissions_manager->sanitize_option_key( $plugin['id'] );
					$access_option    = Permissions_Manager::PLUGIN_ACCESS_OPTION_PREFIX . $plugin_key;
					$providers_option = Permissions_Manager::PLUGIN_PROVIDER_OPTION_PREFIX . $plugin_key;
					$plugin_access    = (bool) get_option( $access_option, false );
					$plugin_providers = (string) get_option( $providers_option, '' );
					$disabled_class   = ! $global_enabled ? 'ai-experiments__item--disabled' : '';
					$desc_id          = 'ai-plugin-' . esc_attr( $plugin_key ) . '-desc';
					$providers_id     = 'ai-plugin-' . esc_attr( $plugin_key ) . '-providers';
					?>

					<li class="ai-experiments__item <?php echo esc_attr( $disabled_class ); ?>">
						<div class="ai-experiments__item-header">
							<label class="components-toggle-control" for="<?php echo esc_attr( $access_option ); ?>">
								<input
									type="checkbox"
									name="<?php echo esc_attr( $access_option ); ?>"
									id="<?php echo esc_attr( $access_option ); ?>"
									value="1"
									<?php checked( $plugin_access ); ?>
									<?php disabled( ! $global_enabled ); ?>
									<?php if ( ! empty( $plugin['description'] ) ) : ?>
										aria-describedby="<?php echo esc_attr( $desc_id ); ?>"
									<?php endif; ?>
								/>
								<span>
									<strong><?php echo esc_html( $plugin['name'] ); ?></strong>
								</span>
							</label>
						</div>

						<?php if ( ! empty( $plugin['description'] ) ) : ?>
							<p class="description" id="<?php echo esc_attr( $desc_id ); ?>">
								<?php echo esc_html( $plugin['description'] ); ?>
							</p>
						<?php endif; ?>

						<div class="ai-plugin-provider-routing">
							<label for="<?php echo esc_attr( $providers_id ); ?>">
								<?php esc_html_e( 'Limit to providers (optional)', 'ai' ); ?>
							</label>
							<input
								type="text"
								name="<?php echo esc_attr( $providers_option ); ?>"
								id="<?php echo esc_attr( $providers_id ); ?>"
								value="<?php echo esc_attr( $plugin_providers ); ?>"
								placeholder="<?php echo esc_attr( implode( ', ', $known_providers ) ); ?>"
								<?php disabled( ! $global_enabled ); ?>
								class="regular-text"
							/>
							<p class="description">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: comma-separated list of available provider slugs. */
										__( 'Comma-separated list of provider slugs this plugin may use (e.g. %s). Leave empty to allow all configured providers.', 'ai' ),
										implode( ', ', $known_providers )
									)
								);
								?>
							</p>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>

		<?php
	}

	/**
	 * Renders a section card containing a list of experiments.
	 *
	 * @since 0.4.0
	 *
	 * @param string $heading_id HTML ID for the heading element.
	 * @param string $heading_text Translated section heading.
	 * @param string $description Translated section description.
	 * @param list<\WordPress\AI\Contracts\Feature> $experiments Experiments to render.
	 * @param bool $global_enabled Whether the global toggle is on.
	 */
	private function render_experiments_section(
		string $heading_id,
		string $heading_text,
		string $description,
		array $experiments,
		bool $global_enabled
	): void {
		if ( empty( $experiments ) ) {
			return;
		}
		?>

		<div class="ai-experiments__card" role="region" aria-labelledby="<?php echo esc_attr( $heading_id ); ?>">
			<div class="ai-experiments__card-heading">
				<h2 id="<?php echo esc_attr( $heading_id ); ?>"><?php echo esc_html( $heading_text ); ?></h2>
				<p class="description">
					<?php echo esc_html( $description ); ?>
				</p>

				<?php if ( ! $global_enabled ) : ?>
					<div class="notice notice-info inline ai-experiments__notice" role="status" aria-live="polite">
						<p><?php esc_html_e( 'Enable AI above to configure individual experiment settings.', 'ai' ); ?></p>
					</div>
				<?php endif; ?>
			</div>

			<ul class="ai-experiments__list">
				<?php foreach ( $experiments as $experiment ) : ?>
					<?php
					$experiment_id      = $experiment::get_id();
					$experiment_option  = "wpai_feature_{$experiment_id}_enabled";
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

		<?php
	}
}
