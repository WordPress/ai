<?php
/**
 * Conditional loader for the vendored PHP AI Client SDK overlay.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Vendor\AiClient;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers vendored PHP AI Client classes only when the environment's own SDK lacks them.
 *
 * The overlay forward-ports SDK changes that are not yet available everywhere. Changes are grouped
 * into independent "features" (e.g. embeddings, streaming). Each feature is gated separately: an
 * environment may already provide one feature (so we defer to it) while lacking another (so we
 * supply it). A feature is NEVER gated on another feature's availability.
 *
 * For each feature the overlay decides, independently:
 *   - defer   — the environment already ships this feature (its sentinel class exists); do nothing.
 *   - skip    — the environment ships an older, incompatible copy of a class this feature must
 *               override, and that class is already loaded, so we cannot replace it; log and do
 *               nothing.
 *   - activate — the environment lacks this feature; serve this feature's classes from the overlay.
 *
 * The autoloader serves ONLY the classes belonging to activated features; every other
 * `WordPress\AiClient\…` class falls through to the environment's own autoloader.
 *
 * @since x.x.x
 */
final class SDK_Overlay {

	/**
	 * Namespace prefix served by the overlay autoloader.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const NAMESPACE_PREFIX = 'WordPress\\AiClient\\';

	/**
	 * Base SDK class the environment must provide for the overlay to function.
	 *
	 * The vendored files extend base SDK classes (e.g. AbstractDataTransferObject) that we do not
	 * ship; without the base SDK present, loading them would fatal. This class is never vendored.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const BASE_SDK_CLASS = 'WordPress\\AiClient\\AiClient';

	/**
	 * Independent overlay features.
	 *
	 * Each feature declares:
	 *   - sentinel: a net-new class that exists in the environment ONLY if it already ships this
	 *               feature. Must be one of the feature's own `classes` (feature-specific and
	 *               net-new), never a shared or base class — probing it must not force-load a class
	 *               this feature intends to override.
	 *   - guards:   override-race classes this feature modifies, each mapped to a method that exists
	 *               only in our (newer) copy. Used to detect an unwinnable conflict where the
	 *               environment's older copy is already loaded.
	 *   - classes:  the exact fully-qualified class names this feature overlays. The autoloader
	 *               serves only these, and only when the feature activates.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, array{sentinel: string, guards: array<string, string>, classes: list<string>}>
	 */
	private const FEATURES = array(
		'embeddings' => array(
			'sentinel' => 'WordPress\\AiClient\\Builders\\EmbeddingBuilder',
			'guards'   => array(
				'WordPress\\AiClient\\Providers\\Models\\DTO\\ModelRequirements' => 'fromEmbeddingData',
			),
			'classes'  => array(
				'WordPress\\AiClient\\Builders\\EmbeddingBuilder',
				'WordPress\\AiClient\\Builders\\Traits\\ModelResolutionTrait',
				'WordPress\\AiClient\\Providers\\ModelResolver',
				'WordPress\\AiClient\\Providers\\Models\\DTO\\ModelRequirements',
				'WordPress\\AiClient\\Providers\\Models\\DTO\\ModelConfig',
				'WordPress\\AiClient\\Providers\\Models\\EmbeddingGeneration\\Contracts\\EmbeddingGenerationModelInterface',
				'WordPress\\AiClient\\Results\\DTO\\Embedding',
				'WordPress\\AiClient\\Results\\DTO\\EmbeddingResult',
				'WordPress\\AiClient\\Events\\BeforeGenerateEmbeddingEvent',
				'WordPress\\AiClient\\Events\\AfterGenerateEmbeddingEvent',
			),
		),
	);

	/**
	 * Class-to-file map the autoloader serves, populated by register() for activated features.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, string>
	 */
	private static $served_classes = array();

	/**
	 * Registers the overlay autoloader for whichever features the environment is missing.
	 *
	 * Must run at plugin bootstrap, before any AI operation could reference an SDK class.
	 *
	 * @since x.x.x
	 */
	public static function register(): void {
		// The overlay supplements an existing base SDK; it cannot (and need not) activate when the
		// environment provides no PHP AI Client SDK at all, since the vendored files extend base
		// SDK classes that must already be present. This also keeps the overlay inert under static
		// analysis / CLI tooling that boots the plugin without the SDK.
		if ( ! class_exists( self::BASE_SDK_CLASS ) ) {
			return;
		}

		$actions = array();

		foreach ( self::FEATURES as $feature => $config ) {
			// Probe the environment BEFORE registering our autoloader, so our own copy cannot
			// satisfy the sentinel and mask the environment's true capability.
			$environment_capable = class_exists( $config['sentinel'] );
			$conflict_loaded     = self::conflicting_class_loaded( $config['guards'] );

			$action             = self::decide( $environment_capable, $conflict_loaded );
			$actions[ $feature ] = $action;

			if ( 'skip' === $action ) {
				self::log_conflict( $feature );
			}
		}

		$served = self::plan_served_classes( self::FEATURES, $actions );

		if ( array() !== $served ) {
			self::$served_classes = $served;
			spl_autoload_register( array( self::class, 'autoload' ), true, true );
		}
	}

	/**
	 * Returns the feature manifest.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{sentinel: string, guards: array<string, string>, classes: list<string>}>
	 */
	public static function features(): array {
		return self::FEATURES;
	}

	/**
	 * Decides what to do for a single feature given the probed environment state.
	 *
	 * @since x.x.x
	 *
	 * @param bool $environment_capable Whether the environment already provides this feature.
	 * @param bool $conflict_loaded     Whether an override-race class for this feature is already
	 *                                   loaded in an older form that cannot be replaced.
	 * @return string One of 'defer', 'skip', or 'activate'.
	 */
	public static function decide( bool $environment_capable, bool $conflict_loaded ): string {
		if ( $environment_capable ) {
			return 'defer';
		}

		if ( $conflict_loaded ) {
			return 'skip';
		}

		return 'activate';
	}

	/**
	 * Builds the class-to-file map served by the overlay for the activated features.
	 *
	 * Classes belonging to non-activated features are excluded, as are classes with no vendored
	 * file on disk. This is the mechanism that keeps features independent: a class is served only
	 * if a feature that lists it activated.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, array{classes: list<string>}> $features Feature manifest.
	 * @param array<string, string>                       $actions  Feature name => resolved action
	 *                                                               ('activate'|'defer'|'skip').
	 * @return array<string, string> Fully-qualified class name => absolute file path.
	 */
	public static function plan_served_classes( array $features, array $actions ): array {
		$served = array();

		foreach ( $features as $feature => $config ) {
			if ( 'activate' !== ( $actions[ $feature ] ?? 'defer' ) ) {
				continue;
			}

			foreach ( $config['classes'] as $class_name ) {
				$file = self::class_to_file( $class_name );
				if ( null !== $file ) {
					$served[ $class_name ] = $file;
				}
			}
		}

		return $served;
	}

	/**
	 * Autoloads a class from the overlay, if it belongs to an activated feature.
	 *
	 * @since x.x.x
	 *
	 * @param string $class_name Fully-qualified class name.
	 */
	public static function autoload( string $class_name ): void {
		if ( isset( self::$served_classes[ $class_name ] ) ) {
			require self::$served_classes[ $class_name ]; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
		}
	}

	/**
	 * Maps a class name to the overlay file that would define it, if we ship it.
	 *
	 * This resolves a path regardless of feature activation; use plan_served_classes() to know
	 * which classes are actually served.
	 *
	 * @since x.x.x
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @return string|null Absolute file path, or null if not shipped by the overlay.
	 */
	public static function class_to_file( string $class_name ): ?string {
		$len = strlen( self::NAMESPACE_PREFIX );

		if ( strncmp( $class_name, self::NAMESPACE_PREFIX, $len ) !== 0 ) {
			return null;
		}

		$relative = substr( $class_name, $len );
		$file     = self::src_dir() . str_replace( '\\', '/', $relative ) . '.php';

		return file_exists( $file ) ? $file : null;
	}

	/**
	 * Returns the absolute path to the overlay source directory (trailing slash).
	 *
	 * @since x.x.x
	 *
	 * @return string
	 */
	public static function src_dir(): string {
		return __DIR__ . '/src/';
	}

	/**
	 * Determines whether any override-race class in the given guard set is already loaded in a
	 * form we cannot replace.
	 *
	 * Uses autoload=false so the probe does not itself force-load the environment's copy.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, string> $guards Override-race class name => required method name.
	 * @return bool
	 */
	private static function conflicting_class_loaded( array $guards ): bool {
		foreach ( $guards as $class_name => $method_name ) {
			if ( class_exists( $class_name, false ) && ! method_exists( $class_name, $method_name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Logs that a feature could not activate because of an already-loaded older SDK class.
	 *
	 * @since x.x.x
	 *
	 * @param string $feature The feature that could not activate.
	 */
	private static function log_conflict( string $feature ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'AI plugin: PHP AI Client "%s" overlay could not activate because an older SDK '
					. 'class was already loaded. That feature is unavailable this request.',
					$feature
				)
			);
		}
	}
}
