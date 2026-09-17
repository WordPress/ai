<?php
/**
 * Helper for running code from inside a throwaway plugin directory.
 *
 * The vendored secrets SDK attributes callers by inspecting backtrace file paths, so testing that
 * attribution needs a caller whose file genuinely lives under a different
 * `wp-content/plugins/{slug}/` path. This writes one to a temporary tree and runs it.
 *
 * @package WordPress\AI\Tests\Integration\Vendor
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Vendor\Secrets;

/**
 * Creates, invokes, and cleans up a fixture plugin file.
 *
 * @since 1.3.0
 */
trait Fixture_Plugin_Trait {

	/**
	 * Root of the throwaway plugin tree, or '' when none has been created.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	private string $fixture_root = '';

	/**
	 * Writes a caller into a temporary `wp-content/plugins/{$slug}/` tree and includes it.
	 *
	 * @since 1.3.0
	 *
	 * @param string $slug Plugin directory name to impersonate.
	 * @param string $body PHP source for the caller, without the opening tag. Whatever it returns
	 *                     is returned from here.
	 * @return mixed The fixture's return value.
	 */
	private function run_in_fixture_plugin( string $slug, string $body ) {
		$this->fixture_root = untrailingslashit( get_temp_dir() ) . '/wpai-secrets-fixture';
		$directory          = $this->fixture_root . '/wp-content/plugins/' . $slug;

		$this->assertTrue( wp_mkdir_p( $directory ), 'Could not create the fixture plugin directory.' );

		$file = $directory . '/caller.php';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture outside the WP tree.
		$this->assertNotFalse( file_put_contents( $file, "<?php\n" . $body ), 'Could not write the fixture caller.' );

		return include $file;
	}

	/**
	 * Removes the fixture tree, if one was created.
	 *
	 * @since 1.3.0
	 */
	private function delete_fixture_plugin(): void {
		if ( '' === $this->fixture_root ) {
			return;
		}

		$this->delete_tree( $this->fixture_root );
		$this->fixture_root = '';
	}

	/**
	 * Recursively removes a directory tree.
	 *
	 * @since 1.3.0
	 *
	 * @param string $path Absolute path to remove.
	 */
	private function delete_tree( string $path ): void {
		if ( is_dir( $path ) ) {
			foreach ( array_diff( (array) scandir( $path ), array( '.', '..' ) ) as $entry ) {
				$this->delete_tree( $path . '/' . $entry );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.directory_operations_rmdir -- test fixture outside the WP tree.
			rmdir( $path );
			return;
		}

		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}
}
