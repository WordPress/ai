<?php
/**
 * Extended Providers experiment implementation.
 *
 * @package WordPress\AI\Experiments\Extended_Providers
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Extended_Providers;

use WordPress\AI\Abstracts\Abstract_Experiment;
use WordPress\AiClient\AiClient;

use function __;
use function _doing_it_wrong;
use function admin_url;
use function apply_filters;
use function class_exists;
use function esc_html;
use function is_string;

/**
 * Registers additional AI providers with the WP AI Client registry.
 *
 * This experiment does not ship providers itself—instead it exposes filters
 * so contributors can list provider classes that should only load when the
 * experiment is enabled.
 */
class Extended_Providers extends Abstract_Experiment {
	private const DEFAULT_PROVIDER_CLASSES = array();

	/**
	 * {@inheritDoc}
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'extended-providers',
			'label'       => __( 'Extended Providers', 'ai' ),
			'description' => __( 'Registers additional AI providers for experimentation without affecting the core set.', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_providers' ), 20 );
	}

	/**
	 * Registers any provider classes supplied via filters.
	 */
	public function register_providers(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		if ( ! class_exists( AiClient::class ) ) {
			return;
		}

		$provider_classes = $this->get_provider_classes();

		if ( empty( $provider_classes ) ) {
			return;
		}

		$registry = AiClient::defaultRegistry();

		foreach ( $provider_classes as $class_name ) {
			if ( ! is_string( $class_name ) || '' === $class_name ) {
				continue;
			}

			if ( ! class_exists( $class_name ) ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: %s: provider class name. */
						__( 'Extended Providers experiment could not load "%s". Make sure the class is autoloadable.', 'ai' ),
						esc_html( $class_name )
					),
					'0.1.0'
				);
				continue;
			}

			if ( $registry->hasProvider( $class_name ) ) {
				continue;
			}

			try {
				$registry->registerProvider( $class_name );
			} catch ( \Throwable $t ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: 1: provider class, 2: error message. */
						__( 'Failed to register provider "%1$s": %2$s', 'ai' ),
						esc_html( $class_name ),
						esc_html( $t->getMessage() )
					),
					'0.1.0'
				);
			}
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_entry_points(): array {
		return array(
			array(
				'label' => __( 'Credentials', 'ai' ),
				'url'   => admin_url( 'options-general.php?page=wp-ai-client' ),
				'type'  => 'dashboard',
			),
		);
	}

	/**
	 * Returns the provider class list after filters have been applied.
	 *
	 * @return array<int, string>
	 */
	private function get_provider_classes(): array {
		$defaults = apply_filters( 'ai_extended_provider_default_classes', self::DEFAULT_PROVIDER_CLASSES );

		/**
		 * Filters the provider class list registered by the Extended Providers experiment.
		 *
		 * @since 0.1.0
		 *
		 * @param array<int, string>                    $classes    Provider class names.
		 * @param \WordPress\AI\Abstracts\Abstract_Experiment $experiment Experiment instance.
		 */
		$providers = apply_filters( 'ai_extended_provider_classes', (array) $defaults, $this );

		return array_values(
			array_filter(
				array_map(
					static function ( $class ) {
						return is_string( $class ) ? trim( $class ) : '';
					},
					(array) $providers
				)
			)
		);
	}
}
