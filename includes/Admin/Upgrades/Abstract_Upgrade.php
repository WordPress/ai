<?php
/**
 * Abstract class for handling plugin upgrade routines.
 *
 * The class encapsulates the logic for performing any necessary database migrations or other upgrade tasks when the plugin is updated to a new version. Each class represents a specific plugin version where the upgrade became necessary and contains the logic to perform the upgrade from the previous version(s).
 *
 * Error are caught during the upgrade routine and stored in an option for use in an admin notice.
 *
 * @package WordPress\AI\Admin\Upgrades
 * @since 0.6.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Admin\Upgrades;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Abstract class for handling plugin upgrade routines.
 *
 * @internal
 *
 * @since 0.6.0
 */
abstract class Abstract_Upgrade {

	/**
	 * The version number associated with this upgrade.
	 *
	 * This should be overloaded
	 *
	 * @since 0.6.0
	 *
	 * @var string
	 */
	public static string $version = '';

	/**
	 * The version of the plugin in the database.
	 *
	 * This is the version we're migrating from.
	 *
	 * @since 0.6.0
	 *
	 * @var string
	 */
	protected string $db_version = '';

	/**
	 * Constructor.
	 *
	 * @since 0.6.0
	 *
	 * @param string $db_version The version of the plugin in the database.
	 *
	 * @throws \InvalidArgumentException Throws an exception if the provided version is invalid.
	 */
	public function __construct( string $db_version ) {
		// @todo remove the !empty() check when we don't need to migrate from < 0.5.0 and can treat '' as a new install.
		if ( ! empty( $db_version ) && ! version_compare( $db_version, '0.0.0', '>' ) ) {
			throw new \InvalidArgumentException( 'Invalid database version provided for upgrade.' );
		}

		$this->db_version = $db_version;
	}

	/**
	 * Performs the upgrade routine.
	 *
	 * @since 0.6.0
	 *
	 * @return true|\WP_Error True on success, or a WP_Error on failure.
	 */
	public function run() {
		if ( version_compare( $this->db_version, static::$version, '>=' ) ) {
			// No upgrade needed.
			return true;
		}

		try {
			$this->upgrade();
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'wpai_upgrade_failed', $e->getMessage() );
		}

		return true;
	}

	/**
	 * The upgrade process.
	 *
	 * @throws \Exception Throws an exception if the upgrade fails.
	 */
	abstract protected function upgrade(): void;
}
