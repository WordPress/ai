<?php
/**
 * HTTP Transporter decorator that logs AI requests.
 *
 * @package WordPress\AI\Logging
 */

declare( strict_types=1 );

namespace WordPress\AI\Logging;

use Throwable;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;

/**
 * Decorates an HTTP transporter to add logging for AI requests.
 *
 * This class wraps the SDK's HttpTransporterInterface rather than the lower-level
 * PSR-18 ClientInterface, providing cleaner integration with the AI Client SDK.
 *
 * Extraction logic is delegated to Log_Data_Extractor for better separation of
 * concerns and extensibility via WordPress filter hooks.
 *
 * @since 0.1.0
 */
class Logging_Http_Transporter implements HttpTransporterInterface {

	/**
	 * The wrapped HTTP transporter.
	 *
	 * @var \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface
	 */
	private HttpTransporterInterface $transporter;

	/**
	 * The log manager instance.
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Manager
	 */
	private AI_Request_Log_Manager $log_manager;

	/**
	 * The data extractor instance.
	 *
	 * @var \WordPress\AI\Logging\Log_Data_Extractor
	 */
	private Log_Data_Extractor $extractor;

	/**
	 * Constructor.
	 *
	 * @param \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface $transporter The HTTP transporter to wrap.
	 * @param \WordPress\AI\Logging\AI_Request_Log_Manager   $log_manager The log manager.
	 * @param \WordPress\AI\Logging\Log_Data_Extractor|null  $extractor   Optional data extractor (created if not provided).
	 */
	public function __construct(
		HttpTransporterInterface $transporter,
		AI_Request_Log_Manager $log_manager,
		?Log_Data_Extractor $extractor = null
	) {
		$this->transporter = $transporter;
		$this->log_manager = $log_manager;
		$this->extractor   = $extractor ?? new Log_Data_Extractor();
	}

	/**
	 * Sends an HTTP request and returns the response, logging the request.
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Request             $request The request to send.
	 * @param \WordPress\AiClient\Providers\Http\DTO\RequestOptions|null $options Optional transport options.
	 * @return \WordPress\AiClient\Providers\Http\DTO\Response The response received.
	 */
	public function send( Request $request, ?RequestOptions $options = null ): Response {
		$timer     = $this->log_manager->start_timer();
		$log_data  = $this->extract_request_data( $request );
		$error_msg = null;
		$status    = 'success';

		try {
			$response = $this->transporter->send( $request, $options );

			$log_data = $this->extract_response_data( $response, $log_data );

			return $response;
		} catch ( Throwable $e ) {
			$status    = 'error';
			$error_msg = $e->getMessage();
			throw $e;
		} finally {
			$log_data['duration_ms']   = $this->log_manager->end_timer( $timer );
			$log_data['status']        = $status;
			$log_data['error_message'] = $error_msg;

			// @phpstan-ignore argument.type (array shape is built incrementally)
			$this->log_manager->log( $log_data );
		}
	}

	/**
	 * Extracts logging data from the request.
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Request $request The SDK request.
	 * @return array<string, mixed> Initial log data.
	 */
	private function extract_request_data( Request $request ): array {
		$log_data = $this->extractor->extract_request_data(
			$request->getUri(),
			$request->getMethod()->value,
			$request->getBody()
		);

		$source = $this->detect_request_source();

		if ( null !== $source ) {
			$context = $log_data['context'] ?? array();

			if ( ! is_array( $context ) ) {
				$context = array();
			}

			$context['source']   = $source;
			$log_data['context'] = $context;
		}

		return $log_data;
	}

	/**
	 * Extracts token usage and other data from the response.
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Response             $response The SDK response.
	 * @param array<string, mixed> $log_data Log data to augment.
	 * @return array<string, mixed> Augmented log data.
	 */
	private function extract_response_data( Response $response, array $log_data ): array {
		return $this->extractor->extract_response_data(
			$response->getBody(),
			$log_data
		);
	}

	/**
	 * Detects which plugin, theme, or core file initiated the request.
	 *
	 * @since 0.6.0
	 *
	 * @return array<string, string>|null Source metadata.
	 */
	private function detect_request_source(): ?array {
		$logging_dir = wp_normalize_path( __DIR__ ) . '/';
		$frames      = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Required to attribute the originating plugin/theme/core source for a request.

		foreach ( $frames as $frame ) {
			if ( empty( $frame['file'] ) || ! is_string( $frame['file'] ) ) {
				continue;
			}

			$file = wp_normalize_path( $frame['file'] );

			if ( 0 === strpos( $file, $logging_dir ) || false !== strpos( $file, '/vendor/' ) ) {
				continue;
			}

			$source = $this->match_request_source( $file );

			if ( null !== $source ) {
				return $source;
			}
		}

		return null;
	}

	/**
	 * Matches a file path to a WordPress source type.
	 *
	 * @since 0.6.0
	 *
	 * @param string $file Normalized absolute file path.
	 * @return array<string, string>|null Source metadata.
	 */
	private function match_request_source( string $file ): ?array {
		$plugin_source = $this->match_plugin_source( $file );

		if ( null !== $plugin_source ) {
			return $plugin_source;
		}

		$mu_plugin_source = $this->match_mu_plugin_source( $file );

		if ( null !== $mu_plugin_source ) {
			return $mu_plugin_source;
		}

		$theme_source = $this->match_theme_source( $file );

		if ( null !== $theme_source ) {
			return $theme_source;
		}

		return $this->match_core_source( $file );
	}

	/**
	 * Matches plugin file paths.
	 *
	 * @since 0.6.0
	 *
	 * @param string $file Normalized absolute file path.
	 * @return array<string, string>|null Source metadata.
	 */
	private function match_plugin_source( string $file ): ?array {
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			return null;
		}

		$plugins_dir = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );

		if ( 0 !== strpos( $file, $plugins_dir ) ) {
			return null;
		}

		$relative = substr( $file, strlen( $plugins_dir ) );
		$segments = explode( '/', $relative );
		$slug     = $segments[0] ?? '';

		if ( '' === $slug ) {
			return null;
		}

		return array(
			'type' => 'plugin',
			'slug' => $slug,
			'name' => 'ai' === $slug ? __( 'AI', 'ai' ) : $slug,
			'file' => $relative,
		);
	}

	/**
	 * Matches mu-plugin file paths.
	 *
	 * @since 0.6.0
	 *
	 * @param string $file Normalized absolute file path.
	 * @return array<string, string>|null Source metadata.
	 */
	private function match_mu_plugin_source( string $file ): ?array {
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
			return null;
		}

		$mu_plugins_dir = trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) );

		if ( 0 !== strpos( $file, $mu_plugins_dir ) ) {
			return null;
		}

		$relative = substr( $file, strlen( $mu_plugins_dir ) );
		$segments = explode( '/', $relative );
		$slug     = $segments[0] ?? '';

		if ( '' === $slug ) {
			return null;
		}

		$name = preg_replace( '/\.php$/', '', $slug );

		if ( ! is_string( $name ) || '' === $name ) {
			$name = $slug;
		}

		return array(
			'type' => 'mu-plugin',
			'slug' => $name,
			'name' => $name,
			'file' => $relative,
		);
	}

	/**
	 * Matches theme file paths.
	 *
	 * @since 0.6.0
	 *
	 * @param string $file Normalized absolute file path.
	 * @return array<string, string>|null Source metadata.
	 */
	private function match_theme_source( string $file ): ?array {
		$themes_dir = trailingslashit( wp_normalize_path( get_theme_root() ) );

		if ( 0 !== strpos( $file, $themes_dir ) ) {
			return null;
		}

		$relative = substr( $file, strlen( $themes_dir ) );
		$segments = explode( '/', $relative );
		$slug     = $segments[0] ?? '';

		if ( '' === $slug ) {
			return null;
		}

		$theme = wp_get_theme( $slug );

		return array(
			'type' => 'theme',
			'slug' => $slug,
			'name' => $theme->exists() ? $theme->get( 'Name' ) : $slug,
			'file' => $relative,
		);
	}

	/**
	 * Matches core WordPress file paths.
	 *
	 * @since 0.6.0
	 *
	 * @param string $file Normalized absolute file path.
	 * @return array<string, string>|null Source metadata.
	 */
	private function match_core_source( string $file ): ?array {
		$core_directories = array(
			trailingslashit( wp_normalize_path( ABSPATH . 'wp-admin' ) ),
			trailingslashit( wp_normalize_path( ABSPATH . 'wp-includes' ) ),
		);

		foreach ( $core_directories as $core_directory ) {
			if ( 0 !== strpos( $file, $core_directory ) ) {
				continue;
			}

			return array(
				'type' => 'core',
				'slug' => 'wordpress',
				'name' => __( 'WordPress Core', 'ai' ),
				'file' => substr( $file, strlen( trailingslashit( wp_normalize_path( ABSPATH ) ) ) ),
			);
		}

		return null;
	}
}
