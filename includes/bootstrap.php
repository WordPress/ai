<?php
/**
 * Bootstrap file for the AI Experiments plugin.
 *
 * Handles plugin initialization, version checks, and feature loading.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI;

use WordPress\AI\Abilities\Utilities\Posts;
use WordPress\AI\Settings\Settings_Page;
use WordPress\AI\Settings\Settings_Registration;
use WordPress\AI_Client\AI_Client;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
if ( ! defined( 'AI_EXPERIMENTS_VERSION' ) ) {
	define( 'AI_EXPERIMENTS_VERSION', '0.3.1' );
}
if ( ! defined( 'AI_EXPERIMENTS_PLUGIN_FILE' ) ) {
	define( 'AI_EXPERIMENTS_PLUGIN_FILE', defined( 'AI_EXPERIMENTS_DIR' ) ? AI_EXPERIMENTS_DIR . 'ai.php' : '' );
}
if ( ! defined( 'AI_EXPERIMENTS_PLUGIN_DIR' ) ) {
	define( 'AI_EXPERIMENTS_PLUGIN_DIR', defined( 'AI_EXPERIMENTS_DIR' ) ? AI_EXPERIMENTS_DIR : '' );
}
if ( ! defined( 'AI_EXPERIMENTS_PLUGIN_URL' ) ) {
	define( 'AI_EXPERIMENTS_PLUGIN_URL', plugin_dir_url( AI_EXPERIMENTS_PLUGIN_FILE ) );
}
if ( ! defined( 'AI_EXPERIMENTS_MIN_PHP_VERSION' ) ) {
	define( 'AI_EXPERIMENTS_MIN_PHP_VERSION', '7.4' );
}
if ( ! defined( 'AI_EXPERIMENTS_MIN_WP_VERSION' ) ) {
	define( 'AI_EXPERIMENTS_MIN_WP_VERSION', '6.9' );
}
if ( ! defined( 'AI_EXPERIMENTS_DEFAULT_ABILITY_CATEGORY' ) ) {
	define( 'AI_EXPERIMENTS_DEFAULT_ABILITY_CATEGORY', 'ai-experiments' );
}

/**
 * Displays an admin notice for version requirement failures.
 *
 * @since 0.1.0
 *
 * @param string $message The error message to display.
 */
function version_notice( string $message ): void {
	if ( ! is_admin() ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p><?php echo esc_html( $message ); ?></p>
	</div>
	<?php
}

/**
 * Checks if the PHP version meets the minimum requirement.
 *
 * @since 0.1.0
 *
 * @return bool True if PHP version is sufficient, false otherwise.
 */
function check_php_version(): bool {
	if ( version_compare( phpversion(), AI_EXPERIMENTS_MIN_PHP_VERSION, '<' ) ) {
		add_action(
			'admin_notices',
			static function () {
				version_notice(
					sprintf(
						/* translators: 1: Required PHP version, 2: Current PHP version */
						__( 'AI Experiments plugin requires PHP version %1$s or higher. You are running PHP version %2$s.', 'ai' ),
						AI_EXPERIMENTS_MIN_PHP_VERSION,
						PHP_VERSION
					)
				);
			}
		);
		return false;
	}
	return true;
}

/**
 * Checks if the WordPress version meets the minimum requirement.
 *
 * @since 0.1.0
 *
 * @global string $wp_version WordPress version.
 *
 * @return bool True if WordPress version is sufficient, false otherwise.
 */
function check_wp_version(): bool {
	if ( ! is_wp_version_compatible( AI_EXPERIMENTS_MIN_WP_VERSION ) ) {
		add_action(
			'admin_notices',
			static function () {
				global $wp_version;
				version_notice(
					sprintf(
						/* translators: 1: Required WordPress version, 2: Current WordPress version */
						__( 'AI Experiments plugin requires WordPress version %1$s or higher. You are running WordPress version %2$s.', 'ai' ),
						AI_EXPERIMENTS_MIN_WP_VERSION,
						$wp_version
					)
				);
			}
		);
		return false;
	}
	return true;
}

/**
 * Displays admin notice about missing Composer autoload files.
 *
 * @since 0.1.0
 */
function display_composer_notice(): void {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: %s: composer install command */
				esc_html__( 'Your installation of the AI Experiments plugin is incomplete. Please run %s.', 'ai' ),
				'<code>composer install</code>'
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Checks whether WordPress core already provides the AI Client API.
 *
 * @since 0.1.0
 *
 * @return bool True if core provides the AI Client API, otherwise false.
 */
function has_core_wp_ai_client(): bool {
	return function_exists( 'wp_ai_client_prompt' );
}

/**
 * Checks whether the plugin should initialize its bundled WP AI Client package.
 *
 * Defaults to false when WordPress core already provides the AI Client API, and
 * true otherwise. Supports an explicit constant override for fast local testing.
 *
 * @since 0.1.0
 *
 * @return bool True if the bundled package should be used, otherwise false.
 */
function should_use_bundled_wp_ai_client(): bool {
	if ( defined( 'AI_DISABLE_BUNDLED_WP_AI_CLIENT' ) && AI_DISABLE_BUNDLED_WP_AI_CLIENT ) {
		return false;
	}

	/**
	 * Filters whether to initialize the bundled WP AI Client package.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $use_bundled_wp_ai_client Whether to use the bundled WP AI Client package.
	 * @return bool True to use the bundled package, false to rely on core.
	 */
	return (bool) apply_filters( 'ai_experiments_use_bundled_wp_ai_client', ! has_core_wp_ai_client() );
}

/**
 * Removes bundled WP AI client package paths from the Jetpack autoloader.
 *
 * This allows relying on core-bundled AI client classes when available while
 * keeping the rest of the plugin dependencies managed by Jetpack autoloader.
 *
 * @since 0.1.0
 *
 * @return void
 */
function maybe_disable_bundled_wp_ai_client_packages(): void {
	if ( should_use_bundled_wp_ai_client() ) {
		return;
	}

	global $jetpack_autoloader_loader;

	if ( ! is_object( $jetpack_autoloader_loader ) ) {
		return;
	}

	$bundled_roots = array(
		wp_normalize_path( AI_EXPERIMENTS_PLUGIN_DIR . 'vendor/wordpress/wp-ai-client/' ),
		wp_normalize_path( AI_EXPERIMENTS_PLUGIN_DIR . 'vendor/wordpress/php-ai-client/' ),
	);

	$path_uses_bundled_package = static function ( $path ) use ( $bundled_roots ): bool {
		if ( ! is_string( $path ) ) {
			return false;
		}

		$path = wp_normalize_path( $path );
		foreach ( $bundled_roots as $root ) {
			if ( 0 === strpos( $path, $root ) ) {
				return true;
			}
		}

		return false;
	};

	try {
		$reflection = new \ReflectionObject( $jetpack_autoloader_loader );

		if ( $reflection->hasProperty( 'psr4_map' ) ) {
			$psr4_property = $reflection->getProperty( 'psr4_map' );
			$psr4_property->setAccessible( true );
			$psr4_map = $psr4_property->getValue( $jetpack_autoloader_loader );

			if ( is_array( $psr4_map ) ) {
				foreach ( $psr4_map as $prefix => $data ) {
					if ( ! is_array( $data ) || ! isset( $data['path'] ) || ! is_array( $data['path'] ) ) {
						continue;
					}

					$data['path'] = array_values(
						array_filter(
							$data['path'],
							static function ( $path ) use ( $path_uses_bundled_package ): bool {
								return ! $path_uses_bundled_package( $path );
							}
						)
					);

					if ( empty( $data['path'] ) ) {
						unset( $psr4_map[ $prefix ] );
						continue;
					}

					$psr4_map[ $prefix ] = $data;
				}

				$psr4_property->setValue( $jetpack_autoloader_loader, $psr4_map );
			}
		}

		if ( $reflection->hasProperty( 'classmap' ) ) {
			$classmap_property = $reflection->getProperty( 'classmap' );
			$classmap_property->setAccessible( true );
			$classmap = $classmap_property->getValue( $jetpack_autoloader_loader );

			if ( is_array( $classmap ) ) {
				foreach ( $classmap as $class => $data ) {
					if ( ! is_array( $data ) || ! isset( $data['path'] ) ) {
						continue;
					}

					if ( ! $path_uses_bundled_package( $data['path'] ) ) {
						continue;
					}

					unset( $classmap[ $class ] );
				}

				$classmap_property->setValue( $jetpack_autoloader_loader, $classmap );
			}
		}

		if ( $reflection->hasProperty( 'filemap' ) ) {
			$filemap_property = $reflection->getProperty( 'filemap' );
			$filemap_property->setAccessible( true );
			$filemap = $filemap_property->getValue( $jetpack_autoloader_loader );

			if ( is_array( $filemap ) ) {
				foreach ( $filemap as $identifier => $data ) {
					if ( ! is_array( $data ) || ! isset( $data['path'] ) ) {
						continue;
					}

					if ( ! $path_uses_bundled_package( $data['path'] ) ) {
						continue;
					}

					unset( $filemap[ $identifier ] );
				}

				$filemap_property->setValue( $jetpack_autoloader_loader, $filemap );
			}
		}
	} catch ( \Throwable $t ) {
		_doing_it_wrong(
			__NAMESPACE__ . '\maybe_disable_bundled_wp_ai_client_packages',
			sprintf(
				/* translators: %s: Error message. */
				esc_html__( 'Could not adjust bundled WP AI Client autoloading: %s', 'ai' ),
				esc_html( $t->getMessage() )
			),
			'0.1.0'
		);
	}
}

/**
 * Registers available provider implementation classes with the default AI registry.
 *
 * This ensures provider classes are registered even when core ships the client API
 * but does not automatically register built-in providers.
 *
 * @since 0.3.1
 *
 * @return void
 */
function maybe_register_available_ai_client_providers(): void {
	if ( ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
		return;
	}

	try {
		$registry = \WordPress\AiClient\AiClient::defaultRegistry();
	} catch ( \Throwable $t ) {
		return;
	}

	$provider_candidates = array(
		'anthropic' => array(
			'\WordPress\AnthropicAiProvider\Provider\AnthropicProvider',
			'\WordPress\AiClient\ProviderImplementations\Anthropic\AnthropicProvider',
		),
		'google'    => array(
			'\WordPress\GoogleAiProvider\Provider\GoogleProvider',
			'\WordPress\AiClient\ProviderImplementations\Google\GoogleProvider',
		),
		'openai'    => array(
			'\WordPress\OpenAiAiProvider\Provider\OpenAiProvider',
			'\WordPress\AiClient\ProviderImplementations\OpenAi\OpenAiProvider',
		),
	);

	/**
	 * Filters provider implementation class name candidates by provider ID.
	 *
	 * @since 0.3.1
	 *
	 * @param array<string, array<int, string>|string> $provider_candidates Provider class candidates keyed by provider ID.
	 * @return array<string, array<int, string>|string> Filtered provider class candidates.
	 */
	$provider_candidates = (array) apply_filters( 'ai_experiments_ai_client_provider_classes', $provider_candidates );

	foreach ( $provider_candidates as $provider_id => $candidate_classes ) {
		try {
			if ( is_string( $provider_id ) && '' !== $provider_id && $registry->hasProvider( $provider_id ) ) {
				continue;
			}
		} catch ( \Throwable $t ) {
			continue;
		}

		if ( is_string( $candidate_classes ) && '' !== $candidate_classes ) {
			$candidate_classes = array( $candidate_classes );
		}

		if ( ! is_array( $candidate_classes ) ) {
			continue;
		}

		$provider_class = '';
		foreach ( $candidate_classes as $candidate_class_raw ) {
			$candidate_class = trim( $candidate_class_raw );
			if ( '' === $candidate_class ) {
				continue;
			}

			if ( ! class_exists( $candidate_class ) ) {
				continue;
			}

			$provider_class = $candidate_class;
			break;
		}

		if ( '' === $provider_class ) {
			continue;
		}

		if ( ! is_subclass_of( $provider_class, '\WordPress\AiClient\Providers\Contracts\ProviderInterface' ) ) {
			continue;
		}

		try {
			if ( $registry->hasProvider( $provider_class ) ) {
				continue;
			}

			$registry->registerProvider( $provider_class );
		} catch ( \Throwable $t ) {
			_doing_it_wrong(
				__NAMESPACE__ . '\maybe_register_available_ai_client_providers',
				sprintf(
					/* translators: 1: Provider class name. 2: Error message. */
					esc_html__( 'Could not register AI provider class %1$s: %2$s', 'ai' ),
					esc_html( $provider_class ),
					esc_html( $t->getMessage() )
				),
				'0.3.1'
			);
		}
	}
}

/**
 * Applies legacy option-based credentials to core AI providers when needed.
 *
 * WordPress 6.9 with bundled WP AI Client stores credentials in the
 * `wp_ai_client_provider_credentials` option. WordPress 7.0 core currently
 * resolves provider credentials from environment variables/constants by default.
 * This bridge preserves existing installs by re-applying saved option values.
 *
 * @since 0.3.1
 *
 * @return void
 */
function maybe_apply_option_credentials_to_core_ai_client(): void {
	if ( should_use_bundled_wp_ai_client() ) {
		return;
	}

	/**
	 * Filters whether to apply option-based credentials to the core AI client.
	 *
	 * @since 0.3.1
	 *
	 * @param bool $should_apply True to apply option credentials to core providers.
	 * @return bool Whether to apply option-based credentials.
	 */
	$should_apply = apply_filters( 'ai_experiments_apply_option_credentials_to_core_ai_client', true );
	if ( ! $should_apply ) {
		return;
	}

	if (
		! class_exists( '\WordPress\AiClient\AiClient' ) ||
		! class_exists( '\WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication' ) ||
		! interface_exists( '\WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface' )
	) {
		return;
	}

	$credentials = get_option( 'wp_ai_client_provider_credentials', array() );
	if ( ! is_array( $credentials ) || empty( $credentials ) ) {
		return;
	}

	try {
		$registry = \WordPress\AiClient\AiClient::defaultRegistry();

		foreach ( $credentials as $provider_id => $api_key ) {
			if ( ! is_string( $provider_id ) || '' === $provider_id ) {
				continue;
			}

			if ( ! is_string( $api_key ) || '' === trim( $api_key ) ) {
				continue;
			}

			if ( ! $registry->hasProvider( $provider_id ) ) {
				continue;
			}

			$authentication_class = '\WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication';

			try {
				$provider_class = $registry->getProviderClassName( $provider_id );
				if (
					class_exists( $provider_class ) &&
					method_exists( $provider_class, 'metadata' )
				) {
					$authentication_method = $provider_class::metadata()->getAuthenticationMethod();
					if ( null !== $authentication_method ) {
						$candidate_authentication_class = $authentication_method->getImplementationClass();
						if (
							is_string( $candidate_authentication_class ) &&
							class_exists( $candidate_authentication_class ) &&
							is_subclass_of( $candidate_authentication_class, '\WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface' )
						) {
							$authentication_class = $candidate_authentication_class;
						}
					}
				}
			} catch ( \Throwable $t ) {
				$authentication_class = '\WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication';
			}

				$api_key                 = trim( $api_key );
				$authentication_instance = null;

			if ( is_subclass_of( $authentication_class, '\WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication' ) ) {
				$authentication_instance = $authentication_class::fromArray(
					array(
						'apiKey' => $api_key,
					)
				);
			}

			if ( ! $authentication_instance instanceof \WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface ) {
				$authentication_instance = new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication( $api_key );
			}

			$registry->setProviderRequestAuthentication( $provider_id, $authentication_instance );
		}
	} catch ( \Throwable $t ) {
		_doing_it_wrong(
			__NAMESPACE__ . '\maybe_apply_option_credentials_to_core_ai_client',
			sprintf(
				/* translators: %s: Error message. */
				esc_html__( 'Could not apply saved AI credentials to core providers: %s', 'ai' ),
				esc_html( $t->getMessage() )
			),
			'0.3.1'
		);
	}
}

/**
 * Returns the AI credentials settings URL if available.
 *
 * @since 0.3.1
 *
 * @return string|null The URL to the credentials settings screen, or null.
 */
function get_ai_credentials_settings_url(): ?string {
	/**
	 * Filters the AI credentials settings URL.
	 *
	 * @since 0.3.1
	 *
	 * @param string|null $url URL to the credentials settings screen, or null if unavailable.
	 * @return string|null The URL to use, or null if unavailable.
	 */
	$url = apply_filters( 'ai_experiments_credentials_settings_url', null );
	if ( is_string( $url ) && '' !== $url ) {
		return $url;
	}

	$settings_slug = 'wp-ai-client';

	// If any plugin or core registered the AI credentials screen, use it.
	global $_parent_pages, $submenu;
	if (
		(
			is_array( $_parent_pages ) &&
			isset( $_parent_pages[ $settings_slug ] )
		) ||
		(
			is_array( $submenu ) &&
			isset( $submenu['options-general.php'] ) &&
			is_array( $submenu['options-general.php'] ) &&
			in_array(
				$settings_slug,
				array_map(
					static function ( $submenu_item ): string {
						return isset( $submenu_item[2] ) && is_string( $submenu_item[2] ) ? $submenu_item[2] : '';
					},
					$submenu['options-general.php']
				),
				true
			)
		)
	) {
		return admin_url( 'options-general.php?page=' . $settings_slug );
	}

	// The bundled package registers this settings page slug.
	if ( should_use_bundled_wp_ai_client() ) {
		return admin_url( 'options-general.php?page=' . $settings_slug );
	}

	return null;
}

/**
 * Adds action links to the plugin list table.
 *
 * This adds "Experiments" and (when available) "Credentials" links to
 * the plugin's action links on the Plugins page.
 *
 * @since 0.1.1
 *
 * @param array<string> $links Existing action links.
 * @return array<string> Modified action links.
 */
function plugin_action_links( array $links ): array {
	$experiments_link = sprintf(
		'<a href="%1$s">%2$s</a>',
		admin_url( 'options-general.php?page=ai-experiments' ),
		esc_html__( 'Experiments', 'ai' )
	);
	array_unshift( $links, $experiments_link );

	$credentials_url = get_ai_credentials_settings_url();
	if ( is_string( $credentials_url ) && '' !== $credentials_url ) {
		$credentials_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $credentials_url ),
			esc_html__( 'Credentials', 'ai' )
		);
		array_unshift( $links, $credentials_link );
	}

	return $links;
}

/**
 * Loads the plugin after checking requirements.
 *
 * @since 0.1.0
 */
function load(): void {
	static $loaded = false;

	// Prevent loading twice.
	if ( $loaded ) {
		return;
	}

	// Check version requirements.
	if ( ! check_php_version() || ! check_wp_version() ) {
		return;
	}

	// Load the Jetpack autoloader.
	if ( ! file_exists( AI_EXPERIMENTS_PLUGIN_DIR . 'vendor/autoload_packages.php' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\display_composer_notice' );
		return;
	}
	require_once AI_EXPERIMENTS_PLUGIN_DIR . 'vendor/autoload_packages.php';
	maybe_disable_bundled_wp_ai_client_packages();

	$loaded = true;

	// Add plugin action links.
	add_filter( 'plugin_action_links_' . plugin_basename( AI_EXPERIMENTS_PLUGIN_FILE ), __NAMESPACE__ . '\plugin_action_links' );

	// Hook experiment initialization to init.
	add_action( 'init', __NAMESPACE__ . '\initialize_experiments' );
}

/**
 * Initializes plugin experiments.
 *
 * @since 0.1.0
 */
function initialize_experiments(): void {
	try {
		// Ensure default providers are registered across core and bundled client combinations.
		maybe_register_available_ai_client_providers();

		// Initialize bundled WP AI Client when not relying on core.
		if ( should_use_bundled_wp_ai_client() && class_exists( AI_Client::class ) ) {
			AI_Client::init();
		}

		maybe_apply_option_credentials_to_core_ai_client();

		$registry = new Experiment_Registry();
		$loader   = new Experiment_Loader( $registry );
		$loader->register_default_experiments();
		$loader->initialize_experiments();

		// Initialize settings registration.
		$settings_registration = new Settings_Registration( $registry );
		$settings_registration->init();

		// Initialize admin settings page.
		if ( is_admin() ) {
			$settings_page = new Settings_Page( $registry );
			$settings_page->init();
		}

		// Register our post-related WordPress Abilities.
		$post_abilities = new Posts();
		$post_abilities->register();

		add_action(
			'wp_abilities_api_categories_init',
			static function () {
				/**
				 * Register a generic catch-all category that all
				 * Abilities we register can use. Can re-evaluate this
				 * in the future if we need/want more specific categories.
				 */
				wp_register_ability_category(
					AI_EXPERIMENTS_DEFAULT_ABILITY_CATEGORY,
					array(
						'label'       => __( 'AI Experiments', 'ai' ),
						'description' => __( 'Various AI experiments.', 'ai' ),
					),
				);
			}
		);
	} catch ( \Throwable $t ) {
		_doing_it_wrong(
			__NAMESPACE__ . '\initialize_experiments',
			sprintf(
				/* translators: %s: Error message. */
				esc_html__( 'AI Plugin initialization failed: %s', 'ai' ),
				esc_html( $t->getMessage() )
			),
			'0.1.0'
		);
	}
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\load' );
