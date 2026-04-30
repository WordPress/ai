<?php
/**
 * AI Capabilities dashboard widget.
 *
 * Displays Ability statistics mirroring the Abilities Explorer summary,
 * plus available capabilities per connected AI provider.
 *
 * @package WordPress\AI\Admin\Dashboard
 *
 * @since 0.8.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Admin\Dashboard;

use WordPress\AI\Experiments\Abilities_Explorer\Ability_Handler;
use WordPress\AI\Features\Registry;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the AI Capabilities dashboard widget.
 *
 * @since 0.8.0
 */
class AI_Capabilities_Widget {

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
	 * Shows Ability statistics and per-provider capabilities.
	 *
	 * @since 0.8.0
	 */
	public function render(): void {
		?>
		<div class="ai-dashboard-capabilities">
			<?php $this->render_abilities_summary(); ?>
			<?php $this->render_provider_capabilities(); ?>
		</div>
		<?php
	}

	/**
	 * Renders the Abilities summary with stat cards.
	 *
	 * @since 0.8.0
	 */
	private function render_abilities_summary(): void {
		if ( ! class_exists( Ability_Handler::class ) ) {
			return;
		}

		$stats        = Ability_Handler::get_statistics();
		$total        = (int) $stats['total'];
		$by_provider  = (array) $stats['by_provider'];
		$core_count   = (int) ( $by_provider['Core'] ?? 0 );
		$plugin_count = (int) ( $by_provider['Plugin'] ?? 0 );
		$theme_count  = (int) ( $by_provider['Theme'] ?? 0 );
		?>

		<div class="ai-dashboard-capabilities__stats">
			<div class="ai-dashboard-capabilities__stat-card">
				<span class="ai-dashboard-capabilities__stat-value"><?php echo (int) $total; ?></span>
				<span class="ai-dashboard-capabilities__stat-label"><?php esc_html_e( 'Total Abilities', 'ai' ); ?></span>
			</div>
			<div class="ai-dashboard-capabilities__stat-card">
				<span class="ai-dashboard-capabilities__stat-value"><?php echo (int) $core_count; ?></span>
				<span class="ai-dashboard-capabilities__stat-label"><?php esc_html_e( 'Core', 'ai' ); ?></span>
			</div>
			<div class="ai-dashboard-capabilities__stat-card">
				<span class="ai-dashboard-capabilities__stat-value"><?php echo (int) $plugin_count; ?></span>
				<span class="ai-dashboard-capabilities__stat-label"><?php esc_html_e( 'Plugins', 'ai' ); ?></span>
			</div>
			<div class="ai-dashboard-capabilities__stat-card">
				<span class="ai-dashboard-capabilities__stat-value"><?php echo (int) $theme_count; ?></span>
				<span class="ai-dashboard-capabilities__stat-label"><?php esc_html_e( 'Theme', 'ai' ); ?></span>
			</div>
		</div>

		<?php
		$feature = $this->registry->get_feature( 'abilities-explorer' );
		if ( $feature && $feature->is_enabled() ) :
			?>
			<p class="ai-dashboard-capabilities__links">
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=ai-abilities-explorer' ) ); ?>">
					<?php esc_html_e( 'Abilities Explorer', 'ai' ); ?>
				</a>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders capabilities grouped by AI provider.
	 *
	 * Uses the PHP AI Client's ProviderRegistry and ModelMetadataDirectory
	 * to discover what each registered provider is capable of.
	 *
	 * @since 0.8.0
	 */
	private function render_provider_capabilities(): void {
		if ( ! class_exists( AiClient::class ) ) {
			return;
		}

		$registry     = AiClient::defaultRegistry();
		$provider_ids = $registry->getRegisteredProviderIds();

		if ( empty( $provider_ids ) ) {
			return;
		}

		?>
		<h4 class="ai-dashboard-capabilities__section-title">
			<?php esc_html_e( 'Provider Capabilities', 'ai' ); ?>
		</h4>
		<div class="ai-dashboard-capabilities__providers">
			<?php
			foreach ( $provider_ids as $provider_id ) {
				try {
					$provider_class = $registry->getProviderClassName( $provider_id );

					/** @var \WordPress\AiClient\Providers\Contracts\ProviderInterface $provider_class */
					$metadata     = $provider_class::metadata();
					$model_dir    = $provider_class::modelMetadataDirectory();
					$models       = $model_dir->listModelMetadata();
					$capabilities = array();

					foreach ( $models as $model ) {
						foreach ( $model->getSupportedCapabilities() as $capability ) {
							$capabilities[ $capability->value ] = true;
						}
					}

					if ( empty( $capabilities ) ) {
						continue;
					}

					?>
					<div class="ai-dashboard-capabilities__provider">
						<span class="ai-dashboard-capabilities__provider-name">
							<?php echo esc_html( $metadata->getName() ); ?>
						</span>
						<span class="ai-dashboard-capabilities__provider-caps">
							<?php foreach ( $capabilities as $cap_value => $unused ) : ?>
								<span class="ai-dashboard-capabilities__cap-tag">
									<?php echo esc_html( $this->get_capability_label( (string) $cap_value ) ); ?>
								</span>
							<?php endforeach; ?>
						</span>
					</div>
					<?php
				} catch ( \Throwable $e ) {
					continue;
				}
			}
			?>
		</div>
		<?php
	}

	/**
	 * Returns a human-readable label for a capability enum value.
	 *
	 * @since 0.8.0
	 *
	 * @param string $capability The capability enum value.
	 * @return string The human-readable label.
	 */
	private function get_capability_label( string $capability ): string {
		$labels = array(
			CapabilityEnum::TEXT_GENERATION           => __( 'Text Generation', 'ai' ),
			CapabilityEnum::IMAGE_GENERATION          => __( 'Image Generation', 'ai' ),
			CapabilityEnum::TEXT_TO_SPEECH_CONVERSION => __( 'Text to Speech', 'ai' ),
			CapabilityEnum::SPEECH_GENERATION         => __( 'Speech Generation', 'ai' ),
			CapabilityEnum::MUSIC_GENERATION          => __( 'Music Generation', 'ai' ),
			CapabilityEnum::VIDEO_GENERATION          => __( 'Video Generation', 'ai' ),
			CapabilityEnum::EMBEDDING_GENERATION      => __( 'Embedding Generation', 'ai' ),
			CapabilityEnum::CHAT_HISTORY              => __( 'Chat History', 'ai' ),
		);

		return $labels[ $capability ] ?? $capability;
	}
}
