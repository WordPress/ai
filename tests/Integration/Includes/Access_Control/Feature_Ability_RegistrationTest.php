<?php
/**
 * Guards that feature abilities stay linked to their feature's access control.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Access_Control
 */

namespace WordPress\AI\Tests\Integration\Includes\Access_Control;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WP_UnitTestCase;

/**
 * Feature ability registration test case.
 *
 * @since x.x.x
 */
class Feature_Ability_RegistrationTest extends WP_UnitTestCase {

	/**
	 * A feature that registers an ability with wp_register_ability() skips the
	 * `wpai_feature` tag, so its access control would silently not apply.
	 *
	 * @since x.x.x
	 */
	public function test_features_do_not_call_wp_register_ability_directly(): void {
		$root      = dirname( __DIR__, 4 ) . '/includes';
		$offenders = array();

		foreach ( array( 'Experiments', 'Features' ) as $dir ) {
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "{$root}/{$dir}" ) );
			foreach ( $files as $file ) {
				if ( 'php' !== $file->getExtension() ) {
					continue;
				}
				// Tokens, not regex, so docblocks that mention the function don't count.
				$tokens = token_get_all( (string) file_get_contents( $file->getPathname() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Reading local source in a test.
				foreach ( $tokens as $token ) {
					if ( ! is_array( $token ) ) {
						continue;
					}

					$is_call = T_STRING === $token[0] && 'wp_register_ability' === $token[1];
					// PHP 8 tokenizes `\wp_register_ability` as one fully qualified name.
					if ( ! $is_call && defined( 'T_NAME_FULLY_QUALIFIED' ) && T_NAME_FULLY_QUALIFIED === $token[0] ) { // phpcs:ignore PHPCompatibility.Constants.NewConstants.t_name_fully_qualifiedFound -- Guarded by defined() for PHP 7.4.
						$is_call = 'wp_register_ability' === ltrim( $token[1], '\\' );
					}

					if ( $is_call ) {
						$offenders[] = str_replace( $root . '/', '', $file->getPathname() );
						break;
					}
				}
			}
		}

		$this->assertSame( array(), $offenders, 'Register feature abilities with $this->register_feature_ability() so access control applies.' );
	}
}
