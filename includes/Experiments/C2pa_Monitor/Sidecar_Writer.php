<?php
/**
 * Writes raw C2PA manifest bytes to a sidecar file under the uploads dir.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions
namespace WordPress\AI\Experiments\C2pa_Monitor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists Raw_Manifest bytes alongside the rest of the uploads tree.
 *
 * Layout: `wp-content/uploads/ai-c2pa/<attachment_id>.<format>.c2pa`.
 *
 * The directory is created on demand and hardened with an `.htaccess` deny
 * rule (Apache) and `index.php` placeholder. Operators on nginx must add a
 * `location` deny rule manually; see README.md.
 *
 * @since 0.7.0
 */
class Sidecar_Writer {
	/**
	 * Subdirectory name under the uploads basedir.
	 *
	 * @var string
	 */
	public const SUBDIR = 'ai-c2pa';

	/**
	 * Persists $manifest for $attachment_id and returns the relative path.
	 *
	 * @since 0.7.0
	 *
	 * @param int                                                $attachment_id Attachment ID.
	 * @param \WordPress\AI\Experiments\C2pa_Monitor\Raw_Manifest $manifest      Manifest payload to persist.
	 * @return string Relative path under the uploads basedir, e.g.
	 *                'ai-c2pa/1234.jpeg.c2pa'.
	 *
	 * @throws \RuntimeException When the sidecar directory cannot be created
	 *                           or the file cannot be written.
	 */
	public function write( int $attachment_id, Raw_Manifest $manifest ): string {
		$basedir = $this->ensure_dir();

		$relative_dir = self::SUBDIR;
		$basename     = sprintf( '%d.%s.c2pa', $attachment_id, $this->safe_format( $manifest->format ) );
		$absolute     = trailingslashit( $basedir ) . $basename;

		$bytes_written = file_put_contents( $absolute, $manifest->bytes, LOCK_EX );
		if ( false === $bytes_written ) {
			throw new \RuntimeException( 'Failed to write C2PA sidecar file.' );
		}
		if ( $bytes_written !== $manifest->bytes_length ) {
			wp_delete_file( $absolute );
			throw new \RuntimeException( 'Short write for C2PA sidecar file.' );
		}

		return $relative_dir . '/' . $basename;
	}

	/**
	 * Ensures the sidecar subdirectory exists with hardening files.
	 *
	 * @since 0.7.0
	 *
	 * @return string Absolute path to the sidecar directory.
	 *
	 * @throws \RuntimeException When the directory cannot be created.
	 */
	public function ensure_dir(): string {
		$uploads = wp_upload_dir( null, false );
		if ( ! is_array( $uploads ) || empty( $uploads['basedir'] ) ) {
			throw new \RuntimeException( 'wp_upload_dir() returned no basedir.' );
		}
		$basedir = trailingslashit( (string) $uploads['basedir'] ) . self::SUBDIR;

		if ( ! is_dir( $basedir ) ) {
			if ( ! wp_mkdir_p( $basedir ) ) {
				throw new \RuntimeException( 'Could not create C2PA sidecar directory.' );
			}
		}

		$this->maybe_write_hardening_files( $basedir );

		return $basedir;
	}

	/**
	 * Writes hardening files into the sidecar directory if they do not already
	 * exist. Failures here are non-fatal: the sidecar directory may still be
	 * usable on hosts where the web server is configured externally.
	 *
	 * @since 0.7.0
	 *
	 * @param string $basedir Absolute path to the sidecar directory.
	 * @return void
	 */
	private function maybe_write_hardening_files( string $basedir ): void {
		$index = trailingslashit( $basedir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		$htaccess = trailingslashit( $basedir ) . '.htaccess';
		if ( file_exists( $htaccess ) ) {
			return;
		}

		$rules = "# C2PA Monitor sidecar files - block direct web access (Apache).\n"
			. "# Operators on nginx must add a 'location ^~ /wp-content/uploads/ai-c2pa/ { deny all; }' rule.\n"
			. "<IfModule mod_authz_core.c>\n"
			. "    Require all denied\n"
			. "</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n"
			. "    Order allow,deny\n"
			. "    Deny from all\n"
			. "</IfModule>\n";
		file_put_contents( $htaccess, $rules );
	}

	/**
	 * Sanitizes the format string used in sidecar filenames.
	 *
	 * @since 0.7.0
	 *
	 * @param string $format Format identifier from Format_Detector.
	 * @return string Lowercase a-z0-9 only, defaulting to 'bin'.
	 */
	private function safe_format( string $format ): string {
		$clean = strtolower( preg_replace( '/[^a-z0-9]/i', '', $format ) ?? '' );
		if ( '' === $clean ) {
			return 'bin';
		}
		return $clean;
	}
}
